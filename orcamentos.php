<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/lead_service.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/calc.php';
require_once __DIR__ . '/includes/crm_core_service.php';
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

function orcRedirect(string $msg = '', ?string $funil = null): void
{
    if ($msg !== '') $_SESSION['flash'] = $msg;
    $params = [];
    if (!empty($_GET['mes'])) $params['mes'] = $_GET['mes'];
    if ($funil !== null && $funil !== '') $params['funil'] = $funil;
    $qs = $params ? ('?' . http_build_query($params)) : '';
    header('Location: /orcamentos.php' . $qs);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'add') {
        $cliente = trim($_POST['cliente'] ?? '');
        $valor = (float) ($_POST['valor'] ?? 0);
        $linha = trim($_POST['linha'] ?? '');
        if ($cliente === '' || $valor <= 0) orcRedirect('Informe ao menos cliente e valor.');
        if ($linha === '') orcRedirect('Linha de Produto é obrigatória — regra: uma oportunidade por linha.');
        $vendedorId = $_POST['vendedor_id'] ?: null;
        if ($vendedorId && !in_array($vendedorId, array_column($vendedores, 'id'), true)) { http_response_code(403); exit; }
        try {
            $assignedTo = usuarioIdPorVendedor($pdo, $vendedorId) ?: (temPapel(PAPEL_VENDEDOR, PAPEL_SUPERVISOR) ? (int) usuarioLogado()['id'] : null);
            $cargoId = cargoParaRegistroComercial($pdo, $assignedTo, !empty($_POST['cargo_id']) ? (int) $_POST['cargo_id'] : null);
            $suporteId = $_POST['suporte_id'] ?: null;
            $data = $_POST['data'] ?: hoje();
            $funil = funilDe($linha);
            $numero = trim($_POST['numero'] ?? '') ?: gerarNumeroProposta($pdo, $vendedores, $vendedorId, $suporteId, $data);
            $stmt = $pdo->prepare('INSERT INTO orcamentos (numero,data,cliente,vendedor_id,suporte_id,linha,tipo,funil,etapa,valor,comissao_dnb_estimada,status,origem,assigned_to_user_id,cargo_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$numero, $data, $cliente, $vendedorId, $suporteId, $linha, tipoDe($linha), $funil, $funisArr[$funil]['etapas'][0], $valor, (float) ($_POST['comissao_dnb_estimada'] ?? 0), 'Aberta', 'manual', $assignedTo, $cargoId]);
            $orcamentoId=(int)$pdo->lastInsertId();
            if(crmCoreFase1Disponivel($pdo)) garantirNegociacaoParaOrcamento($pdo,$orcamentoId);
            registrarTimeline($pdo, $orcamentoId, 'created', 'Oportunidade criada manualmente.');
        } catch (Throwable $e) {
            error_log('orcamentos.add: ' . $e->getMessage());
            orcRedirect('Não foi possível registrar a oportunidade agora. Tente novamente ou avise o suporte técnico.');
        }
        orcRedirect('Oportunidade ' . $numero . ' registrada no funil ' . $funisArr[$funil]['label'] . '.', $funil);
    }

    if ($acao === 'set_etapa') {
        $leadId = (int)($_POST['id']??0);
        if (!podeAcessarLead($pdo, $leadId)) { http_response_code(403); exit; }
        $stmt = $pdo->prepare("SELECT linha,status FROM orcamentos WHERE id=? LIMIT 1");
        $stmt->execute([$leadId]); $leadEtapa = $stmt->fetch();
        $funilEtapa = $leadEtapa ? funilDe((string)$leadEtapa['linha']) : '';
        $novaEtapa = trim((string)($_POST['etapa']??''));
        if (!$leadEtapa || $leadEtapa['status'] !== 'Aberta' || !in_array($novaEtapa,$funisArr[$funilEtapa]['etapas']??[],true)) { http_response_code(422); exit; }
        $stmt = $pdo->prepare('UPDATE orcamentos SET etapa=? WHERE id=?');
        $stmt->execute([$novaEtapa, $leadId]);
        registrarTimeline($pdo, $leadId, 'funnel_update', 'Etapa atualizada para ' . $novaEtapa . '.');
        if(crmCoreFase1Disponivel($pdo)) sincronizarNegociacaoDoOrcamento($pdo,$leadId);
        orcRedirect();
    }

    if ($acao === 'set_valor') {
        if (!podeAcessarLead($pdo, (int) $_POST['id'])) { http_response_code(403); exit; }
        $stmt = $pdo->prepare('UPDATE orcamentos SET valor=? WHERE id=?');
        $leadId=(int)$_POST['id'];
        $stmt->execute([max(0,(float)($_POST['valor']??0)), $leadId]);
        if(crmCoreFase1Disponivel($pdo)) sincronizarNegociacaoDoOrcamento($pdo,$leadId);
        orcRedirect();
    }

    if ($acao === 'set_status') {
        if (!podeAcessarLead($pdo, (int) $_POST['id'])) { http_response_code(403); exit; }
        if (!in_array($_POST['status'] ?? '', ['Aberta','Ganha','Perdida'], true)) { http_response_code(422); exit; }
        $stmt = $pdo->prepare('UPDATE orcamentos SET status=? WHERE id=?');
        $stmt->execute([$_POST['status'], (int) $_POST['id']]);
        $leadId=(int)$_POST['id'];
        registrarTimeline($pdo,$leadId,'status_update','Status atualizado para '.(string)$_POST['status'].'.');
        if(crmCoreFase1Disponivel($pdo)) sincronizarNegociacaoDoOrcamento($pdo,$leadId);
        orcRedirect();
    }

    if ($acao === 'toggle_cadencia') {
        $leadId=(int)($_POST['id']??0);
        if(!podeAcessarLead($pdo,$leadId)){http_response_code(403);exit;}
        $pausar=!empty($_POST['pausar'])?1:0;
        $pdo->prepare("UPDATE orcamentos SET cad_pausada=? WHERE id=? AND status='Aberta'")->execute([$pausar,$leadId]);
        registrarTimeline($pdo,$leadId,$pausar?'followup_paused':'followup_resumed',$pausar?'Cadência pausada manualmente.':'Cadência retomada manualmente.');
        orcRedirect($pausar?'Cadência pausada.':'Cadência retomada.');
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
                $orcamentoId=(int)$pdo->lastInsertId();
                if(crmCoreFase1Disponivel($pdo)) garantirNegociacaoParaOrcamento($pdo,$orcamentoId);
                $n++;
            }
            orcRedirect("$n proposta(s) importada(s) do Cotador.");
        } catch (Throwable $e) {
            orcRedirect('JSON inválido — verifique o conteúdo exportado do Cotador.');
        }
    }

    if ($acao === 'importar_rdstation_csv' && !empty($_FILES['arquivo']['tmp_name'])) {
        if (!temPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR)) { http_response_code(403); exit; }
        $tipoRelatorio = in_array($_POST['tipo_relatorio'] ?? '', ['semanal', 'mensal'], true) ? $_POST['tipo_relatorio'] : 'semanal';
        $novos = 0; $atualizados = 0;
        if (($h = fopen($_FILES['arquivo']['tmp_name'], 'r')) !== false) {
            $header = fgetcsv($h, 0, ',');
            if ($header) {
                $header = array_map(fn($c) => mb_strtolower(trim($c)), $header);
                $find = function (array $cands) use ($header) {
                    foreach ($header as $i => $c) foreach ($cands as $x) if (str_contains($c, $x)) return $i;
                    return null;
                };
                $iNome = $find(['nome do negócio', 'negócio', 'negocio', 'deal name', 'opportunity name', 'nome da oportunidade']);
                $iConta = $find(['empresa', 'organização', 'organizacao', 'contato', 'account', 'cliente']);
                $iId = $find(['id do negócio', 'id do negocio', 'deal id', 'opportunity id', 'id']);
                $iValor = $find(['valor', 'amount']);
                $iCom = $find(['comiss']);
                $iFase = $find(['etapa do funil', 'etapa', 'fase', 'stage', 'situação', 'situacao']);
                $iDono = $find(['responsável', 'responsavel', 'vendedor', 'owner']);
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
                        if(crmCoreFase1Disponivel($pdo)) sincronizarNegociacaoDoOrcamento($pdo,(int)$exId);
                        $atualizados++;
                    } else {
                        $pdo->prepare('INSERT INTO orcamentos (sf_id,numero,data,cliente,vendedor_id,linha,tipo,funil,etapa,valor,comissao_dnb_estimada,status,origem,assigned_to_user_id,cargo_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                            ->execute([$sfId, $nomeOp ?: $sfId, hoje(), $cliente, $vendId, 'Compressores IR (agenciamento)', 'Equipamento', 'equipamentos', $etapa, $valor, $comEst, $status, 'rdstation-' . $tipoRelatorio, $assignedTo, $cargoId]);
                        $orcamentoId=(int)$pdo->lastInsertId();
                        if(crmCoreFase1Disponivel($pdo)) garantirNegociacaoParaOrcamento($pdo,$orcamentoId);
                        $novos++;
                    }
                }
            }
            fclose($h);
        }
        orcRedirect("RD Station sincronizado (relatório $tipoRelatorio): $novos oportunidade(s) nova(s), $atualizados atualizada(s). Revise vendedor e comissão estimada das novas.");
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
            $orcamentoId=(int)$pdo->lastInsertId();
            if(crmCoreFase1Disponivel($pdo)){
                $negId=garantirNegociacaoParaOrcamento($pdo,$orcamentoId);
                garantirVisitaCore($pdo,$vid);
                if($negId>0)$pdo->prepare('UPDATE visitas SET negotiation_id=? WHERE id=?')->execute([$negId,$vid]);
            }
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
            $pedidoId=(int)$pdo->lastInsertId();
            if(crmCoreFase1Disponivel($pdo)) garantirPedidoCore($pdo,$pedidoId,$oid);
            orcRedirect("Pedido {$_POST['ped_numero']} gerado — confirme o faturamento na aba Pedidos.");
        }
        orcRedirect();
    }
}

$msg = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$orcamentosTodos = listarLeadsAcessiveis($pdo);
$filtrosKanban = [
    'q'=>trim((string)($_GET['q']??'')),
    'funil'=>(string)($_GET['funil']??array_key_first($funisArr)),
    'responsavel'=>(int)($_GET['responsavel']??0),
    'linha'=>(string)($_GET['linha']??''),
    'origem'=>(string)($_GET['origem']??''),
    'automacao'=>(string)($_GET['automacao']??''),
];
if (!isset($funisArr[$filtrosKanban['funil']])) $filtrosKanban['funil']=(string)array_key_first($funisArr);
$orcamentos = array_values(array_filter($orcamentosTodos,static function(array $o) use ($filtrosKanban): bool {
    if ($filtrosKanban['q']!=='' && !str_contains(mb_strtolower(implode(' ',[(string)$o['cliente'],(string)$o['numero'],(string)$o['linha']])),mb_strtolower($filtrosKanban['q']))) return false;
    if (funilDe((string)$o['linha'])!==$filtrosKanban['funil']) return false;
    if ($filtrosKanban['responsavel']>0 && (int)($o['assigned_to_user_id']??0)!==$filtrosKanban['responsavel']) return false;
    if ($filtrosKanban['linha']!=='' && (string)$o['linha']!==$filtrosKanban['linha']) return false;
    if ($filtrosKanban['origem']!=='' && (string)$o['origem']!==$filtrosKanban['origem']) return false;
    if ($filtrosKanban['automacao']==='active' && (!empty($o['cad_pausada']) || $o['status']!=='Aberta')) return false;
    if ($filtrosKanban['automacao']==='paused' && (empty($o['cad_pausada']) || $o['status']!=='Aberta')) return false;
    return true;
}));
$responsaveisFiltro=[]; $origensFiltro=[];
foreach($orcamentosTodos as $itemFiltro) {
    if (!empty($itemFiltro['assigned_to_user_id'])) $responsaveisFiltro[(int)$itemFiltro['assigned_to_user_id']]=(string)($itemFiltro['assigned_to_name']??'Responsável');
    if (!empty($itemFiltro['origem'])) $origensFiltro[(string)$itemFiltro['origem']]=true;
}
asort($responsaveisFiltro); ksort($origensFiltro);
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

function renderCartaoOportunidade(array $o, array $funisArr, array $vendedores, bool $kanban = false): void
{
    $fk=funilDe((string)$o['linha']);
    $etapa=(string)($o['etapa']?:$funisArr[$fk]['etapas'][0]);
    $aberta=$o['status']==='Aberta';
    $responsavel=(string)($o['assigned_to_name']?:nomeVendedor($vendedores,$o['vendedor_id']));
    $idade=max(0,(int)floor((time()-strtotime((string)$o['data']))/86400));
    ?>
    <article class="dnb-opportunity-card<?= $kanban?' dnb-opportunity-card--kanban':'' ?>" <?= $kanban&&$aberta?'draggable="true" data-kanban-card data-lead-id="'.(int)$o['id'].'"':'' ?>>
      <div class="dnb-opportunity-card__head"><div><a href="/lead.php?id=<?= (int)$o['id'] ?>"><?= e($o['cliente']) ?></a><small><?= e($o['numero']?:'s/n') ?> · <?= e($o['data']) ?></small></div><?php if($o['lead_score']!==null): ?><?= tag('Score '.(int)$o['lead_score'],'blue') ?><?php endif; ?></div>
      <dl class="dnb-opportunity-card__meta"><div><dt>Responsável</dt><dd><?= e($responsavel?:'Não atribuído') ?></dd></div><div><dt>Linha</dt><dd><?= e($o['linha']) ?></dd></div><div><dt>Valor</dt><dd><?= fmtBRL($o['valor']) ?></dd></div><div><dt>Idade</dt><dd><?= $idade ?> dia(s)</dd></div><div><dt>Origem</dt><dd><?= e($o['origem']?:'manual') ?></dd></div><div><dt>Automação</dt><dd><?= $aberta?tag(empty($o['cad_pausada'])?'Ativa':'Pausada',empty($o['cad_pausada'])?'green':'amber'):'—' ?></dd></div></dl>
      <?php if(ehAgenciamento($o['linha'])&&(float)$o['comissao_dnb_estimada']>0): ?><p class="dnb-opportunity-card__commission">Comissão DNB estimada: <?= fmtBRL($o['comissao_dnb_estimada']) ?></p><?php endif; ?>
      <?php if($aberta): ?>
        <form method="post" class="dnb-opportunity-card__stage" data-kanban-stage-form><?= csrfInput() ?><input type="hidden" name="acao" value="set_etapa"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><label><span>Etapa</span><select name="etapa" data-kanban-stage-select><?php foreach($funisArr[$fk]['etapas'] as $opcao): ?><option value="<?= e($opcao) ?>" <?= $etapa===$opcao?'selected':'' ?>><?= e($opcao) ?></option><?php endforeach; ?></select></label><button class="<?= BTN_S ?>">Mover</button></form>
        <form method="post" class="dnb-opportunity-card__value"><?= csrfInput() ?><input type="hidden" name="acao" value="set_valor"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><label><span>Editar valor</span><input type="number" min="0" step="0.01" name="valor" value="<?= e((string)$o['valor']) ?>"></label><button class="<?= BTN_S ?>">Salvar</button></form>
      <?php endif; ?>
      <div class="dnb-opportunity-card__actions"><?php if($aberta): ?><a href="/orcamentos.php?converter=<?= (int)$o['id'] ?>" class="<?= BTN_S ?>">→ Pedido</a><form method="post"><?= csrfInput() ?><input type="hidden" name="acao" value="toggle_cadencia"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="pausar" value="<?= empty($o['cad_pausada'])?1:0 ?>"><button class="<?= BTN_S ?>"><?= empty($o['cad_pausada'])?'Pausar follow-up':'Retomar follow-up' ?></button></form><form method="post" onsubmit="return confirm('Marcar esta oportunidade como perdida?');"><?= csrfInput() ?><input type="hidden" name="acao" value="set_status"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="status" value="Perdida"><button class="<?= BTN_S ?>">Perdida</button></form><?php else: ?><?= tag((string)$o['status'],$o['status']==='Ganha'?'green':'red') ?><?php endif; ?><?php if(temPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR)): ?><form method="post" onsubmit="return confirm('Excluir esta oportunidade?');"><?= csrfInput() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="<?= BTN_S ?> dnb-button--danger" aria-label="Excluir oportunidade" title="Excluir oportunidade"><?= dnbIcon('trash','dnb-danger-icon') ?></button></form><?php endif; ?></div>
    </article>
    <?php
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
          <form method="post"><?= csrfInput() ?><input type="hidden" name="acao" value="promover_lead"><input type="hidden" name="visita_id" value="<?= $v['id'] ?>">
            <button class="<?= BTN_P ?> text-xs px-3 py-1.5" style="background:<?= NAVY ?>;">Promover a oportunidade</button></form>
          <form method="post"><?= csrfInput() ?><input type="hidden" name="acao" value="descartar_lead"><input type="hidden" name="visita_id" value="<?= $v['id'] ?>">
            <button class="<?= BTN_S ?>">Descartar</button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="px-4 pt-4"><span class="dnb-eyebrow">Visualização operacional</span><h2 class="dnb-section-title">Filtros do Kanban</h2></div>
  <form method="get" class="dnb-kanban-filters p-4">
    <input type="hidden" name="mes" value="<?= e($mes) ?>">
    <label><span>Busca</span><input type="search" name="q" value="<?= e($filtrosKanban['q']) ?>" placeholder="Cliente, número ou linha" class="<?= INPUT_CLS ?>"></label>
    <label><span>Funil</span><select name="funil" class="<?= INPUT_CLS ?>"><?php foreach($funisArr as $chave=>$funil): ?><option value="<?= e($chave) ?>" <?= $filtrosKanban['funil']===$chave?'selected':'' ?>><?= e($funil['label']) ?></option><?php endforeach; ?></select></label>
    <label><span>Responsável</span><select name="responsavel" class="<?= INPUT_CLS ?>"><option value="0">Todos</option><?php foreach($responsaveisFiltro as $idResponsavel=>$nomeResponsavel): ?><option value="<?= (int)$idResponsavel ?>" <?= $filtrosKanban['responsavel']===(int)$idResponsavel?'selected':'' ?>><?= e($nomeResponsavel) ?></option><?php endforeach; ?></select></label>
    <label><span>Linha</span><select name="linha" class="<?= INPUT_CLS ?>"><option value="">Todas</option><?php foreach($linhas as $linhaFiltro): ?><option value="<?= e($linhaFiltro) ?>" <?= $filtrosKanban['linha']===$linhaFiltro?'selected':'' ?>><?= e($linhaFiltro) ?></option><?php endforeach; ?></select></label>
    <label><span>Origem</span><select name="origem" class="<?= INPUT_CLS ?>"><option value="">Todas</option><?php foreach(array_keys($origensFiltro) as $origemFiltro): ?><option value="<?= e($origemFiltro) ?>" <?= $filtrosKanban['origem']===$origemFiltro?'selected':'' ?>><?= e($origemFiltro) ?></option><?php endforeach; ?></select></label>
    <label><span>Automação</span><select name="automacao" class="<?= INPUT_CLS ?>"><option value="">Todas</option><option value="active" <?= $filtrosKanban['automacao']==='active'?'selected':'' ?>>Ativa</option><option value="paused" <?= $filtrosKanban['automacao']==='paused'?'selected':'' ?>>Pausada</option></select></label>
    <div class="dnb-kanban-filters__actions"><button class="<?= BTN_P ?>">Aplicar</button><a href="/orcamentos.php?mes=<?= urlencode($mes) ?>" class="<?= BTN_S ?>">Limpar</a></div>
  </form>
</section>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Funis de venda · oportunidades abertas</span>
  </div>
  <div class="p-4">
    <?php foreach ($funisArr as $k => $fu): if($k!==$filtrosKanban['funil']) continue;
      $abertas = array_values(array_filter($orcamentos, fn($o) => $o['status'] === 'Aberta' && funilDe($o['linha']) === $k));
      $volume = somaValor($abertas);
      $receita = array_sum(array_map(fn($o) => ehAgenciamento($o['linha']) ? (float) $o['comissao_dnb_estimada'] : (float) $o['valor'], $abertas));
    ?>
      <section class="dnb-kanban-funnel">
        <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
          <div><span class="font-bold" style="color:<?= NAVY ?>;"><?= e($fu['label']) ?></span><span class="text-xs text-slate-400 ml-2"><?= e($fu['desc']) ?></span></div>
          <div class="text-xs text-slate-600"><?= count($abertas) ?> oportunidade(s) · volume <b><?= fmtBRL($volume) ?></b> · receita efetiva projetada <b style="color:<?= NAVY ?>;"><?= fmtBRL($receita) ?></b></div>
        </div>
        <div class="dnb-kanban" data-opportunity-kanban data-funnel="<?= e($k) ?>">
          <?php foreach ($fu['etapas'] as $et):
            $nEt = array_values(array_filter($abertas, fn($o) => ($o['etapa'] ?: $fu['etapas'][0]) === $et));
            $vEt = somaValor($nEt);
          ?>
            <section class="dnb-kanban-column" data-kanban-stage="<?= e($et) ?>">
              <header><div><strong><?= e($et) ?></strong><small><?= $vEt?fmtBRL($vEt):'Sem volume' ?></small></div><span><?= count($nEt) ?></span></header>
              <div class="dnb-kanban-column__cards"><?php foreach($nEt as $o) renderCartaoOportunidade($o,$funisArr,$vendedores,true); ?><?php if(!$nEt): ?><p class="dnb-kanban-empty">Arraste uma oportunidade para esta etapa ou use o seletor no cartão.</p><?php endif; ?></div>
            </section>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
    <?php if(!array_filter($orcamentos,fn($o)=>$o['status']==='Aberta')): ?><div class="dnb-empty-state"><p>Nenhuma oportunidade aberta corresponde aos filtros.</p></div><?php endif; ?>
    <div class="text-xs text-slate-400">As colunas se reorganizam automaticamente sem rolagem lateral. No desktop, arraste um cartão entre etapas; o seletor permanece disponível em qualquer dispositivo. Agenciamento usa a comissão DNB estimada como receita projetada.</div>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Nova oportunidade / orçamento</span>
  </div>
  <div class="p-4">
    <form method="post"><?= csrfInput() ?>
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
        <?php if ($msg): $msgOk = str_contains($msg, 'registrada'); ?>
        <span class="text-xs font-medium px-2 py-1 rounded-md border <?= $msgOk ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-amber-50 border-amber-200 text-amber-700' ?>"><?= e($msg) ?></span>
        <?php endif; ?>
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
    <form method="post"><?= csrfInput() ?>
      <input type="hidden" name="acao" value="importar_json">
      <textarea name="json_imp" class="<?= INPUT_CLS ?> h-20 font-mono text-xs" placeholder='[{"numero":"54435","cliente":"Cliente","vendedor":"Nome","linha":"Ingersoll Rand","tipo":"Manutenção","valor":12500,"data":"2026-07-20"}]'></textarea>
      <button class="<?= BTN_P ?> mt-2" style="background:<?= NAVY2 ?>;">Importar propostas</button>
    </form>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Sincronizar RD Station CRM (CSV)</span>
  </div>
  <div class="p-4">
    <div class="text-xs text-slate-500 mb-2">Exporte o relatório de negócios do <b>RD Station CRM</b> (semanal ou mensal) como <b>CSV</b> e anexe aqui. Já sincronizados são atualizados, nunca duplicados. Colunas reconhecidas: Nome do Negócio/ID, Empresa/Organização/Conta, Valor, Etapa do Funil, Responsável, Comissão.</div>
    <form method="post" enctype="multipart/form-data"><?= csrfInput() ?>
      <input type="hidden" name="acao" value="importar_rdstation_csv">
      <select name="tipo_relatorio" class="<?= INPUT_CLS ?> inline-block w-auto"><option value="semanal">Relatório semanal</option><option value="mensal">Relatório mensal</option></select>
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
    <form method="post"><?= csrfInput() ?>
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

<details class="dnb-opportunity-history bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <summary><span><span class="dnb-eyebrow">Histórico e edição detalhada</span><strong>Orçamentos filtrados (<?= count($orcamentos) ?>)</strong></span><small>Os mesmos dados e ações permanecem disponíveis em cartões responsivos.</small></summary>
  <div class="dnb-opportunity-history__grid p-4"><?php foreach($orcamentos as $o) renderCartaoOportunidade($o,$funisArr,$vendedores,false); ?><?php if(!$orcamentos): ?><div class="dnb-empty-state"><p>Nenhuma oportunidade corresponde aos filtros.</p></div><?php endif; ?></div>
</details>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
