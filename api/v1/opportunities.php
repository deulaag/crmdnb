<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/calc.php';
require_once __DIR__ . '/../../includes/lead_service.php';
require_once __DIR__ . '/../../includes/task_service.php';

header('Content-Type: application/json; charset=utf-8');
exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_VENDEDOR);
$pdo = db();
$acao = (string) ($_POST['acao'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'erro'=>'Método não permitido.']); exit;
}
exigirCsrf();

try {
    if ($acao === 'create') {
        $cliente = trim((string) ($_POST['cliente'] ?? ''));
        $valor = (float) ($_POST['valor'] ?? 0);
        $linha = trim((string) ($_POST['linha'] ?? ''));
        if ($cliente === '' || $valor <= 0 || $linha === '') throw new InvalidArgumentException('Cliente, linha e valor são obrigatórios.');

        $vendedores = filtrarVendedoresDaEquipe($pdo, obterVendedores($pdo));
        $vendedorId = trim((string) ($_POST['vendedor_id'] ?? '')) ?: null;
        if ($vendedorId && !in_array($vendedorId, array_column($vendedores, 'id'), true)) {
            http_response_code(403); echo json_encode(['ok'=>false,'erro'=>'Vendedor fora do seu escopo.']); exit;
        }
        $assignedTo = usuarioIdPorVendedor($pdo, $vendedorId) ?: (int) usuarioLogado()['id'];
        $cargoId = cargoParaRegistroComercial($pdo, $assignedTo, !empty($_POST['cargo_id']) ? (int) $_POST['cargo_id'] : null);
        $suporteId = trim((string) ($_POST['suporte_id'] ?? '')) ?: null;
        $data = trim((string) ($_POST['data'] ?? '')) ?: hoje();
        $funil = funilDe($linha);
        $funisArr = funis();
        if (!isset($funisArr[$funil])) throw new InvalidArgumentException('Funil inválido para a linha informada.');
        $numero = trim((string) ($_POST['numero'] ?? '')) ?: gerarNumeroProposta($pdo, $vendedores, $vendedorId, $suporteId, $data);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO orcamentos (numero,data,cliente,vendedor_id,suporte_id,linha,tipo,funil,etapa,valor,comissao_dnb_estimada,status,origem,assigned_to_user_id,cargo_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$numero,$data,$cliente,$vendedorId,$suporteId,$linha,tipoDe($linha),$funil,$funisArr[$funil]['etapas'][0],$valor,(float)($_POST['comissao_dnb_estimada']??0),'Aberta','manual',$assignedTo,$cargoId]);
        $leadId = (int) $pdo->lastInsertId();
        registrarTimeline($pdo, $leadId, 'created', 'Oportunidade criada manualmente.');

        $taskTitle = trim((string) ($_POST['task_title'] ?? ''));
        $taskDue = trim((string) ($_POST['task_due_date'] ?? ''));
        $taskId = null;
        if ($taskDue !== '') {
            $taskId = criarTarefaOrcamento($pdo, $leadId, $assignedTo, $taskTitle ?: 'Acompanhar orçamento', $taskDue, (int) usuarioLogado()['id']);
            registrarTimeline($pdo, $leadId, 'task_created', 'Tarefa criada para ' . $taskDue . ': ' . ($taskTitle ?: 'Acompanhar orçamento') . '.');
        }
        $pdo->commit();
        echo json_encode(['ok'=>true,'lead_id'=>$leadId,'numero'=>$numero,'task_id'=>$taskId]); exit;
    }

    if ($acao === 'set_stage') {
        $leadId = (int) ($_POST['id'] ?? 0);
        if (!$leadId || !podeAcessarLead($pdo, $leadId)) {
            http_response_code(403); echo json_encode(['ok'=>false,'erro'=>'Oportunidade fora do seu escopo.']); exit;
        }
        $stmt = $pdo->prepare('SELECT * FROM orcamentos WHERE id=? LIMIT 1');
        $stmt->execute([$leadId]);
        $o = $stmt->fetch();
        $novaEtapa = trim((string) ($_POST['etapa'] ?? ''));
        $funil = $o ? funilDe((string) $o['linha']) : '';
        $etapas = funis()[$funil]['etapas'] ?? [];
        if (!$o || $o['status'] !== 'Aberta' || !in_array($novaEtapa, $etapas, true)) {
            http_response_code(422); echo json_encode(['ok'=>false,'erro'=>'Etapa inválida para esta oportunidade.']); exit;
        }

        $pdo->beginTransaction();
        $pedido = null;
        $gerado = false;
        if ($novaEtapa === 'Fechamento') {
            $stmtPed = $pdo->prepare('SELECT id,numero FROM pedidos WHERE orcamento_id=? ORDER BY id DESC LIMIT 1');
            $stmtPed->execute([$leadId]);
            $pedido = $stmtPed->fetch() ?: null;
            if (!$pedido) {
                $numeroPedido = 'PV-' . ((string) ($o['numero'] ?: $o['id']));
                $modalidade = ehAgenciamento((string) $o['linha']) ? 'agenciada' : 'revenda';
                $pdo->prepare('INSERT INTO pedidos (numero,data,cliente,vendedor_id,suporte_id,linha,valor,modalidade,comissao_dnb,faturado,orcamento_id,assigned_to_user_id,cargo_id) VALUES (?,?,?,?,?,?,?,?,?,0,?,?,?)')
                    ->execute([$numeroPedido,hoje(),$o['cliente'],$o['vendedor_id'],$o['suporte_id'],$o['linha'],(float)$o['valor'],$modalidade,$modalidade==='agenciada'?(float)$o['comissao_dnb_estimada']:0,$leadId,$o['assigned_to_user_id'],$o['cargo_id']]);
                $pedido = ['id'=>(int)$pdo->lastInsertId(),'numero'=>$numeroPedido];
                $gerado = true;
            }
            $pdo->prepare("UPDATE orcamentos SET etapa='Fechamento',status='Ganha',cad_pausada=1 WHERE id=?")->execute([$leadId]);
            registrarTimeline($pdo, $leadId, 'funnel_update', 'Etapa atualizada para Fechamento. Pedido de venda ' . ($gerado ? 'gerado automaticamente' : 'já existente') . '.');
            registrarTimeline($pdo, $leadId, 'status_update', 'Status atualizado automaticamente para Ganha ao entrar em Fechamento.');
        } else {
            $pdo->prepare('UPDATE orcamentos SET etapa=? WHERE id=?')->execute([$novaEtapa,$leadId]);
            registrarTimeline($pdo, $leadId, 'funnel_update', 'Etapa atualizada para ' . $novaEtapa . '.');
        }
        $pdo->commit();
        echo json_encode(['ok'=>true,'stage'=>$novaEtapa,'order_generated'=>$gerado,'order'=>$pedido]); exit;
    }

    if ($acao === 'complete_task') {
        concluirTarefa($pdo, (int) ($_POST['task_id'] ?? 0));
        echo json_encode(['ok'=>true]); exit;
    }

    if ($acao === 'add_task') {
        $leadId = (int) ($_POST['id'] ?? 0);
        if (!$leadId || !podeAcessarLead($pdo, $leadId)) {
            http_response_code(403); echo json_encode(['ok'=>false,'erro'=>'Oportunidade fora do seu escopo.']); exit;
        }
        $stmt = $pdo->prepare('SELECT assigned_to_user_id FROM orcamentos WHERE id=?');
        $stmt->execute([$leadId]);
        $assigned = (int) $stmt->fetchColumn();
        $taskId = criarTarefaOrcamento($pdo,$leadId,$assigned,trim((string)($_POST['task_title']??'')),trim((string)($_POST['task_due_date']??'')),(int)usuarioLogado()['id']);
        registrarTimeline($pdo,$leadId,'task_created','Nova tarefa adicionada ao orçamento.');
        echo json_encode(['ok'=>true,'task_id'=>$taskId]); exit;
    }

    http_response_code(422); echo json_encode(['ok'=>false,'erro'=>'Ação inválida.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('api opportunities: ' . $e->getMessage());
    http_response_code(422);
    echo json_encode(['ok'=>false,'erro'=>$e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível concluir a operação.']);
}
