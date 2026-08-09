<?php
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/lead_service.php';
exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_VENDEDOR, PAPEL_SUPORTE);
$pdo = db();
$mes = $_GET['mes'] ?? mesAtual();
$leadId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$lead = $leadId ? buscarLeadAutorizado($pdo, $leadId) : null;
if (!$lead) { http_response_code(404); $titulo='Lead não encontrado'; $mensagem='O lead não existe ou está fora do seu escopo.'; include __DIR__ . '/includes/forbidden.php'; exit; }

function leadRedirect(int $leadId, string $msg): void
{
    $_SESSION['flash'] = $msg;
    header('Location: /lead.php?id=' . $leadId); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'reatribuir' && podeReatribuirLead()) {
        $novoId = (int) ($_POST['assigned_to_user_id'] ?? 0);
        $permitidos = [];
        if (temPapel(PAPEL_ADMINISTRADOR)) {
            $permitidos = array_map('intval', array_column($pdo->query("SELECT id FROM usuarios WHERE status='active' AND role IN ('VENDEDOR','SUPERVISOR')")->fetchAll(), 'id'));
        } else {
            $permitidos = idsUsuariosDaEquipe($pdo);
        }
        if (!$novoId || !in_array($novoId, $permitidos, true)) leadRedirect($leadId, 'Responsável fora do seu escopo.');
        $stmt = $pdo->prepare('SELECT nome,vendedor_id FROM usuarios WHERE id=?'); $stmt->execute([$novoId]); $novo = $stmt->fetch();
        $pdo->prepare('UPDATE orcamentos SET assigned_to_user_id=?,vendedor_id=COALESCE(?,vendedor_id) WHERE id=?')->execute([$novoId, $novo['vendedor_id'] ?: null, $leadId]);
        registrarTimeline($pdo, $leadId, 'assignment', 'Lead atribuído a ' . $novo['nome'] . '.');
        leadRedirect($leadId, 'Responsável atualizado.');
    }
    if ($acao === 'atualizar' && !temPapel(PAPEL_SUPORTE)) {
        $status = in_array($_POST['status'] ?? '', ['Aberta','Ganha','Perdida'], true) ? $_POST['status'] : $lead['status'];
        $etapa = mb_substr(trim((string) ($_POST['etapa'] ?? $lead['etapa'])), 0, 80);
        $nota = trim((string) ($_POST['nota'] ?? ''));
        $pdo->prepare('UPDATE orcamentos SET etapa=?,status=? WHERE id=?')->execute([$etapa, $status, $leadId]);
        registrarTimeline($pdo, $leadId, 'funnel_update', 'Etapa atualizada para ' . $etapa . ' e status para ' . $status . '.');
        if ($nota !== '') registrarTimeline($pdo, $leadId, 'note', $nota);
        leadRedirect($leadId, 'Lead atualizado.');
    }
}

$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$lead = buscarLead($pdo, $leadId);
$timeline = listarTimeline($pdo, $leadId);
$stmt = $pdo->prepare('SELECT ai.*,u.nome requested_by_name FROM ai_interactions ai JOIN usuarios u ON u.id=ai.requested_by_user_id WHERE ai.lead_id=? ORDER BY ai.created_at DESC LIMIT 20'); $stmt->execute([$leadId]); $interacoes = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT sc.*,u.nome agent_name FROM support_cases sc LEFT JOIN usuarios u ON u.id=sc.agent_id WHERE sc.lead_id=? ORDER BY sc.created_at DESC'); $stmt->execute([$leadId]); $casos = $stmt->fetchAll();
if (temPapel(PAPEL_ADMINISTRADOR)) {
    $responsaveis = $pdo->query("SELECT id,nome,role FROM usuarios WHERE status='active' AND role IN ('VENDEDOR','SUPERVISOR') ORDER BY nome")->fetchAll();
} elseif (temPapel(PAPEL_SUPERVISOR)) {
    $ids = idsUsuariosDaEquipe($pdo); $marks = implode(',', array_fill(0,count($ids),'?')); $stmt=$pdo->prepare("SELECT id,nome,role FROM usuarios WHERE id IN ({$marks}) ORDER BY nome"); $stmt->execute($ids); $responsaveis=$stmt->fetchAll();
} else $responsaveis=[];
$funil = funis()[$lead['funil'] ?: funilDe($lead['linha'])] ?? null;
$tabAtual = 'orc';
include __DIR__ . '/includes/layout_header.php';
?>
<?php if ($flash): ?><div class="dnb-alert dnb-alert--success" role="status"><?= e($flash) ?></div><?php endif; ?>
<div class="dnb-lead-page">
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm">
    <div class="p-4">
      <div class="flex items-start justify-between gap-4 flex-wrap"><div><span class="dnb-eyebrow">Lead #<?= $leadId ?> · <?= e($lead['numero']) ?></span><h2 class="dnb-section-title"><?= e($lead['cliente']) ?></h2><p class="text-sm text-slate-500"><?= e($lead['linha']) ?> · <?= fmtBRL($lead['valor']) ?></p></div><div class="dnb-score <?= ((int)($lead['lead_score']??0))>=70?'is-hot':'' ?>"><span>Score</span><strong><?= $lead['lead_score']!==null?(int)$lead['lead_score']:'—' ?></strong><small>/100</small></div></div>
      <div class="dnb-lead-summary mt-4"><div><span>Status</span><strong><?= e($lead['status']) ?></strong></div><div><span>Etapa</span><strong><?= e($lead['etapa']) ?></strong></div><div><span>Responsável</span><strong><?= e($lead['assigned_to_name'] ?: 'Não atribuído') ?></strong></div><div><span>Origem</span><strong><?= e($lead['origem']) ?></strong></div></div>
      <?php if ($lead['score_justification']): ?><div class="dnb-insight mt-4"><b>Justificativa do score</b><p><?= e($lead['score_justification']) ?></p></div><?php endif; ?>
      <?php if (podeReatribuirLead()): ?><form method="post" class="flex gap-2 items-end mt-4"><?= csrfInput() ?><input type="hidden" name="acao" value="reatribuir"><input type="hidden" name="id" value="<?= $leadId ?>"><div class="flex-1"><label class="block text-xs font-semibold mb-1">Reatribuir responsável</label><select name="assigned_to_user_id" class="<?= INPUT_CLS ?>" required><option value="">Selecione</option><?php foreach($responsaveis as $u):?><option value="<?= (int)$u['id'] ?>" <?= (int)$lead['assigned_to_user_id']===(int)$u['id']?'selected':'' ?>><?= e($u['nome']) ?> · <?= e($u['role']) ?></option><?php endforeach;?></select></div><button class="<?= BTN_P ?>">Reatribuir</button></form><?php endif; ?>
      <?php if (!temPapel(PAPEL_SUPORTE)): ?><form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end mt-4"><?= csrfInput() ?><input type="hidden" name="acao" value="atualizar"><input type="hidden" name="id" value="<?= $leadId ?>"><div><label class="block text-xs font-semibold mb-1">Etapa</label><select name="etapa" class="<?= INPUT_CLS ?>"><?php foreach(($funil['etapas']??[$lead['etapa']]) as $et):?><option <?= $lead['etapa']===$et?'selected':'' ?>><?= e($et) ?></option><?php endforeach;?></select></div><div><label class="block text-xs font-semibold mb-1">Status</label><select name="status" class="<?= INPUT_CLS ?>"><?php foreach(['Aberta','Ganha','Perdida'] as $st):?><option <?= $lead['status']===$st?'selected':'' ?>><?= $st ?></option><?php endforeach;?></select></div><div><label class="block text-xs font-semibold mb-1">Nota na timeline</label><input name="nota" class="<?= INPUT_CLS ?>" placeholder="Contexto da atualização"></div><button class="<?= BTN_P ?> md:col-span-3">Salvar evolução</button></form><?php endif; ?>
      <div class="flex gap-2 mt-4"><a href="/ia.php?lead_id=<?= $leadId ?>" class="<?= BTN_P ?> inline-flex">Abrir na IA</a><a href="/orcamentos.php" class="<?= BTN_S ?> inline-flex">Voltar ao funil</a></div>
    </div>
  </section>
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm">
    <div class="px-4 pt-4"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Linha do tempo</span></div>
    <div class="p-4 dnb-timeline"><?php foreach($timeline as $ev):?><article><span></span><div><strong><?= e($ev['event_type']) ?></strong><p><?= nl2br(e($ev['description'])) ?></p><small><?= e($ev['actor']) ?> · <?= e($ev['created_at']) ?></small></div></article><?php endforeach;?><?php if(!$timeline):?><div class="dnb-empty-state"><p>Nenhum evento registrado.</p></div><?php endif;?></div>
  </section>
</div>
<?php if ($casos): ?><section class="bg-white rounded-xl border border-slate-200 shadow-sm mt-4"><div class="p-4"><h2 class="dnb-section-title">Casos de suporte</h2><?php foreach($casos as $c):?><p><a href="/casos.php?caso=<?= (int)$c['id'] ?>">Caso #<?= (int)$c['id'] ?></a> · <?= e($c['status']) ?> · <?= e($c['agent_name'] ?: 'sem agente') ?></p><?php endforeach;?></div></section><?php endif; ?>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
