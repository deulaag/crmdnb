<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/calc.php';
exigirLogin();

$mes = $_GET['mes'] ?? mesAtual();
$pdo = db();
$vendedores = obterVendedores($pdo);
$calc = calcularMes($pdo, $mes, $vendedores);
$politicasArr = politicas();

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
          <td class="py-2 pr-2">
            <div class="font-semibold" style="color:<?= NAVY ?>;"><?= e($v['nome']) ?></div>
            <div class="text-xs text-slate-400"><?= e($politicasArr[$v['politica']]['label'] ?? $v['politica']) ?></div>
            <?php if ($d['rampa'] < 1): ?><?= tag('rampa mês ' . $d['mesAtuacao'] . ' · ' . round($d['rampa'] * 100) . '%', 'amber') ?><?php endif; ?>
          </td>
          <td class="pr-2">
            <div class="text-xs text-slate-500"><?= fmtBRL($d['propostas']) ?><?= $d['metaProp'] ? (' / ' . fmtBRL($d['metaProp'])) : '' ?></div>
            <?php if ($d['metaProp'] > 0): ?><?= farol(($d['propostas'] / $d['metaProp']) * 100) ?><?php endif; ?>
          </td>
          <td class="pr-2">
            <div class="text-xs text-slate-500"><?= fmtBRL($d['revFat']) ?><?= $metaRev ? (' / ' . fmtBRL($metaRev) . ($v['politica'] === 'consultor_senior' ? ' (ref. mensal)' : '')) : '' ?></div>
            <?php if ($metaRev > 0): ?><?= farol(($d['revFat'] / $metaRev) * 100) ?><?php endif; ?>
          </td>
          <td class="pr-2">
            <?php if ((float) ($pol['metaAgenciadas'] ?? 0) > 0): ?>
              <div class="text-xs text-slate-500"><?= fmtBRL($d['agInterm']) ?> / <?= fmtBRL($pol['metaAgenciadas']) ?></div>
              <?= farol(($d['agInterm'] / $pol['metaAgenciadas']) * 100) ?>
            <?php else: ?>
              <span class="text-xs text-slate-400"><?= $d['agInterm'] ? fmtBRL($d['agInterm']) : '—' ?></span>
            <?php endif; ?>
          </td>
          <td class="pr-2">
            <?php if ($metaAtiv > 0): ?>
              <div class="text-xs text-slate-500"><?= $ativ ?> / <?= $metaAtiv ?> <?= $v['politica'] === 'interno' ? 'prospecções' : 'visitas' ?></div>
              <?= farol(($ativ / $metaAtiv) * 100) ?>
            <?php else: ?>
              <span class="text-xs text-slate-400">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="text-xs text-slate-400 mt-3">Meta de propostas derivada da meta de vendas ÷ conversão (IR peças/serviços 40% · equipamentos 20% · demais 15%). Consultor Sênior: metas anuais, referência mensal proporcional.</div>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">KPIs automáticos (gatilhos de bonificação — apurados dos dados do CRM)</span>
  </div>
  <div class="p-4">
    <?php foreach ($vendedores as $v):
      $d = $calc['porPessoa'][$v['id']];
      if (empty($d['kpis'])) continue;
    ?>
      <div class="border border-slate-200 rounded-lg p-3 mb-3">
        <div class="flex items-center gap-2 mb-2">
          <span class="font-semibold" style="color:<?= NAVY ?>;"><?= e($v['nome']) ?></span>
          <?= $d['kpiOk'] ? tag('100% — bonificação liberada', 'green') : tag('gatilho não atingido', 'amber') ?>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1">
          <?php foreach ($d['kpis'] as $k):
            $vf = is_callable($k['fmt']) ? call_user_func($k['fmt'], $k['valor']) : $k['valor'];
            $mf = is_callable($k['fmt']) ? call_user_func($k['fmt'], $k['meta']) : $k['meta'];
          ?>
            <div class="flex items-center justify-between text-xs py-1 border-b border-slate-100">
              <span class="<?= $k['ok'] ? 'text-slate-600' : 'text-red-700 font-semibold' ?>"><?= dnbIcon($k['ok'] ? 'check-circle' : 'x-circle', 'dnb-status-icon') ?><?= e($k['nome']) ?></span>
              <span class="text-slate-500"><?= e((string) $vf) ?> / <?= e((string) $mf) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="text-xs text-slate-400">Indicadores sem registro no CRM (tempo médio de resposta, reativações de inativos, relatórios semanais) permanecem em verificação documental pela diretoria e não bloqueiam o gatilho automático.</div>
  </div>
</div>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
