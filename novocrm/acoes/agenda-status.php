<?php
declare(strict_types=1);

/** Confirmar / concluir / cancelar compromisso — regra copiada de cronograma.php (action status). */
require_once __DIR__ . '/../_app.php';

$user = nc_boot();
nc_require_post();
$pdo = db();
ensure_commercial_automation_schema($pdo);

try {
    $id = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');

    if ($id < 1 || !in_array($status, ['agendado', 'confirmado', 'concluido', 'cancelado'], true)) {
        throw new RuntimeException('Atualização de cronograma inválida.');
    }

    $pdo->prepare('UPDATE agenda SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status, $id]);
    audit($pdo, (int)$user['id'], 'update_status', 'agenda', $id, ['status' => $status]);
    flash('success', 'Status do compromisso atualizado.');
    header('HX-Refresh: true');
    exit;
} catch (Throwable $e) {
    nc_fail($e->getMessage(), 400);
}
