<?php
declare(strict_types=1);

/**
 * Itens opcionais do pedido (tabela order_items, migração 003).
 * O valor do pedido continua sendo orders.amount — os itens não o alteram.
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/pedido.php';

$user = nc_boot();
nc_require_post();
$pdo = db();

try {
    if (!nc_table_exists($pdo, 'order_items')) {
        throw new RuntimeException('Aplique as migrações do NOVOCRM para usar itens de pedido.');
    }

    $orderId = (int)($_POST['order_id'] ?? 0);
    $order = nc_pedido_load($pdo, $orderId);
    $op = (string)($_POST['op'] ?? '');

    if ($op === 'add') {
        $description = trim((string)($_POST['description'] ?? ''));
        $sku = trim((string)($_POST['sku'] ?? '')) ?: null;
        $qty = (float)($_POST['quantity'] ?? 0);
        $price = (float)($_POST['unit_price'] ?? 0);
        if ($description === '' || $qty <= 0 || $price < 0) {
            throw new RuntimeException('Informe descrição, quantidade e valor unitário válidos.');
        }
        $subtotal = round($qty * $price, 2);
        $pdo->prepare('INSERT INTO order_items (order_id,description,sku,quantity,unit_price,subtotal) VALUES (?,?,?,?,?,?)')
            ->execute([$orderId, $description, $sku, $qty, $price, $subtotal]);
        audit($pdo, (int)$user['id'], 'add_item', 'order', $orderId, ['description' => $description]);
        $message = 'Item adicionado ao pedido.';
    } elseif ($op === 'delete') {
        $pdo->prepare('DELETE FROM order_items WHERE id=? AND order_id=?')->execute([(int)($_POST['item_id'] ?? 0), $orderId]);
        audit($pdo, (int)$user['id'], 'delete_item', 'order', $orderId);
        $message = 'Item removido.';
    } else {
        throw new RuntimeException('Ação inválida.');
    }
} catch (Throwable $e) {
    nc_fail($e->getMessage(), 400);
}

header('Content-Type: text/html; charset=utf-8');
nc_hx_toast('success', $message);
nc_pedido_ficha($pdo, nc_pedido_load($pdo, $orderId));
