<?php
declare(strict_types=1);

/** Define a meta de um mês e/ou do ano de uma pessoa (sales_targets). Somente admin ou gestor. */
require_once __DIR__ . '/../_app.php';

$user = nc_boot();
nc_require_post();
if (!nc_is_manager($user)) {
    nc_fail('Ação não permitida.', 403);
}
$pdo = db();

try {
    $userId = (int)($_POST['user_id'] ?? 0);
    $month = valid_month($_POST['mes'] ?? null);
    [$year, $mon] = array_map('intval', explode('-', $month));

    $q = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id=?');
    $q->execute([$userId]);
    if (!(int)$q->fetchColumn()) {
        throw new RuntimeException('Usuário não encontrado.');
    }

    if (array_key_exists('monthly_target', $_POST)) {
        nc_target_save($pdo, $userId, $year, $mon, nc_parse_amount((string)$_POST['monthly_target']), (int)$user['id']);
    }
    if (array_key_exists('annual_target', $_POST)) {
        nc_target_save($pdo, $userId, $year, null, nc_parse_amount((string)$_POST['annual_target']), (int)$user['id']);
    }

    flash('success', 'Meta atualizada.');
    header('HX-Refresh: true');
    exit;
} catch (Throwable $e) {
    nc_fail($e->getMessage(), 400);
}
