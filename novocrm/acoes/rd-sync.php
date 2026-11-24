<?php
declare(strict_types=1);

/**
 * Sincronização MANUAL com a RD Station — somente admin.
 * Mesma ação "rd_crm_sync" de clientes.php/empresas.php da raiz (rd_sync_all + audit).
 */
require_once __DIR__ . '/../_app.php';

$user = nc_boot();
nc_require_post();
if (!nc_is_admin($user)) {
    nc_fail('Ação não permitida.', 403);
}
$pdo = db();
ensure_rd_crm_schema($pdo);

$back = safe_internal_redirect((string)($_POST['return'] ?? ''), nc_url('clientes.php'));
if (!str_starts_with($back, '/novocrm/')) {
    $back = nc_url('clientes.php');
}

try {
    $result = rd_sync_all($pdo, (int)$user['id']);
    audit($pdo, (int)$user['id'], 'sync', 'integration', null, ['provider' => array_keys($result['results'])]);
    flash($result['status'] === 'success' ? 'success' : 'warning', 'RD Station sincronizada. ' . $result['message']);
} catch (Throwable $e) {
    flash('danger', $e->getMessage());
}

redirect($back);
