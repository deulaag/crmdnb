<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    verify_csrf();

    $user = current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Sessão expirada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    session_regenerate_id(true);
    session_touch_server_activity();

    echo json_encode([
        'ok' => true,
        'server_time' => time(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Não foi possível renovar a sessão.'], JSON_UNESCAPED_UNICODE);
}
