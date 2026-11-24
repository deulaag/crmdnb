<?php
declare(strict_types=1);

require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/pedido.php';

$user = nc_boot();
$pdo = db();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

nc_pedido_form($pdo, ['seller_user_id' => (string)(int)$user['id']]);
