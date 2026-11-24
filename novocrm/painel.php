<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';

$user = nc_boot();
$pdo = db();
ensure_opportunity_visit_schema($pdo);
ensure_opportunity_tracking_schema($pdo);
ensure_commercial_automation_schema($pdo);

$period = nc_period();
[$sellerId, $canPickSeller] = nc_seller_scope($user);
$sellers = $canPickSeller ? nc_users($pdo) : [];

/** Acrescenta o filtro de vendedor a um WHERE e aos parâmetros. */
$scope = static function (string $column, array &$params) use ($sellerId): string {
    if ($sellerId === null) {
        return '';
    }
    $params[] = $sellerId;
    return " AND $column = ?";
};

// --- KPIs (mesmas consultas do painel.php da raiz, com escopo de vendedor) ---
$p = [$period['from'], $period['to']];
$sql = 'SELECT COUNT(*) qtd, COALESCE(SUM(amount),0) total FROM opportunities WHERE opportunity_date BETWEEN ? AND ?' . $scope('seller_user_id', $p);
$q = $pdo->prepare($sql); $q->execute($p); $opps = $q->fetch();

$p = [$period['from'], $period['to']];
$sql = "SELECT COUNT(*) qtd, COALESCE(SUM(amount),0) total FROM opportunities WHERE status='Aberta' AND opportunity_date BETWEEN ? AND ?" . $scope('seller_user_id', $p);
$q = $pdo->prepare($sql); $q->execute($p); $open = $q->fetch();

$p = [$period['from'], $period['to']];
$sql = 'SELECT COUNT(*) qtd, COALESCE(SUM(amount),0) total FROM orders WHERE invoiced=1 AND invoice_date BETWEEN ? AND ?' . $scope('seller_user_id', $p);
$q = $pdo->prepare($sql); $q->execute($p); $invoiced = $q->fetch();

$p = [$period['prev_from'], $period['prev_to']];
$sql = 'SELECT COALESCE(SUM(amount),0) FROM orders WHERE invoiced=1 AND invoice_date BETWEEN ? AND ?' . $scope('seller_user_id', $p);
$q = $pdo->prepare($sql); $q->execute($p); $invoicedPrev = (float)$q->fetchColumn();

$p = [$period['from'], $period['to']];
$sql = 'SELECT COUNT(*) FROM visits WHERE visit_date BETWEEN ? AND ?' . $scope('seller_user_id', $p);
$q = $pdo->prepare($sql); $q->execute($p); $visits = (int)$q->fetchColumn();

// --- Faturamento por semana: período atual × anterior ---
$buckets = (int)ceil($period['days'] / 7);
$labels = [];
for ($i = 1; $i <= $buckets; $i++) {
    $labels[] = 'S' . $i;
}
$cur = array_fill(0, $buckets, 0.0);
$prev = array_fill(0, $buckets, 0.0);
$p = [$period['prev_from'], $period['to']];
$sql = 'SELECT invoice_date, amount FROM orders WHERE invoiced=1 AND invoice_date BETWEEN ? AND ?' . $scope('seller_user_id', $p);
$q = $pdo->prepare($sql); $q->execute($p);
$curFrom = new DateTimeImmutable($period['from']);
$prevFrom = new DateTimeImmutable($period['prev_from']);
$curTo = new DateTimeImmutable($period['to']);
foreach ($q->fetchAll() as $row) {
    $d = new DateTimeImmutable((string)$row['invoice_date']);
    if ($d >= $curFrom && $d <= $curTo) {
        $idx = min($buckets - 1, intdiv((int)$curFrom->diff($d)->days, 7));
        $cur[$idx] += (float)$row['amount'];
    } elseif ($d >= $prevFrom && $d < $curFrom) {
        $idx = min($buckets - 1, intdiv((int)$prevFrom->diff($d)->days, 7));
        $prev[$idx] += (float)$row['amount'];
    }
}
$chartSeries = [
    ['name' => 'Período atual', 'class' => 'is-current', 'values' => $cur],
    ['name' => 'Período anterior', 'class' => 'is-previous', 'values' => $prev],
];

// --- Pipeline aberto por linha de produto ---
$p = [$period['from'], $period['to']];
$sql = "SELECT pl.name, pl.is_agency, COUNT(o.id) qtd, COALESCE(SUM(o.amount),0) total
        FROM opportunities o JOIN product_lines pl ON pl.id=o.product_line_id
        WHERE o.status='Aberta' AND o.opportunity_date BETWEEN ? AND ?" . $scope('o.seller_user_id', $p) . '
        GROUP BY pl.id ORDER BY total DESC, pl.sort_order';
$q = $pdo->prepare($sql); $q->execute($p); $byLine = $q->fetchAll();
$maxLine = 0.0;
foreach ($byLine as $row) {
    $maxLine = max($maxLine, (float)$row['total']);
}

// --- Funis (aberto, como na raiz) com barra por etapa ---
$p = [];
$sql = "SELECT f.id, f.label, f.description, fs.id stage_id, fs.name stage_name,
               COUNT(o.id) qtd, COALESCE(SUM(o.amount),0) total
        FROM funnels f
        JOIN funnel_stages fs ON fs.funnel_id=f.id
        LEFT JOIN opportunities o ON o.stage_id=fs.id AND o.status='Aberta'" . ($sellerId !== null ? ' AND o.seller_user_id = ?' : '') . '
        WHERE f.active=1
        GROUP BY f.id, fs.id ORDER BY f.sort_order, fs.sort_order';
if ($sellerId !== null) {
    $p[] = $sellerId;
}
$q = $pdo->prepare($sql); $q->execute($p);
$funnels = [];
foreach ($q->fetchAll() as $row) {
    $fid = (int)$row['id'];
    $funnels[$fid]['label'] = $row['label'];
    $funnels[$fid]['description'] = $row['description'];
    $funnels[$fid]['stages'][] = $row;
    $funnels[$fid]['qtd'] = ($funnels[$fid]['qtd'] ?? 0) + (int)$row['qtd'];
    $funnels[$fid]['total'] = ($funnels[$fid]['total'] ?? 0) + (float)$row['total'];
}

// --- Últimas oportunidades e próximos compromissos ---
$p = [$period['from'], $period['to']];
$sql = 'SELECT o.id,o.number,o.opportunity_date,o.client_name,o.amount,o.status,pl.name line_name,u.name seller_name
        FROM opportunities o JOIN product_lines pl ON pl.id=o.product_line_id LEFT JOIN users u ON u.id=o.seller_user_id
        WHERE o.opportunity_date BETWEEN ? AND ?' . $scope('o.seller_user_id', $p) . ' ORDER BY o.id DESC LIMIT 8';
$q = $pdo->prepare($sql); $q->execute($p); $recent = $q->fetchAll();

$p = [];
$sql = "SELECT a.id,a.event_date,a.event_time,a.client_name,a.objective,a.status,a.event_type
        FROM agenda a WHERE a.status IN ('sugerido','agendado','confirmado') AND a.event_date >= CURDATE()"
        . ($sellerId !== null ? ' AND a.user_id = ?' : '') . ' ORDER BY a.event_date, a.event_time LIMIT 6';
if ($sellerId !== null) {
    $p[] = $sellerId;
}
$q = $pdo->prepare($sql); $q->execute($p); $agenda = $q->fetchAll();

$delta = null;
if ($invoicedPrev > 0) {
    $delta = ((float)$invoiced['total'] - $invoicedPrev) / $invoicedPrev * 100;
}

nc_header('Painel', 'painel');

$sub = $opps['qtd'] . ' oportunidades no período · ' . nc_money_short($open['total']) . ' em negociação';
nc_page_header($sub, 'Painel', '<a class="nc-btn nc-btn-primary" href="' . e(nc_url('oportunidades.php?novo=1')) . '">+ Nova oportunidade</a>');
?>
<div class="nc-filterbar">
    <div class="nc-segmented" role="group" aria-label="Período">
        <a class="nc-seg<?= $period['key'] === 'mes' ? ' is-active' : '' ?>" href="<?= e(nc_query_url(['periodo' => null, 'mes' => null])) ?>"<?= $period['key'] === 'mes' ? ' aria-current="true"' : '' ?>>Mês atual</a>
        <a class="nc-seg<?= $period['key'] === '30' ? ' is-active' : '' ?>" href="<?= e(nc_query_url(['periodo' => '30', 'mes' => null])) ?>"<?= $period['key'] === '30' ? ' aria-current="true"' : '' ?>>30 dias</a>
        <a class="nc-seg<?= $period['key'] === '90' ? ' is-active' : '' ?>" href="<?= e(nc_query_url(['periodo' => '90', 'mes' => null])) ?>"<?= $period['key'] === '90' ? ' aria-current="true"' : '' ?>>90 dias</a>
    </div>
    <form method="get" class="nc-inline-field">
        <?php if ($sellerId !== null && $canPickSeller): ?><input type="hidden" name="vendedor" value="<?= $sellerId ?>"><?php endif; ?>
        <label for="nc-mes">Mês</label>
        <input class="nc-input" id="nc-mes" type="month" name="mes" value="<?= e($period['month']) ?>" data-nc-autosubmit>
    </form>
    <?php if ($canPickSeller): ?>
        <form method="get" class="nc-inline-field">
            <?php if ($period['key'] === '30' || $period['key'] === '90'): ?><input type="hidden" name="periodo" value="<?= e($period['key']) ?>">
            <?php elseif ($period['key'] === 'custom'): ?><input type="hidden" name="mes" value="<?= e($period['month']) ?>"><?php endif; ?>
            <label for="nc-vendedor">Vendedor</label>
            <select class="nc-input" id="nc-vendedor" name="vendedor" data-nc-autosubmit>
                <option value="">Todos</option>
                <?php foreach ($sellers as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"<?= $sellerId === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
    <span class="nc-filterbar-note"><?= e($period['label']) ?> · <?= e(nc_date($period['from'])) ?> a <?= e(nc_date($period['to'])) ?></span>
</div>

<div class="nc-kpis">
    <article class="nc-kpi">
        <div class="nc-kpi-label">Oportunidades no período</div>
        <div class="nc-kpi-value"><?= (int)$opps['qtd'] ?></div>
        <div class="nc-kpi-sub"><?= e(money($opps['total'])) ?> em volume</div>
    </article>
    <article class="nc-kpi">
        <div class="nc-kpi-label">Pipeline aberto</div>
        <div class="nc-kpi-value"><?= (int)$open['qtd'] ?></div>
        <div class="nc-kpi-sub"><?= e(money($open['total'])) ?> em negociação</div>
    </article>
    <article class="nc-kpi">
        <div class="nc-kpi-label">Faturado</div>
        <div class="nc-kpi-value"><?= e(money($invoiced['total'])) ?></div>
        <div class="nc-kpi-sub">
            <?= (int)$invoiced['qtd'] ?> pedido(s)
            <?php if ($delta !== null): ?>
                · <?= $delta >= 0 ? '▲' : '▼' ?> <?= e(number_format(abs($delta), 1, ',', '.')) ?>% vs. período anterior
            <?php endif; ?>
        </div>
    </article>
    <article class="nc-kpi">
        <div class="nc-kpi-label">Visitas</div>
        <div class="nc-kpi-value"><?= $visits ?></div>
        <div class="nc-kpi-sub">registradas no período</div>
    </article>
</div>

<div class="nc-grid-2">
    <section class="nc-card" aria-labelledby="h-fat">
        <h2 class="nc-card-title" id="h-fat">Faturamento por semana</h2>
        <div class="nc-legend">
            <span><i class="nc-swatch is-current"></i> Período atual</span>
            <span><i class="nc-swatch is-previous"></i> Período anterior</span>
        </div>
        <?php if ((float)$invoiced['total'] <= 0 && $invoicedPrev <= 0): ?>
            <p class="nc-muted">Nenhum pedido faturado neste período nem no anterior.</p>
        <?php else: ?>
            <?= nc_svg_line($labels, $chartSeries, 'Faturamento por semana, período atual comparado ao anterior') ?>
            <?= nc_chart_table($labels, $chartSeries, 'Faturamento por semana') ?>
        <?php endif; ?>
    </section>

    <section class="nc-card" aria-labelledby="h-line">
        <h2 class="nc-card-title" id="h-line">Pipeline aberto por linha de produto</h2>
        <?php if (!$byLine): ?>
            <p class="nc-muted">Nenhuma oportunidade aberta no período.</p>
        <?php else: ?>
            <ul class="nc-bars">
                <?php foreach ($byLine as $row): $pct = $maxLine > 0 ? max(2, (float)$row['total'] / $maxLine * 100) : 0; ?>
                    <li>
                        <div class="nc-bar-head">
                            <span><?= e($row['name']) ?> <?= nc_badge((int)$row['is_agency'] === 1 ? 'Agenciado' : 'Revenda', (int)$row['is_agency'] === 1 ? 'info' : 'neutral') ?></span>
                            <strong class="nc-num"><?= e(money($row['total'])) ?></strong>
                        </div>
                        <div class="nc-progress" role="img" aria-label="<?= e($row['name'] . ': ' . money($row['total'])) ?>"><span style="width:<?= round($pct, 1) ?>%"></span></div>
                        <small class="nc-muted"><?= (int)$row['qtd'] ?> oportunidade(s)</small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<section class="nc-card nc-section" aria-labelledby="h-funis">
    <div class="nc-card-head">
        <h2 class="nc-card-title" id="h-funis">Funis</h2>
        <a class="nc-btn nc-btn-secondary" href="<?= e(nc_url('oportunidades.php')) ?>">Abrir kanban</a>
    </div>
    <div class="nc-funnels">
        <?php foreach ($funnels as $f): $maxQ = 0; foreach ($f['stages'] as $st) { $maxQ = max($maxQ, (int)$st['qtd']); } ?>
            <div class="nc-funnel">
                <h3><?= e($f['label']) ?></h3>
                <p class="nc-muted"><?= (int)$f['qtd'] ?> abertas · <?= e(money($f['total'])) ?></p>
                <ul class="nc-stagelist">
                    <?php foreach ($f['stages'] as $st): $pct = $maxQ > 0 ? (int)$st['qtd'] / $maxQ * 100 : 0; ?>
                        <li>
                            <div class="nc-bar-head"><span><?= e($st['stage_name']) ?></span><span class="nc-num"><?= (int)$st['qtd'] ?> · <?= e(nc_money_short($st['total'])) ?></span></div>
                            <div class="nc-progress" role="img" aria-label="<?= e($st['stage_name'] . ': ' . (int)$st['qtd'] . ' oportunidade(s), ' . money($st['total'])) ?>"><span style="width:<?= round($pct, 1) ?>%"></span></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
        <?php if (!$funnels): ?><p class="nc-muted">Nenhum funil ativo.</p><?php endif; ?>
    </div>
</section>

<div class="nc-grid-2">
    <section class="nc-card" aria-labelledby="h-ult">
        <h2 class="nc-card-title" id="h-ult">Últimas oportunidades</h2>
        <?php if (!$recent): ?>
            <?php nc_empty('Sem oportunidades no período', 'Cadastre a primeira oportunidade para ver o movimento aqui.'); ?>
        <?php else: ?>
            <div class="nc-table-wrap nc-flat">
                <table class="nc-table">
                    <thead><tr><th>Nº</th><th>Cliente</th><th class="is-num">Valor</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $row): ?>
                        <tr>
                            <td class="nc-num-left"><?= e($row['number']) ?></td>
                            <td><strong><?= e($row['client_name']) ?></strong><br><small class="nc-muted"><?= e($row['line_name']) ?> · <?= e($row['seller_name'] ?: '—') ?></small></td>
                            <td class="is-num"><?= e(money($row['amount'])) ?></td>
                            <td><?= nc_status_badge((string)$row['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="nc-card" aria-labelledby="h-agenda">
        <h2 class="nc-card-title" id="h-agenda">Próximos compromissos</h2>
        <?php if (!$agenda): ?>
            <?php nc_empty('Agenda livre', 'Nenhum compromisso agendado, sugerido ou confirmado pela frente.', '<a class="nc-btn nc-btn-secondary" href="/cronograma.php">Abrir cronograma</a>'); ?>
        <?php else: ?>
            <ul class="nc-agenda">
                <?php foreach ($agenda as $a): ?>
                    <li>
                        <div class="nc-agenda-date"><strong><?= e(substr(nc_date($a['event_date']), 0, 5)) ?></strong><small><?= e($a['event_time'] ? substr((string)$a['event_time'], 0, 5) : 'dia todo') ?></small></div>
                        <div class="nc-agenda-body">
                            <strong><?= e($a['client_name']) ?></strong>
                            <span class="nc-muted"><?= e(nc_truncate((string)$a['objective'], 90)) ?></span>
                        </div>
                        <?= nc_badge(ucfirst((string)$a['status']), $a['status'] === 'confirmado' ? 'success' : ($a['status'] === 'sugerido' ? 'warning' : 'info')) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
<?php
nc_footer();
