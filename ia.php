<?php
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/lead_service.php';
require_once __DIR__ . '/includes/ai_client.php';
exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_VENDEDOR, PAPEL_SUPORTE);

$pdo = db();
$mes = $_GET['mes'] ?? mesAtual();
$leads = listarLeadsAcessiveis($pdo, true);
$leadId = (int) ($_GET['lead_id'] ?? ($leads[0]['id'] ?? 0));
$lead = $leadId ? buscarLeadAutorizado($pdo, $leadId) : null;
$historico = [];
if ($lead) {
    $stmt = $pdo->prepare('SELECT ai.*, u.nome requested_by_name FROM ai_interactions ai JOIN usuarios u ON u.id=ai.requested_by_user_id WHERE ai.lead_id=? ORDER BY ai.created_at DESC LIMIT 20');
    $stmt->execute([$leadId]);
    $historico = $stmt->fetchAll();
}
$tabAtual = 'ia';
include __DIR__ . '/includes/layout_header.php';
?>

<div class="dnb-ai-grid">
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm">
    <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Roteador de IA</span><?= iaConfigurada() ? tag('VPS configurada', 'green') : tag('VPS não configurada', 'amber') ?></div>
    <div class="p-4">
      <?php if (!$leads): ?>
        <div class="dnb-empty-state"><h2>Nenhum lead disponível</h2><p>Não há oportunidades abertas no seu escopo para processar.</p></div>
      <?php else: ?>
      <form method="get" class="mb-4">
        <label class="block text-xs font-semibold mb-1" for="lead_id">Lead / oportunidade</label>
        <select id="lead_id" name="lead_id" class="<?= INPUT_CLS ?>" onchange="this.form.submit()">
          <?php foreach ($leads as $l): ?><option value="<?= (int) $l['id'] ?>" <?= $leadId === (int) $l['id'] ? 'selected' : '' ?>>#<?= (int) $l['id'] ?> · <?= e($l['cliente']) ?> · <?= e($l['numero'] ?: 'sem número') ?></option><?php endforeach; ?>
        </select>
      </form>
      <?php if ($lead): ?>
        <div class="dnb-lead-summary mb-4">
          <div><span>Cliente</span><strong><?= e($lead['cliente']) ?></strong></div>
          <div><span>Etapa</span><strong><?= e($lead['etapa']) ?></strong></div>
          <div><span>Valor</span><strong><?= fmtBRL($lead['valor']) ?></strong></div>
          <div><span>Score</span><strong><?= $lead['lead_score'] !== null ? ((int) $lead['lead_score'] . '/100') : '—' ?></strong></div>
        </div>
        <form data-ai-form data-endpoint="/api/v1/ai/process.php" data-csrf="<?= e(csrfToken()) ?>">
          <input type="hidden" name="lead_id" value="<?= (int) $leadId ?>">
          <label class="block text-xs font-semibold mb-1" for="ai-message">Solicitação para a IA</label>
          <textarea id="ai-message" name="message" class="<?= INPUT_CLS ?> min-h-[150px]" maxlength="<?= AI_MAX_PROMPT_LENGTH ?>" required placeholder="Ex.: analise o histórico, qualifique o lead e sugira a próxima ação comercial."></textarea>
          <div class="flex items-center gap-3 mt-3"><button class="<?= BTN_P ?>" type="submit">Processar na VPS</button><span class="text-xs text-slate-500" data-ai-status aria-live="polite"></span></div>
        </form>
        <div class="dnb-ai-result mt-4" data-ai-result hidden></div>
      <?php endif; endif; ?>
    </div>
  </section>

  <section class="bg-white rounded-xl border border-slate-200 shadow-sm">
    <div class="flex items-center justify-between px-4 pt-4"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Histórico da IA</span></div>
    <div class="p-4">
      <?php foreach ($historico as $item): ?>
        <article class="dnb-history-item">
          <div class="flex justify-between gap-2"><strong><?= e($item['route_name'] ?: 'Processando') ?></strong><?= tag($item['status'], $item['status'] === 'completed' ? 'green' : ($item['status'] === 'failed' ? 'red' : 'amber')) ?></div>
          <p class="text-xs text-slate-500"><?= e($item['requested_by_name']) ?> · <?= e($item['created_at']) ?></p>
          <p><b>Solicitação:</b> <?= e($item['customer_query']) ?></p>
          <?php if ($item['response_text']): ?><p><b>Resposta:</b> <?= nl2br(e($item['response_text'])) ?></p><?php endif; ?>
          <?php if ($item['lead_score'] !== null): ?><p><?= tag('Score ' . $item['lead_score'] . '/100', 'blue') ?> <?= e($item['score_justification']) ?></p><?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if (!$historico): ?><div class="dnb-empty-state"><p>Nenhuma interação registrada para este lead.</p></div><?php endif; ?>
    </div>
  </section>
</div>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
