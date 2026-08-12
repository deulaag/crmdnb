<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/whatsapp_service.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
    exit;
}
$esperado = whatsappEnv('DNB_WHATSAPP_WEBHOOK_TOKEN');
if (!whatsappTokenValido($esperado, whatsappTokenCabecalho())) {
    http_response_code($esperado === '' ? 503 : 401);
    echo json_encode(['ok'=>false,'error'=>$esperado === '' ? 'webhook_not_configured' : 'unauthorized']);
    exit;
}

try {
    $payload = json_decode((string) file_get_contents('php://input'), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new InvalidArgumentException('Payload inválido.');
    $evento = normalizarEventoWhatsapp($payload);
    $resultado = registrarWebhookWhatsapp(db(), $evento);
    http_response_code($resultado['duplicate'] ? 200 : 202);
    echo json_encode(['ok'=>true] + $resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Quando o cPanel usa PHP-FPM, encerra a resposta ao provedor e continua em
    // segundo plano. O Cron Jobs documentado no README é o fallback obrigatório.
    if (!$resultado['duplicate'] && function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        ignore_user_abort(true);
        @set_time_limit(60);
        usleep((WHATSAPP_BUFFER_SECONDS * 1000000) + 150000);
        try { processarFilaWhatsapp(db(), 5); }
        catch (Throwable $e) { error_log('DNB WhatsApp pós-webhook: ' . $e->getMessage()); }
    }
} catch (JsonException|InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'invalid_payload','message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('DNB WhatsApp webhook: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'internal_error']);
}
