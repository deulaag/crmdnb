<?php
declare(strict_types=1);

/**
 * Ações sobre uma oportunidade (ficha e kanban): add_note, quote_complete, status_won,
 * status_lost, status_reopen, stage, delete. Regras em nc_opp_apply_action().
 *
 * - Pedido vindo da ficha: devolve a ficha atualizada + toast.
 * - Pedido vindo do kanban (fetch, campo "json"=1): devolve JSON.
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/oportunidade.php';

$user = nc_boot();
nc_require_post();
$pdo = db();
ensure_opportunity_visit_schema($pdo);
ensure_opportunity_tracking_schema($pdo);

$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$wantsJson = ($_POST['json'] ?? '') === '1';

try {
    if ($id < 1) {
        throw new RuntimeException('Oportunidade não encontrada.');
    }
    $message = nc_opp_apply_action($pdo, $user, $id, $action, $_POST);
} catch (Throwable $e) {
    nc_fail($e->getMessage(), 400);
}

if ($action === 'delete') {
    flash('success', $message);
    nc_hx_redirect(nc_url('oportunidades.php'));
}

if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('HX-Trigger: ' . json_encode([
    'nc:toast' => ['type' => 'success', 'message' => $message],
    'nc:dirty' => true,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP));
nc_opp_ficha($pdo, nc_opp_load($pdo, $id), $user);
