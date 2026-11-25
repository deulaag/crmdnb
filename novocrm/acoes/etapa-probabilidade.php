<?php
declare(strict_types=1);

/** Define a probabilidade (%) de uma etapa do funil, exibida no card do kanban. Somente admin. */
require_once __DIR__ . '/../_app.php';

$user = nc_boot();
nc_require_post();
nc_require_admin_action($user);
$pdo = db();

try {
    if (!nc_column_exists($pdo, 'funnel_stages', 'probability')) {
        throw new RuntimeException('Aplique as migrações do NOVOCRM para definir a probabilidade das etapas.');
    }
    $stageId = (int)($_POST['stage_id'] ?? 0);
    $raw = trim((string)($_POST['probability'] ?? ''));

    if ($raw !== '' && (!ctype_digit($raw) || (int)$raw > 100)) {
        throw new RuntimeException('Informe um número inteiro de 0 a 100, ou deixe em branco para ocultar.');
    }
    $value = $raw === '' ? null : (int)$raw;

    $q = $pdo->prepare('SELECT COUNT(*) FROM funnel_stages WHERE id=?');
    $q->execute([$stageId]);
    if (!(int)$q->fetchColumn()) {
        throw new RuntimeException('Etapa não encontrada.');
    }

    $pdo->prepare('UPDATE funnel_stages SET probability=? WHERE id=?')->execute([$value, $stageId]);
    audit($pdo, (int)$user['id'], 'update_probability', 'funnel_stage', $stageId, ['probability' => $value]);
    nc_hx_toast('success', $value === null ? 'Probabilidade removida da etapa.' : 'Probabilidade da etapa atualizada.');
    http_response_code(200);
    exit;
} catch (Throwable $e) {
    nc_fail($e->getMessage(), 400);
}
