<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/partials/cliente-ficha.php';

$user = nc_boot();
$pdo = db();
ensure_company_cnpja_schema($pdo);
ensure_rd_crm_schema($pdo);

$id = max(0, (int)($_GET['id'] ?? 0));
$stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$company = $stmt->fetch();

if (!$company) {
    if (nc_is_htmx()) {
        nc_fail('Cliente não encontrado.', 404);
    }
    http_response_code(404);
    nc_header('Cliente não encontrado', 'clientes', ['Clientes', 'Não encontrado']);
    nc_empty('Cliente não encontrado', 'O cadastro pode ter sido removido ou o link está incorreto.', '<a class="nc-btn nc-btn-primary" href="' . e(nc_url('clientes.php')) . '">Voltar para Clientes</a>');
    nc_footer();
    exit;
}

$dados = nc_cliente_dados($pdo, $company);

// Dentro do drawer (htmx): só o fragmento. Acesso direto pelo link: página completa.
if (nc_is_htmx()) {
    header('Content-Type: text/html; charset=utf-8');
    nc_cliente_ficha($company, $dados, nc_is_admin($user));
    exit;
}

nc_header((string)$company['name'], 'clientes', ['Clientes', (string)$company['name']]);
echo '<p><a href="' . e(nc_url('clientes.php')) . '">← Voltar para Clientes</a></p>';
echo '<div class="nc-card nc-ficha-page">';
nc_cliente_ficha($company, $dados, nc_is_admin($user));
echo '</div>';
nc_footer();
