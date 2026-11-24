<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/partials/oportunidade.php';

$user = nc_boot();
$pdo = db();
ensure_opportunity_visit_schema($pdo);
ensure_opportunity_tracking_schema($pdo);

$view = ($_GET['vista'] ?? 'kanban') === 'lista' ? 'lista' : 'kanban';
$funnels = $pdo->query('SELECT id,label FROM funnels WHERE active=1 ORDER BY sort_order')->fetchAll();
$funnelIds = array_map(static fn($f) => (int)$f['id'], $funnels);

$funnelId = (int)($_GET['funil'] ?? ($view === 'kanban' ? ($funnelIds[0] ?? 0) : 0));
if ($funnelId !== 0 && !in_array($funnelId, $funnelIds, true)) {
    $funnelId = $view === 'kanban' ? ($funnelIds[0] ?? 0) : 0;
}
if ($view === 'kanban' && $funnelId === 0) {
    $funnelId = $funnelIds[0] ?? 0;
}

$sellerFilter = (int)($_GET['vendedor'] ?? 0);
$lineFilter = (int)($_GET['linha'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$month = ($_GET['mes'] ?? '') !== '' ? valid_month((string)$_GET['mes']) : '';
$tab = (string)($_GET['status'] ?? 'todas');
if (!in_array($tab, ['todas', 'Aberta', 'Ganha', 'Perdida'], true)) {
    $tab = 'todas';
}
$perPage = 50;
$page = max(1, (int)($_GET['p'] ?? 1));

$users = nc_users($pdo);
$lines = $pdo->query('SELECT id,name,funnel_key FROM product_lines WHERE active=1 ORDER BY sort_order')->fetchAll();
$hasPosition = nc_column_exists($pdo, 'opportunities', 'position');

// Filtros comuns às duas visões (sem status).
$where = [];
$params = [];
if ($funnelId > 0) {
    $where[] = 'o.funnel_id = ?';
    $params[] = $funnelId;
}
if ($sellerFilter > 0) {
    $where[] = 'o.seller_user_id = ?';
    $params[] = $sellerFilter;
}
if ($view === 'lista') {
    if ($lineFilter > 0) {
        $where[] = 'o.product_line_id = ?';
        $params[] = $lineFilter;
    }
    if ($month !== '') {
        $where[] = "DATE_FORMAT(o.opportunity_date,'%Y-%m') = ?";
        $params[] = $month;
    }
}
if ($q !== '') {
    $where[] = '(o.client_name LIKE ? OR o.number LIKE ?)';
    $params[] = nc_like($q);
    $params[] = nc_like($q);
}
$baseSql = $where ? ' AND ' . implode(' AND ', $where) : '';

// Contexto do cabeçalho: abertas em todos os funis (respeitando o filtro de vendedor).
$ctxParams = [];
$ctxSql = "SELECT COUNT(*) qtd, COALESCE(SUM(amount),0) total FROM opportunities WHERE status='Aberta'";
if ($sellerFilter > 0) {
    $ctxSql .= ' AND seller_user_id = ?';
    $ctxParams[] = $sellerFilter;
}
$stmt = $pdo->prepare($ctxSql);
$stmt->execute($ctxParams);
$ctx = $stmt->fetch();

nc_header('Oportunidades', 'oportunidades');

$newUrl = nc_url('acoes/oportunidade-form.php');
$actions = '<details class="nc-menu"><summary class="nc-btn nc-btn-secondary">Importar</summary><div class="nc-menu-list">'
    . '<a href="/orcamentos.php">Cotador (JSON)</a><a href="/orcamentos.php">PDF do ERP</a><a href="/orcamentos.php">Relatório Salesforce</a>'
    . '<small>Abre a versão clássica</small></div></details>'
    . '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e($newUrl) . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">+ Nova oportunidade</button>';
nc_page_header(
    (int)$ctx['qtd'] . ' oportunidades abertas · ' . nc_money_short($ctx['total']),
    'Oportunidades',
    $actions
);

// Abrir uma ficha automaticamente (ex.: depois de promover uma visita).
$openId = (int)($_GET['abrir'] ?? 0);
if (($_GET['novo'] ?? '') === '1'): ?>
    <div hidden hx-get="<?= e($newUrl) ?>" hx-trigger="load" hx-target="#nc-drawer-body" hx-swap="innerHTML"></div>
<?php endif;
if ($openId > 0): ?>
    <div hidden hx-get="<?= e(nc_url('oportunidade.php?id=' . $openId)) ?>" hx-trigger="load" hx-target="#nc-drawer-body" hx-swap="innerHTML"></div>
<?php endif; ?>

<div class="nc-tabs" role="tablist" aria-label="Funis">
    <?php if ($view === 'lista'): ?>
        <a class="nc-tab<?= $funnelId === 0 ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $funnelId === 0 ? 'true' : 'false' ?>" href="<?= e(nc_query_url(['funil' => null, 'p' => null])) ?>">Todos os funis</a>
    <?php endif; ?>
    <?php foreach ($funnels as $f): ?>
        <a class="nc-tab<?= $funnelId === (int)$f['id'] ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $funnelId === (int)$f['id'] ? 'true' : 'false' ?>"
           href="<?= e(nc_query_url(['funil' => (int)$f['id'], 'p' => null])) ?>"><?= e((string)$f['label']) ?></a>
    <?php endforeach; ?>
</div>

<form method="get" class="nc-toolbar" role="search">
    <input type="hidden" name="vista" value="<?= e($view) ?>">
    <?php if ($funnelId > 0): ?><input type="hidden" name="funil" value="<?= $funnelId ?>"><?php endif; ?>
    <?php if ($view === 'lista' && $tab !== 'todas'): ?><input type="hidden" name="status" value="<?= e($tab) ?>"><?php endif; ?>
    <label class="nc-sr-only" for="f-q">Buscar</label>
    <input class="nc-input nc-grow" id="f-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por cliente ou número">
    <label class="nc-sr-only" for="f-seller">Vendedor</label>
    <select class="nc-input nc-auto" id="f-seller" name="vendedor" data-nc-autosubmit>
        <option value="">Todos os vendedores</option>
        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $sellerFilter === (int)$u['id'] ? ' selected' : '' ?>><?= e((string)$u['name']) ?></option><?php endforeach; ?>
    </select>
    <?php if ($view === 'lista'): ?>
        <label class="nc-sr-only" for="f-line">Linha de produto</label>
        <select class="nc-input nc-auto" id="f-line" name="linha" data-nc-autosubmit>
            <option value="">Todas as linhas</option>
            <?php foreach ($lines as $l): ?><option value="<?= (int)$l['id'] ?>"<?= $lineFilter === (int)$l['id'] ? ' selected' : '' ?>><?= e((string)$l['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="nc-sr-only" for="f-month">Mês</label>
        <input class="nc-input nc-auto" id="f-month" type="month" name="mes" value="<?= e($month) ?>" data-nc-autosubmit>
    <?php endif; ?>
    <button class="nc-btn nc-btn-secondary" type="submit">Filtrar</button>
    <div class="nc-segmented" role="group" aria-label="Visão">
        <a class="nc-seg<?= $view === 'kanban' ? ' is-active' : '' ?>" href="<?= e(nc_query_url(['vista' => null, 'p' => null, 'status' => null])) ?>"<?= $view === 'kanban' ? ' aria-current="true"' : '' ?>>Kanban</a>
        <a class="nc-seg<?= $view === 'lista' ? ' is-active' : '' ?>" href="<?= e(nc_query_url(['vista' => 'lista', 'p' => null])) ?>"<?= $view === 'lista' ? ' aria-current="true"' : '' ?>>Lista</a>
    </div>
</form>

<?php if ($view === 'kanban'):
    $stagesQ = $pdo->prepare('SELECT id,name FROM funnel_stages WHERE funnel_id=? ORDER BY sort_order');
    $stagesQ->execute([$funnelId]);
    $stages = $stagesQ->fetchAll();

    $order = $hasPosition ? '(o.position IS NULL), o.position, o.updated_at DESC' : 'o.updated_at DESC';
    $stmt = $pdo->prepare(
        "SELECT o.id,o.number,o.client_name,o.amount,o.updated_at,o.stage_id,o.quote_required,o.quote_completed_at,
                pl.name line_name, u.name seller_name
         FROM opportunities o
         JOIN product_lines pl ON pl.id = o.product_line_id
         LEFT JOIN users u ON u.id = o.seller_user_id
         WHERE o.status = 'Aberta'$baseSql
         ORDER BY $order LIMIT 600"
    );
    $stmt->execute($params);
    $byStage = [];
    foreach ($stmt->fetchAll() as $row) {
        $byStage[(int)$row['stage_id']][] = $row;
    }

    if (!$stages): ?>
        <?php nc_empty('Funil sem etapas', 'Este funil ainda não tem etapas configuradas.'); ?>
    <?php else: ?>
        <div class="nc-kanban" data-nc-kanban
             data-move-url="<?= e(nc_url('acoes/oportunidade-etapa.php')) ?>"
             data-action-url="<?= e(nc_url('acoes/oportunidade-acao.php')) ?>">
            <?php foreach ($stages as $st):
                $cards = $byStage[(int)$st['id']] ?? [];
                $sum = array_sum(array_map(static fn($c) => (float)$c['amount'], $cards)); ?>
                <section class="nc-kanban-col" aria-label="Etapa <?= e((string)$st['name']) ?>">
                    <header class="nc-kanban-col-head">
                        <h2><?= e((string)$st['name']) ?></h2>
                        <span class="nc-tab-count" data-count><?= count($cards) ?></span>
                        <span class="nc-kanban-sum nc-num" data-sum><?= e(nc_money_short($sum)) ?></span>
                    </header>
                    <div class="nc-kanban-list" data-stage-id="<?= (int)$st['id'] ?>">
                        <?php foreach ($cards as $c) { nc_deal_card($c); } ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <div class="nc-dropzones" aria-label="Zonas de resultado: arraste um card para concluir">
            <div class="nc-dropzone nc-dz-won">
                <span class="nc-dz-label">Soltar aqui: <strong>Ganha</strong></span>
                <div class="nc-zone-list" data-zone="won"></div>
            </div>
            <div class="nc-dropzone nc-dz-lost">
                <span class="nc-dz-label">Soltar aqui: <strong>Perdida</strong> (pede o motivo)</span>
                <div class="nc-zone-list" data-zone="lost"></div>
            </div>
        </div>
        <p class="nc-help nc-kanban-help">Arraste os cards entre as colunas. Prefere teclado ou celular? Abra a oportunidade e use “Mover de etapa”.</p>
    <?php endif; ?>

<?php else:
    // Lista: abas por status com contagem (respeitando os demais filtros).
    $stmt = $pdo->prepare('SELECT o.status s, COUNT(*) n FROM opportunities o WHERE 1=1' . $baseSql . ' GROUP BY o.status');
    $stmt->execute($params);
    $counts = ['Aberta' => 0, 'Ganha' => 0, 'Perdida' => 0];
    foreach ($stmt->fetchAll() as $r) {
        $counts[(string)$r['s']] = (int)$r['n'];
    }
    $counts['todas'] = array_sum($counts);

    $listSql = $baseSql;
    $listParams = $params;
    if ($tab !== 'todas') {
        $listSql .= ' AND o.status = ?';
        $listParams[] = $tab;
    }
    $total = $counts[$tab];
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT o.id,o.number,o.opportunity_date,o.client_name,o.amount,o.status,o.quote_required,o.quote_completed_at,
                pl.name line_name, fs.name stage_name, u.name seller_name
         FROM opportunities o
         JOIN product_lines pl ON pl.id = o.product_line_id
         JOIN funnel_stages fs ON fs.id = o.stage_id
         LEFT JOIN users u ON u.id = o.seller_user_id
         WHERE 1=1$listSql
         ORDER BY o.opportunity_date DESC, o.id DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($listParams);
    $rows = $stmt->fetchAll();
    $tabs = ['todas' => 'Todas', 'Aberta' => 'Abertas', 'Ganha' => 'Ganhas', 'Perdida' => 'Perdidas']; ?>

    <div class="nc-tabs" role="tablist" aria-label="Status">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="nc-tab<?= $tab === $key ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>"
               href="<?= e(nc_query_url(['status' => $key === 'todas' ? null : $key, 'p' => null])) ?>"><?= e($label) ?> <span class="nc-tab-count"><?= (int)$counts[$key] ?></span></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$rows): ?>
        <?php nc_empty('Nenhuma oportunidade encontrada', 'Ajuste os filtros ou cadastre uma nova oportunidade.'); ?>
    <?php else: ?>
        <div class="nc-table-wrap">
            <table class="nc-table">
                <thead><tr><th>Nº</th><th>Data</th><th>Cliente</th><th>Linha</th><th>Etapa</th><th>Vendedor</th><th class="is-num">Valor</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $url = nc_url('oportunidade.php?id=' . (int)$r['id']);
                    $pending = (int)$r['quote_required'] === 1 && empty($r['quote_completed_at']); ?>
                    <tr>
                        <td class="nc-num-left"><a href="<?= e($url) ?>" hx-get="<?= e($url) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-push-url="true"><?= e((string)$r['number']) ?></a></td>
                        <td><?= e(nc_date((string)$r['opportunity_date'])) ?></td>
                        <td><strong><?= e((string)$r['client_name']) ?></strong><?= $pending ? '<br>' . nc_badge('Orçamento pendente', 'warning') : '' ?></td>
                        <td><?= e((string)$r['line_name']) ?></td>
                        <td><?= e((string)$r['stage_name']) ?></td>
                        <td><?= e((string)($r['seller_name'] ?: '—')) ?></td>
                        <td class="is-num"><?= e(money($r['amount'])) ?></td>
                        <td><?= nc_status_badge((string)$r['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php nc_pagination($page, $pages); ?>
    <?php endif; ?>
<?php endif;

nc_footer();
