<?php
declare(strict_types=1);

/** Criar pedido — regras copiadas de pedidos.php (action create). */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/pedido.php';

$user = nc_boot();
nc_require_post();
$pdo = db();

$v = nc_pedido_from_post();

try {
    $number = trim((string)($_POST['number'] ?? ''));
    $date = (string)($_POST['date'] ?? date('Y-m-d'));
    $client = trim((string)($_POST['client_name'] ?? ''));
    $seller = ($_POST['seller_user_id'] ?? '') !== '' ? (int)$_POST['seller_user_id'] : null;
    $support = ($_POST['support_user_id'] ?? '') !== '' ? (int)$_POST['support_user_id'] : null;
    $line = (int)($_POST['product_line_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $modality = (string)($_POST['modality'] ?? 'revenda');
    $margin = (float)($_POST['margin_amount'] ?? 0);
    $invoiceDate = trim((string)($_POST['invoice_date'] ?? '')) ?: null;
    $invoiced = $invoiceDate ? 1 : 0;

    if ($number === '' || $client === '' || $line <= 0) {
        throw new RuntimeException('Informe número, cliente e linha de produto.');
    }
    if (!valid_date_string($date)) {
        throw new RuntimeException('Informe uma data válida.');
    }
    if ($invoiceDate !== null && !valid_date_string($invoiceDate)) {
        throw new RuntimeException('Informe uma data de faturamento válida.');
    }
    if (!in_array($modality, ['revenda', 'agenciada'], true)) {
        throw new RuntimeException('Modalidade inválida.');
    }

    // Acréscimo ao fluxo da raiz: mensagem clara em vez do erro de chave única do MySQL.
    $dup = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE number=?');
    $dup->execute([$number]);
    if ((int)$dup->fetchColumn() > 0) {
        throw new RuntimeException('Já existe um pedido com o número ' . $number . '.');
    }

    $q = $pdo->prepare(
        'INSERT INTO orders
        (number,order_date,client_name,seller_user_id,support_user_id,product_line_id,amount,modality,margin_amount,invoiced,invoice_date,created_by)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $q->execute([
        $number,
        $date,
        $client,
        $seller,
        $support,
        $line,
        $amount,
        $modality,
        $margin,
        $invoiced,
        $invoiceDate,
        (int)$user['id'],
    ]);

    $id = (int)$pdo->lastInsertId();
    audit($pdo, (int)$user['id'], 'create', 'order', $id, ['number' => $number]);
    flash('success', 'Pedido registrado.');
    nc_hx_redirect(nc_url('pedidos.php'));
} catch (Throwable $e) {
    http_response_code(422);
    header('Content-Type: text/html; charset=utf-8');
    nc_pedido_form($pdo, $v, $e->getMessage());
}
