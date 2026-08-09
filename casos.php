<?php
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/lead_service.php';
exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_SUPORTE);
$pdo = db();
$mes = $_GET['mes'] ?? mesAtual();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    if (!podeGerenciarCasos()) {
        http_response_code(403); exit('Sem permissão para alterar casos.');
    }
    $casoId = (int) ($_POST['id'] ?? 0);
    $status = in_array($_POST['status'] ?? '', ['open','in_progress','resolved'], true) ? $_POST['status'] : 'open';
    $stmt = $pdo->prepare('SELECT lead_id FROM support_cases WHERE id=?');
    $stmt->execute([$casoId]);
    $leadId = (int) $stmt->fetchColumn();
    if ($leadId) {
        $agentId = $status === 'open' ? null : (int) usuarioLogado()['id'];
        $pdo->prepare('UPDATE support_cases SET status=?,agent_id=?,updated_at=NOW() WHERE id=?')->execute([$status, $agentId, $casoId]);
        registrarTimeline($pdo, $leadId, 'support_case', 'Caso #' . $casoId . ' atualizado para ' . $status . '.');
    }
    header('Location: /casos.php?caso=' . $casoId); exit;
}

$where = '1=1'; $params = [];
if (temPapel(PAPEL_SUPERVISOR)) {
    [$where, $params] = filtroEscopoAtribuicao($pdo, 'o.assigned_to_user_id');
}
$filtroStatus = $_GET['status'] ?? '';
if (in_array($filtroStatus, ['open','in_progress','resolved'], true)) { $where .= ' AND sc.status=?'; $params[] = $filtroStatus; }
$stmt = $pdo->prepare("SELECT sc.*,o.cliente,o.numero,o.assigned_to_user_id,u.nome agent_name,owner.nome owner_name
    FROM support_cases sc JOIN orcamentos o ON o.id=sc.lead_id
    LEFT JOIN usuarios u ON u.id=sc.agent_id LEFT JOIN usuarios owner ON owner.id=o.assigned_to_user_id
    WHERE {$where} ORDER BY FIELD(sc.status,'open','in_progress','resolved'),sc.created_at DESC");
$stmt->execute($params); $casos = $stmt->fetchAll();
$selecionadoId = (int) ($_GET['caso'] ?? ($casos[0]['id'] ?? 0));
$selecionado = null;
foreach ($casos as $c) if ((int) $c['id'] === $selecionadoId) { $selecionado = $c; break; }
if ($selecionado) $pdo->prepare("UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND link=?")->execute([(int) usuarioLogado()['id'], '/casos.php?caso=' . $selecionadoId]);
$tabAtual = 'cas';
include __DIR__ . '/includes/layout_header.php';
?>

<div class="dnb-case-grid">
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm">
    <div class="flex items-center justify-between px-4 pt-4"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Casos de atendimento</span></div>
    <div class="p-4">
      <form method="get" class="mb-3"><label class="block text-xs font-semibold mb-1">Status</label><select name="status" class="<?= INPUT_CLS ?>" onchange="this.form.submit()"><option value="">Todos</option><option value="open" <?= $filtroStatus==='open'?'selected':'' ?>>Abertos</option><option value="in_progress" <?= $filtroStatus==='in_progress'?'selected':'' ?>>Em atendimento</option><option value="resolved" <?= $filtroStatus==='resolved'?'selected':'' ?>>Resolvidos</option></select></form>
      <div class="dnb-case-list">
      <?php foreach ($casos as $caso): ?><a class="dnb-case-link <?= $selecionadoId===(int)$caso['id']?'is-active':'' ?>" href="/casos.php?caso=<?= (int)$caso['id'] ?><?= $filtroStatus?'&status='.urlencode($filtroStatus):'' ?>"><span>#<?= (int)$caso['id'] ?> · <?= e($caso['cliente']) ?></span><small><?= e($caso['created_at']) ?> · <?= e($caso['status']) ?></small></a><?php endforeach; ?>
      <?php if (!$casos): ?><div class="dnb-empty-state"><p>Nenhum caso neste filtro.</p></div><?php endif; ?>
      </div>
    </div>
  </section>
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm">
    <div class="p-4">
      <?php if ($selecionado): ?>
        <div class="flex items-start justify-between gap-3 flex-wrap"><div><span class="dnb-eyebrow">Caso #<?= (int)$selecionado['id'] ?></span><h2 class="dnb-section-title"><?= e($selecionado['cliente']) ?></h2><p class="text-sm text-slate-500">Lead <a href="/lead.php?id=<?= (int)$selecionado['lead_id'] ?>">#<?= (int)$selecionado['lead_id'] ?> · <?= e($selecionado['numero']) ?></a></p></div><?= tag($selecionado['status'], $selecionado['status']==='resolved'?'green':($selecionado['status']==='in_progress'?'blue':'amber')) ?></div>
        <dl class="dnb-detail-list"><div><dt>Responsável comercial</dt><dd><?= e($selecionado['owner_name'] ?: 'Não atribuído') ?></dd></div><div><dt>Agente de suporte</dt><dd><?= e($selecionado['agent_name'] ?: 'Aguardando triagem') ?></dd></div><div><dt>Motivo do bloqueio</dt><dd><?= nl2br(e($selecionado['reason_blocked'] ?: 'Não informado pela IA')) ?></dd></div><div><dt>Solicitação do cliente</dt><dd><?= nl2br(e($selecionado['customer_query'])) ?></dd></div></dl>
        <?php if (podeGerenciarCasos()): ?><form method="post" class="flex gap-2 items-end mt-4"><?= csrfInput() ?><input type="hidden" name="id" value="<?= (int)$selecionado['id'] ?>"><div class="flex-1"><label class="block text-xs font-semibold mb-1">Atualizar status</label><select name="status" class="<?= INPUT_CLS ?>"><option value="open">Aberto</option><option value="in_progress" <?= $selecionado['status']==='in_progress'?'selected':'' ?>>Em atendimento</option><option value="resolved" <?= $selecionado['status']==='resolved'?'selected':'' ?>>Resolvido</option></select></div><button class="<?= BTN_P ?>">Salvar</button></form><?php endif; ?>
      <?php else: ?><div class="dnb-empty-state"><h2>Selecione um caso</h2><p>Os transbordos da IA aparecem aqui para triagem.</p></div><?php endif; ?>
    </div>
  </section>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
