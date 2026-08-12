<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/whatsapp_service.php';

header('Content-Type: application/json; charset=utf-8');
exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_VENDEDOR, PAPEL_SUPORTE);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit;
}
try {
    echo json_encode(['ok'=>true,'conversations'=>listarConversasWhatsapp(db())], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('DNB WhatsApp conversations API: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'internal_error']);
}
