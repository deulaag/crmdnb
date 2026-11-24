<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';

$user = nc_boot();
$pdo = db();

$tab = (string)($_GET['status'] ?? 'todos');
if (!in_array($tab, ['todos', 'a_faturar', 'faturados'], true)) {
    $tab = 'todos';
}
$q = trim((string)($_GET['q'] ?? ''));
$sellerFilter = (int)($_GET['vendedor'] ?? 0);
$month = ($_GET['mes'] ?? '') !== '' ? valid_month((string)$_GET['mes']) : '';
$perPage = 50;
$page = max(1, (int)($_GET['p'] ?? 1));

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(o.client_name LIKE ? OR o.number LIKE ?)';
    $params[] = nc_like($q);
    $params[] = nc_like($q);
}
if ($sellerFilter > 0) {
    $where[] = 'o.seller_user_id = ?';
    $params[] = $sellerFilter;
}
if ($month !== '') {
    $where[] = "DATE_FORMAT(o.order_date,'%Y-%m') = ?";
    $params[] = $month;
}
$baseSql = $where ? ' AND ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare('SELECT o.invoiced inv, COUNT(*) n, COALESCE(SUM(o.amount),0) total FROM orders o WHERE 1=1' . $baseSql . ' GROUP BY o.invoiced');
$stmt->execute($params);
$counts = ['a_faturar' => 0, 'faturados' => 0];
$totals = ['a_faturar' => 0.0, 'faturados' => 0.0];
foreach ($stmt->fetchAll() as $r) {
    $key = (int)$r['inv'] === 1 ? 'faturados' : 'a_faturar';
    $counts[$key] = (int)$r['n'];
    $totals[$key] = (float)$r['total'];
}
$counts['todos'] = $counts['a_faturar'] + $counts['faturados'];

$listSql = $baseSql;
if ($tab === 'a_faturar') {
    $listSql .= ' AND o.invoiced = 0';
} elseif ($tab === 'faturados') {
    $listSql .= ' AND o.invoiced = 1';
}
$total = $counts[$tab];
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT o.*, pl.name line_name, u.name seller_name
     FROM orders o
     JOIN product_lines pl ON pl.id = o.product_line_id
     LEFT JOIN users u ON u.id = o.seller_user_id
     WHERE 1=1$listSql
     ORDER BY o.order_date DESC, o.id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$users = nc_users($pdo);
$tabs = ['todos' => 'Todos', 'a_faturar' => 'A faturar', 'faturados' => 'Faturados'];

nc_header('Pedidos', 'pedidos');
$newUrl = nc_url('acoes/pedido-form.php');
nc_page_header(
    $counts['todos'] . ' pedidos · ' . nc_money_short($totals['a_faturar']) . ' a faturar · ' . nc_money_short($totals['faturados']) . ' faturados',
    'Pedidos',
    '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e($newUrl) . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">+ Novo pedido</button>'
);
?>
<form method="get" class="nc-toolbar" role="search">
    <?php if ($tab !== 'todos'): ?><input type="hidden" name="status" value="<?= e($tab) ?>"><?php endif; ?>
    <label class="nc-sr-only" for="f-q">Buscar</label>
    <input class="nc-input nc-grow" id="f-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por cliente ou nº do pedido">
    <label class="nc-sr-only" for="f-seller">Vendedor</label>
    <select class="nc-input nc-auto" id="f-seller" name="vendedor" data-nc-autosubmit>
        <option value="">Todos os vendedores</option>
        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $sellerFilter === (int)$u['id'] ? ' selected' : '' ?>><?= e((string)$u['name']) ?></option><?php endforeach; ?>
    </select>
    <label class="nc-sr-only" for="f-month">Mês</label>
    <input class="nc-input nc-auto" id="f-month" type="month" name="mes" value="<?= e($month) ?>" data-nc-autosubmit>
    <button class="nc-btn nc-btn-secondary" type="submit">Filtrar</button>
</form>

<div class="nc-tabs" role="tablist" aria-label="Situação do faturamento">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="nc-tab<?= $tab === $key ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>"
           href="<?= e(nc_query_url(['status' => $key === 'todos' ? null : $key, 'p' => null])) ?>"><?= e($label) ?> <span class="nc-tab-count"><?= (int)$counts[$key] ?></span></a>
    <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
    <?php nc_empty('Nenhum pedido encontrado', 'Ajuste os filtros ou registre um novo pedido.',
        '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e($newUrl) . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">+ Novo pedido</button>'); ?>
<?php else: ?>
    <div class="nc-table-wrap">
        <table class="nc-table">
            <thead>
                <tr><th>Nº</th><th>Data</th><th>Cliente</th><th>Linha</th><th>Modalidade</th><th class="is-num">Valor</th><th class="is-num">Comissão DNB</th><th class="is-num">Margem</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $url = nc_url('pedido.php?id=' . (int)$r['id']); ?>
                <tr>
                    <td class="nc-num-left"><a href="<?= e($url) ?>" hx-get="<?= e($url) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-push-url="true"><?= e((string)$r['number']) ?></a></td>
                    <td><?= e(nc_date((string)$r['order_date'])) ?></td>
                    <td><strong><?= e((string)$r['client_name']) ?></strong><br><small class="nc-muted"><?= e((string)($r['seller_name'] ?: '—')) ?></small></td>
                    <td><?= e((string)$r['line_name']) ?></td>
                    <td><?= nc_badge($r['modality'] === 'agenciada' ? 'Agenciada' : 'Revenda', $r['modality'] === 'agenciada' ? 'info' : 'neutral') ?></td>
                    <td class="is-num"><?= e(money($r['amount'])) ?></td>
                    <td class="is-num"><?= e(money($r['dnb_commission'])) ?></td>
                    <td class="is-num"><?= e(money($r['margin_amount'])) ?></td>
                    <td>
                        <?php if ((int)$r['invoiced'] === 1): ?>
                            <?= nc_badge('Faturado', 'success') ?> <small class="nc-muted"><?= e(nc_date((string)$r['invoice_date'])) ?></small>
                        <?php else: ?>
                            <form class="nc-inline-fields nc-row-form" method="post" hx-post="<?= e(nc_url('acoes/pedido-faturar.php')) ?>" hx-swap="none">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <label class="nc-sr-only" for="inv-<?= (int)$r['id'] ?>">Data de faturamento do pedido <?= e((string)$r['number']) ?></label>
                                <input class="nc-input" id="inv-<?= (int)$r['id'] ?>" type="date" name="invoice_date" value="<?= date('Y-m-d') ?>" required>
                                <button class="nc-btn nc-btn-secondary" type="submit">Faturar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php nc_pagination($page, $pages); ?>
<?php endif;

nc_footer();
