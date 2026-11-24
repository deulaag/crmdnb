<?php
declare(strict_types=1);

require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/cliente-form.php';

$user = nc_boot();
$pdo = db();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

nc_cliente_form(['owner_user_id' => (string)(int)$user['id']], nc_users($pdo));
