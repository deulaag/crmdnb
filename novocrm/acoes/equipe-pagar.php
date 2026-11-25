<?php
declare(strict_types=1);

/** Marcar fechamento como pago (com data). Somente admin. */
require_once __DIR__ . '/../_app.php';

$user = nc_boot();
nc_require_post();
nc_require_admin_action($user);
$pdo = db();

try {
    if (!nc_table_exists($pdo, 'commission_closings')) {
        throw new RuntimeException('Aplique as migrações do NOVOCRM.');
    }
    $id = (int)($_POST['id'] ?? 0);
    $date = (string)($_POST['paid_at'] ?? date('Y-m-d'));
    if ($id < 1 || !valid_date_string($date)) {
        throw new RuntimeException('Informe a data do pagamento.');
    }
    if ($date > date('Y-m-d')) {
        throw new RuntimeException('A data do pagamento não pode ser futura.');
    }

    $q = $pdo->prepare("UPDATE commission_closings SET status='paga', paid_at=?, paid_by=? WHERE id=? AND status='fechada'");
    $q->execute([$date, (int)$user['id'], $id]);
    if ($q->rowCount() < 1) {
        throw new RuntimeException('Fechamento não encontrado ou já quitado.');
    }

    audit($pdo, (int)$user['id'], 'pay_commission', 'commission_closing', $id, ['paid_at' => $date]);
    flash('success', 'Comissão marcada como paga.');
    header('HX-Refresh: true');
    exit;
} catch (Throwable $e) {
    nc_fail($e->getMessage(), 400);
}
