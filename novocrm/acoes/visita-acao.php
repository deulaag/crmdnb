<?php
declare(strict_types=1);

/**
 * Descartar lead (orcamentos.php: discard_visit) e excluir visita (visitas.php: delete).
 */
require_once __DIR__ . '/../_app.php';

$user = nc_boot();
nc_require_post();
$pdo = db();
ensure_opportunity_visit_schema($pdo);
ensure_commercial_automation_schema($pdo);

try {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'discard') {
        $visitId = (int)($_POST['visit_id'] ?? 0);
        $pdo->prepare("UPDATE visits SET lead_status='descartada' WHERE id=? AND lead_status='pendente'")->execute([$visitId]);
        audit($pdo, (int)$user['id'], 'discard_lead', 'visit', $visitId);
        flash('success', 'Lead da visita descartado da triagem.');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['visit_id'] ?? 0);
        if ($id > 0) {
            $q = $pdo->prepare('SELECT COUNT(*) FROM opportunities WHERE visit_id=?');
            $q->execute([$id]);
            if ((int)$q->fetchColumn() > 0) {
                throw new RuntimeException('Esta visita já virou uma oportunidade. Exclua primeiro a oportunidade em Oportunidades.');
            }
            $pdo->prepare('DELETE FROM agenda WHERE visit_id=? AND auto_generated=1')->execute([$id]);
            $pdo->prepare('DELETE FROM visits WHERE id=?')->execute([$id]);
            audit($pdo, (int)$user['id'], 'delete', 'visit', $id, ['auto_agenda_removed' => true]);
        }
        flash('success', 'Visita excluída.');
    } else {
        throw new RuntimeException('Ação inválida.');
    }

    header('HX-Refresh: true');
    exit;
} catch (Throwable $e) {
    nc_fail($e->getMessage(), 400);
}
