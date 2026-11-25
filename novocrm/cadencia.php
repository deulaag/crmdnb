<?php
declare(strict_types=1);

/**
 * Cadência Comercial (nativa). Mesmas consultas do cadencia.php da raiz.
 * NÃO dispara o envio de lembretes da agenda: os avisos continuam saindo só do job da raiz.
 */
require_once __DIR__ . '/_layout.php';

$user = nc_boot();
$pdo = db();
ensure_commercial_automation_schema($pdo);

$isManager = nc_is_manager($user);
$isAdmin = nc_is_admin($user);

// comercial só vê o que é seu (agenda.user_id); gestor/admin veem tudo e podem filtrar por responsável.
$respId = $isManager ? (int)($_GET['resp'] ?? 0) : (int)$user['id'];
$respId = $respId > 0 ? $respId : null;
$users = $isManager ? nc_users($pdo) : [];

$aba = (string)($_GET['aba'] ?? 'proximos');
if (!in_array($aba, ['proximos', 'atrasados', 'abertos', 'concluidos'], true)) {
    $aba = 'proximos';
}
$busca = trim((string)($_GET['q'] ?? ''));

$open = "a.status IN ('sugerido','agendado','confirmado')";
$types = "a.event_type IN ('followup','visita_retorno')";
$scopeSql = $respId !== null ? ' AND a.user_id = ?' : '';
$scopeParams = $respId !== null ? [$respId] : [];

$count = static function (string $where, array $params = []) use ($pdo, $scopeSql, $scopeParams): int {
    $q = $pdo->prepare("SELECT COUNT(*) FROM agenda a WHERE $where" . $scopeSql);
    $q->execute(array_merge($params, $scopeParams));
    return (int)$q->fetchColumn();
};

// --- KPIs ---
$q = $pdo->prepare("SELECT COUNT(*) FROM opportunities WHERE status='Aberta'" . ($respId !== null ? ' AND seller_user_id = ?' : ''));
$q->execute($respId !== null ? [$respId] : []);
$kOpen = (int)$q->fetchColumn();
$kFollow = $count("a.event_type='followup' AND $open");
$kVisits = $count("a.event_type='visita_retorno' AND $open");
$kLate = $count("$types AND $open AND a.event_date < CURDATE()");

// --- Lista da aba ---
$where = $types;
switch ($aba) {
    case 'atrasados':
        $where .= " AND $open AND a.event_date < CURDATE()";
        $order = 'a.event_date ASC, a.id ASC';
        $limit = 200;
        break;
    case 'abertos':
        $where .= " AND $open";
        $order = 'a.event_date ASC, a.id ASC';
        $limit = 200;
        break;
    case 'concluidos':
        $where .= " AND a.status = 'concluido' AND a.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $order = 'a.updated_at DESC, a.id DESC';
        $limit = 200;
        break;
    default: // próximos 7 dias: mesma consulta da raiz (sem limite inferior, atrasados entram)
        $where .= " AND $open AND a.event_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        $order = 'a.event_date ASC, a.id ASC';
        $limit = 60;
}
$params = [];
if ($busca !== '') {
    $where .= ' AND a.client_name LIKE ?';
    $params[] = nc_like($busca);
}
$q = $pdo->prepare(
    "SELECT a.*, u.name user_name FROM agenda a LEFT JOIN users u ON u.id=a.user_id
     WHERE $where" . $scopeSql . " ORDER BY $order LIMIT $limit"
);
$q->execute(array_merge($params, $scopeParams));
$items = $q->fetchAll();

// Contagem das abas (sem a busca, para o número ser estável)
$tabCounts = [
    'proximos' => $count("$types AND $open AND a.event_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)"),
    'atrasados' => $kLate,
    'abertos' => $count("$types AND $open"),
    'concluidos' => $count("$types AND a.status='concluido' AND a.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"),
];

// Link para a ficha do cliente quando o nome bate com um cadastro
$companyIds = [];
$names = array_values(array_unique(array_map(static fn($r) => (string)$r['client_name'], $items)));
if ($names) {
    $in = implode(',', array_fill(0, count($names), '?'));
    $q = $pdo->prepare("SELECT id,name FROM companies WHERE name IN ($in)");
    $q->execute($names);
    foreach ($q->fetchAll() as $c) {
        $companyIds[(string)$c['name']] = (int)$c['id'];
    }
}

$today = date('Y-m-d');

nc_header('Cadência', 'cadencia');
nc_page_header(
    'Automação: follow-up +3 dias · retorno +60 dias',
    'Cadência Comercial',
    '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e(nc_url('acoes/agenda-form.php?tipo=followup&volta=cadencia'))
        . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">' . nc_icon('plus', 18) . ' Novo follow-up</button>',
    'Follow-ups e retornos gerados automaticamente a partir das visitas.'
);
?>
<div class="nc-kpis">
    <?php
    nc_kpi('Oportunidades abertas', (string)$kOpen, 'Em negociação', 'funnel', 'info');
    nc_kpi('Follow-ups programados', (string)$kFollow, 'Sugeridos, agendados ou confirmados', 'repeat', 'success');
    nc_kpi('Próximas visitas', (string)$kVisits, 'Retornos de visita em aberto', 'calendar', 'warning');
    nc_kpi('Atrasados', (string)$kLate, $kLate > 0 ? 'Data já passou e ainda estão em aberto' : 'Nada atrasado', 'alert', $kLate > 0 ? 'danger' : 'neutral', $kLate > 0 ? 'danger' : '');
    ?>
</div>

<div class="nc-tabbar">
    <div class="nc-tabs" role="tablist" aria-label="Filtro da cadência">
        <?php foreach (['proximos' => 'Próximos 7 dias', 'atrasados' => 'Atrasados', 'abertos' => 'Todos em aberto', 'concluidos' => 'Concluídos (30 dias)'] as $key => $label): ?>
            <a class="nc-tab<?= $aba === $key ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $aba === $key ? 'true' : 'false' ?>"
               href="<?= e(nc_query_url(['aba' => $key === 'proximos' ? null : $key])) ?>"><?= e($label) ?> <span class="nc-tab-count"><?= (int)$tabCounts[$key] ?></span></a>
        <?php endforeach; ?>
    </div>
    <form method="get" class="nc-toolbar nc-toolbar-flush">
        <?php if ($aba !== 'proximos'): ?><input type="hidden" name="aba" value="<?= e($aba) ?>"><?php endif; ?>
        <?php if ($isManager): ?>
            <label class="nc-sr-only" for="cd-resp">Responsável</label>
            <select class="nc-input nc-auto" id="cd-resp" name="resp" data-nc-autosubmit>
                <option value="">Todos os responsáveis</option>
                <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $respId === (int)$u['id'] ? ' selected' : '' ?>><?= e((string)$u['name']) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
        <div class="nc-search-field-inline">
            <?= nc_icon('search', 18) ?>
            <label class="nc-sr-only" for="cd-q">Buscar cliente</label>
            <input class="nc-input" id="cd-q" type="search" name="q" value="<?= e($busca) ?>" placeholder="Buscar cliente..." autocomplete="off">
        </div>
    </form>
</div>

<?php if (!$items): ?>
    <?php nc_empty(
        $busca !== '' ? 'Nada encontrado' : 'Nenhum item nesta aba',
        $busca !== '' ? 'Nenhum follow-up ou retorno combina com a busca.' : 'Quando uma visita for importada, o follow-up e o retorno aparecem aqui automaticamente.'
    ); ?>
<?php else: ?>
    <div class="nc-cad-list">
        <?php foreach ($items as $a):
            $isOpen = in_array($a['status'], ['sugerido', 'agendado', 'confirmado'], true);
            $late = $isOpen && (string)$a['event_date'] < $today;
            $isFollow = $a['event_type'] === 'followup';
            $companyId = $companyIds[(string)$a['client_name']] ?? null;
            $statusUrl = nc_url('acoes/agenda-status.php');
            $id = (int)$a['id']; ?>
            <article class="nc-cad-card<?= $late ? ' is-late' : '' ?>">
                <div class="nc-cad-date">
                    <strong><?= e(substr(nc_date((string)$a['event_date']), 0, 5)) ?></strong>
                    <small><?= e(substr((string)$a['event_date'], 0, 4)) ?><?= $a['event_time'] ? ' · ' . e(substr((string)$a['event_time'], 0, 5)) : '' ?></small>
                </div>
                <div class="nc-cad-body">
                    <div class="nc-cad-meta">
                        <?= nc_badge($isFollow ? 'FOLLOW-UP' : 'PRÓXIMA VISITA', $isFollow ? 'info' : 'warning') ?>
                        <?php if ($late): ?><?= nc_badge('ATRASADO', 'danger') ?><?php endif; ?>
                        <?php if (!$isOpen): ?><?= nc_badge($a['status'] === 'concluido' ? 'Concluído' : 'Cancelado', $a['status'] === 'concluido' ? 'success' : 'neutral') ?><?php endif; ?>
                    </div>
                    <div class="nc-cad-client">
                        <?php if ($companyId): ?>
                            <a href="<?= e(nc_url('cliente.php?id=' . $companyId)) ?>"><?= e((string)$a['client_name']) ?></a>
                        <?php else: ?><?= e((string)$a['client_name']) ?><?php endif; ?>
                    </div>
                    <div><?= e(nc_truncate((string)$a['objective'], 220)) ?></div>
                    <div class="nc-cad-meta">
                        <span><?= nc_icon('users', 16) ?> <?= e((string)($a['user_name'] ?: 'Sem responsável')) ?></span>
                        <span>
                            <?= nc_icon('mail', 16) ?>
                            <?php if ($a['reminder_sent_at']): ?>
                                E-mail enviado <?= e(substr(nc_date((string)$a['reminder_sent_at']), 0, 5) . ' ' . substr((string)$a['reminder_sent_at'], 11, 5)) ?>
                            <?php elseif ((int)($a['auto_generated'] ?? 0) === 1): ?>
                                Aviso automático pendente
                            <?php else: ?>
                                Sem aviso automático
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <?php if ($isOpen): ?>
                    <div class="nc-cad-actions">
                        <button class="nc-btn nc-btn-primary" type="button" hx-post="<?= e($statusUrl) ?>" hx-vals='{"id":<?= $id ?>,"status":"concluido"}' hx-swap="none" hx-disabled-elt="this">Concluir</button>
                        <button class="nc-btn nc-btn-secondary" type="button" hx-get="<?= e(nc_url('acoes/agenda-reagendar-form.php?id=' . $id)) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML">Reagendar</button>
                        <button class="nc-btn nc-btn-secondary" type="button" hx-get="<?= e(nc_url('acoes/oportunidade-form.php?cliente=' . rawurlencode((string)$a['client_name']))) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML">Criar oportunidade</button>
                        <button class="nc-btn nc-btn-danger-outline" type="button" hx-post="<?= e($statusUrl) ?>" hx-vals='{"id":<?= $id ?>,"status":"cancelado"}' hx-swap="none"
                                data-nc-confirm="Cancelar o compromisso com <?= e((string)$a['client_name']) ?>?" data-nc-confirm-ok="Cancelar compromisso">Cancelar</button>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if ($aba === 'proximos' && count($items) >= 60): ?><p class="nc-help">Mostrando os 60 primeiros itens, como na versão clássica.</p><?php endif; ?>
<?php endif; ?>

<section class="nc-card nc-section" aria-labelledby="h-como">
    <div class="nc-how">
        <span class="nc-kpi-icon is-info"><?= nc_icon('sparkle') ?></span>
        <div>
            <h2 class="nc-card-title" id="h-como">Como funciona</h2>
            <p>Visita importada → follow-up em 3 dias → retorno em 60 dias. Modelos de e-mail em Configurações.</p>
            <p class="nc-help">Os avisos por e-mail são enviados pela rotina automática do CRM; esta página apenas mostra o que está programado.
                <?php if ($isAdmin): ?><a href="/configuracoes.php">Abrir modelos de e-mail</a><?php endif; ?></p>
        </div>
    </div>
</section>
<?php
nc_footer();
