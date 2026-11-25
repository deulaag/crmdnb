<?php
declare(strict_types=1);

require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/contato.php';

$user = nc_boot();
$pdo = db();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

// ?empresa=ID pré-seleciona a empresa (botão "+ Novo contato" da ficha).
$company = null;
$companyId = (int)($_GET['empresa'] ?? 0);
if ($companyId > 0) {
    $q = $pdo->prepare('SELECT id, name, cnpj FROM companies WHERE id = ? LIMIT 1');
    $q->execute([$companyId]);
    $company = $q->fetch() ?: null;
}

$return = (string)($_GET['return'] ?? '');
nc_contact_form(['company' => $company, 'return' => str_starts_with($return, NC_BASE) ? $return : '']);
