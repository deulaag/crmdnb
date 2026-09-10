<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/rbac.php';
require_once __DIR__ . '/../../../includes/ai_client.php';
require_once __DIR__ . '/../../../includes/lead_service.php';
require_once __DIR__ . '/../../../includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');
exigirPapel(PAPEL_ADMINISTRADOR);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Método não permitido.']);
    exit;
}
exigirCsrf();

$pdo = db();
$interacaoId = 0;
try {
    $dados = json_decode((string) file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    $leadId = (int) ($dados['lead_id'] ?? 0);
    $consulta = trim((string) ($dados['message'] ?? ''));
    if (!$leadId || $consulta === '') throw new InvalidArgumentException('Selecione uma oportunidade e informe a solicitação.');
    if (mb_strlen($consulta) > AI_MAX_PROMPT_LENGTH) throw new InvalidArgumentException('A solicitação excede o limite de ' . AI_MAX_PROMPT_LENGTH . ' caracteres.');
    $lead = buscarLeadAutorizado($pdo, $leadId);
    if (!$lead) {
        http_response_code(403);
        throw new RuntimeException('Oportunidade fora do seu escopo de acesso.');
    }

    $usuario = usuarioLogado();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ai_interactions WHERE requested_by_user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 MINUTE)');
    $stmt->execute([(int) $usuario['id']]);
    if ((int) $stmt->fetchColumn() >= 20) {
        http_response_code(429);
        throw new RuntimeException('Limite temporário de solicitações atingido. Aguarde um minuto.');
    }
    $correlationId = bin2hex(random_bytes(16));
    $interacaoId = registrarInteracaoIa($pdo, $leadId, (int) $usuario['id'], $consulta);
    $payload = [
        'contract_version' => '1.0',
        'correlation_id' => $correlationId,
        'source' => 'dnb-crm-cpanel',
        'lead' => [
            'id' => $leadId, 'number' => $lead['numero'], 'customer' => $lead['cliente'],
            'product_line' => $lead['linha'], 'funnel' => $lead['funil'], 'stage' => $lead['etapa'],
            'status' => $lead['status'], 'value' => (float) $lead['valor'], 'notes' => $lead['obs'],
        ],
        'request' => ['message' => $consulta],
        'requested_by' => ['id' => (int) $usuario['id'], 'name' => $usuario['nome'], 'role' => papelAtual(),
            'roles' => papeisAtuais(), 'cargos' => array_values((array) ($usuario['cargo_titles'] ?? []))],
    ];

    $resposta = enviarParaIaVps($payload);
    $pdo->beginTransaction();
    concluirInteracaoIa($pdo, $interacaoId, $resposta);
    if ($resposta['score'] !== null) salvarLeadScore($pdo, $leadId, $resposta['score'], $resposta['score_justification']);
    registrarTimeline($pdo, $leadId, 'ai_interaction', 'IA roteou a solicitação para ' . $resposta['route'] . '.');

    $casoId = null;
    if ($resposta['escalated'] || $resposta['guardrail_status'] === 'blocked') {
        $stmt = $pdo->prepare('INSERT INTO support_cases (lead_id,ai_interaction_id,reason_blocked,customer_query,status,created_at,updated_at) VALUES (?,?,?,?,' . "'open'" . ',NOW(),NOW())');
        $stmt->execute([$leadId, $interacaoId, $resposta['reason_blocked'] ?: $resposta['guardrail_reason'], $consulta]);
        $casoId = (int) $pdo->lastInsertId();
        notificarTransbordo($pdo, $casoId, $leadId, $lead['cliente'], $lead['assigned_to_user_id'] ? (int) $lead['assigned_to_user_id'] : null);
        registrarTimeline($pdo, $leadId, 'support_handoff', 'Atendimento transbordado para o Suporte no caso #' . $casoId . '.');
    }
    $pdo->commit();

    echo json_encode(['ok' => true, 'interaction_id' => $interacaoId, 'case_id' => $casoId, 'result' => [
        'route' => $resposta['route'], 'response' => $resposta['response'], 'score' => $resposta['score'],
        'score_justification' => $resposta['score_justification'], 'guardrail_status' => $resposta['guardrail_status'],
        'escalated' => (bool) $casoId,
    ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($interacaoId) falharInteracaoIa($pdo, $interacaoId, $e->getMessage());
    if (http_response_code() < 400) http_response_code($e instanceof InvalidArgumentException ? 422 : 502);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
