<?php
declare(strict_types=1);

/** Mescla empresas duplicadas (somente admin). Transação única; bloqueia CNPJs válidos diferentes; grava auditoria. */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/empresa-mesclar.php';

$user = nc_boot();
nc_require_post();
if (!nc_is_admin($user)) {
    nc_fail('Somente administradores podem mesclar empresas.', 403);
}
$pdo = db();

$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))), static fn($i) => $i > 0)));
$principal = (int)($_POST['principal'] ?? 0);
$pick = [];
foreach ((array)($_POST['pick'] ?? []) as $field => $from) {
    if (isset(NC_MERGE_PICK_FIELDS[(string)$field])) { // só campos conhecidos
        $pick[(string)$field] = (int)$from;
    }
}

try {
    if (count($ids) < 2 || count($ids) > 20 || !in_array($principal, $ids, true)) {
        throw new RuntimeException('Escolha a empresa principal entre as listadas.');
    }
    foreach ($pick as $from) {
        if (!in_array($from, $ids, true)) {
            throw new RuntimeException('Valor escolhido de uma empresa fora do grupo.');
        }
    }
    $result = nc_company_merge($pdo, $principal, array_diff($ids, [$principal]), $pick, (int)$user['id']);
    flash('success', 'Empresas mescladas: ' . $result['moved_contacts'] . ' contato(s) e ' . $result['moved_opportunities']
        . ' oportunidade(s) movidos; ' . count($result['deleted']) . ' cadastro(s) duplicado(s) excluído(s).');
    nc_hx_redirect(nc_url('clientes.php?status=duplicadas'));
} catch (Throwable $e) {
    http_response_code(422);
    header('Content-Type: text/html; charset=utf-8');
    $rows = nc_merge_load($pdo, $ids ?: [0]);
    if (count($rows) < 2 || !isset($rows[$principal])) {
        nc_fail($e->getMessage(), 422);
    }
    nc_merge_form($rows, $principal, $pick + nc_merge_default_pick($rows, $principal), $e->getMessage());
}
