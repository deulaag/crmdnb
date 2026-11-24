<?php
declare(strict_types=1);

require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/visita.php';

$user = nc_boot();
$pdo = db();
ensure_commercial_automation_schema($pdo);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

nc_agenda_form($pdo, ['user_id' => (string)(int)$user['id']]);
