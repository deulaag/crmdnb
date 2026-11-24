<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/partials/comissao.php';

$user = nc_boot();
$pdo = db();

$month = valid_month($_GET['mes'] ?? null);
$aba = ($_GET['aba'] ?? 'resumo') === 'extrato' ? 'extrato' : 'resumo';
$personFilter = (int)($_GET['pessoa'] ?? 0);
$canEditTarget = nc_is_manager($user);

$report = nc_commission_report($pdo, $month);
$people = $report['people'];
$summary = $report['summary'];
$definitions = $report['definitions'];
$policies = $report['policies'];

// Mostra quem está ativo ou teve movimento no mês.
$visible = array_values(array_filter($people, static fn($p) => (int)$p['active'] === 1 || $summary[(int)$p['id']]['orders'] > 0));

$totalBase = 0.0;
$totalBilled = 0.0;
foreach ($visible as $p) {
    $totalBase += $summary[(int)$p['id']]['base_total'];
    $totalBilled += $summary[(int)$p['id']]['billed'];
}

nc_header('Comissões', 'comissoes');
nc_page_header(
    nc_month_label($month) . ' · ' . nc_money_short($totalBilled) . ' faturados · ' . nc_money_short($totalBase) . ' de comissão base',
    'Comissões',
    '<form method="get" class="nc-inline-field"><input type="hidden" name="aba" value="' . e($aba) . '">'
    . '<label for="c-mes">Mês</label><input class="nc-input" id="c-mes" type="month" name="mes" value="' . e($month) . '" data-nc-autosubmit></form>'
);
?>
<div class="nc-tabs" role="tablist" aria-label="Seções">
    <a class="nc-tab<?= $aba === 'resumo' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $aba === 'resumo' ? 'true' : 'false' ?>" href="<?= e(nc_query_url(['aba' => null])) ?>">Equipe</a>
    <a class="nc-tab<?= $aba === 'extrato' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $aba === 'extrato' ? 'true' : 'false' ?>" href="<?= e(nc_query_url(['aba' => 'extrato'])) ?>">Extrato</a>
</div>

<?php if (!$report['has_target'] && nc_is_admin($user)): ?>
    <div class="nc-alert nc-alert-warning" role="status">
        A coluna de meta mensal ainda não existe, então as metas ficam ocultas.
        <a href="<?= e(nc_url('migrations/run.php')) ?>">Aplicar migrações</a>.
    </div>
<?php endif; ?>

<?php if ($aba === 'resumo'): ?>
    <?php if (!$visible): ?>
        <?php nc_empty('Nenhuma pessoa cadastrada', 'Cadastre usuários e defina as políticas de comissão em Configurações.'); ?>
    <?php else: ?>
        <div class="nc-people">
            <?php foreach ($visible as $p):
                $id = (int)$p['id'];
                $s = $summary[$id];
                $policy = $policies[$id];
                $def = $definitions[$policy['policy_key']] ?? $definitions['sem_politica'];
                $pct = $s['target'] !== null && $s['target'] > 0 ? $s['billed'] / $s['target'] * 100 : null; ?>
                <article class="nc-person<?= (int)$p['active'] === 1 ? '' : ' is-inactive' ?>">
                    <header class="nc-person-head">
                        <span class="nc-avatar" aria-hidden="true"><?= e(nc_initials((string)$p['name'])) ?></span>
                        <div>
                            <h2><?= e((string)$p['name']) ?></h2>
                            <p class="nc-muted"><?= e((string)$def['label']) ?><?= (int)$p['active'] === 1 ? '' : ' · inativo' ?></p>
                        </div>
                    </header>
                    <dl class="nc-person-stats">
                        <div><dt>Faturado no mês</dt><dd class="nc-num"><?= e(money($s['billed'])) ?></dd></div>
                        <div><dt>Comissão calculada</dt><dd class="nc-num"><strong><?= e(money($s['base_total'])) ?></strong></dd></div>
                        <div><dt>Pedidos</dt><dd class="nc-num"><?= (int)$s['orders'] ?></dd></div>
                    </dl>
                    <?php if ($s['agenciado'] > 0 && $s['dnb_commission'] <= 0): ?>
                        <p class="nc-help">Agenciadas aguardando a base de comissão DNB da integração.</p>
                    <?php endif; ?>

                    <?php if ($pct !== null): ?>
                        <div class="nc-goal">
                            <div class="nc-bar-head"><span>Meta <?= e(money($s['target'])) ?></span><strong class="nc-num"><?= e(number_format($pct, 1, ',', '.')) ?>% atingido</strong></div>
                            <div class="nc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)min(100, round($pct)) ?>" aria-label="Meta de <?= e((string)$p['name']) ?>">
                                <span style="width:<?= round(min(100, $pct), 1) ?>%"></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($canEditTarget && $report['has_target']): ?>
                        <details class="nc-details nc-details-neutral">
                            <summary><?= $s['target'] !== null ? 'Editar meta' : 'Definir meta' ?></summary>
                            <form class="nc-inline-fields" method="post" hx-post="<?= e(nc_url('acoes/meta-salvar.php')) ?>" hx-swap="none">
                                <input type="hidden" name="user_id" value="<?= $id ?>">
                                <label class="nc-sr-only" for="meta-<?= $id ?>">Meta mensal de <?= e((string)$p['name']) ?></label>
                                <input class="nc-input" id="meta-<?= $id ?>" type="number" name="monthly_target" step="0.01" min="0" value="<?= $s['target'] !== null ? e((string)$s['target']) : '' ?>" placeholder="Meta mensal (R$)">
                                <button class="nc-btn nc-btn-secondary" type="submit">Salvar</button>
                            </form>
                            <small class="nc-help">Deixe em branco para remover a meta.</small>
                        </details>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="nc-help">As políticas individuais ficam em <a href="/configuracoes.php#politicas-pessoa">Configurações → Políticas por pessoa</a> (versão clássica).</p>
    <?php endif; ?>

<?php else:
    $rows = array_values(array_filter($report['statement'], static fn($r) => $personFilter === 0 || $r['person_id'] === $personFilter));
    $sumRows = array_sum(array_map(static fn($r) => $r['commission'], $rows)); ?>
    <form method="get" class="nc-toolbar">
        <input type="hidden" name="aba" value="extrato"><input type="hidden" name="mes" value="<?= e($month) ?>">
        <label class="nc-sr-only" for="c-pessoa">Pessoa</label>
        <select class="nc-input nc-auto" id="c-pessoa" name="pessoa" data-nc-autosubmit>
            <option value="">Todas as pessoas</option>
            <?php foreach ($visible as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $personFilter === (int)$p['id'] ? ' selected' : '' ?>><?= e((string)$p['name']) ?></option><?php endforeach; ?>
        </select>
    </form>

    <?php if (!$rows): ?>
        <?php nc_empty('Sem pedidos faturados', 'Nenhum pedido faturado neste mês gera comissão para o filtro escolhido.'); ?>
    <?php else: ?>
        <div class="nc-table-wrap">
            <table class="nc-table">
                <thead><tr><th>Faturado em</th><th>Pedido</th><th>Cliente</th><th>Modalidade</th><th class="is-num">Valor</th><th>Pessoa</th><th class="is-num">Comissão</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $o = $r['order']; ?>
                    <tr>
                        <td><?= e(nc_date((string)$o['invoice_date'])) ?></td>
                        <td class="nc-num-left"><a href="<?= e(nc_url('pedido.php?id=' . (int)$o['id'])) ?>"><?= e((string)$o['number']) ?></a></td>
                        <td><?= e((string)$o['client_name']) ?></td>
                        <td><?= nc_badge($o['modality'] === 'agenciada' ? 'Agenciada' : 'Revenda', $o['modality'] === 'agenciada' ? 'info' : 'neutral') ?></td>
                        <td class="is-num"><?= e(money($o['amount'])) ?></td>
                        <td><?= e((string)$r['person']) ?></td>
                        <td class="is-num"><?= e(money($r['commission'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th colspan="6" scope="row">Total</th><td class="is-num"><strong><?= e(money($sumRows)) ?></strong></td></tr></tfoot>
            </table>
        </div>
        <p class="nc-help">Somente pedidos faturados no mês entram na apuração. A comissão DNB de agenciamentos é um dado de integração, não um campo manual.</p>
    <?php endif; ?>
<?php endif;

nc_footer();
