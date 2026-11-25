<?php
declare(strict_types=1);

/** Drawer: reagendar um compromisso (nova data e horário opcional). */
require_once __DIR__ . '/../_app.php';

$user = nc_boot();
$pdo = db();
ensure_commercial_automation_schema($pdo);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$id = max(0, (int)($_GET['id'] ?? 0));
$q = $pdo->prepare('SELECT id,event_date,event_time,client_name,objective,status,user_id FROM agenda WHERE id=? LIMIT 1');
$q->execute([$id]);
$a = $q->fetch();
if (!$a) {
    nc_fail('Compromisso não encontrado.', 404);
}
if (($user['role'] ?? '') === 'comercial' && (int)($a['user_id'] ?? 0) !== (int)$user['id']) {
    nc_fail('Ação não permitida.', 403);
}
if (!in_array($a['status'], ['sugerido', 'agendado', 'confirmado'], true)) {
    nc_fail('Só compromissos em aberto podem ser reagendados.', 422);
}
?>
<div data-drawer-title="Reagendar">
    <form class="nc-form" method="post" hx-post="<?= e(nc_url('acoes/agenda-reagendar.php')) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <p><strong><?= e((string)$a['client_name']) ?></strong><br><span class="nc-muted"><?= e(nc_truncate((string)$a['objective'], 140)) ?></span></p>
        <p class="nc-help">Data atual: <?= e(nc_date((string)$a['event_date'])) ?></p>
        <div class="nc-field-grid">
            <div class="nc-field">
                <label for="rg-date">Nova data <span class="nc-req" aria-hidden="true">*</span></label>
                <input class="nc-input" id="rg-date" type="date" name="event_date" value="<?= e((string)$a['event_date']) ?>" min="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="nc-field">
                <label for="rg-time">Horário</label>
                <input class="nc-input" id="rg-time" type="time" name="event_time" value="<?= e($a['event_time'] ? substr((string)$a['event_time'], 0, 5) : '') ?>">
            </div>
        </div>
        <div class="nc-form-actions">
            <button type="button" class="nc-btn nc-btn-secondary" data-nc-drawer-close>Cancelar</button>
            <button type="submit" class="nc-btn nc-btn-primary">Reagendar</button>
        </div>
    </form>
</div>
