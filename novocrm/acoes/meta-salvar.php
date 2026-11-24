<?php
declare(strict_types=1);

/** Define a meta mensal de uma pessoa (users.monthly_target). Somente admin ou gestor. */
require_once __DIR__ . '/../_app.php';

$user = nc_boot();
nc_require_post();
if (!nc_is_manager($user)) {
    nc_fail('Ação não permitida.', 403);
}
$pdo = db();

try {
    if (!nc_column_exists($pdo, 'users', 'monthly_target')) {
        throw new RuntimeException('Aplique as migrações do NOVOCRM para definir metas.');
    }

    $userId = (int)($_POST['user_id'] ?? 0);
    $raw = trim((string)($_POST['monthly_target'] ?? ''));
    $target = $raw === '' ? null : (float)$raw;
    if ($target !== null && $target < 0) {
        throw new RuntimeException('A meta não pode ser negativa.');
    }

    $q = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id=?');
    $q->execute([$userId]);
    if (!(int)$q->fetchColumn()) {
        throw new RuntimeException('Usuário não encontrado.');
    }

    $pdo->prepare('UPDATE users SET monthly_target=? WHERE id=?')->execute([$target, $userId]);
    audit($pdo, (int)$user['id'], 'update_target', 'user', $userId, ['monthly_target' => $target]);
    flash('success', $target === null ? 'Meta removida.' : 'Meta mensal atualizada.');
    header('HX-Refresh: true');
    exit;
} catch (Throwable $e) {
    nc_fail($e->getMessage(), 400);
}
