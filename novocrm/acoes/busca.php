<?php
declare(strict_types=1);

/**
 * Busca global (⌘K / Ctrl+K): grupos de até 5 resultados. Somente leitura (GET).
 * Clientes (nome/CNPJ), Oportunidades (nº/cliente), Pedidos (nº/cliente), Visitas (OS/cliente).
 * Usuário "comercial" só enxerga o que é seu (oportunidades, pedidos e visitas).
 */
require_once __DIR__ . '/../_app.php';

$user = nc_boot();
$pdo = db();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$q = trim((string)($_GET['q'] ?? ''));
if (strlen($q) < 2) {
    echo '<p class="nc-search-hint">Digite ao menos 2 letras. Use ↑ ↓ para navegar e Enter para abrir.</p>';
    exit;
}

$like = nc_like($q);
$digits = cnpj_digits($q);
[$sellerId] = nc_seller_scope($user);
if (($user['role'] ?? '') !== 'comercial') {
    $sellerId = null; // admin/gestor/demais enxergam tudo (sem o filtro opcional da URL)
}
$groups = [];

// Clientes: nome, nome fantasia ou CNPJ (só dígitos).
$sql = 'SELECT id,name,cnpj,city,state FROM companies WHERE (name LIKE ? OR alias LIKE ?' . ($digits !== '' ? ' OR cnpj LIKE ?' : '') . ') ORDER BY name LIMIT 5';
$params = [$like, $like];
if ($digits !== '') {
    $params[] = nc_like($digits);
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = [];
foreach ($stmt->fetchAll() as $r) {
    $items[] = [
        'href' => nc_url('cliente.php?id=' . (int)$r['id']),
        'title' => (string)$r['name'],
        'meta' => trim(($r['cnpj'] ? format_cnpj((string)$r['cnpj']) . ' · ' : '') . trim(($r['city'] ?? '') . ($r['state'] ? '/' . $r['state'] : ''))),
    ];
}
$groups['Clientes'] = $items;

// Oportunidades: número ou cliente.
$stmt = $pdo->prepare(
    'SELECT id,number,client_name,amount,status FROM opportunities
     WHERE (number LIKE ? OR client_name LIKE ?)' . ($sellerId !== null ? ' AND seller_user_id = ?' : '') . ' ORDER BY id DESC LIMIT 5'
);
$stmt->execute($sellerId !== null ? [$like, $like, $sellerId] : [$like, $like]);
$items = [];
foreach ($stmt->fetchAll() as $r) {
    $items[] = [
        'href' => nc_url('oportunidade.php?id=' . (int)$r['id']),
        'title' => $r['number'] . ' · ' . $r['client_name'],
        'meta' => $r['status'] . ' · ' . money($r['amount']),
    ];
}
$groups['Oportunidades'] = $items;

// Pedidos: número ou cliente.
$stmt = $pdo->prepare(
    'SELECT id,number,client_name,amount,invoiced FROM orders
     WHERE (number LIKE ? OR client_name LIKE ?)' . ($sellerId !== null ? ' AND seller_user_id = ?' : '') . ' ORDER BY id DESC LIMIT 5'
);
$stmt->execute($sellerId !== null ? [$like, $like, $sellerId] : [$like, $like]);
$items = [];
foreach ($stmt->fetchAll() as $r) {
    $items[] = [
        'href' => nc_url('pedido.php?id=' . (int)$r['id']),
        'title' => $r['number'] . ' · ' . $r['client_name'],
        'meta' => ((int)$r['invoiced'] === 1 ? 'Faturado' : 'A faturar') . ' · ' . money($r['amount']),
    ];
}
$groups['Pedidos'] = $items;

// Visitas: OS ou cliente.
$stmt = $pdo->prepare(
    'SELECT id,os_number,client_name,visit_date FROM visits
     WHERE (os_number LIKE ? OR client_name LIKE ?)' . ($sellerId !== null ? ' AND seller_user_id = ?' : '') . ' ORDER BY visit_date DESC, id DESC LIMIT 5'
);
$stmt->execute($sellerId !== null ? [$like, $like, $sellerId] : [$like, $like]);
$items = [];
foreach ($stmt->fetchAll() as $r) {
    $items[] = [
        'href' => nc_url('visitas.php?aba=visitas&q=' . rawurlencode($r['os_number'] ?: (string)$r['client_name'])),
        'title' => ($r['os_number'] ? 'OS ' . $r['os_number'] . ' · ' : '') . $r['client_name'],
        'meta' => nc_date((string)$r['visit_date']),
    ];
}
$groups['Visitas'] = $items;

$any = false;
foreach ($groups as $label => $items) {
    if (!$items) {
        continue;
    }
    $any = true;
    echo '<div class="nc-search-group">' . e($label) . '</div>';
    foreach ($items as $it) {
        echo '<a class="nc-search-item" href="' . e($it['href']) . '"><span>' . e($it['title']) . '</span>'
            . ($it['meta'] !== '' ? '<small class="nc-muted">' . e($it['meta']) . '</small>' : '') . '</a>';
    }
}
if (!$any) {
    echo '<p class="nc-search-hint">Nenhum resultado para “' . e($q) . '”.</p>';
}
