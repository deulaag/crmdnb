<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/whatsapp_service.php';

header('Content-Type: application/json; charset=utf-8');
exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_VENDEDOR, PAPEL_SUPORTE);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit;
}
exigirCsrf();
try {
    $dados = json_decode((string) file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
    $conversationId = (int) ($dados['conversation_id'] ?? 0);
    $acao = (string) ($dados['action'] ?? '');
    if (!$conversationId) throw new InvalidArgumentException('Conversa obrigatória.');
    $conversa = alterarTravaHumanaWhatsapp(db(), $conversationId, $acao, usuarioLogado());
    echo json_encode(['ok'=>true,'conversation'=>$conversa], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (JsonException|InvalidArgumentException $e) {
    http_response_code(422); echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (WhatsappServiceException $e) {
    http_response_code(409); echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('DNB WhatsApp assign API: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'internal_error']);
}
