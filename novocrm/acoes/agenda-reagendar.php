<?php
declare(strict_types=1);

/**
 * Reagendar compromisso (nova data/horário). A raiz (cronograma.php) não tem esta ação: segue o mesmo
 * padrão da ação "status" (UPDATE em agenda + audit). Não mexe em reminder_sent_at, para o job da raiz
 * continuar decidindo sozinho sobre os avisos.
 */
require_once __DIR__ . '/../_app.php';

$user = nc_boot();
nc_require_post();
$pdo = db();
ensure_commercial_automation_schema($pdo);

try {
    $id = (int)($_POST['id'] ?? 0);
    $date = (string)($_POST['event_date'] ?? '');
    $time = trim((string)($_POST['event_time'] ?? ''));

    if ($id < 1 || !valid_date_string($date)) {
        throw new RuntimeException('Informe a nova data do compromisso.');
    }
    if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        throw new RuntimeException('Horário inválido.');
    }

    $q = $pdo->prepare('SELECT id,status,user_id,event_date FROM agenda WHERE id=? LIMIT 1');
    $q->execute([$id]);
    $a = $q->fetch();
    if (!$a) {
        throw new RuntimeException('Compromisso não encontrado.');
    }
    if (($user['role'] ?? '') === 'comercial' && (int)($a['user_id'] ?? 0) !== (int)$user['id']) {
        nc_fail('Ação não permitida.', 403);
    }
    if (!in_array($a['status'], ['sugerido', 'agendado', 'confirmado'], true)) {
        throw new RuntimeException('Só compromissos em aberto podem ser reagendados.');
    }

    $pdo->prepare('UPDATE agenda SET event_date=?, event_time=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
        ->execute([$date, $time !== '' ? $time : null, $id]);
    audit($pdo, (int)$user['id'], 'reschedule', 'agenda', $id, ['from' => (string)$a['event_date'], 'to' => $date]);
    flash('success', 'Compromisso reagendado para ' . nc_date($date) . '.');
    nc_hx_redirect(nc_url('cadencia.php'));
} catch (Throwable $e) {
    nc_fail($e->getMessage(), 400);
}
