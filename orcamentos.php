<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/lead_service.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/calc.php';
exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_VENDEDOR);

$pdo = db();
$mes = $_GET['mes'] ?? mesAtual();
$vendedores = filtrarVendedoresDaEquipe($pdo, obterVendedores($pdo));
$linhas = array_column($pdo->query('SELECT nome FROM linhas ORDER BY ordem ASC')->fetchAll(), 'nome');
$funisArr = funis();
$idsPessoasVisiveis = array_column($vendedores, 'id');
$cargosRegistro = [];
if ($idsPessoasVisiveis) {
    $marks = implode(',', array_fill(0, count($idsPessoasVisiveis), '?'));
    $stmt = $pdo->prepare("SELECT u.id user_id,COALESCE(u.pessoa_id,u.vendedor_id) pessoa_id,u.nome,c.id cargo_id,c.title,c.has_commission
        FROM usuarios u JOIN user_cargos uc ON uc.user_id=u.id JOIN cargos c ON c.id=uc.cargo_id
        WHERE u.status='active' AND COALESCE(u.pessoa_id,u.vendedor_id) IN ({$marks})
          AND (c.has_commission=1 OR c.title='ADMINISTRADOR')
          AND (c.title='ADMINISTRADOR' OR NOT EXISTS (SELECT 1 FROM user_cargos uca JOIN cargos ca ON ca.id=uca.cargo_id WHERE uca.user_id=u.id AND ca.title='ADMINISTRADOR'))
        ORDER BY u.nome,c.id");
    $stmt->execute($idsPessoasVisiveis); $cargosRegistro = $stmt->fetchAll();
}

function orcRedirect(string $msg = ''): void
{
    if ($msg !== '') $_SESSION['flash'] = $msg;
    $qs = !empty($_GET['mes']) ? ('?mes=' . urlencode($_GET['mes'])) : '';
    header('Location: /orcamentos.php' . $qs);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'add') {
        $cliente = trim($_POST['cliente'] ?? '');
        $valor = (float) ($_POST['valor'] ?? 0);
        $linha = trim($_POST['linha'] ?? '');
        if ($cliente === '' || $valor <= 0) orcRedirect('Informe ao menos cliente e valor.');
        if ($linha === '') orcRedirect('Linha de Produto é obrigatória — regra: uma oportunidade por linha.');
        $vendedorId = $_POST['vendedor_id'] ?: null;
        if ($vendedorId && !in_array($vendedorId, array_column($vendedores, 'id'), true)) { http_response_code(403); exit; }
        $assignedTo = usuarioIdPorVendedor($pdo, $vendedorId) ?: (temPapel(PAPEL_VENDEDOR, PAPEL_SUPERVISOR) ? (int) usuarioLogado()['id'] : null);
        $cargoId = cargoParaRegistroComercial($pdo, $assignedTo, !empty($_POST['cargo_id']) ? (int) $_POST['cargo_id'] : null);
        $suporteId = $_POST['suporte_id'] ?: null;
        $data = $_POST['data'] ?: hoje();
        $funil = funilDe($linha);
        $numero = trim($_POST['numero'] ?? '') ?: gerarNumeroProposta($pdo, $vendedores, $vendedorId, $suporteId, $data);
        $stmt = $pdo->prepare('INSERT INTO orcamentos (numero,data,cliente,vendedor_id,suporte_id,linha,tipo,funil,etapa,valor,comissao_dnb_estimada,status,origem,assigned_to_user_id,cargo_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$numero, $data, $cliente, $vendedorId, $suporteId, $linha, tipoDe($linha), $funil, $funisArr[$funil]['etapas'][0], $valor, (float) ($_POST['comissao_dnb_estimada'] ?? 0), 'Aberta', 'manual', $assignedTo, $cargoId]);
        registrarTimeline($pdo, (int) $pdo->lastInsertId(), 'created', 'Oportunidade criada manualmente.');
        orcRedirect('Oportunidade ' . $numero . ' registrada no funil ' . $funisArr[$funil]['label'] . '.');
    }

    if ($acao === 'set_etapa') {
        if (!podeAcessarLead($pdo, (int) $_POST['id'])) { http_response_code(403); exit; }
        $stmt = $pdo->prepare('UPDATE orcamentos SET etapa=? WHERE id=?');
        $stmt->execute([$_POST['etapa'], (int) $_POST['id']]);
        registrarTimeline($pdo, (int) $_POST['id'], 'funnel_update', 'Etapa atualizada para ' . $_POST['etapa'] . '.');
        orcRedirect();
    }

    if ($acao === 'set_valor') {
        if (!podeAcessarLead($pdo, (int) $_POST['id'])) { http_response_code(403); exit; }
        $stmt = $pdo->prepare('UPDATE orcamentos SET valor=? WHERE id=?');
        $stmt->execute([(float) $_POST['valor'], (int) $_POST['id']]);
        orcRedirect();
    }

    if ($acao === 'set_status') {
        if (!podeAcessarLead($pdo, (int) $_POST['id'])) { http_response_code(403); exit; }
        if (!in_array($_POST['status'] ?? '', ['Aberta','Ganha','Perdida'], true)) { http_response_code(422); exit; }
        $stmt = $pdo->prepare('UPDATE orcamentos SET status=? WHERE id=?');
        $stmt->execute([$_POST['status'], (int) $_POST['id']]);
        orcRedirect();
    }

    if ($acao === 'excluir') {
        if (!temPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR)) { http_response_code(403); exit; }
        if (!podeAcessarLead($pdo, (int) $_POST['id'])) { http_response_code(403); exit; }
        $pdo->prepare('DELETE FROM orcamentos WHERE id=?')->execute([(int) $_POST['id']]);
        orcRedirect();
    }

    if ($acao === 'importar_json') {
        if (!temPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR)) { http_response_code(403); exit; }
        try {
            $arr = json_decode($_POST['json_imp'] ?? '', true, 512, JSON_THROW_ON_ERROR);
            $lista = array_is_list($arr) ? $arr : [$arr];
            $n = 0;
            foreach ($lista as $o) {
                $nomeVend = mb_strtolower(trim($o['vendedor'] ?? ''));
                $vendId = null;
                foreach ($vendedores as $v) {
                    $vn = mb_strtolower($v['nome']);
                    if ($nomeVend !== '' && ($vn === $nomeVend || explode(' ', $vn)[0] === explode(' ', $nomeVend)[0])) { $vendId = $v['id']; break; }
                }
                $linha = in_array($o['linha'] ?? '', $linhas, true) ? $o['linha'] : mapLinha(($o['linha'] ?? '') ?: ($o['tipo'] ?? ''));
                $assignedTo = usuarioIdPorVendedor($pdo, $vendId) ?: (temPapel(PAPEL_SUPERVISOR) ? (int) usuarioLogado()['id'] : null);
                $cargoId = cargoParaRegistroComercial($pdo, $assignedTo);
                $stmt = $pdo->prepare('INSERT INTO orcamentos (numero,data,cliente,vendedor_id,suporte_id,linha,tipo,funil,etapa,valor,status,origem,assigned_to_user_id,cargo_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $funil = funilDe($linha);
                $stmt->execute([$o['numero'] ?? '', $o['data'] ?? hoje(), $o['cliente'] ?? '', $vendId, null, $linha, $o['tipo'] ?? 'Peças', $funil, $funisArr[$funil]['etapas'][0], (float) ($o['valor'] ?? 0), 'Aberta', 'cotador', $assignedTo, $cargoId]);
                $n++;
            }
            orcRedirect("$n proposta(s) importada(s) do Cotador.");
        } catch (Throwable $e) {
            orcRedirect('JSON inválido — verifique o conteúdo exportado do Cotador.');
        }
    }

    if ($acao === 'importar_sf_csv' && !empty($_FILES['arquivo']['tmp_name'])) {
        if (!temPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR)) { http_response_code(403); exit; }
        $novos = 0; $atualizados = 0;
        if (($h = fopen($_FILES['arquivo']['tmp_name'], 'r')) !== false) {
            $header = fgetcsv($h, 0, ',');
            if ($header) {
                $header = array_map(fn($c) => mb_strtolower(trim($c)), $header);
                $find = function (array $cands) use ($header) {
                    foreach ($header as $i => $c) foreach ($cands as $x) if (str_contains($c, $x)) return $i;
                    return null;
                };
                $iNome = $find(['opportunity name', 'nome da oportunidade', 'oportunidade']);
                $iConta = $find(['account', 'conta', 'cliente']);
                $iId = $find(['opportunity id', 'id da oportunidade']);
                $iValor = $find(['amount', 'valor']);
                $iCom = $find(['comiss']);
                $iFase = $find(['stage', 'fase', 'etapa']);
                $iDono = $find(['owner', 'proprietário', 'proprietario', 'responsável', 'responsavel', 'vendedor']);
                $num = fn($t) => (float) str_replace(',', '.', preg_replace('/[R$\s.]/', '', (string) $t));
                while (($row = fgetcsv($h, 0, ',')) !== false) {
                    $nomeOp = $iNome !== null ? trim($row[$iNome] ?? '') : '';
                    $cliente = ($iConta !== null ? trim($row[$iConta] ?? '') : '') ?: $nomeOp;
                    $sfId = ($iId !== null ? trim($row[$iId] ?? '') : '') ?: ($nomeOp . '|' . $cliente);
                    if ($sfId === '' || $sfId === '|') continue;
                    $valor = $iValor !== null ? $num($row[$iValor] ?? 0) : 0;
                    $comEst = $iCom !== null ? $num($row[$iCom] ?? 0) : 0;
                    $fase = mb_strtolower($iFase !== null ? ($row[$iFase] ?? '') : '');
                    $dono = mb_strtolower($iDono !== null ? ($row[$iDono] ?? '') : '');
                    $status = 'Aberta'; $etapa = 'Proposta emitida';
                    if (preg_match('/won|ganh/', $fase)) $status = 'Ganha';
                    elseif (preg_match('/lost|perd/', $fase)) $status = 'Perdida';
                    elseif (preg_match('/prospec|qualif/', $fase)) $etapa = 'Prospecção';
                    elseif (preg_match('/needs|levantamento|an[aá]lise|discovery|visita/', $fase)) $etapa = 'Levantamento técnico / Visita';
                    elseif (preg_match('/negoc|review|contrato/', $fase)) $etapa = 'Negociação';
                    $vendId = null;
                    foreach ($vendedores as $v) {
                        $vn = mb_strtolower($v['nome']);
                        if ($dono !== '' && ($vn === $dono || explode(' ', $vn)[0] === explode(' ', $dono)[0])) { $vendId = $v['id']; break; }
                    }
                    $assignedTo = usuarioIdPorVendedor($pdo, $vendId) ?: (temPapel(PAPEL_SUPERVISOR) ? (int) usuarioLogado()['id'] : null);
                    $cargoId = cargoParaRegistroComercial($pdo, $assignedTo);
                    $ex = $pdo->prepare('SELECT id FROM orcamentos WHERE sf_id=?');
                    $ex->execute([$sfId]);
                    $exId = $ex->fetchColumn();
                    if ($exId) {
                        if (!podeAcessarLead($pdo, (int) $exId)) continue;
                        $pdo->prepare('UPDATE orcamentos SET valor=IF(?>0,?,valor), status=?, etapa=IF(?="Aberta",?,etapa), comissao_dnb_estimada=IF(?>0,?,comissao_dnb_estimada), vendedor_id=COALESCE(vendedor_id,?), assigned_to_user_id=COALESCE(assigned_to_user_id,?), cargo_id=COALESCE(cargo_id,?) WHERE id=?')
                            ->execute([$valor, $valor, $status, $status, $etapa, $comEst, $comEst, $vendId, $assignedTo, $cargoId, $exId]);
                        $atualizados++;
                    } else {
                        $pdo->prepare('INSERT INTO orcamentos (sf_id,numero,data,cliente,vendedor_id,linha,tipo,funil,etapa,valor,comissao_dnb_estimada,status,origem,assigned_to_user_id,cargo_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                            ->execute([$sfId, $nomeOp ?: $sfId, hoje(), $cliente, $vendId, 'Compressores IR (agenciamento)', 'Equipamento', 'equipamentos', $etapa, $valor, $comEst, $status, 'salesforce', $assignedTo, $cargoId]);
                        $novos++;
                    }
                }
            }
            fclose($h);
        }
        orcRedirect("Salesforce sincronizado: $novos oportunidade(s) nova(s), $atualizados atualizada(s). Revise vendedor e comissão estimada das novas.");
    }

    if ($acao === 'promover_lead') {
        $vid = (int) $_POST['visita_id'];
        if (!podeAcessarRegistroAtribuido($pdo, 'visitas', $vid)) { http_response_code(403); exit; }
        $stmt = $pdo->prepare('SELECT * FROM visitas WHERE id=?');
        $stmt->execute([$vid]);
        $v = $stmt->fetch();
        if ($v) {
            $linha = mapLinha(($v['oportunidade'] ?? '') . ' ' . ($v['setor'] ?? ''));
            $funil = funilDe($linha);
            $numero = gerarNumeroProposta($pdo, $vendedores, $v['vendedor_id'], null, hoje());
            $cargoId = cargoParaRegistroComercial($pdo, $v['assigned_to_user_id'] ? (int) $v['assigned_to_user_id'] : null);
            $pdo->prepare('INSERT INTO orcamentos (numero,data,cliente,vendedor_id,linha,tipo,funil,etapa,valor,status,origem,obs,assigned_to_user_id,cargo_id) VALUES (?,?,?,?,?,?,?,?,0,?,?,?,?,?)')
                ->execute([$numero, hoje(), $v['cliente'], $v['vendedor_id'], $linha, tipoDe($linha), $funil, $funisArr[$funil]['etapas'][0], 'Aberta', 'lead-visita', 'Lead da OS ' . $v['os'] . ' (' . $v['data'] . '): ' . $v['oportunidade'], $v['assigned_to_user_id'], $cargoId]);
            $pdo->prepare('UPDATE visitas SET lead_status=? WHERE id=?')->execute(['promovida', $vid]);
            orcRedirect("Lead promovido: $numero · {$v['cliente']} · $linha — confirme a linha e preencha o valor na tabela.");
        }
        orcRedirect();
    }

    if ($acao === 'descartar_lead') {
        if (!podeAcessarRegistroAtribuido($pdo, 'visitas', (int) $_POST['visita_id'])) { http_response_code(403); exit; }
        $pdo->prepare('UPDATE visitas SET lead_status=? WHERE id=?')->execute(['descartada', (int) $_POST['visita_id']]);
        orcRedirect();
    }

    if ($acao === 'confirmar_pedido') {
        $oid = (int) $_POST['orcamento_id'];
        if (!podeAcessarLead($pdo, $oid)) { http_response_code(403); exit; }
        $stmt = $pdo->prepare('SELECT * FROM orcamentos WHERE id=?');
        $stmt->execute([$oid]);
        $o = $stmt->fetch();
        if ($o) {
            $pdo->prepare('UPDATE orcamentos SET status=? WHERE id=?')->execute(['Ganha', $oid]);
            $modalidade = ehAgenciamento($o['linha']) ? 'agenciada' : 'revenda';
            $pdo->prepare('INSERT INTO pedidos (numero,data,cliente,vendedor_id,suporte_id,linha,valor,modalidade,comissao_dnb,faturado,orcamento_id,assigned_to_user_id,cargo_id) VALUES (?,?,?,?,?,?,?,?,?,0,?,?,?)')
                ->execute([$_POST['ped_numero'], $_POST['ped_data'], $o['cliente'], $o['vendedor_id'], $o['suporte_id'], $o['linha'], (float) $_POST['ped_valor'], $modalidade, $modalidade === 'agenciada' ? (float) $o['comissao_dnb_estimada'] : 0, $oid, $o['assigned_to_user_id'], $o['cargo_id']]);
            orcRedirect("Pedido {$_POST['ped_numero']} gerado — confirme o faturamento na aba Pedidos.");
        }
        orcRedirect();
    }
}

$msg = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$orcamentos = listarLeadsAcessiveis($pdo);
[$filtroVis, $paramsVis] = filtroEscopoAtribuicao($pdo);
$stmt = $pdo->prepare("SELECT * FROM visitas WHERE {$filtroVis} AND CHAR_LENGTH(TRIM(COALESCE(oportunidade,''))) > 15 AND lead_status IS NULL ORDER BY data DESC");
$stmt->execute($paramsVis); $leadsPendentes = $stmt->fetchAll();
$convertendo = null;
if (!empty($_GET['converter'])) {
    if (!podeAcessarLead($pdo, (int) $_GET['converter'])) { http_response_code(403); exit; }
    $stmt = $pdo->prepare('SELECT * FROM orcamentos WHERE id=?');
    $stmt->execute([(int) $_GET['converter']]);
    $convertendo = $stmt->fetch();
}

$tabAtual = 'orc';
include __DIR__ . '/includes/layout_header.php';
?>

<?php if (count($leadsPendentes)): ?>
<div class="bg-white rounded-xl border border-amber-300 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Triagem de leads das visitas (<?= count($leadsPendentes) ?> aguardando qualificação)</span>
  </div>
  <div class="p-4">
    <div class="text-xs text-slate-500 mb-2">Oportunidades identificadas nas OS de visita. Promova ao funil (nº gerado, linha sugerida) ou descarte.</div>
    <?php foreach ($leadsPendentes as $v): $linhaSug = mapLinha(($v['oportunidade'] ?? '') . ' ' . ($v['setor'] ?? '')); ?>
      <div class="border border-slate-200 rounded-lg p-3 mb-2 flex items-start justify-between flex-wrap gap-2">
        <div class="max-w-[520px]">
          <span class="font-semibold" style="color:<?= NAVY ?>;"><?= e($v['cliente']) ?></span>
          <span class="text-xs text-slate-500 ml-2">OS <?= e($v['os']) ?> · <?= e($v['data']) ?> · <?= e(nomeVendedor($vendedores, $v['vendedor_id'])) ?></span>
          <div class="text-xs text-slate-600 mt-1"><?= e($v['oportunidade']) ?></div>
          <div class="text-xs mt-1"><?= tag('Linha sugerida: ' . $linhaSug, 'blue') ?></div>
        </div>
        <div class="flex gap-2">
          <form method="post"><input type="hidden" name="acao" value="promover_lead"><input type="hidden" name="visita_id" value="<?= $v['id'] ?>">
            <button class="<?= BTN_P ?> text-xs px-3 py-1.5" style="background:<?= NAVY ?>;">Promover a oportunidade</button></form>
          <form method="post"><input type="hidden" name="acao" value="descartar_lead"><input type="hidden" name="visita_id" value="<?= $v['id'] ?>">
            <button class="<?= BTN_S ?>">Descartar</button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Funis de venda · oportunidades abertas</span>
  </div>
  <div class="p-4">
    <?php foreach ($funisArr as $k => $fu):
      $abertas = array_values(array_filter($orcamentos, fn($o) => $o['status'] === 'Aberta' && funilDe($o['linha']) === $k));
      $volume = somaValor($abertas);
      $receita = array_sum(array_map(fn($o) => ehAgenciamento($o['linha']) ? (float) $o['comissao_dnb_estimada'] : (float) $o['valor'], $abertas));
    ?>
      <div class="border border-slate-200 rounded-lg p-3 mb-3">
        <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
          <div><span class="font-bold" style="color:<?= NAVY ?>;"><?= e($fu['label']) ?></span><span class="text-xs text-slate-400 ml-2"><?= e($fu['desc']) ?></span></div>
          <div class="text-xs text-slate-600"><?= count($abertas) ?> oportunidade(s) · volume <b><?= fmtBRL($volume) ?></b> · receita efetiva projetada <b style="color:<?= NAVY ?>;"><?= fmtBRL($receita) ?></b></div>
        </div>
        <div class="grid gap-2" style="grid-template-columns:repeat(<?= count($fu['etapas']) ?>,minmax(0,1fr));">
          <?php foreach ($fu['etapas'] as $et):
            $nEt = array_values(array_filter($abertas, fn($o) => ($o['etapa'] ?: $fu['etapas'][0]) === $et));
            $vEt = somaValor($nEt);
          ?>
            <div class="bg-slate-50 border border-slate-200 rounded-lg p-2 text-center">
              <div class="text-xs font-semibold text-slate-600 leading-tight"><?= e($et) ?></div>
              <div class="text-lg font-bold mt-1" style="color:<?= count($nEt) ? NAVY : '#cbd5e1' ?>;"><?= count($nEt) ?></div>
              <div class="text-xs text-slate-400"><?= $vEt ? fmtBRL($vEt) : '—' ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="text-xs text-slate-400">Agenciamento: o funil mostra o volume negociado; a receita efetiva projetada usa a comissão DNB estimada. Regra: 1 oportunidade por Linha de Produto.</div>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Nova oportunidade / orçamento</span>
  </div>
  <div class="p-4">
    <form method="post">
      <input type="hidden" name="acao" value="add">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Nº (automático)</label><input name="numero" class="<?= INPUT_CLS ?>" placeholder="gerado ao salvar"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Data</label><input type="date" name="data" value="<?= e(hoje()) ?>" class="<?= INPUT_CLS ?>"></div>
        <div class="col-span-2"><label class="block text-xs font-semibold text-slate-600 mb-1">Cliente</label><input name="cliente" required class="<?= INPUT_CLS ?>"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Vendedor</label>
          <select name="vendedor_id" class="<?= INPUT_CLS ?>"><option value="">—</option><?php foreach ($vendedores as $v): ?><option value="<?= e($v['id']) ?>"><?= e($v['nome']) ?></option><?php endforeach; ?></select>
        </div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Suporte (opcional)</label>
          <select name="suporte_id" class="<?= INPUT_CLS ?>"><option value="">—</option><?php foreach ($vendedores as $v): if ($v['funcao'] !== 'suporte') continue; ?><option value="<?= e($v['id']) ?>"><?= e($v['nome']) ?></option><?php endforeach; ?></select>
        </div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Linha de Produto</label>
          <select name="linha" required class="<?= INPUT_CLS ?>"><option value="">— selecione —</option><?php foreach ($linhas as $l): ?><option><?= e($l) ?></option><?php endforeach; ?></select>
        </div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Comissão DNB estimada (se agenciamento)</label><input type="number" step="0.01" name="comissao_dnb_estimada" class="<?= INPUT_CLS ?> bg-blue-50"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Cargo da proposta</label><select name="cargo_id" class="<?= INPUT_CLS ?>"><option value="">Automático pelo responsável</option><?php foreach($cargosRegistro as $cr): ?><option value="<?= (int)$cr['cargo_id'] ?>"><?= e($cr['nome']) ?> · <?= e($cr['title']) ?><?= $cr['has_commission']?'':' · sem comissão' ?></option><?php endforeach; ?></select></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Valor (R$)</label><input type="number" step="0.01" name="valor" required class="<?= INPUT_CLS ?>"></div>
      </div>
      <div class="mt-3 flex gap-2 items-center">
        <button class="<?= BTN_P ?>" style="background:<?= NAVY ?>;">Registrar oportunidade</button>
        <?php if ($msg): ?><span class="text-xs text-slate-500"><?= e($msg) ?></span><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if (temPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR)): ?>
<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Importar do Cotador DNB (JSON)</span>
  </div>
  <div class="p-4">
    <div class="text-xs text-slate-500 mb-2">Cole o JSON exportado pelo Cotador (peças/serviços/locação). Formato: {"numero","data","cliente","vendedor","linha","tipo","valor"} — aceita lista.</div>
    <form method="post">
      <input type="hidden" name="acao" value="importar_json">
      <textarea name="json_imp" class="<?= INPUT_CLS ?> h-20 font-mono text-xs" placeholder='[{"numero":"54435","cliente":"Cliente","vendedor":"Nome","linha":"Ingersoll Rand","tipo":"Manutenção","valor":12500,"data":"2026-07-20"}]'></textarea>
      <button class="<?= BTN_P ?> mt-2" style="background:<?= NAVY2 ?>;">Importar propostas</button>
    </form>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Sincronizar relatório semanal Salesforce (CSV)</span>
  </div>
  <div class="p-4">
    <div class="text-xs text-slate-500 mb-2">Exporte o relatório de oportunidades do Salesforce como <b>CSV</b> e anexe aqui. Já sincronizadas são atualizadas, nunca duplicadas. Colunas reconhecidas: Opportunity Name/ID, Account/Conta, Amount/Valor, Stage/Fase, Owner/Proprietário, Comissão.</div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="acao" value="importar_sf_csv">
      <input type="file" name="arquivo" accept=".csv" required class="text-sm">
      <button class="<?= BTN_P ?> mt-2 ml-2" style="background:<?= NAVY2 ?>;">Sincronizar</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($convertendo): ?>
<div class="bg-white rounded-xl border-2 border-slate-300 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Converter em pedido de venda · <?= e($convertendo['cliente']) ?></span>
  </div>
  <div class="p-4">
    <form method="post">
      <input type="hidden" name="acao" value="confirmar_pedido">
      <input type="hidden" name="orcamento_id" value="<?= $convertendo['id'] ?>">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Nº do pedido</label><input name="ped_numero" value="PV-<?= e($convertendo['numero'] ?: $convertendo['id']) ?>" class="<?= INPUT_CLS ?>"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Data do pedido</label><input type="date" name="ped_data" value="<?= e(hoje()) ?>" class="<?= INPUT_CLS ?>"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Valor fechado (R$)</label><input type="number" step="0.01" name="ped_valor" value="<?= e((string) $convertendo['valor']) ?>" class="<?= INPUT_CLS ?>"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Referência</label><div class="text-xs text-slate-500 pt-2">Orçamento <?= e($convertendo['numero'] ?: 's/n') ?> · <?= e($convertendo['linha']) ?> · orçado <?= fmtBRL($convertendo['valor']) ?></div></div>
      </div>
      <div class="flex gap-2 mt-3">
        <button class="<?= BTN_P ?>" style="background:<?= NAVY ?>;">Confirmar pedido</button>
        <a href="/orcamentos.php<?= !empty($_GET['mes']) ? '?mes=' . urlencode($_GET['mes']) : '' ?>" class="<?= BTN_S ?>">Cancelar</a>
      </div>
      <div class="text-xs text-slate-400 mt-2">O orçamento será marcado como Ganho e o pedido entra como não faturado — confirme o faturamento na aba Pedidos.</div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Orçamentos (<?= count($orcamentos) ?>)</span>
  </div>
  <div class="p-4">
    <div class="overflow-x-auto">
      <table class="w-full text-sm min-w-[760px]">
        <thead><tr class="text-left text-xs text-slate-500 border-b border-slate-200">
          <th class="py-2 pr-2">Data</th><th class="pr-2">Nº</th><th class="pr-2">Cliente</th><th class="pr-2">Vendedor</th>
          <th class="pr-2">Linha</th><th class="pr-2">Etapa</th><th class="pr-2">Valor</th><th class="pr-2">Status</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($orcamentos as $o): $fk = funilDe($o['linha']); ?>
          <tr class="border-b border-slate-100">
            <td class="py-2 pr-2 text-slate-500"><?= e($o['data']) ?></td>
            <td class="pr-2 font-mono text-xs"><?= e($o['numero']) ?></td>
            <td class="pr-2 font-semibold" style="color:<?= NAVY ?>;"><a href="/lead.php?id=<?= (int) $o['id'] ?>"><?= e($o['cliente']) ?></a><?php if ($o['lead_score'] !== null): ?><div class="text-xs font-normal text-slate-400">Score <?= (int) $o['lead_score'] ?>/100</div><?php endif; ?></td>
            <td class="pr-2"><?= e(nomeVendedor($vendedores, $o['vendedor_id'])) ?><div class="text-xs text-slate-400"><?= e($o['registro_cargo_title'] ?: 'sem cargo') ?></div></td>
            <td class="pr-2 text-xs"><?= e($o['linha']) ?><div class="text-xs text-slate-400"><?= e($funisArr[$fk]['label']) ?></div></td>
            <td class="pr-2">
              <?php if ($o['status'] === 'Aberta'): ?>
                <form method="post" onchange="this.submit()"><input type="hidden" name="acao" value="set_etapa"><input type="hidden" name="id" value="<?= $o['id'] ?>">
                  <select name="etapa" class="text-xs border border-slate-300 rounded-md px-1 py-1 bg-white max-w-[170px]">
                    <?php foreach ($funisArr[$fk]['etapas'] as $et): ?><option <?= ($o['etapa'] ?: $funisArr[$fk]['etapas'][0]) === $et ? 'selected' : '' ?>><?= e($et) ?></option><?php endforeach; ?>
                  </select>
                </form>
              <?php else: ?><span class="text-xs text-slate-400">—</span><?php endif; ?>
            </td>
            <td class="pr-2 font-bold">
              <?php if ($o['status'] === 'Aberta'): ?>
                <form method="post" onsubmit="return true" class="inline-flex items-center gap-1">
                  <input type="hidden" name="acao" value="set_valor"><input type="hidden" name="id" value="<?= $o['id'] ?>">
                  <input type="number" step="0.01" name="valor" value="<?= e((string) $o['valor']) ?>" class="w-24 px-2 py-1 border rounded-md text-sm font-bold <?= $o['valor'] > 0 ? 'border-slate-200' : 'border-red-400 bg-red-50' ?>">
                  <button class="<?= BTN_S ?>">Salvar</button>
                </form>
              <?php else: ?><?= fmtBRL($o['valor']) ?><?php endif; ?>
              <?php if (ehAgenciamento($o['linha']) && $o['comissao_dnb_estimada'] > 0): ?><div class="text-xs font-normal text-slate-400">com. DNB est. <?= fmtBRL($o['comissao_dnb_estimada']) ?></div><?php endif; ?>
            </td>
            <td class="pr-2">
              <?php if ($o['status'] === 'Aberta'): ?><?= tag('Aberta', 'blue') ?><?php elseif ($o['status'] === 'Ganha'): ?><?= tag('Ganha', 'green') ?><?php else: ?><?= tag('Perdida', 'red') ?><?php endif; ?>
            </td>
            <td class="text-right whitespace-nowrap">
              <?php if ($o['status'] === 'Aberta'): ?>
                <a href="/orcamentos.php?converter=<?= $o['id'] ?><?= !empty($_GET['mes']) ? '&mes=' . urlencode($_GET['mes']) : '' ?>" class="<?= BTN_S ?> mr-1">→ Pedido</a>
                <form method="post" class="inline"><input type="hidden" name="acao" value="set_status"><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="status" value="Perdida"><button class="<?= BTN_S ?> mr-1">Perdida</button></form>
              <?php endif; ?>
              <?php if (temPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR)): ?><form method="post" class="inline" onsubmit="return confirm('Excluir esta oportunidade?');"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="<?= BTN_S ?> dnb-button--danger" aria-label="Excluir oportunidade" title="Excluir oportunidade"><?= dnbIcon('trash', 'dnb-danger-icon') ?></button></form><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($orcamentos)): ?><tr><td colspan="9" class="py-6 text-center text-slate-400 text-sm">Nenhuma oportunidade — registre acima ou importe.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
