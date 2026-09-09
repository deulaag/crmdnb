<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/calc.php';
exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_VENDEDOR);

$pdo = db();
$mes = $_GET['mes'] ?? mesAtual();
$vendedores = filtrarPessoasElegiveisComissao($pdo, filtrarVendedoresDaEquipe($pdo, obterVendedores($pdo)));
$calc = calcularMes($pdo, $mes, $vendedores);
$politicasArr = politicas();

// Movimentações executadas pelo próprio usuário no fluxo do CRM. A coluna
// actor_user_id é instalada pela migração 20260908_timeline_actor.sql.
$movimentacoes = [];
try {
    $stmtMov = $pdo->prepare("SELECT COALESCE(u.pessoa_id,u.vendedor_id) pessoa_id,
        COUNT(*) total_movimentos,
        COUNT(DISTINCT lt.lead_id) oportunidades_movimentadas,
        SUM(lt.event_type IN ('created','funnel_update','status_update','followup_paused','followup_resumed','lead_score')) movimentos_indicador
        FROM lead_timeline lt
        JOIN usuarios u ON u.id=lt.actor_user_id
        WHERE DATE_FORMAT(lt.created_at,'%Y-%m')=?
          AND COALESCE(u.pessoa_id,u.vendedor_id) IS NOT NULL
        GROUP BY COALESCE(u.pessoa_id,u.vendedor_id)");
    $stmtMov->execute([$mes]);
    foreach ($stmtMov->fetchAll() as $mov) $movimentacoes[(string) $mov['pessoa_id']] = $mov;
} catch (Throwable) {
    // Instalações ainda sem a migração continuam exibindo o cálculo financeiro.
}

$totalGeral = 0;
foreach ($vendedores as $p) $totalGeral += $calc['porPessoa'][$p['id']]['total'] ?? 0;

$tabAtual = 'com';
include __DIR__ . '/includes/layout_header.php';
?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Relatório mensal de comissões e bonificações · <?= e(rotuloMes($mes)) ?></span>
    <button onclick="window.print()" class="<?= BTN_S ?> no-print">Imprimir / PDF</button>
  </div>
  <div class="p-4">
    <div class="overflow-x-auto">
      <table class="w-full text-sm min-w-[1040px]">
        <thead><tr class="text-left text-xs text-slate-500 border-b border-slate-200">
          <th class="py-2 pr-2">Pessoa · política</th><th class="pr-2">Base do mês</th><th class="pr-2">Atingimento</th><th class="pr-2">Movimentações CRM</th>
          <th class="pr-2">Comissão revenda</th><th class="pr-2">Comissão agenciadas</th><th class="pr-2">Bonificação</th><th class="pr-2">Total</th>
        </tr></thead>
        <tbody>
          <?php foreach ($vendedores as $p): $c = $calc['porPessoa'][$p['id']]; $mov=$movimentacoes[(string)$p['id']]??null; ?>
          <tr class="border-b border-slate-100 align-top">
            <td class="py-2 pr-2">
              <div class="font-semibold" style="color:<?= NAVY ?>;"><?= e($p['nome']) ?></div>
              <div class="text-xs text-slate-400"><?= e($politicasArr[$p['politica']]['label'] ?? $p['politica']) ?></div>
              <?php foreach ($c['notas'] as $n): ?><div class="text-xs text-amber-700 mt-1 max-w-[300px]"><?= e($n) ?></div><?php endforeach; ?>
            </td>
            <td class="pr-2 text-xs text-slate-600">
              Revenda: <?= fmtBRL($c['revFat']) ?><br>
              <?php if ($c['agInterm'] > 0): ?>Agenc.: <?= fmtBRL($c['agInterm']) ?> <span class="text-slate-400">(com. DNB <?= fmtBRL($c['agComDNB']) ?>)</span><?php endif; ?>
            </td>
            <td class="pr-2">
              <div class="text-xs font-bold" style="color:<?= $c['ating'] >= 100 ? '#15803d' : ($c['ating'] >= 80 ? '#b45309' : '#b91c1c') ?>;"><?= round($c['ating']) ?>%</div>
              <div class="text-xs text-slate-400"><?= e($c['faixaTxt']) ?></div>
            </td>
            <td class="pr-2 text-xs text-slate-600">
              <?php if ($mov): ?>
                <strong><?= (int)$mov['total_movimentos'] ?></strong> ação(ões)<br>
                <span class="text-slate-400"><?= (int)$mov['oportunidades_movimentadas'] ?> oportunidade(s)</span><br>
                <span class="text-slate-400"><?= (int)$mov['movimentos_indicador'] ?> evento(s) de indicador</span>
              <?php else: ?><span class="text-slate-400">Sem eventos rastreados</span><?php endif; ?>
            </td>
            <td class="pr-2 font-bold"><?= fmtBRL($c['comRevenda']) ?></td>
            <td class="pr-2 font-bold"><?= fmtBRL($c['comAgenciada']) ?></td>
            <td class="pr-2">
              <span class="font-bold" style="color:<?= $c['bonif'] > 0 ? '#15803d' : '#64748b' ?>;"><?= fmtBRL($c['bonif']) ?></span>
              <?php if ($c['teto']): ?><div class="text-xs text-amber-700"><?= e($c['teto']) ?></div><?php endif; ?>
            </td>
            <td class="pr-2 font-bold" style="color:<?= NAVY ?>;"><?= fmtBRL($c['total']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
          <td colspan="7" class="py-3 text-right font-bold text-slate-600">Total de remuneração variável do período:</td>
          <td class="py-3 font-bold text-lg" style="color:<?= NAVY ?>;"><?= fmtBRL($totalGeral) ?></td>
        </tr></tfoot>
      </table>
    </div>
    <div class="text-xs text-slate-400 mt-2">
      A rastreabilidade acompanha as ações efetivamente executadas pelo usuário no fluxo da oportunidade. Eventos que correspondem a indicadores ficam identificados no relatório; a base financeira de comissão continua sendo formada pelos pedidos faturados e pelas regras da política comercial vigente. KPIs seguem apurados pelos dados registrados no CRM.
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
