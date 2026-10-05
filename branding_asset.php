<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!is_installed()) {
    http_response_code(404);
    exit;
}

$kind = (string)($_GET['kind'] ?? 'portal');
if (!in_array($kind, ['portal', 'pdf'], true)) {
    http_response_code(404);
    exit;
}

try {
    $pdo = db();
    $path = branding_logo_path($pdo, $kind);

    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit;
    }

    $info = @getimagesize($path);
    $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        http_response_code(415);
        exit;
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');

    readfile($path);
} catch (Throwable $e) {
    http_response_code(404);
}
