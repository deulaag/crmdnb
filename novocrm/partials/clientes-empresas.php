<?php
declare(strict_types=1);

/** Cabeçalho da aba Empresas: "N empresas · N com CNPJ · N sem CNPJ". */
function nc_clientes_empresas_header(array $totals, array $user): void
{
    $semCnpj = $totals['empresas'] - $totals['com_cnpj'];
    $actions = '';
    if (nc_is_admin($user)) {
        $actions .= '<form method="post" action="' . e(nc_url('acoes/rd-sync.php')) . '" class="nc-inline-form">'
            . csrf_field()
            . '<input type="hidden" name="return" value="' . e(nc_url('clientes.php')) . '">'
            . '<button class="nc-btn nc-btn-secondary" type="submit">Sincronizar RD Station</button></form>';
    }
    $actions .= nc_clientes_import_button($user, 'contatos');
    $actions .= '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e(nc_url('acoes/cliente-form.php')) . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">+ Nova empresa</button>';

    nc_page_header(
        $totals['empresas'] . ' empresas · ' . $totals['com_cnpj'] . ' com CNPJ · ' . $semCnpj . ' sem CNPJ',
        'Empresas',
        $actions
    );
}

/** Lista de empresas: status da carteira, busca, contatos, selo "Sem CNPJ" e filtro "Possíveis duplicadas". */
function nc_clientes_empresas(PDO $pdo, array $user): void
{
    $isAdmin = nc_is_admin($user);
    $q = trim((string)($_GET['q'] ?? ''));
    $tab = (string)($_GET['status'] ?? 'todos');
    if (!in_array($tab, ['todos', 'ativo', 'risco', 'inativo', 'duplicadas'], true)) {
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
        LEFT JOIN (SELECT company_id, COUNT(*) qtd FROM contacts WHERE company_id IS NOT NULL GROUP BY company_id) cc ON cc.company_id = co.id
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

    // Contagem por status (respeita a busca) para o seletor.
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

    $groups = nc_company_duplicate_groups($pdo);
    $counts['duplicadas'] = count($groups);

    $labels = ['todos' => 'Todos', 'ativo' => 'Ativos', 'risco' => 'Em risco', 'inativo' => 'Inativos', 'duplicadas' => 'Possíveis duplicadas'];

    if (!$hasPortfolio && $isAdmin): ?>
        <div class="nc-alert nc-alert-warning" role="status">
            A coluna de status da carteira ainda não existe; o status está sendo calculado automaticamente.
            <a href="<?= e(nc_url('migrations/run.php')) ?>">Aplicar migrações</a> para habilitá-la.
        </div>
    <?php endif; ?>

    <form method="get" class="nc-toolbar" role="search">
        <input type="hidden" name="status" value="<?= e($tab) ?>">
        <label class="nc-sr-only" for="nc-q">Buscar empresas</label>
        <input class="nc-input nc-grow" id="nc-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por nome, CNPJ, cidade ou contato">
        <button class="nc-btn nc-btn-secondary" type="submit">Buscar</button>
        <?php if ($q !== ''): ?><a class="nc-btn nc-btn-secondary" href="<?= e(nc_query_url(['q' => null, 'p' => null])) ?>">Limpar</a><?php endif; ?>
        <div class="nc-segmented" role="group" aria-label="Filtro de empresas">
            <?php foreach ($labels as $key => $label): ?>
                <a class="nc-seg<?= $tab === $key ? ' is-active' : '' ?>"<?= $tab === $key ? ' aria-current="true"' : '' ?>
                   href="<?= e(nc_query_url(['status' => $key === 'todos' ? null : $key, 'p' => null])) ?>">
                    <?= e($label) ?> <span class="nc-seg-count"><?= (int)$counts[$key] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </form>
    <?php

    if ($tab === 'duplicadas') {
        nc_clientes_duplicadas($pdo, $groups, $isAdmin);
        return;
    }

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
                COALESCE(op.qtd, 0) opp_qtd, COALESCE(op.total, 0) opp_total, COALESCE(cc.qtd, 0) contact_qtd,
                $lastExpr last_order, $statusExpr portfolio
         $from $listSql
         ORDER BY co.name
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($listParams);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        nc_empty(
            'Nenhuma empresa encontrada',
            $q !== '' ? 'Nenhuma empresa corresponde à busca. Tente outro nome, CNPJ ou cidade.' : 'Cadastre a primeira empresa ou sincronize a RD Station para importar sua carteira.',
            '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e(nc_url('acoes/cliente-form.php')) . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">+ Nova empresa</button>'
        );
        return;
    }
    ?>
    <div class="nc-table-wrap">
        <table class="nc-table">
            <thead>
                <tr>
                    <th>Empresa</th><th>CNPJ</th><th>Cidade / UF</th><th>Segmento</th><th>Responsável</th>
                    <th class="is-num">Contatos</th><th class="is-num">Oport. abertas</th><th>Último pedido</th><th>Carteira</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
                $url = nc_url('cliente.php?id=' . (int)$row['id']);
                $hasCnpj = trim((string)$row['cnpj']) !== ''; ?>
                <tr>
                    <td>
                        <a href="<?= e($url) ?>" hx-get="<?= e($url) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-push-url="true"><strong><?= e($row['name']) ?></strong></a>
                        <?php if ($row['alias']): ?><br><small class="nc-muted"><?= e($row['alias']) ?></small><?php endif; ?>
                    </td>
                    <td class="nc-num-left">
                        <?php if ($hasCnpj): ?><?= e(format_cnpj((string)$row['cnpj'])) ?><?php else: ?><?= nc_badge('Sem CNPJ', 'warning') ?><?php endif; ?>
                    </td>
                    <td><?= e(trim(($row['city'] ?? '') . ($row['state'] ? ' / ' . $row['state'] : '')) ?: '—') ?></td>
                    <td><?= e($row['industry'] ? nc_truncate((string)$row['industry'], 36) : '—') ?></td>
                    <td><?= e($row['owner_name'] ?: '—') ?></td>
                    <td class="is-num"><?= (int)$row['contact_qtd'] ?></td>
                    <td class="is-num"><?= (int)$row['opp_qtd'] > 0 ? (int)$row['opp_qtd'] . ' · ' . e(nc_money_short($row['opp_total'])) : '—' ?></td>
                    <td><?= e(nc_date($row['last_order'])) ?></td>
                    <td><?= nc_portfolio_badge((string)$row['portfolio']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php nc_pagination($page, $pages);
}

/** Filtro "Possíveis duplicadas": grupos por CNPJ ou nome normalizado, com o botão Mesclar (admin). */
function nc_clientes_duplicadas(PDO $pdo, array $groups, bool $isAdmin): void
{
    if (!$groups) {
        nc_empty('Nenhuma duplicada encontrada', 'Não há empresas com o mesmo CNPJ nem com o mesmo nome normalizado.');
        return;
    }

    $ids = [];
    foreach ($groups as $g) {
        foreach ($g['ids'] as $id) {
            $ids[$id] = true;
        }
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    // A coluna import_batch_id só existe após a migração 008.
    $batchCol = nc_column_exists($pdo, 'companies', 'import_batch_id') ? ', co.import_batch_id' : '';
    $stmt = $pdo->prepare(
        "SELECT co.id, co.name, co.cnpj, co.city, co.state, co.source$batchCol,
                (SELECT COUNT(*) FROM contacts ct WHERE ct.company_id = co.id) contact_qtd,
                (SELECT COUNT(*) FROM opportunities o WHERE o.company_id = co.id) opp_qtd
         FROM companies co WHERE co.id IN ($in)"
    );
    $stmt->execute(array_keys($ids));
    $byId = [];
    foreach ($stmt->fetchAll() as $r) {
        $byId[(int)$r['id']] = $r;
    }
    ?>
    <p class="nc-muted"><?= count($groups) ?> grupo<?= count($groups) === 1 ? '' : 's' ?> de possíveis duplicadas. Empresas com CNPJs válidos diferentes (matriz e filial) não podem ser mescladas.</p>
    <div class="nc-dup-list">
    <?php foreach ($groups as $g): ?>
        <section class="nc-card nc-dup-group" aria-label="Grupo de possíveis duplicadas">
            <header class="nc-card-head">
                <h2 class="nc-card-title"><?= e(implode(' + ', $g['reasons'])) ?> · <?= count($g['ids']) ?> cadastros</h2>
                <?php if ($isAdmin && !$g['blocked']): ?>
                    <button type="button" class="nc-btn nc-btn-primary"
                            hx-get="<?= e(nc_url('acoes/empresa-mesclar-form.php?ids=' . implode(',', $g['ids']))) ?>"
                            hx-target="#nc-drawer-body" hx-swap="innerHTML">Mesclar…</button>
                <?php elseif ($g['blocked']): ?>
                    <?= nc_badge('CNPJs diferentes: não mesclável', 'warning') ?>
                <?php endif; ?>
            </header>
            <div class="nc-table-wrap">
                <table class="nc-table">
                    <thead><tr><th>Empresa</th><th>CNPJ</th><th>Cidade / UF</th><th class="is-num">Contatos</th><th class="is-num">Oportunidades</th><th>Origem</th></tr></thead>
                    <tbody>
                    <?php foreach ($g['ids'] as $id): $r = $byId[$id] ?? null; if (!$r) continue; $url = nc_url('cliente.php?id=' . $id); ?>
                        <tr>
                            <td><a href="<?= e($url) ?>" hx-get="<?= e($url) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-push-url="true"><strong><?= e((string)$r['name']) ?></strong></a></td>
                            <td class="nc-num-left"><?= trim((string)$r['cnpj']) !== '' ? e(format_cnpj((string)$r['cnpj'])) : nc_badge('Sem CNPJ', 'warning') ?></td>
                            <td><?= e(trim(($r['city'] ?? '') . ($r['state'] ? ' / ' . $r['state'] : '')) ?: '—') ?></td>
                            <td class="is-num"><?= (int)$r['contact_qtd'] ?></td>
                            <td class="is-num"><?= (int)$r['opp_qtd'] ?></td>
                            <td><?= e(nc_origin_label($r)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
    </div>
    <?php
}
