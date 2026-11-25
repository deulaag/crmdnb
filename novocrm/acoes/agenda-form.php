<?php
declare(strict_types=1);

require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/visita.php';

$user = nc_boot();
$pdo = db();
ensure_commercial_automation_schema($pdo);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$values = ['user_id' => (string)(int)$user['id']];
if (($_GET['tipo'] ?? '') === 'followup') {
    $values['event_type'] = 'followup';
    $values['event_date'] = date('Y-m-d', strtotime('+3 days')); // cadência padrão: follow-up em 3 dias
}
if (($_GET['volta'] ?? '') === 'cadencia') {
    $values['volta'] = 'cadencia';
}
if (trim((string)($_GET['cliente'] ?? '')) !== '') {
    $values['client_name'] = trim((string)$_GET['cliente']);
}

nc_agenda_form($pdo, $values);
