<?php
declare(strict_types=1);

/**
 * Fechar mês: grava um snapshot por pessoa em commission_closings (status 'fechada').
 * Admin e gestor. Quem já tem fechamento no mês é preservado (inclusive pagos).
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/comissao.php';

$user = nc_boot();
nc_require_post();
if (!nc_is_manager($user)) {
    nc_fail('Ação não permitida.', 403);
}
$pdo = db();

try {
    if (!nc_table_exists($pdo, 'commission_closings')) {
        throw new RuntimeException('Aplique as migrações do NOVOCRM para fechar o mês.');
    }
    $month = valid_month($_POST['mes'] ?? null);
    if ($month > current_month()) {
        throw new RuntimeException('Não é possível fechar um mês futuro.');
    }

    $report = nc_commission_report($pdo, $month);

    $toClose = [];
    foreach ($report['people'] as $p) {
        $id = (int)$p['id'];
        $s = $report['summary'][$id];
        if ($s['from_snapshot'] || !$s['has_policy'] || $s['orders'] < 1) {
            continue;
        }
        $lines = [];
        foreach ($report['statement'] as $row) {
            if ((int)$row['person_id'] !== $id) {
                continue;
            }
            $lines[] = [
                'order_id' => (int)$row['order_id'],
                'number' => (string)$row['number'],
                'invoice_date' => (string)$row['invoice_date'],
                'client' => (string)$row['client'],
                'modality' => (string)$row['modality'],
                'amount' => (float)$row['amount'],
                'base' => (float)$row['base'],
                'pct' => (float)$row['pct'],
                'policy' => (string)$row['policy'],
                'commission' => (float)$row['commission'],
            ];
        }
        $toClose[] = [$id, (float)$s['billed'], (float)$s['commission'], $lines];
    }

    if (!$toClose) {
        throw new RuntimeException('Não há comissões em aberto para fechar neste mês.');
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            "INSERT INTO commission_closings
             (user_id,reference_month,invoiced_amount,commission_amount,details_json,status,closed_by,closed_at)
             VALUES (?,?,?,?,?,'fechada',?,NOW())"
        );
        foreach ($toClose as [$id, $billed, $commission, $lines]) {
            $ins->execute([
                $id, $month, $billed, $commission,
                json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (int)$user['id'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    audit($pdo, (int)$user['id'], 'close_month', 'commission_closing', null, [
        'month' => $month, 'people' => array_map(static fn($r) => $r[0], $toClose),
    ]);
    flash('success', 'Mês fechado: ' . count($toClose) . ' fechamento(s) gravado(s).');
    header('HX-Refresh: true');
    exit;
} catch (Throwable $e) {
    nc_fail($e->getMessage(), 400);
}
