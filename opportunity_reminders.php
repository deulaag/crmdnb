<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pdo = db();
ensure_opportunity_tracking_schema($pdo);
ensure_commercial_automation_schema($pdo);

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $token = (string)($_GET['token'] ?? '');
    if ($token === '' || !hash_equals(opportunity_reminder_token(), $token)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'message'=>'Acesso não autorizado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    $quoteResult = process_quote_reminders($pdo);
    $commercialResult = process_commercial_agenda_reminders($pdo);

    $payload = [
        'ok' => true,
        'quote_reminders' => $quoteResult,
        'commercial_agenda_reminders' => $commercialResult,
        'sent_total' => (int)($quoteResult['sent'] ?? 0) + (int)($commercialResult['sent'] ?? 0),
    ];

    if ($isCli) {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable $e) {
    if (!$isCli) http_response_code(500);
    $payload = ['ok'=>false,'message'=>$e->getMessage()];

    if ($isCli) {
        fwrite(STDERR, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        exit(1);
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
