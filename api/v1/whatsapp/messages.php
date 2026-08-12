<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/whatsapp_service.php';

header('Content-Type: application/json; charset=utf-8');
exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_VENDEDOR, PAPEL_SUPORTE);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit;
}
try {
    $conversationId = (int) ($_GET['conversation_id'] ?? 0);
    if (!$conversationId) throw new InvalidArgumentException('Conversa obrigatória.');
    $depoisId = max(0, (int) ($_GET['after_id'] ?? 0));
    $mensagens = listarMensagensWhatsapp(db(), $conversationId, $depoisId);
    db()->prepare('UPDATE whatsapp_conversations SET unread_count=0 WHERE id=?')->execute([$conversationId]);
    echo json_encode(['ok'=>true,'messages'=>$mensagens], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(422); echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (WhatsappServiceException $e) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('DNB WhatsApp messages API: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'internal_error']);
}
