<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/calc.php';
require_once __DIR__ . '/includes/task_service.php';
require_once __DIR__ . '/includes/profile_service.php';
exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_VENDEDOR);

$mes = $_GET['mes'] ?? mesAtual();
$pdo = db();
$vendedores = filtrarVendedoresDaEquipe($pdo, obterVendedores($pdo));
$calc = calcularMes($pdo, $mes, $vendedores);
$politicasArr = politicas();
$tarefas = listarTarefasPainel($pdo, usuarioLogado());

$tot = function (string $k) use ($calc) {
    $s = 0;
    foreach ($calc['porPessoa'] as $v) $s += $v[$k] ?? 0;
    return $s;
};
$revFatMes = array_sum(array_map(fn($p) => ($p['modalidade'] ?? '') !== 'agenciada' ? (float) $p['valor'] : 0, $calc['pedFatMes']));
$agFatMes = array_sum(array_map(fn($p) => ($p['modalidade'] ?? '') === 'agenciada' ? (float) $p['valor'] : 0, $calc['pedFatMes']));
$agComMes = array_sum(array_map(fn($p) => ($p['modalidade'] ?? '') === 'agenciada' ? (float) ($p['comissao_dnb'] ?? 0) : 0, $calc['pedFatMes']));
$kpisTopo = [
    ['Propostas emitidas', fmtBRL($tot('propostas')), count($calc['orcMes']) . ' no mês'],
    ['Revenda faturada', fmtBRL($revFatMes), count($calc['pedFatMes']) . ' pedidos faturados'],
    ['Agenciadas (intermediado)', fmtBRL($agFatMes), 'comissão DNB: ' . fmtBRL($agComMes)],
    ['Visitas + prospecções', (string) count($calc['visMes']), rotuloMes($mes)],
];

$tabAtual = 'painel';
include __DIR__ . '/includes/layout_header.php';
?>

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
  <?php foreach ($kpisTopo as [$l, $v, $s]): ?>
    <div class="bg-white rounded-xl border border-slate-200 p-4">
      <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide"><?= e($l) ?></div>
      <div class="text-xl font-bold mt-1" style="color:<?= NAVY ?>;"><?= e($v) ?></div>
      <div class="text-xs text-slate-400"><?= e($s) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<?php if (ehOperadorVendedor()): ?>
<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Farol de metas · <?= e(rotuloMes($mes)) ?></span>
  </div>
  <div class="p-4">
    <div class="overflow-x-auto">
    <table class="w-full text-sm min-w-[860px]">
      <thead><tr class="text-left text-xs text-slate-500 border-b border-slate-200">
        <th class="py-2 pr-2">Pessoa · política</th><th class="pr-2">Propostas (meta derivada)</th><th class="pr-2">Revenda faturada</th><th class="pr-2">Agenciadas</th><th class="pr-2">Atividade</th>
      </tr></thead>
      <tbody>
        <?php foreach ($vendedores as $v):
          $d = $calc['porPessoa'][$v['id']];
          $pol = $d['polEfetiva'] ?: $v['pol'];
          $metaAtiv = $v['politica'] === 'interno' ? (float) ($pol['prospecMes'] ?? 0) : (float) ($pol['visitasMes'] ?? 0);
          $ativ = $v['politica'] === 'interno' ? $d['prosp'] : $d['vis'];
          $metaRev = $v['politica'] === 'consultor_senior' ? ((float) ($pol['metaPropriasAno'] ?? 0)) / 12 : (float) ($pol['metaRevenda'] ?? 0);
        ?>
        <tr class="border-b border-slate-100">
          <td class="py-2 pr-2"><div class="font-semibold" style="color:<?= NAVY ?>;"><?= e($v['nome']) ?></div><div class="text-xs text-slate-400"><?= e($politicasArr[$v['politica']]['label'] ?? $v['politica']) ?></div><?php if ($d['rampa'] < 1): ?><?= tag('rampa mês ' . $d['mesAtuacao'] . ' · ' . round($d['rampa'] * 100) . '%', 'amber') ?><?php endif; ?></td>
          <td class="pr-2"><div class="text-xs text-slate-500"><?= fmtBRL($d['propostas']) ?><?= $d['metaProp'] ? (' / ' . fmtBRL($d['metaProp'])) : '' ?></div><?php if ($d['metaProp'] > 0): ?><?= farol(($d['propostas'] / $d['metaProp']) * 100) ?><?php endif; ?></td>
          <td class="pr-2"><div class="text-xs text-slate-500"><?= fmtBRL($d['revFat']) ?><?= $metaRev ? (' / ' . fmtBRL($metaRev) . ($v['politica'] === 'consultor_senior' ? ' (ref. mensal)' : '')) : '' ?></div><?php if ($metaRev > 0): ?><?= farol(($d['revFat'] / $metaRev) * 100) ?><?php endif; ?></td>
          <td class="pr-2"><?php if ((float) ($pol['metaAgenciadas'] ?? 0) > 0): ?><div class="text-xs text-slate-500"><?= fmtBRL($d['agInterm']) ?> / <?= fmtBRL($pol['metaAgenciadas']) ?></div><?= farol(($d['agInterm'] / $pol['metaAgenciadas']) * 100) ?><?php else: ?><span class="text-xs text-slate-400"><?= $d['agInterm'] ? fmtBRL($d['agInterm']) : '—' ?></span><?php endif; ?></td>
          <td class="pr-2"><?php if ($metaAtiv > 0): ?><div class="text-xs text-slate-500"><?= $ativ ?> / <?= $metaAtiv ?> <?= $v['politica'] === 'interno' ? 'prospecções' : 'visitas' ?></div><?= farol(($ativ / $metaAtiv) * 100) ?><?php else: ?><span class="text-xs text-slate-400">—</span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="text-xs text-slate-400 mt-3">Seu farol usa apenas os registros vinculados ao seu usuário no CRM.</div>
  </div>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4" data-crm-tasks-panel>
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <div><span class="dnb-eyebrow">Acompanhamento diário</span><h2 class="dnb-section-title">Tarefas dos orçamentos</h2></div>
    <span class="text-xs text-slate-500"><?= count($tarefas) ?> pendente(s)</span>
  </div>
  <div class="p-4">
    <?php if (!tarefasDisponiveis($pdo)): ?>
      <div class="dnb-alert dnb-alert--warning">Instale <code>sql/migrations/20260909_crm_tasks.sql</code> para habilitar o acompanhamento de tarefas.</div>
    <?php elseif (!$tarefas): ?>
      <div class="dnb-empty-state"><p>Nenhuma tarefa pendente no seu escopo. As tarefas são criadas junto com os orçamentos ou adicionadas depois.</p></div>
    <?php else: ?>
      <div class="dnb-task-list">
      <?php foreach ($tarefas as $t):
          $dias = (int) floor((strtotime((string)$t['due_date']) - strtotime(hoje())) / 86400);
          $urgencia = $dias < 0 ? 'atrasada' : ($dias === 0 ? 'hoje' : ($dias <= 2 ? 'proxima' : 'normal'));
          $nomeResp = nomeUsuarioComHumor($pdo,(int)$t['assigned_to_user_id'],(string)$t['responsavel_nome']);
      ?>
        <article class="dnb-task-card is-<?= e($urgencia) ?>" data-task-id="<?= (int)$t['id'] ?>">
          <div class="dnb-task-card__main"><strong><?= e($t['title']) ?></strong><span><?= e($t['cliente']) ?> · orçamento <?= e($t['numero'] ?: ('#'.$t['orcamento_id'])) ?></span><small><?= e($nomeResp) ?> · prazo <?= e($t['due_date']) ?><?= $dias < 0 ? ' · atrasada ' . abs($dias) . ' dia(s)' : ($dias === 0 ? ' · vence hoje' : ' · faltam ' . $dias . ' dia(s)') ?></small></div>
          <div class="dnb-task-card__actions"><a href="/lead.php?id=<?= (int)$t['orcamento_id'] ?>" class="<?= BTN_S ?>">Abrir orçamento</a><button type="button" class="<?= BTN_P ?>" data-complete-crm-task="<?= (int)$t['id'] ?>">Concluir</button></div>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="text-xs text-slate-400 mt-3">Este painel substitui os antigos KPIs automáticos. O histórico financeiro e os indicadores continuam sendo apurados nos relatórios; aqui o foco é o trabalho diário dos vendedores.</p>
  </div>
</div>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
