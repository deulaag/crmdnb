<?php
declare(strict_types=1);

/**
 * Cálculo de comissões — copiado de comissoes.php (raiz). Mesma fórmula, sem reinterpretar.
 * V2: metas vêm de sales_targets; o mês fechado é exibido a partir do snapshot (commission_closings).
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
 * Base e percentual de UM pedido, para o extrato. A comissão é base × % — igual a nc_commission_base()
 * aplicada ao pedido isolado, então a soma do extrato bate com o total da pessoa.
 * @return array{0:float,1:float,2:float} [base, pct, comissão]
 */
function nc_commission_order_detail(array $policy, array $order): array
{
    $key = (string)($policy['policy_key'] ?? 'sem_politica');
    $p = $policy['params'] ?? [];
    [$revenda, $dnb, $margin] = nc_commission_order_parts($order);
    $agency = ($order['modality'] ?? 'revenda') === 'agenciada';

    if ($key === 'representante') {
        $base = $agency ? $dnb : $margin;
        $pct = (float)($p[$agency ? 'pctComDNB' : 'pctMargem'] ?? 0);
    } elseif ($key === 'consultor_senior') {
        $base = $agency ? $dnb : $revenda;
        $pct = $agency ? (float)($p['pctAgenciada'] ?? 0) : 0.0;
    } else {
        $base = $agency ? $dnb : $revenda;
        $pct = (float)($p[$agency ? 'pctAgenciada' : 'pctPecasServ'] ?? 0);
    }
    return [$base, $pct, $base * $pct / 100];
}

/** Rótulo do status de um fechamento. */
function nc_closing_label(?string $status): string
{
    return match ($status) {
        'paga' => 'Paga',
        'fechada' => 'Fechada',
        default => 'Em aberto',
    };
}

/**
 * Apuração do mês, por pessoa e por pedido.
 *
 * Pessoa com fechamento no mês → valores do snapshot (details_json); sem fechamento → cálculo em aberto.
 *
 * summary[id]: orders, billed (faturado), commission, target (meta do mês), closing_status (null|fechada|paga),
 *              closing_id, paid_at, from_snapshot, agenciado, dnb_commission
 * statement[]: linhas planas (person_id, person, order_id, number, invoice_date, client, modality, amount,
 *              base, pct, policy, commission, status)
 */
function nc_commission_report(PDO $pdo, string $month): array
{
    ensure_commission_policy_schema($pdo);
    $definitions = commission_policy_definitions();
    $hasTargets = nc_table_exists($pdo, 'sales_targets');
    $hasClosings = nc_table_exists($pdo, 'commission_closings');
    [$year, $mon] = array_map('intval', explode('-', valid_month($month)));

    $people = $pdo->query('SELECT id,name,email,role,active FROM users ORDER BY active DESC,name')->fetchAll();
    $policies = [];
    foreach ($people as $person) {
        $policies[(int)$person['id']] = user_commission_policy($pdo, (int)$person['id']);
    }

    $closings = [];
    if ($hasClosings) {
        $q = $pdo->prepare('SELECT * FROM commission_closings WHERE reference_month = ?');
        $q->execute([$month]);
        foreach ($q->fetchAll() as $c) {
            $closings[(int)$c['user_id']] = $c;
        }
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

    $teamBilled = 0.0;
    foreach ($orders as $order) {
        $teamBilled += (float)$order['amount'];
    }

    $summary = [];
    $statement = [];
    $payable = 0.0;

    foreach ($people as $person) {
        $id = (int)$person['id'];
        $policy = $policies[$id];
        $policyLabel = (string)($definitions[$policy['policy_key']]['label'] ?? $definitions['sem_politica']['label']);
        $target = $hasTargets ? nc_target($pdo, $id, $year, $mon) : null;
        $closing = $closings[$id] ?? null;
        $hasPolicy = (string)$policy['policy_key'] !== 'sem_politica';

        if ($closing) {
            $lines = json_decode((string)($closing['details_json'] ?? '[]'), true);
            $lines = is_array($lines) ? $lines : [];
            foreach ($lines as $line) {
                $statement[] = array_merge($line, [
                    'person_id' => $id,
                    'person' => (string)$person['name'],
                    'status' => (string)$closing['status'],
                ]);
            }
            $summary[$id] = [
                'orders' => count($lines),
                'billed' => (float)$closing['invoiced_amount'],
                'commission' => (float)$closing['commission_amount'],
                'target' => $target,
                'closing_status' => (string)$closing['status'],
                'closing_id' => (int)$closing['id'],
                'paid_at' => $closing['paid_at'],
                'from_snapshot' => true,
                'has_policy' => $hasPolicy,
                'agenciado' => 0.0,
                'dnb_commission' => 0.0,
            ];
            if ($closing['status'] !== 'paga') {
                $payable += (float)$closing['commission_amount'];
            }
            continue;
        }

        $revenda = 0.0; $agenciado = 0.0; $dnb = 0.0; $margin = 0.0; $count = 0;
        foreach ($orders as $order) {
            if (!nc_commission_relevant($order, $id, $policy)) {
                continue;
            }
            $count++;
            [$r, $d, $m, $a] = nc_commission_order_parts($order);
            $revenda += $r; $agenciado += $a; $dnb += $d; $margin += $m;
            [$base, $pct, $commission] = nc_commission_order_detail($policy, $order);
            if (!$hasPolicy) {
                continue; // sem política a comissão é sempre 0: fica fora do extrato
            }
            $statement[] = [
                'person_id' => $id,
                'person' => (string)$person['name'],
                'order_id' => (int)$order['id'],
                'number' => (string)$order['number'],
                'invoice_date' => (string)$order['invoice_date'],
                'client' => (string)$order['client_name'],
                'modality' => (string)$order['modality'],
                'amount' => (float)$order['amount'],
                'base' => $base,
                'pct' => $pct,
                'policy' => $policyLabel,
                'commission' => $commission,
                'status' => 'aberta',
            ];
        }

        [, , $total] = nc_commission_base($policy, $revenda, $dnb, $margin);
        $summary[$id] = [
            'orders' => $count,
            'billed' => $revenda + $agenciado,
            'commission' => $total,
            'target' => $target,
            'closing_status' => null,
            'closing_id' => null,
            'paid_at' => null,
            'from_snapshot' => false,
            'has_policy' => $hasPolicy,
            'agenciado' => $agenciado,
            'dnb_commission' => $dnb,
        ];
        $payable += $total;
    }

    return [
        'month' => $month,
        'people' => $people,
        'summary' => $summary,
        'statement' => $statement,
        'policies' => $policies,
        'definitions' => $definitions,
        'has_targets' => $hasTargets,
        'has_closings' => $hasClosings,
        'team_billed' => $teamBilled,
        'payable' => $payable,
    ];
}

/** Representantes exibidos: usuário ativo com política diferente de sem_politica (ou com movimento no mês). */
function nc_commission_reps(array $report): array
{
    $reps = [];
    foreach ($report['people'] as $p) {
        $id = (int)$p['id'];
        $policyKey = (string)($report['policies'][$id]['policy_key'] ?? 'sem_politica');
        $s = $report['summary'][$id];
        $hasPolicy = $policyKey !== 'sem_politica';
        if (((int)$p['active'] === 1 && $hasPolicy) || ($hasPolicy && ($s['orders'] > 0 || $s['from_snapshot']))) {
            $reps[] = $p;
        }
    }
    return $reps;
}

/** Texto curto da política: "X% comissão" quando há um único percentual relevante, senão o nome da política. */
function nc_policy_caption(array $report, int $userId): string
{
    $policy = $report['policies'][$userId] ?? ['policy_key' => 'sem_politica', 'params' => []];
    $def = $report['definitions'][$policy['policy_key']] ?? $report['definitions']['sem_politica'];
    $params = $policy['params'] ?? [];
    $key = (string)$policy['policy_key'];
    $pct = null;
    if ($key === 'representante') {
        $pct = (float)($params['pctMargem'] ?? 0);
    } elseif ($key !== 'consultor_senior' && $key !== 'sem_politica') {
        $pct = (float)($params['pctPecasServ'] ?? 0);
    }
    $label = (string)$def['label'];
    if ($pct !== null && $pct > 0) {
        return $label . ' · ' . rtrim(rtrim(number_format($pct, 2, ',', '.'), '0'), ',') . '% comissão';
    }
    return $label;
}
