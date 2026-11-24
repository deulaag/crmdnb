<?php
declare(strict_types=1);

/** Confirmar faturamento — regra copiada de pedidos.php (action invoice). */
require_once __DIR__ . '/../_app.php';

$user = nc_boot();
nc_require_post();
$pdo = db();

try {
    $id = (int)($_POST['id'] ?? 0);
    $date = trim((string)($_POST['invoice_date'] ?? ''));
    if (!valid_date_string($date)) {
        throw new RuntimeException('Informe a data de faturamento.');
    }

    $pdo->prepare('UPDATE orders SET invoiced=1,invoice_date=? WHERE id=?')->execute([$date, $id]);
    audit($pdo, (int)$user['id'], 'invoice', 'order', $id, ['invoice_date' => $date]);
    flash('success', 'Faturamento confirmado.');
    header('HX-Refresh: true');
    exit;
} catch (Throwable $e) {
    nc_fail($e->getMessage(), 400);
}
