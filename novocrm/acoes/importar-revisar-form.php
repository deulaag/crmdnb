<?php
declare(strict_types=1);

/** Drawer "Resolver linha": candidatas, escolha de outra empresa, criar empresa nova (Contatos) ou ignorar. Somente leitura. */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/clientes-importacoes.php';
require_once __DIR__ . '/../partials/importar.php';

$user = nc_boot();
if (!nc_is_manager($user)) {
    nc_fail('Ação não permitida.', 403);
}
$pdo = db();
$row = nc_import_row($pdo, (int)($_GET['linha'] ?? 0));
$batch = $row ? nc_import_batch($pdo, (int)$row['batch_id']) : null;
if (!$row || !$batch) {
    nc_fail('Linha não encontrada.', 404);
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
nc_import_review_drawer($pdo, $batch, $row);
