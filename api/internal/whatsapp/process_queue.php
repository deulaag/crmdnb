<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/whatsapp_service.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: application/json; charset=utf-8');
    $esperado = whatsappEnv('DNB_WHATSAPP_CRON_TOKEN');
    if (!whatsappTokenValido($esperado, whatsappTokenCabecalho())) {
        http_response_code($esperado === '' ? 503 : 401);
        echo json_encode(['ok'=>false,'error'=>$esperado === '' ? 'cron_not_configured' : 'unauthorized']);
        exit;
    }
}
try {
    $limite = $cli ? (int) ($argv[1] ?? 20) : (int) ($_GET['limit'] ?? 20);
    $resultado = processarFilaWhatsapp(db(), $limite);
    echo json_encode(['ok'=>true,'queue'=>$resultado], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    error_log('DNB WhatsApp cron: ' . $e->getMessage());
    if (!$cli) http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'queue_failed','message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if ($cli) exit(1);
}
