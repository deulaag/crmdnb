<?php
declare(strict_types=1);

/**
 * Arrastar e soltar no kanban: muda a etapa (regra do bloco 'stage' de orcamentos.php)
 * e grava a ordem dentro da coluna (opportunities.position, quando a migração 001 existe).
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/oportunidade.php';

$user = nc_boot();
nc_require_post();
$pdo = db();

header('Content-Type: application/json; charset=utf-8');

try {
    $id = (int)($_POST['id'] ?? 0);
    $stageId = (int)($_POST['stage_id'] ?? 0);

    $q = $pdo->prepare('SELECT stage_id FROM opportunities WHERE id=? LIMIT 1');
    $q->execute([$id]);
    $currentStage = $q->fetchColumn();
    if ($currentStage === false) {
        throw new RuntimeException('Oportunidade não encontrada.');
    }

    if ((int)$currentStage !== $stageId) {
        nc_opp_apply_action($pdo, $user, $id, 'stage', ['stage_id' => $stageId]);
    }

    // Ordem na coluna de destino. updated_at=updated_at preserva "dias parado na etapa".
    if (nc_column_exists($pdo, 'opportunities', 'position')) {
        $order = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['order'] ?? ''))), static fn($n) => $n > 0));
        if ($order) {
            $upd = $pdo->prepare('UPDATE opportunities SET position=?, updated_at=updated_at WHERE id=? AND stage_id=?');
            foreach ($order as $i => $oppId) {
                $upd->execute([$i + 1, $oppId, $stageId]);
            }
        }
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}
