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
    $mensagem = trim((string) ($dados['message'] ?? ''));
    if (!$conversationId) throw new InvalidArgumentException('Conversa obrigatória.');
    $registro = enviarMensagemHumanaWhatsapp(db(), $conversationId, $mensagem, usuarioLogado());
    echo json_encode(['ok'=>true,'message'=>$registro], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (JsonException|InvalidArgumentException $e) {
    http_response_code(422); echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (WhatsappServiceException $e) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (WhatsappProviderException $e) {
    http_response_code(502); echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('DNB WhatsApp send API: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'internal_error']);
}
