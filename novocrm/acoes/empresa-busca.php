<?php
declare(strict_types=1);

/**
 * Autocomplete de empresas (GET, somente leitura): nome, nome fantasia ou CNPJ (só dígitos).
 * Devolve botões .nc-picker-item que o JS usa para preencher o campo company_id.
 */
require_once __DIR__ . '/../_app.php';

$user = nc_boot();
$pdo = db();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$q = trim((string)($_GET['q'] ?? ''));
$digits = cnpj_digits($q);
if (strlen($q) < 2 && strlen($digits) < 2) {
    echo '<p class="nc-picker-hint">Digite ao menos 2 caracteres.</p>';
    exit;
}

$like = nc_like($q);
$sql = 'SELECT id, name, alias, cnpj, city, state FROM companies WHERE (name LIKE ? OR alias LIKE ?';
$params = [$like, $like];
if ($digits !== '') {
    $sql .= ' OR cnpj LIKE ?';
    $params[] = nc_like($digits);
}
$sql .= ') ORDER BY name LIMIT 8';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (!$rows) {
    echo '<p class="nc-picker-hint">Nenhuma empresa encontrada. Cadastre a empresa primeiro.</p>';
    exit;
}
foreach ($rows as $r) {
    $cnpj = $r['cnpj'] ? format_cnpj((string)$r['cnpj']) : '';
    $place = trim(($r['city'] ?? '') . ($r['state'] ? '/' . $r['state'] : ''));
    $label = (string)$r['name'] . ($cnpj !== '' ? ' · ' . $cnpj : '');
    echo '<button type="button" class="nc-picker-item" role="option" data-id="' . (int)$r['id'] . '" data-label="' . e($label) . '">'
        . '<strong>' . e((string)$r['name']) . '</strong>'
        . '<small class="nc-muted">' . e(implode(' · ', array_filter([$cnpj ?: 'Sem CNPJ', $place]))) . '</small></button>';
}
