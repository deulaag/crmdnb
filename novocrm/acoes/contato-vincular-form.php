<?php
declare(strict_types=1);

require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/contato.php';

$user = nc_boot();
if (!nc_is_manager($user)) {
    nc_fail('Ação não permitida.', 403);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$ids = array_values(array_unique(array_filter(array_map('intval', (array)($_GET['ids'] ?? [])), static fn($i) => $i > 0)));
if (!$ids) {
    nc_fail('Selecione ao menos um contato sem empresa.', 400);
}
nc_contact_link_form(array_slice($ids, 0, 500));
