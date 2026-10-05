<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$user = require_auth();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    verify_csrf();

    $pdo = db();
    $cnpj = cnpj_digits((string)($_POST['cnpj'] ?? ''));
    if (!cnpj_is_valid($cnpj)) {
        throw new RuntimeException('CNPJ inválido. Confira os 14 dígitos.');
    }

    $data = cnpja_lookup($pdo, $cnpj);

    if (!isset($_SESSION['cnpja_lookup']) || !is_array($_SESSION['cnpja_lookup'])) {
        $_SESSION['cnpja_lookup'] = [];
    }

    $_SESSION['cnpja_lookup'][$cnpj] = [
        'expires_at' => time() + 1800,
        'data' => $data,
    ];

    audit($pdo, (int)$user['id'], 'lookup', 'cnpja', null, [
        'cnpj' => $cnpj,
        'strategy' => $data['strategy'] ?? '',
        'stale' => !empty($data['stale']),
    ]);

    $response = $data;
    unset($response['raw']);

    echo json_encode([
        'ok' => true,
        'data' => $response,
        'message' => 'Dados localizados na CNPJá.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
