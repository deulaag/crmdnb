<?php
declare(strict_types=1);

/**
 * Cálculo de comissões — copiado de comissoes.php (raiz). Mesma fórmula, sem reinterpretar.
 */

/** Copiado de comissoes.php: commission_base_for_policy(). */
function nc_commission_base(array $policy, float $revenda, float $dnbCommission, float $margin): array
{
    $key = (string)($policy['policy_key'] ?? 'sem_politica');
    $p = $policy['params'] ?? [];

    if ($key === 'representante') {
        $rev = $margin * ((float)($p['pctMargem'] ?? 0) / 100);
        $ag = $dnbCommission * ((float)($p['pctComDNB'] ?? 0) / 100);
        return [$rev, $ag, $rev + $ag];
    }

    if ($key === 'consultor_senior') {
        $ag = $dnbCommission * ((float)($p['pctAgenciada'] ?? 0) / 100);
        return [0.0, $ag, $ag];
    }

    $rev = $revenda * ((float)($p['pctPecasServ'] ?? 0) / 100);
    $ag = $dnbCommission * ((float)($p['pctAgenciada'] ?? 0) / 100);
    return [$rev, $ag, $rev + $ag];
}

/** O pedido conta para esta pessoa? (mesma regra de comissoes.php) */
function nc_commission_relevant(array $order, int $personId, array $policy): bool
{
    $linked = array_values(array_filter([
        (int)($policy['linked_user_id'] ?? 0),
        (int)($policy['linked_user_id2'] ?? 0),
    ]));

    if (($policy['operation_role'] ?? 'vendedor') === 'suporte') {
        return (int)($order['support_user_id'] ?? 0) === $personId
            || (int)($order['seller_user_id'] ?? 0) === $personId
            || in_array((int)($order['seller_user_id'] ?? 0), $linked, true);
    }
    return (int)($order['seller_user_id'] ?? 0) === $personId;
}

/** Valores de um pedido na ótica da fórmula: [revenda, dnbCommission, margin, agenciado]. */
function nc_commission_order_parts(array $order): array
{
    if (($order['modality'] ?? 'revenda') === 'agenciada') {
        return [0.0, (float)$order['dnb_commission'], 0.0, (float)$order['amount']];
    }
    return [(float)$order['amount'], 0.0, (float)$order['margin_amount'], 0.0];
}

/**
 * Apuração do mês: resumo por pessoa e extrato por pedido.
 * @return array{people:array,summary:array,statement:array,policies:array,definitions:array}
 */
function nc_commission_report(PDO $pdo, string $month): array
{
    ensure_commission_policy_schema($pdo);
    $definitions = commission_policy_definitions();

    $hasTarget = nc_column_exists($pdo, 'users', 'monthly_target');
    $people = $pdo->query('SELECT id,name,email,role,active' . ($hasTarget ? ',monthly_target' : '') . ' FROM users ORDER BY active DESC,name')->fetchAll();
    $policies = [];
    foreach ($people as $person) {
        $policies[(int)$person['id']] = user_commission_policy($pdo, (int)$person['id']);
    }

    $q = $pdo->prepare(
        "SELECT o.*,pl.is_agency,pl.name line_name
         FROM orders o
         JOIN product_lines pl ON pl.id=o.product_line_id
         WHERE o.invoiced=1 AND DATE_FORMAT(o.invoice_date,'%Y-%m')=?
         ORDER BY o.invoice_date,o.id"
    );
    $q->execute([$month]);
    $orders = $q->fetchAll();

    $summary = [];
    $statement = [];
    foreach ($people as $person) {
        $id = (int)$person['id'];
        $policy = $policies[$id];

        $revenda = 0.0; $agenciado = 0.0; $dnb = 0.0; $margin = 0.0; $count = 0;
        foreach ($orders as $order) {
            if (!nc_commission_relevant($order, $id, $policy)) {
                continue;
            }
            $count++;
            [$r, $d, $m, $a] = nc_commission_order_parts($order);
            $revenda += $r; $agenciado += $a; $dnb += $d; $margin += $m;
            [, , $orderBase] = nc_commission_base($policy, $r, $d, $m);
            $statement[] = ['person_id' => $id, 'person' => $person['name'], 'order' => $order, 'commission' => $orderBase];
        }

        [$cRev, $cAg, $base] = nc_commission_base($policy, $revenda, $dnb, $margin);
        $target = $hasTarget && $person['monthly_target'] !== null ? (float)$person['monthly_target'] : null;
        $summary[$id] = [
            'orders' => $count,
            'revenda' => $revenda,
            'agenciado' => $agenciado,
            'dnb_commission' => $dnb,
            'margin' => $margin,
            'commission_revenda' => $cRev,
            'commission_agency' => $cAg,
            'base_total' => $base,
            'billed' => $revenda + $agenciado,
            'target' => $target,
        ];
    }

    return ['people' => $people, 'summary' => $summary, 'statement' => $statement, 'policies' => $policies, 'definitions' => $definitions, 'has_target' => $hasTarget];
}
