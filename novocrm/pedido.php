<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/partials/pedido.php';

$user = nc_boot();
$pdo = db();

try {
    $order = nc_pedido_load($pdo, max(0, (int)($_GET['id'] ?? 0)));
} catch (Throwable $e) {
    if (nc_is_htmx()) {
        nc_fail($e->getMessage(), 404);
    }
    http_response_code(404);
    nc_header('Pedido não encontrado', 'pedidos', ['Pedidos', 'Não encontrado']);
    nc_empty('Pedido não encontrado', 'Ele pode ter sido removido ou o link está incorreto.', '<a class="nc-btn nc-btn-primary" href="' . e(nc_url('pedidos.php')) . '">Voltar para Pedidos</a>');
    nc_footer();
    exit;
}

if (nc_is_htmx()) {
    header('Content-Type: text/html; charset=utf-8');
    nc_pedido_ficha($pdo, $order);
    exit;
}

nc_header('Pedido ' . $order['number'], 'pedidos', ['Pedidos', (string)$order['number']]);
echo '<p><a href="' . e(nc_url('pedidos.php')) . '">← Voltar para Pedidos</a></p>';
echo '<div class="nc-card nc-ficha-page">';
nc_pedido_ficha($pdo, $order);
echo '</div>';
nc_footer();
