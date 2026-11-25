<?php
declare(strict_types=1);

/** Criar compromisso — regras copiadas de cronograma.php (action create). */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/visita.php';

$user = nc_boot();
nc_require_post();
$pdo = db();
ensure_commercial_automation_schema($pdo);

$v = nc_agenda_from_post();

try {
    $date = (string)($_POST['event_date'] ?? date('Y-m-d'));
    $client = trim((string)($_POST['client_name'] ?? ''));
    $objective = trim((string)($_POST['objective'] ?? ''));
    $statusIn = (string)($_POST['status'] ?? 'agendado');
    $status = in_array($statusIn, ['sugerido', 'agendado', 'confirmado'], true) ? $statusIn : 'agendado';
    $typeIn = (string)($_POST['event_type'] ?? 'compromisso');
    $eventType = in_array($typeIn, ['compromisso', 'followup', 'visita_retorno'], true) ? $typeIn : 'compromisso';

    if (!valid_date_string($date) || $client === '' || $objective === '') {
        throw new RuntimeException('Informe data, cliente e objetivo do compromisso.');
    }

    $q = $pdo->prepare(
        'INSERT INTO agenda
         (event_date,event_time,client_name,user_id,contact_name,objective,status,event_type,auto_generated,source,created_by)
         VALUES (?,?,?,?,?,?,?, ?,0,"manual",?)'
    );
    $q->execute([
        $date,
        trim((string)($_POST['event_time'] ?? '')) ?: null,
        $client,
        ($_POST['user_id'] ?? '') !== '' ? (int)$_POST['user_id'] : null,
        trim((string)($_POST['contact_name'] ?? '')) ?: null,
        $objective,
        $status,
        $eventType,
        (int)$user['id'],
    ]);

    $id = (int)$pdo->lastInsertId();
    audit($pdo, (int)$user['id'], 'create', 'agenda', $id, ['event_type' => $eventType]);
    flash('success', 'Compromisso adicionado ao Cronograma Comercial.');
    nc_hx_redirect(nc_url(($v['volta'] ?? '') === 'cadencia' ? 'cadencia.php' : 'visitas.php'));
} catch (Throwable $e) {
    http_response_code(422);
    header('Content-Type: text/html; charset=utf-8');
    nc_agenda_form($pdo, $v, $e->getMessage());
}
