<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/calc.php';
require_once __DIR__ . '/includes/crm_core_service.php';
exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_VENDEDOR);

$pdo = db();
$mes = $_GET['mes'] ?? mesAtual();
$vendedores = filtrarVendedoresDaEquipe($pdo, obterVendedores($pdo));
$linhas = array_column($pdo->query('SELECT nome FROM linhas ORDER BY ordem ASC')->fetchAll(), 'nome');
$idsPessoasVisiveis = array_column($vendedores, 'id');
$cargosRegistro = [];
if ($idsPessoasVisiveis) {
    $marks = implode(',', array_fill(0, count($idsPessoasVisiveis), '?'));
    $stmt = $pdo->prepare("SELECT u.nome,c.id cargo_id,c.title,c.has_commission FROM usuarios u JOIN user_cargos uc ON uc.user_id=u.id JOIN cargos c ON c.id=uc.cargo_id
        WHERE u.status='active' AND COALESCE(u.pessoa_id,u.vendedor_id) IN ({$marks}) AND (c.has_commission=1 OR c.title='ADMINISTRADOR')
          AND (c.title='ADMINISTRADOR' OR NOT EXISTS (SELECT 1 FROM user_cargos uca JOIN cargos ca ON ca.id=uca.cargo_id WHERE uca.user_id=u.id AND ca.title='ADMINISTRADOR'))
        ORDER BY u.nome,c.id");
    $stmt->execute($idsPessoasVisiveis); $cargosRegistro = $stmt->fetchAll();
}

function pedRedirect(string $msg = ''): void
{
    if ($msg !== '') $_SESSION['flash'] = $msg;
    $qs = !empty($_GET['mes']) ? ('?mes=' . urlencode($_GET['mes'])) : '';
    header('Location: /pedidos.php' . $qs);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'add') {
        $cliente = trim($_POST['cliente'] ?? '');
        $valor = (float) ($_POST['valor'] ?? 0);
        if ($cliente === '' || $valor <= 0) pedRedirect('Informe cliente e valor.');
        $vendedorId = $_POST['vendedor_id'] ?: null;
        if ($vendedorId && !in_array($vendedorId, array_column($vendedores, 'id'), true)) { http_response_code(403); exit; }
        $assignedTo = usuarioIdPorVendedor($pdo, $vendedorId) ?: (temPapel(PAPEL_VENDEDOR, PAPEL_SUPERVISOR) ? (int) usuarioLogado()['id'] : null);
        $cargoId = cargoParaRegistroComercial($pdo, $assignedTo, !empty($_POST['cargo_id']) ? (int) $_POST['cargo_id'] : null);
        $modalidade = $_POST['modalidade'] === 'agenciada' ? 'agenciada' : 'revenda';
        $dataFat = $_POST['data_fat'] ?: null;
        $stmt = $pdo->prepare('INSERT INTO pedidos (numero,data,cliente,vendedor_id,suporte_id,linha,valor,margem,modalidade,comissao_dnb,faturado,data_fat,assigned_to_user_id,cargo_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            trim($_POST['numero'] ?? ''), $_POST['data'] ?: hoje(), $cliente,
            $vendedorId, $_POST['suporte_id'] ?: null, $_POST['linha'] ?? '',
            $valor, $_POST['margem'] !== '' ? (float) $_POST['margem'] : null, $modalidade,
            $modalidade === 'agenciada' ? (float) ($_POST['comissao_dnb'] ?? 0) : 0,
            $dataFat ? 1 : 0, $dataFat, $assignedTo, $cargoId,
        ]);
        $pedidoId=(int)$pdo->lastInsertId();
        if(crmCoreFase1Disponivel($pdo)) garantirPedidoCore($pdo,$pedidoId,null);
        pedRedirect('Pedido registrado.');
    }

    if ($acao === 'faturar') {
        if (!podeAcessarRegistroAtribuido($pdo, 'pedidos', (int) $_POST['id'])) { http_response_code(403); exit; }
        $pdo->prepare('UPDATE pedidos SET faturado=1, data_fat=COALESCE(data_fat, ?) WHERE id=?')->execute([hoje(), (int) $_POST['id']]);
        pedRedirect();
    }

    if ($acao === 'excluir') {
        if (!temPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR)) { http_response_code(403); exit; }
        if (!podeAcessarRegistroAtribuido($pdo, 'pedidos', (int) $_POST['id'])) { http_response_code(403); exit; }
        $pdo->prepare('DELETE FROM pedidos WHERE id=?')->execute([(int) $_POST['id']]);
        pedRedirect();
    }
}

$msg = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
[$filtro, $params] = filtroEscopoAtribuicao($pdo, 'p.assigned_to_user_id');
$stmt = $pdo->prepare("SELECT p.*,c.title cargo_title FROM pedidos p LEFT JOIN cargos c ON c.id=p.cargo_id WHERE {$filtro} ORDER BY p.data DESC,p.id DESC");
$stmt->execute($params); $pedidos = $stmt->fetchAll();

$tabAtual = 'ped';
include __DIR__ . '/includes/layout_header.php';
?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Novo pedido de venda</span></div>
  <div class="p-4">
    <form method="post" id="form-pedido">
      <input type="hidden" name="acao" value="add">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Nº</label><input name="numero" class="<?= INPUT_CLS ?>"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Data</label><input type="date" name="data" value="<?= e(hoje()) ?>" class="<?= INPUT_CLS ?>"></div>
        <div class="col-span-2"><label class="block text-xs font-semibold text-slate-600 mb-1">Cliente</label><input name="cliente" required class="<?= INPUT_CLS ?>"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Vendedor</label>
          <select name="vendedor_id" class="<?= INPUT_CLS ?>"><option value="">—</option><?php foreach ($vendedores as $v): ?><option value="<?= e($v['id']) ?>"><?= e($v['nome']) ?></option><?php endforeach; ?></select>
        </div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Suporte (opcional)</label>
          <select name="suporte_id" class="<?= INPUT_CLS ?>"><option value="">—</option><?php foreach ($vendedores as $v): if ($v['funcao'] !== 'suporte') continue; ?><option value="<?= e($v['id']) ?>"><?= e($v['nome']) ?></option><?php endforeach; ?></select>
        </div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Linha de produto</label>
          <select name="linha" class="<?= INPUT_CLS ?>"><?php foreach ($linhas as $l): ?><option><?= e($l) ?></option><?php endforeach; ?></select>
        </div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Modalidade</label>
          <select name="modalidade" id="ped-modalidade" class="<?= INPUT_CLS ?>" onchange="document.getElementById('ped-comdnb').classList.toggle('hidden', this.value!=='agenciada'); document.getElementById('ped-margem').classList.toggle('hidden', this.value==='agenciada');">
            <option value="revenda">Revenda (faturada DNB)</option>
            <option value="agenciada">Agenciada (intermediação)</option>
          </select>
        </div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Valor (R$)</label><input type="number" step="0.01" name="valor" required class="<?= INPUT_CLS ?>"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Cargo da venda</label><select name="cargo_id" class="<?= INPUT_CLS ?>"><option value="">Automático pelo responsável</option><?php foreach($cargosRegistro as $cr): ?><option value="<?= (int)$cr['cargo_id'] ?>"><?= e($cr['nome']) ?> · <?= e($cr['title']) ?><?= $cr['has_commission']?'':' · sem comissão' ?></option><?php endforeach; ?></select></div>
        <div id="ped-comdnb" class="hidden"><label class="block text-xs font-semibold text-slate-600 mb-1">Comissão recebida pela DNB (R$)</label><input type="number" step="0.01" name="comissao_dnb" class="<?= INPUT_CLS ?> bg-blue-50"></div>
        <div id="ped-margem"><label class="block text-xs font-semibold text-slate-600 mb-1">Resultado / margem (R$)</label><input type="number" step="0.01" name="margem" class="<?= INPUT_CLS ?>" placeholder="p/ representante (5% s/ margem)"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Data de faturamento (se já faturado)</label><input type="date" name="data_fat" class="<?= INPUT_CLS ?>"></div>
      </div>
      <div class="mt-3 flex gap-2 items-center">
        <button class="<?= BTN_P ?>" style="background:<?= NAVY ?>;">Registrar pedido</button>
        <?php if ($msg): ?><span class="text-xs text-slate-500"><?= e($msg) ?></span><?php endif; ?>
      </div>
      <div class="text-xs text-slate-400 mt-2">Agenciadas: a comissão do time incide sobre a comissão recebida pela DNB — o atingimento de meta usa o valor intermediado.</div>
    </form>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Pedidos de venda (<?= count($pedidos) ?>)</span></div>
  <div class="p-4">
    <div class="overflow-x-auto">
      <table class="w-full text-sm min-w-[880px]">
        <thead><tr class="text-left text-xs text-slate-500 border-b border-slate-200">
          <th class="py-2 pr-2">Data</th><th class="pr-2">Nº</th><th class="pr-2">Cliente</th><th class="pr-2">Vendedor</th>
          <th class="pr-2">Suporte</th><th class="pr-2">Linha</th><th class="pr-2">Modalidade</th><th class="pr-2">Valor</th><th class="pr-2">Faturamento</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($pedidos as $p): ?>
          <tr class="border-b border-slate-100">
            <td class="py-2 pr-2 text-slate-500"><?= e($p['data']) ?></td>
            <td class="pr-2 font-mono text-xs"><?= e($p['numero']) ?></td>
            <td class="pr-2 font-semibold" style="color:<?= NAVY ?>;"><?= e($p['cliente']) ?></td>
            <td class="pr-2"><?= e(nomeVendedor($vendedores, $p['vendedor_id'])) ?><div class="text-xs text-slate-400"><?= e($p['cargo_title'] ?: 'sem cargo') ?></div></td>
            <td class="pr-2 text-xs"><?= $p['suporte_id'] ? e(nomeVendedor($vendedores, $p['suporte_id'])) : '—' ?></td>
            <td class="pr-2 text-xs"><?= e($p['linha']) ?></td>
            <td class="pr-2 text-xs">
              <?= $p['modalidade'] === 'agenciada' ? tag('Agenciada', 'blue') : tag('Revenda') ?>
              <?php if ($p['modalidade'] === 'agenciada'): ?><div class="text-xs text-slate-400">com. DNB <?= fmtBRL($p['comissao_dnb']) ?></div><?php elseif ((float) $p['margem'] > 0): ?><div class="text-xs text-slate-400">margem <?= fmtBRL($p['margem']) ?></div><?php endif; ?>
            </td>
            <td class="pr-2 font-bold"><?= fmtBRL($p['valor']) ?></td>
            <td class="pr-2">
              <?php if ($p['faturado']): ?><?= tag('Faturado ' . $p['data_fat'], 'green') ?>
              <?php else: ?><form method="post" class="inline"><input type="hidden" name="acao" value="faturar"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="<?= BTN_S ?>">Marcar faturado</button></form><?php endif; ?>
            </td>
            <td class="text-right"><?php if (temPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR)): ?><form method="post" class="inline" onsubmit="return confirm('Excluir este pedido?');"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="<?= BTN_S ?> dnb-button--danger" aria-label="Excluir pedido" title="Excluir pedido"><?= dnbIcon('trash', 'dnb-danger-icon') ?></button></form><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($pedidos)): ?><tr><td colspan="10" class="py-6 text-center text-slate-400 text-sm">Nenhum pedido registrado.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
