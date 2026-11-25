<?php
declare(strict_types=1);

require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/empresa-mesclar.php';

$user = nc_boot();
if (!nc_is_admin($user)) {
    nc_fail('Somente administradores podem mesclar empresas.', 403);
}
$pdo = db();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))), static fn($i) => $i > 0)));
if (count($ids) < 2 || count($ids) > 20) {
    nc_fail('Informe de 2 a 20 empresas para mesclar.', 400);
}
$rows = nc_merge_load($pdo, $ids);
if (count($rows) !== count($ids)) {
    nc_fail('Uma das empresas não existe mais. Atualize a lista.', 404);
}

// Principal sugerida: a que tem CNPJ válido; em empate, a com mais vínculos; depois a mais antiga.
$principal = (int)($_GET['principal'] ?? 0);
if (!isset($rows[$principal])) {
    $best = null;
    foreach ($rows as $id => $r) {
        $score = [nc_valid_cnpj_or_empty((string)($r['cnpj'] ?? '')) !== '' ? 1 : 0, (int)$r['contact_qtd'] + (int)$r['opp_qtd'], -$id];
        if ($best === null || $score > $best[0]) {
            $best = [$score, $id];
        }
    }
    $principal = (int)$best[1];
}

nc_merge_form($rows, $principal, nc_merge_default_pick($rows, $principal));
