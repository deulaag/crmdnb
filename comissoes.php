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
      <table class="w-full text-sm min-w-[900px]">
        <thead><tr class="text-left text-xs text-slate-500 border-b border-slate-200">
          <th class="py-2 pr-2">Pessoa · política</th><th class="pr-2">Base do mês</th><th class="pr-2">Atingimento</th>
          <th class="pr-2">Comissão revenda</th><th class="pr-2">Comissão agenciadas</th><th class="pr-2">Bonificação</th><th class="pr-2">Total</th>
        </tr></thead>
        <tbody>
          <?php foreach ($vendedores as $p): $c = $calc['porPessoa'][$p['id']]; ?>
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
          <td colspan="6" class="py-3 text-right font-bold text-slate-600">Total de remuneração variável do período:</td>
          <td class="py-3 font-bold text-lg" style="color:<?= NAVY ?>;"><?= fmtBRL($totalGeral) ?></td>
        </tr></tfoot>
      </table>
    </div>
    <div class="text-xs text-slate-400 mt-2">
      Cálculo conforme políticas vigentes: Vendedor Externo rev02 (0,5% / 1% s/ com. DNB · bonificação em escala 80/90/100% pela meta · reduções de 5% por ocorrência) · Vendedor IR v3 (0,5% / 1% · bonificação ×0,8/0,9/1,0 a partir de 80%) · Suporte IR (0,25% / 0,5% · mesma bonificação) · Interno Sênior (0,5% / 1% · multiplicador ×0–1,2 · dobra por KPIs · teto R$ 9.600) · Representante (30% s/ comissão DNB · 5% s/ margem · +20% se 100% dos KPIs) · Consultor Sênior (regime anual acumulado, faixas 0,35–0,75%, agenciadas 6%, tetos por modalidade e global R$ 120 mil). KPIs apurados automaticamente pelos dados do CRM. Comissão sempre sobre pedidos faturados; pagamento condicionado à liquidação financeira conforme política.
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
