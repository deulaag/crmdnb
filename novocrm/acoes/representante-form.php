<?php
declare(strict_types=1);

/** Drawer: cadastrar (sem id) ou editar representante. Somente admin e gestor. */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/comissao.php';
require_once __DIR__ . '/../partials/equipe.php';

$user = nc_boot();
if (!nc_is_manager($user)) {
    nc_fail('Ação não permitida.', 403);
}
$pdo = db();
ensure_commission_policy_schema($pdo);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$month = valid_month($_GET['mes'] ?? null);
$id = max(0, (int)($_GET['id'] ?? 0));

try {
    if ($id > 0) {
        nc_rep_form($pdo, $user, nc_rep_values_for_user($pdo, $id, $month));
    } else {
        $defaults = commission_policy_definitions();
        nc_rep_form($pdo, $user, [
            'month' => $month,
            'role' => 'comercial',
            'policy_key' => 'vendedor_ir',
            'operation_role' => 'vendedor',
            'active_operation' => true,
            'params' => $defaults['vendedor_ir']['defaults'],
        ]);
    }
} catch (Throwable $e) {
    nc_fail($e->getMessage(), 404);
}
