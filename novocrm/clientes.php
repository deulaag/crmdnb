<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';

$user = nc_boot();
$pdo = db();
ensure_company_cnpja_schema($pdo);
ensure_rd_crm_schema($pdo);
$isAdmin = nc_is_admin($user);

$q = trim((string)($_GET['q'] ?? ''));
$tab = (string)($_GET['status'] ?? 'todos');
if (!in_array($tab, ['todos', 'ativo', 'risco', 'inativo'], true)) {
    $tab = 'todos';
}
$perPage = 50;
$page = max(1, (int)($_GET['p'] ?? 1));

$hasPortfolio = nc_column_exists($pdo, 'companies', 'portfolio_status');

// Último pedido: por vínculo da oportunidade (company_id) OU pelo nome do cliente (orders.client_name).
$lastExpr = 'COALESCE(GREATEST(l1.d, l2.d), l1.d, l2.d)';
$calc = "CASE WHEN $lastExpr IS NULL THEN 'inativo'
               WHEN $lastExpr >= (CURDATE() - INTERVAL 180 DAY) THEN 'ativo'
               WHEN $lastExpr >= (CURDATE() - INTERVAL 365 DAY) THEN 'risco'
               ELSE 'inativo' END";
$statusExpr = $hasPortfolio ? "COALESCE(NULLIF(co.portfolio_status, ''), $calc)" : $calc;

$from = "FROM companies co
    LEFT JOIN users ou ON ou.id = co.owner_user_id
    LEFT JOIN (SELECT company_id, COUNT(*) qtd, COALESCE(SUM(amount),0) total
               FROM opportunities WHERE status='Aberta' AND company_id IS NOT NULL GROUP BY company_id) op ON op.company_id = co.id
    LEFT JOIN (SELECT o2.company_id cid, MAX(ord.order_date) d
               FROM orders ord JOIN opportunities o2 ON o2.id = ord.opportunity_id
               WHERE o2.company_id IS NOT NULL GROUP BY o2.company_id) l1 ON l1.cid = co.id
    LEFT JOIN (SELECT LOWER(TRIM(client_name)) nm, MAX(order_date) d
               FROM orders GROUP BY LOWER(TRIM(client_name))) l2 ON l2.nm = LOWER(TRIM(co.name))";

$where = [];
$params = [];
if ($q !== '') {
    $digits = cnpj_digits($q);
    $like = nc_like($q);
    $parts = ['co.name LIKE :q0', 'co.alias LIKE :q1', 'co.city LIKE :q2',
        'EXISTS (SELECT 1 FROM contacts ct WHERE ct.company_id = co.id AND (ct.name LIKE :q3 OR ct.email LIKE :q4 OR ct.phone LIKE :q5))'];
    foreach (range(0, 5) as $i) {
        $params['q' . $i] = $like;
    }
    if ($digits !== '') {
        $parts[] = 'co.cnpj LIKE :cnpj';
        $params['cnpj'] = nc_like($digits);
    }
    $where[] = '(' . implode(' OR ', $parts) . ')';
}
$searchSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Contagem por status (respeita a busca) para as abas.
$stmt = $pdo->prepare("SELECT $statusExpr s, COUNT(*) n $from $searchSql GROUP BY s");
$stmt->execute($params);
$counts = ['ativo' => 0, 'risco' => 0, 'inativo' => 0];
foreach ($stmt->fetchAll() as $r) {
    $key = (string)$r['s'];
    if (isset($counts[$key])) {
        $counts[$key] += (int)$r['n'];
    }
}
$counts['todos'] = array_sum($counts);

$listWhere = $where;
$listParams = $params;
if ($tab !== 'todos') {
    $listWhere[] = "$statusExpr = :status";
    $listParams['status'] = $tab;
}
$listSql = $listWhere ? 'WHERE ' . implode(' AND ', $listWhere) : '';

$total = $counts[$tab];
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT co.id, co.name, co.alias, co.cnpj, co.city, co.state, co.industry, ou.name owner_name,
            COALESCE(op.qtd, 0) opp_qtd, COALESCE(op.total, 0) opp_total,
            $lastExpr last_order, $statusExpr portfolio
     $from $listSql
     ORDER BY co.name
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($listParams);
$rows = $stmt->fetchAll();

$tabs = ['todos' => 'Todos', 'ativo' => 'Ativos', 'risco' => 'Em risco', 'inativo' => 'Inativos'];

nc_header('Clientes', 'clientes');

$actions = '';
if ($isAdmin) {
    $actions .= '<form method="post" action="' . e(nc_url('acoes/rd-sync.php')) . '" class="nc-inline-form">'
        . csrf_field()
        . '<input type="hidden" name="return" value="' . e(nc_url('clientes.php')) . '">'
        . '<button class="nc-btn nc-btn-secondary" type="submit">Sincronizar RD Station</button></form>';
}
$actions .= '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e(nc_url('acoes/cliente-form.php')) . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">+ Novo cliente</button>';

nc_page_header(
    $counts['todos'] . ' clientes · ' . $counts['ativo'] . ' ativos · ' . $counts['risco'] . ' em risco',
    'Clientes',
    $actions
);

if (!$hasPortfolio && $isAdmin): ?>
    <div class="nc-alert nc-alert-warning" role="status">
        A coluna de status da carteira ainda não existe; o status está sendo calculado automaticamente.
        <a href="<?= e(nc_url('migrations/run.php')) ?>">Aplicar migrações</a> para habilitá-la.
    </div>
<?php endif; ?>

<form method="get" class="nc-toolbar" role="search">
    <input type="hidden" name="status" value="<?= e($tab) ?>">
    <label class="nc-sr-only" for="nc-q">Buscar clientes</label>
    <input class="nc-input nc-grow" id="nc-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por nome, CNPJ, cidade ou contato">
    <button class="nc-btn nc-btn-secondary" type="submit">Buscar</button>
    <?php if ($q !== ''): ?><a class="nc-btn nc-btn-secondary" href="<?= e(nc_query_url(['q' => null, 'p' => null])) ?>">Limpar</a><?php endif; ?>
</form>

<div class="nc-tabs" role="tablist" aria-label="Status da carteira">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="nc-tab<?= $tab === $key ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>"
           href="<?= e(nc_query_url(['status' => $key === 'todos' ? null : $key, 'p' => null])) ?>">
            <?= e($label) ?> <span class="nc-tab-count"><?= (int)$counts[$key] ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
    <?php nc_empty(
        'Nenhum cliente encontrado',
        $q !== '' ? 'Nenhum cliente corresponde à busca. Tente outro nome, CNPJ ou cidade.' : 'Cadastre o primeiro cliente ou sincronize a RD Station para importar sua carteira.',
        '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e(nc_url('acoes/cliente-form.php')) . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">+ Novo cliente</button>'
    ); ?>
<?php else: ?>
    <div class="nc-table-wrap">
        <table class="nc-table">
            <thead>
                <tr>
                    <th>Empresa</th><th>CNPJ</th><th>Cidade / UF</th><th>Segmento</th><th>Responsável</th>
                    <th class="is-num">Oport. abertas</th><th>Último pedido</th><th>Carteira</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
                $url = nc_url('cliente.php?id=' . (int)$row['id']); ?>
                <tr>
                    <td>
                        <a href="<?= e($url) ?>" hx-get="<?= e($url) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-push-url="true"><strong><?= e($row['name']) ?></strong></a>
                        <?php if ($row['alias']): ?><br><small class="nc-muted"><?= e($row['alias']) ?></small><?php endif; ?>
                    </td>
                    <td class="nc-num-left"><?= $row['cnpj'] ? e(format_cnpj((string)$row['cnpj'])) : '—' ?></td>
                    <td><?= e(trim(($row['city'] ?? '') . ($row['state'] ? ' / ' . $row['state'] : '')) ?: '—') ?></td>
                    <td><?= e($row['industry'] ? nc_truncate((string)$row['industry'], 36) : '—') ?></td>
                    <td><?= e($row['owner_name'] ?: '—') ?></td>
                    <td class="is-num"><?= (int)$row['opp_qtd'] > 0 ? (int)$row['opp_qtd'] . ' · ' . e(nc_money_short($row['opp_total'])) : '—' ?></td>
                    <td><?= e(nc_date($row['last_order'])) ?></td>
                    <td><?= nc_portfolio_badge((string)$row['portfolio']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php nc_pagination($page, $pages); ?>
<?php endif;

nc_footer();
