<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/partials/comissao.php';

$user = nc_boot();
$pdo = db();
$isAdmin = nc_is_admin($user);
$isManager = nc_is_manager($user);
$selfId = (int)$user['id'];

$month = valid_month($_GET['mes'] ?? null);
$aba = ($_GET['aba'] ?? 'reps') === 'extrato' ? 'extrato' : 'reps';
$personFilter = $isManager ? (int)($_GET['pessoa'] ?? 0) : $selfId;
[$year, $mon] = array_map('intval', explode('-', $month));
$isCurrent = $month === current_month();
$isPast = $month < current_month();

$report = nc_commission_report($pdo, $month);
$summary = $report['summary'];

// comercial (e demais papéis sem gestão) vê só o próprio card e o próprio extrato.
$reps = array_values(array_filter(
    nc_commission_reps($report),
    static fn($p) => $isManager || (int)$p['id'] === $selfId
));
$statement = array_values(array_filter(
    $report['statement'],
    static fn($r) => ($isManager || (int)$r['person_id'] === $selfId)
        && ($personFilter === 0 || (int)$r['person_id'] === $personFilter)
));

// --- KPIs (a soma dos cards bate com o KPI) ---
$goalSum = 0.0;
$hasGoal = false;
$billedReps = 0.0;
$payable = 0.0;
foreach ($reps as $p) {
    $s = $summary[(int)$p['id']];
    if ($s['target'] !== null && (int)$p['active'] === 1) {
        $goalSum += (float)$s['target'];
        $hasGoal = true;
    }
    $billedReps += (float)$s['billed'];
    if ($s['closing_status'] !== 'paga') {
        $payable += (float)$s['commission'];
    }
}
$realized = $isManager ? (float)$report['team_billed'] : $billedReps;
$attain = $hasGoal && $goalSum > 0 ? $realized / $goalSum * 100 : null;
$attainSub = $attain !== null && $attain >= 100 ? 'Meta batida' : ($isCurrent ? 'Em andamento' : ($isPast ? 'Encerrado' : 'Mês futuro'));

$openToClose = false;
foreach ($report['people'] as $p) {
    $s = $summary[(int)$p['id']];
    if (!$s['from_snapshot'] && $s['has_policy'] && $s['orders'] > 0) {
        $openToClose = true;
        break;
    }
}

$canClose = $isManager && $report['has_closings'] && $openToClose && $month <= current_month();

$actions = '';
if ($isManager) {
    $actions = '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e(nc_url('acoes/representante-form.php?mes=' . $month))
        . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">' . nc_icon('plus', 18) . ' Cadastrar Representante</button>';
}

nc_header('Equipe & Comissões', 'equipe', ['Equipe & Comissões']);
nc_page_header(
    nc_month_label($month) . ' · ' . nc_money_short($realized) . ' faturados',
    'Equipe Comercial, Metas & Comissões',
    $actions,
    'Representantes comerciais (RCAs), metas de faturamento e fechamento de comissões.'
);
?>
<div class="nc-filterbar">
    <form method="get" class="nc-inline-field">
        <input type="hidden" name="aba" value="<?= e($aba) ?>">
        <?php if ($personFilter > 0 && $isManager): ?><input type="hidden" name="pessoa" value="<?= $personFilter ?>"><?php endif; ?>
        <label for="eq-mes">Mês</label>
        <input class="nc-input" id="eq-mes" type="month" name="mes" value="<?= e($month) ?>" data-nc-autosubmit>
    </form>
</div>

<?php if (!$report['has_targets'] && $isAdmin): ?>
    <div class="nc-alert nc-alert-warning" role="status">
        A tabela de metas ainda não existe, então as metas ficam ocultas.
        <a href="<?= e(nc_url('migrations/run.php')) ?>">Aplicar migrações</a>.
    </div>
<?php endif; ?>

<div class="nc-kpis">
    <?php
    nc_kpi('Meta Global da Equipe', $hasGoal ? money($goalSum) : '—', $hasGoal ? 'Soma das metas mensais de vendas' : 'Nenhuma meta cadastrada', 'target', 'info');
    nc_kpi('Faturamento Realizado', money($realized), 'Pedidos faturados no período', 'trending', 'success', 'success');
    nc_kpi('Atingimento da Meta', $attain !== null ? number_format($attain, 1, ',', '.') . '%' : '—', $attain !== null ? $attainSub : 'Sem meta para comparar', 'medal', 'warning');
    nc_kpi('Comissões a Pagar', money($payable), 'Pendentes de quitação', 'dollar', 'neutral');
    ?>
</div>

<div class="nc-tabbar">
    <div class="nc-tabs" role="tablist" aria-label="Seções">
        <a class="nc-tab<?= $aba === 'reps' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $aba === 'reps' ? 'true' : 'false' ?>"
           href="<?= e(nc_query_url(['aba' => null])) ?>">Representantes <span class="nc-tab-count"><?= count($reps) ?></span></a>
        <a class="nc-tab<?= $aba === 'extrato' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $aba === 'extrato' ? 'true' : 'false' ?>"
           href="<?= e(nc_query_url(['aba' => 'extrato'])) ?>">Extrato de Comissões <span class="nc-tab-count"><?= count($statement) ?></span></a>
    </div>
    <div class="nc-search-field-inline">
        <?= nc_icon('search', 18) ?>
        <label class="nc-sr-only" for="eq-busca">Buscar representante</label>
        <input class="nc-input" id="eq-busca" type="search" placeholder="Buscar representante..." autocomplete="off"
               data-nc-filter="#eq-lista" aria-controls="eq-lista">
    </div>
</div>

<div id="eq-lista">
<?php if ($aba === 'reps'): ?>
    <?php if (!$reps): ?>
        <?php nc_empty(
            'Nenhum representante com política de comissão',
            'Cadastre um representante ou defina a política de comissão de um usuário para vê-lo aqui.',
            $isManager ? '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e(nc_url('acoes/representante-form.php?mes=' . $month)) . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">Cadastrar Representante</button>' : ''
        ); ?>
    <?php else: ?>
        <div class="nc-people">
            <?php foreach ($reps as $p):
                $id = (int)$p['id'];
                $s = $summary[$id];
                $pct = $s['target'] !== null && $s['target'] > 0 ? $s['billed'] / $s['target'] * 100 : null;
                $editUrl = nc_url('acoes/representante-form.php?id=' . $id . '&mes=' . $month); ?>
                <article class="nc-person<?= (int)$p['active'] === 1 ? '' : ' is-inactive' ?>" data-nc-filter-item data-nc-filter-text="<?= e(strtolower((string)$p['name'] . ' ' . (string)$p['email'])) ?>">
                    <header class="nc-person-head">
                        <span class="nc-avatar nc-avatar-lg" style="background:<?= e(nc_avatar_color($id)) ?>" aria-hidden="true"><?= e(nc_initials((string)$p['name'])) ?></span>
                        <div class="nc-person-id">
                            <h2><?= e((string)$p['name']) ?></h2>
                            <p class="nc-muted"><?= e(nc_policy_caption($report, $id)) ?><?= (int)$p['active'] === 1 ? '' : ' · inativo' ?></p>
                        </div>
                        <?php if ($isManager): ?>
                            <button type="button" class="nc-icon-btn" hx-get="<?= e($editUrl) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML"
                                    aria-label="Editar representante <?= e((string)$p['name']) ?>" title="Editar representante"><?= nc_icon('pencil', 18) ?></button>
                        <?php endif; ?>
                    </header>

                    <div class="nc-goal">
                        <div class="nc-bar-head">
                            <span>Progresso da Meta</span>
                            <strong class="nc-num"><?= $pct !== null ? e(number_format($pct, 1, ',', '.')) . '% (' . e(money($s['billed'])) . ')' : e(money($s['billed'])) ?></strong>
                        </div>
                        <?php if ($pct !== null): ?>
                            <div class="nc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)min(100, round($pct)) ?>" aria-label="Meta de <?= e((string)$p['name']) ?>">
                                <span style="width:<?= round(min(100, $pct), 1) ?>%"></span>
                            </div>
                            <div class="nc-bar-head nc-bar-foot"><span>Meta <?= e(money($s['target'])) ?></span><span><?= (int)$s['orders'] ?> pedido(s) faturado(s)</span></div>
                        <?php else: ?>
                            <div class="nc-progress is-empty" role="img" aria-label="Sem meta definida"><span style="width:0"></span></div>
                            <div class="nc-bar-head nc-bar-foot">
                                <span>Sem meta definida<?php if ($isManager): ?> ·
                                    <a href="<?= e($editUrl) ?>" hx-get="<?= e($editUrl) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML">Definir meta</a><?php endif; ?></span>
                                <span><?= (int)$s['orders'] ?> pedido(s) faturado(s)</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <p class="nc-person-commission">
                        <span class="nc-muted">Comissão do mês</span>
                        <strong class="nc-num"><?= e(money($s['commission'])) ?></strong>
                        <?= nc_badge(nc_closing_label($s['closing_status']), $s['closing_status'] === 'paga' ? 'success' : ($s['closing_status'] === 'fechada' ? 'info' : 'warning')) ?>
                    </p>

                    <footer class="nc-person-foot">
                        <?php if ($p['email']): ?><span><?= nc_icon('mail', 16) ?> <?= e((string)$p['email']) ?></span><?php endif; ?>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="nc-empty-filter nc-muted" data-nc-filter-empty hidden>Nenhum representante encontrado para a busca.</p>
    <?php endif; ?>

<?php else: ?>
    <div class="nc-toolbar">
        <?php if ($isManager): ?>
            <form method="get" class="nc-inline-field">
                <input type="hidden" name="aba" value="extrato"><input type="hidden" name="mes" value="<?= e($month) ?>">
                <label class="nc-sr-only" for="eq-pessoa">Representante</label>
                <select class="nc-input nc-auto" id="eq-pessoa" name="pessoa" data-nc-autosubmit>
                    <option value="">Todos os representantes</option>
                    <?php foreach (nc_commission_reps($report) as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"<?= $personFilter === (int)$p['id'] ? ' selected' : '' ?>><?= e((string)$p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <div class="nc-toolbar-actions">
            <a class="nc-btn nc-btn-secondary" href="<?= e(nc_url('acoes/equipe-csv.php?mes=' . $month . ($personFilter > 0 && $isManager ? '&pessoa=' . $personFilter : ''))) ?>"><?= nc_icon('download', 18) ?> Exportar CSV</a>
            <?php if ($canClose): ?>
                <button type="button" class="nc-btn nc-btn-primary" hx-post="<?= e(nc_url('acoes/equipe-fechar.php')) ?>" hx-vals='<?= e((string)json_encode(['mes' => $month])) ?>' hx-swap="none"
                        data-nc-confirm="Fechar <?= e(nc_month_label($month)) ?>? Os valores de comissão de cada pessoa serão congelados e o mês passa a ser exibido a partir do fechamento."
                        data-nc-confirm-title="Fechar mês" data-nc-confirm-ok="Fechar mês">Fechar mês</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$statement): ?>
        <?php nc_empty('Sem pedidos faturados', 'Nenhum pedido faturado neste mês gera comissão para o filtro escolhido.'); ?>
    <?php else:
        $totAmount = 0.0; $totCommission = 0.0; ?>
        <div class="nc-table-wrap">
            <table class="nc-table">
                <thead><tr>
                    <th>Pedido</th><th>Faturado em</th><th>Cliente</th><th>Representante</th>
                    <th class="is-num">Valor do pedido</th><th class="is-num">Base</th><th>% / Política</th><th class="is-num">Comissão</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($statement as $r):
                    $totAmount += (float)$r['amount']; $totCommission += (float)$r['commission'];
                    $st = $r['status'] === 'aberta' ? null : (string)$r['status']; ?>
                    <tr data-nc-filter-item data-nc-filter-text="<?= e(strtolower((string)$r['person'] . ' ' . (string)$r['client'] . ' ' . (string)$r['number'])) ?>">
                        <td class="nc-num-left"><a href="<?= e(nc_url('pedido.php?id=' . (int)$r['order_id'])) ?>" hx-get="<?= e(nc_url('pedido.php?id=' . (int)$r['order_id'])) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML"><?= e((string)$r['number']) ?></a></td>
                        <td><?= e(nc_date((string)$r['invoice_date'])) ?></td>
                        <td><?= e((string)$r['client']) ?> <?= nc_badge($r['modality'] === 'agenciada' ? 'Agenciada' : 'Revenda', $r['modality'] === 'agenciada' ? 'info' : 'neutral') ?></td>
                        <td><?= e((string)$r['person']) ?></td>
                        <td class="is-num"><?= e(money($r['amount'])) ?></td>
                        <td class="is-num"><?= e(money($r['base'])) ?></td>
                        <td><?= e(rtrim(rtrim(number_format((float)$r['pct'], 2, ',', '.'), '0'), ',')) ?>%<br><small class="nc-muted"><?= e((string)$r['policy']) ?></small></td>
                        <td class="is-num"><strong><?= e(money($r['commission'])) ?></strong></td>
                        <td><?= nc_badge(nc_closing_label($st), $st === 'paga' ? 'success' : ($st === 'fechada' ? 'info' : 'warning')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <th colspan="4" scope="row">Total</th>
                    <td class="is-num"><strong><?= e(money($totAmount)) ?></strong></td>
                    <td colspan="2"></td>
                    <td class="is-num"><strong><?= e(money($totCommission)) ?></strong></td>
                    <td></td>
                </tr></tfoot>
            </table>
        </div>
        <p class="nc-empty-filter nc-muted" data-nc-filter-empty hidden>Nenhuma linha encontrada para a busca.</p>
        <p class="nc-help">Somente pedidos faturados no mês entram na apuração. A comissão DNB de agenciamentos é um dado de integração, não um campo manual.</p>
    <?php endif; ?>

    <?php
    $closed = array_values(array_filter($reps, static fn($p) => $summary[(int)$p['id']]['closing_id'] !== null));
    if ($closed): ?>
        <section class="nc-card nc-section" aria-labelledby="h-fech">
            <h2 class="nc-card-title" id="h-fech">Fechamentos de <?= e(nc_month_label($month)) ?></h2>
            <div class="nc-table-wrap nc-flat">
                <table class="nc-table">
                    <thead><tr><th>Representante</th><th class="is-num">Faturado</th><th class="is-num">Comissão</th><th>Status</th><th><span class="nc-sr-only">Ação</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($closed as $p): $s = $summary[(int)$p['id']]; ?>
                        <tr>
                            <td><?= e((string)$p['name']) ?></td>
                            <td class="is-num"><?= e(money($s['billed'])) ?></td>
                            <td class="is-num"><strong><?= e(money($s['commission'])) ?></strong></td>
                            <td><?= nc_badge(nc_closing_label($s['closing_status']), $s['closing_status'] === 'paga' ? 'success' : 'info') ?>
                                <?php if ($s['closing_status'] === 'paga' && $s['paid_at']): ?><small class="nc-muted"> em <?= e(nc_date((string)$s['paid_at'])) ?></small><?php endif; ?></td>
                            <td class="is-actions">
                                <?php if ($isAdmin && $s['closing_status'] === 'fechada'): ?>
                                    <form class="nc-inline-fields" method="post" hx-post="<?= e(nc_url('acoes/equipe-pagar.php')) ?>" hx-swap="none"
                                          data-nc-confirm="Marcar a comissão de <?= e((string)$p['name']) ?> (<?= e(money($s['commission'])) ?>) como paga?"
                                          data-nc-confirm-title="Marcar como paga" data-nc-confirm-ok="Marcar como paga">
                                        <input type="hidden" name="id" value="<?= (int)$s['closing_id'] ?>">
                                        <label class="nc-sr-only" for="pg-<?= (int)$s['closing_id'] ?>">Data do pagamento</label>
                                        <input class="nc-input nc-auto" id="pg-<?= (int)$s['closing_id'] ?>" type="date" name="paid_at" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                                        <button class="nc-btn nc-btn-secondary" type="submit">Marcar como paga</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php
nc_footer();
