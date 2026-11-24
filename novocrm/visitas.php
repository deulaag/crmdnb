<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/partials/visita.php';

$user = nc_boot();
$pdo = db();
ensure_commercial_automation_schema($pdo);
ensure_opportunity_visit_schema($pdo);

$aba = ($_GET['aba'] ?? 'agenda') === 'visitas' ? 'visitas' : 'agenda';
$sellerFilter = (int)($_GET['vendedor'] ?? 0);
$users = nc_users($pdo);

// Contadores do cabeçalho (mesmos KPIs do cronograma.php da raiz).
$followupCount = (int)$pdo->query("SELECT COUNT(*) FROM agenda WHERE event_type='followup' AND status IN ('sugerido','agendado','confirmado')")->fetchColumn();
$returnCount = (int)$pdo->query("SELECT COUNT(*) FROM agenda WHERE event_type='visita_retorno' AND status IN ('sugerido','agendado','confirmado')")->fetchColumn();

nc_header('Visitas & Agenda', 'visitas');

$actions = '<details class="nc-menu"><summary class="nc-btn nc-btn-secondary">Importar</summary><div class="nc-menu-list">'
    . '<a href="/visitas.php">PDF de visita (IA)</a><a href="/visitas.php">JSON de visitas</a>'
    . '<small>Abre a versão clássica</small></div></details>';
if ($aba === 'agenda') {
    $actions .= '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e(nc_url('acoes/agenda-form.php')) . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">+ Novo compromisso</button>';
} else {
    $actions .= '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e(nc_url('acoes/visita-form.php')) . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">+ Nova visita</button>';
}
nc_page_header($followupCount . ' follow-ups · ' . $returnCount . ' retornos programados', 'Visitas & Agenda', $actions);
?>
<div class="nc-tabs" role="tablist" aria-label="Seções">
    <a class="nc-tab<?= $aba === 'agenda' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $aba === 'agenda' ? 'true' : 'false' ?>" href="<?= e(nc_query_url(['aba' => null, 'p' => null, 'status' => null])) ?>">Agenda</a>
    <a class="nc-tab<?= $aba === 'visitas' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $aba === 'visitas' ? 'true' : 'false' ?>" href="<?= e(nc_query_url(['aba' => 'visitas', 'p' => null])) ?>">Visitas</a>
</div>

<?php if ($aba === 'agenda'):
    $sql = "SELECT a.*, u.name user_name, v.os_number
            FROM agenda a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN visits v ON v.id = a.visit_id
            WHERE a.status IN ('sugerido','agendado','confirmado')"
        . ($sellerFilter > 0 ? ' AND a.user_id = ?' : '') . ' ORDER BY a.event_date ASC, a.event_time ASC, a.id ASC LIMIT 400';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($sellerFilter > 0 ? [$sellerFilter] : []);
    $items = $stmt->fetchAll();

    $today = new DateTimeImmutable('today');
    $tomorrow = $today->modify('+1 day')->format('Y-m-d');
    $weekEnd = $today->modify('+7 days')->format('Y-m-d');
    $todayS = $today->format('Y-m-d');
    $groups = ['Atrasados' => [], 'Hoje' => [], 'Amanhã' => [], 'Esta semana' => [], 'Mais adiante' => []];
    foreach ($items as $a) {
        $d = (string)$a['event_date'];
        $key = $d < $todayS ? 'Atrasados' : ($d === $todayS ? 'Hoje' : ($d === $tomorrow ? 'Amanhã' : ($d <= $weekEnd ? 'Esta semana' : 'Mais adiante')));
        $groups[$key][] = $a;
    }

    $done = $pdo->query(
        "SELECT a.*, u.name user_name, v.os_number FROM agenda a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN visits v ON v.id=a.visit_id
         WHERE a.status='concluido' ORDER BY a.updated_at DESC LIMIT 10"
    )->fetchAll(); ?>

    <form method="get" class="nc-toolbar">
        <label class="nc-sr-only" for="f-seller">Responsável</label>
        <select class="nc-input nc-auto" id="f-seller" name="vendedor" data-nc-autosubmit>
            <option value="">Todos os responsáveis</option>
            <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $sellerFilter === (int)$u['id'] ? ' selected' : '' ?>><?= e((string)$u['name']) ?></option><?php endforeach; ?>
        </select>
    </form>

    <?php if (!$items): ?>
        <?php nc_empty('Agenda livre', 'Nenhum compromisso sugerido, agendado ou confirmado.',
            '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e(nc_url('acoes/agenda-form.php')) . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">+ Novo compromisso</button>'); ?>
    <?php endif; ?>

    <?php foreach ($groups as $label => $list): if (!$list) continue; ?>
        <section class="nc-agenda-group" aria-label="<?= e($label) ?>">
            <h2 class="nc-group-title<?= $label === 'Atrasados' ? ' is-late' : '' ?>"><?= e($label) ?> <span class="nc-tab-count"><?= count($list) ?></span></h2>
            <div class="nc-agenda-list">
                <?php foreach ($list as $a) { nc_agenda_card($a); } ?>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if ($done): ?>
        <details class="nc-details nc-details-neutral">
            <summary>Concluídos recentemente (<?= count($done) ?>)</summary>
            <div class="nc-agenda-list">
                <?php foreach ($done as $a) { nc_agenda_card($a, false); } ?>
            </div>
        </details>
    <?php endif; ?>

<?php else:
    $tab = (string)($_GET['status'] ?? 'todas');
    if (!in_array($tab, ['todas', 'pendente', 'promovida', 'descartada'], true)) {
        $tab = 'todas';
    }
    $q = trim((string)($_GET['q'] ?? ''));
    $month = ($_GET['mes'] ?? '') !== '' ? valid_month((string)$_GET['mes']) : '';
    $perPage = 50;
    $page = max(1, (int)($_GET['p'] ?? 1));

    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = '(v.client_name LIKE ? OR v.os_number LIKE ?)';
        $params[] = nc_like($q);
        $params[] = nc_like($q);
    }
    if ($sellerFilter > 0) {
        $where[] = 'v.seller_user_id = ?';
        $params[] = $sellerFilter;
    }
    if ($month !== '') {
        $where[] = "DATE_FORMAT(v.visit_date,'%Y-%m') = ?";
        $params[] = $month;
    }
    $baseSql = $where ? ' AND ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare('SELECT v.lead_status s, COUNT(*) n FROM visits v WHERE 1=1' . $baseSql . ' GROUP BY v.lead_status');
    $stmt->execute($params);
    $counts = ['pendente' => 0, 'promovida' => 0, 'descartada' => 0];
    foreach ($stmt->fetchAll() as $r) {
        $counts[(string)$r['s']] = (int)$r['n'];
    }
    $counts['todas'] = array_sum($counts);

    $listSql = $baseSql;
    $listParams = $params;
    if ($tab !== 'todas') {
        $listSql .= ' AND v.lead_status = ?';
        $listParams[] = $tab;
    }
    $total = $counts[$tab];
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT v.*, u.name seller_name, (SELECT o.id FROM opportunities o WHERE o.visit_id = v.id LIMIT 1) opp_id
         FROM visits v LEFT JOIN users u ON u.id = v.seller_user_id
         WHERE 1=1$listSql ORDER BY v.visit_date DESC, v.id DESC LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($listParams);
    $rows = $stmt->fetchAll();
    $tabs = ['todas' => 'Todas', 'pendente' => 'Pendentes', 'promovida' => 'Promovidas', 'descartada' => 'Descartadas'];
    $promoteUrl = nc_url('acoes/visita-promover.php');
    $actionUrl = nc_url('acoes/visita-acao.php'); ?>

    <form method="get" class="nc-toolbar" role="search">
        <input type="hidden" name="aba" value="visitas">
        <?php if ($tab !== 'todas'): ?><input type="hidden" name="status" value="<?= e($tab) ?>"><?php endif; ?>
        <label class="nc-sr-only" for="f-q">Buscar</label>
        <input class="nc-input nc-grow" id="f-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por cliente ou nº da OS">
        <label class="nc-sr-only" for="f-seller">Responsável</label>
        <select class="nc-input nc-auto" id="f-seller" name="vendedor" data-nc-autosubmit>
            <option value="">Todos os responsáveis</option>
            <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $sellerFilter === (int)$u['id'] ? ' selected' : '' ?>><?= e((string)$u['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="nc-sr-only" for="f-month">Mês</label>
        <input class="nc-input nc-auto" id="f-month" type="month" name="mes" value="<?= e($month) ?>" data-nc-autosubmit>
        <button class="nc-btn nc-btn-secondary" type="submit">Filtrar</button>
    </form>

    <div class="nc-tabs" role="tablist" aria-label="Status do lead">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="nc-tab<?= $tab === $key ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>"
               href="<?= e(nc_query_url(['status' => $key === 'todas' ? null : $key, 'p' => null])) ?>"><?= e($label) ?> <span class="nc-tab-count"><?= (int)$counts[$key] ?></span></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$rows): ?>
        <?php nc_empty('Nenhuma visita encontrada', 'Ajuste os filtros ou registre uma nova visita.',
            '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e(nc_url('acoes/visita-form.php')) . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">+ Nova visita</button>'); ?>
    <?php else: ?>
        <div class="nc-table-wrap">
            <table class="nc-table">
                <thead><tr><th>Data</th><th>OS</th><th>Cliente</th><th>Tipo</th><th>Responsável</th><th>Oportunidade identificada</th><th>Lead</th><th class="is-actions">Ações</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $pending = $r['lead_status'] === 'pendente'; ?>
                    <tr>
                        <td><?= e(nc_date((string)$r['visit_date'])) ?></td>
                        <td class="nc-num-left"><?= e((string)($r['os_number'] ?: '—')) ?></td>
                        <td><strong><?= e((string)$r['client_name']) ?></strong><?= $r['contact_name'] ? '<br><small class="nc-muted">' . e((string)$r['contact_name']) . '</small>' : '' ?></td>
                        <td><?= e($r['visit_type'] === 'prospeccao' ? 'Prospecção' : 'Visita') ?></td>
                        <td><?= e((string)($r['seller_name'] ?: '—')) ?></td>
                        <td><?= e($r['opportunity_text'] ? nc_truncate((string)$r['opportunity_text'], 80) : '—') ?></td>
                        <td><?= nc_badge(ucfirst((string)$r['lead_status']), $r['lead_status'] === 'promovida' ? 'success' : ($r['lead_status'] === 'descartada' ? 'neutral' : 'warning')) ?></td>
                        <td class="is-actions">
                            <?php if ($pending): ?>
                                <button class="nc-btn nc-btn-primary" type="button" hx-post="<?= e($promoteUrl) ?>" hx-vals='{"visit_id":<?= (int)$r['id'] ?>}' hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-disabled-elt="this">Promover</button>
                                <button class="nc-btn nc-btn-secondary" type="button" hx-post="<?= e($actionUrl) ?>" hx-vals='{"action":"discard","visit_id":<?= (int)$r['id'] ?>}' hx-swap="none"
                                        data-nc-confirm="Descartar o lead de <?= e((string)$r['client_name']) ?> da triagem?" data-nc-confirm-ok="Descartar">Descartar</button>
                            <?php elseif (!empty($r['opp_id'])): ?>
                                <a class="nc-btn nc-btn-secondary" href="<?= e(nc_url('oportunidades.php?abrir=' . (int)$r['opp_id'])) ?>">Ver oportunidade</a>
                            <?php endif; ?>
                            <button class="nc-btn nc-btn-danger-outline" type="button" hx-post="<?= e($actionUrl) ?>" hx-vals='{"action":"delete","visit_id":<?= (int)$r['id'] ?>}' hx-swap="none"
                                    data-nc-confirm="Excluir a visita de <?= e((string)$r['client_name']) ?>? Os compromissos gerados automaticamente por ela também serão removidos." data-nc-confirm-ok="Excluir">Excluir</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php nc_pagination($page, $pages); ?>
    <?php endif; ?>
<?php endif;

nc_footer();
