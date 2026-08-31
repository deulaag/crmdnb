<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/followup_flow_service.php';
exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_SUPORTE);

$pdo = db();
$mes = $_GET['mes'] ?? mesAtual();
$disponivel = followupFluxoTabelasDisponiveis($pdo);
$filtros = [
    'status'=>(string)($_GET['status']??''),
    'flow_id'=>(int)($_GET['flow_id']??0),
    'date_from'=>(string)($_GET['date_from']??''),
    'date_to'=>(string)($_GET['date_to']??''),
    'version'=>(int)($_GET['version']??0),'node_type'=>(string)($_GET['node_type']??''),
    'funnel'=>(string)($_GET['funnel']??''),'stage'=>(string)($_GET['stage']??''),
    'owner_id'=>(int)($_GET['owner_id']??0),'q'=>trim((string)($_GET['q']??'')),
    'page'=>max(1,(int)($_GET['page']??1)),
];
$execucoes = $disponivel ? listarExecucoesFluxoPr3($pdo,$filtros,50) : [];
$stats = $disponivel ? estatisticasExecucoesFluxoPr3($pdo) : [];
$selecionadaId = (int)($_GET['run']??($execucoes[0]['id']??0));
$selecionada = $selecionadaId ? detalharExecucaoFluxoPr3($pdo,$selecionadaId) : null;
$fluxos = [];
$responsaveis=[];
if ($disponivel) {
    $fluxos = $pdo->query('SELECT id,name,status FROM followup_flows ORDER BY name,id')->fetchAll();
    [$escopoResp,$paramsResp]=escopoAuditoriaFluxoPr3($pdo);
    $stmt=$pdo->prepare('SELECT DISTINCT u.id,u.nome FROM orcamentos o JOIN usuarios u ON u.id=o.assigned_to_user_id WHERE '.$escopoResp.' ORDER BY u.nome');
    $stmt->execute($paramsResp); $responsaveis=$stmt->fetchAll();
}
$rotulos = [
    'running'=>['Em execução','blue'],'success'=>['Sucesso','green'],'failed'=>['Falha','red'],
    'paused'=>['Pausado','amber'],'blocked'=>['Bloqueado','red'],'delivery_unknown'=>['Revisão manual','amber'],
    'skipped'=>['Ignorado','slate'],
];

function auditoriaTag(string $status, array $rotulos): string
{
    [$texto,$cor] = $rotulos[$status] ?? [$status,'slate'];
    return tag($texto,$cor);
}

$tabAtual = 'audit';
include __DIR__ . '/includes/layout_header.php';
?>

<?php if (!$disponivel): ?>
  <div class="dnb-alert dnb-alert--warning">A auditoria visual será habilitada após instalar <code>sql/migrations/20260812_followup_flows_pr3.sql</code>. O motor atual continua funcionando sem alteração.</div>
<?php else: ?>
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
    <div class="flex items-start justify-between px-4 pt-4 gap-3 flex-wrap">
      <div><span class="dnb-eyebrow">Últimas 24 horas</span><h2 class="dnb-section-title">Saúde operacional dos fluxos</h2><p class="text-sm text-slate-500 mt-1">Resultados agregados dentro do seu escopo de acesso.</p></div>
      <a href="/cadencia.php" class="<?= BTN_S ?>">Abrir editor de fluxos</a>
    </div>
    <div class="dnb-operation-kpis p-4">
      <?php foreach (['running','success','failed','paused','blocked','delivery_unknown'] as $status): ?>
        <div class="dnb-operation-kpi"><span><?= e($rotulos[$status][0]) ?></span><strong><?= (int)($stats[$status]??0) ?></strong><?= auditoriaTag($status,$rotulos) ?></div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
    <div class="px-4 pt-4"><span class="dnb-eyebrow">Filtros</span><h2 class="dnb-section-title">Execuções auditáveis</h2></div>
    <form method="get" class="dnb-audit-filters p-4">
      <input type="hidden" name="mes" value="<?= e($mes) ?>">
      <label><span>Status</span><select name="status" class="<?= INPUT_CLS ?>"><option value="">Todos</option><?php foreach ($rotulos as $valor=>$meta): ?><option value="<?= e($valor) ?>" <?= $filtros['status']===$valor?'selected':'' ?>><?= e($meta[0]) ?></option><?php endforeach; ?></select></label>
      <label><span>Fluxo</span><select name="flow_id" class="<?= INPUT_CLS ?>"><option value="0">Todos</option><?php foreach($fluxos as $fluxo): ?><option value="<?= (int)$fluxo['id'] ?>" <?= $filtros['flow_id']===(int)$fluxo['id']?'selected':'' ?>><?= e($fluxo['name']) ?> · <?= e($fluxo['status']) ?></option><?php endforeach; ?></select></label>
      <label><span>De</span><input type="date" name="date_from" value="<?= e($filtros['date_from']) ?>" class="<?= INPUT_CLS ?>"></label>
      <label><span>Até</span><input type="date" name="date_to" value="<?= e($filtros['date_to']) ?>" class="<?= INPUT_CLS ?>"></label>
      <label><span>Versão</span><input type="number" min="1" name="version" value="<?= $filtros['version']?:'' ?>" class="<?= INPUT_CLS ?>"></label>
      <label><span>Tipo de nó</span><select name="node_type" class="<?= INPUT_CLS ?>"><option value="">Todos</option><?php foreach(tiposNosFollowupPr3() as $tipoNo=>$rotuloNo): ?><option value="<?= e($tipoNo) ?>" <?= $filtros['node_type']===$tipoNo?'selected':'' ?>><?= e($rotuloNo) ?></option><?php endforeach; ?></select></label>
      <label><span>Funil</span><select name="funnel" class="<?= INPUT_CLS ?>"><option value="">Todos</option><?php foreach(funis() as $chaveFunil=>$metaFunil): ?><option value="<?= e($chaveFunil) ?>" <?= $filtros['funnel']===$chaveFunil?'selected':'' ?>><?= e($metaFunil['label']) ?></option><?php endforeach; ?></select></label>
      <label><span>Etapa</span><input name="stage" maxlength="80" value="<?= e($filtros['stage']) ?>" class="<?= INPUT_CLS ?>"></label>
      <label><span>Responsável</span><select name="owner_id" class="<?= INPUT_CLS ?>"><option value="0">Todos</option><?php foreach($responsaveis as $responsavel): ?><option value="<?= (int)$responsavel['id'] ?>" <?= $filtros['owner_id']===(int)$responsavel['id']?'selected':'' ?>><?= e($responsavel['nome']) ?></option><?php endforeach; ?></select></label>
      <label><span>Cliente ou oportunidade</span><input type="search" name="q" maxlength="80" value="<?= e($filtros['q']) ?>" class="<?= INPUT_CLS ?>"></label>
      <div class="dnb-audit-filters__actions"><button class="<?= BTN_P ?>">Aplicar filtros</button><a href="/auditoria.php?mes=<?= urlencode($mes) ?>" class="<?= BTN_S ?>">Limpar</a></div>
    </form>
  </section>

  <div class="dnb-audit-layout">
    <section class="bg-white rounded-xl border border-slate-200 shadow-sm">
      <div class="px-4 pt-4"><span class="dnb-eyebrow">Histórico</span><h2 class="dnb-section-title"><?= count($execucoes) ?> execução(ões)</h2></div>
      <div class="dnb-audit-run-list p-3">
        <?php foreach ($execucoes as $run):
          $query = array_filter(array_merge($filtros,['mes'=>$mes,'run'=>(int)$run['id']]),static fn($v):bool=>$v!==''&&$v!==0);
        ?>
          <a href="/auditoria.php?<?= e(http_build_query($query)) ?>" class="dnb-audit-run<?= (int)$run['id']===$selecionadaId?' is-active':'' ?>">
            <span><strong>#<?= (int)$run['id'] ?> · <?= e($run['cliente']) ?></strong><?= auditoriaTag((string)$run['status'],$rotulos) ?></span>
            <small><?= e($run['flow_name']) ?> · v<?= (int)$run['version_number'] ?> · <?= e($run['owner_name']?:'Sem responsável') ?> · <?= (int)$run['nodes_executed'] ?> nós · <?= (int)$run['duration_ms'] ?> ms · último: <?= e(tiposNosFollowupPr3()[$run['last_node']]??($run['last_node']?:'—')) ?></small>
          </a>
        <?php endforeach; ?>
        <?php if (!$execucoes): ?><div class="dnb-empty-state"><p>Nenhuma execução encontrada para estes filtros.</p></div><?php endif; ?>
        <nav class="flex gap-2 mt-2" aria-label="Paginação da auditoria"><?php if($filtros['page']>1): $anterior=array_merge($filtros,['mes'=>$mes,'page'=>$filtros['page']-1]); ?><a class="<?= BTN_S ?>" href="/auditoria.php?<?= e(http_build_query(array_filter($anterior,static fn($v)=>$v!==''&&$v!==0))) ?>">← Anterior</a><?php endif; ?><?php if(count($execucoes)===50): $proxima=array_merge($filtros,['mes'=>$mes,'page'=>$filtros['page']+1]); ?><a class="<?= BTN_S ?>" href="/auditoria.php?<?= e(http_build_query(array_filter($proxima,static fn($v)=>$v!==''&&$v!==0))) ?>">Próxima →</a><?php endif; ?></nav>
      </div>
    </section>

    <section class="bg-white rounded-xl border border-slate-200 shadow-sm">
      <?php if ($selecionada):
        $eventoPorNo=[];
        foreach($selecionada['events'] as $evento) $eventoPorNo[$evento['node_key']]=$evento;
      ?>
        <div class="p-4 border-b border-slate-200">
          <div class="flex items-start justify-between gap-3 flex-wrap">
            <div><span class="dnb-eyebrow">Execução #<?= (int)$selecionada['id'] ?> · versão <?= (int)$selecionada['version_number'] ?></span><h2 class="dnb-section-title"><?= e($selecionada['flow_name']) ?></h2><p class="text-sm text-slate-500"><a href="/lead.php?id=<?= (int)$selecionada['lead_id'] ?>"><?= e($selecionada['cliente']) ?> · <?= e($selecionada['numero']) ?></a></p></div>
            <?= auditoriaTag((string)$selecionada['status'],$rotulos) ?>
          </div>
        </div>
        <div class="p-4">
          <h3 class="text-sm font-bold mb-3" style="color:<?= NAVY ?>;">Caminho visual executado</h3>
          <div class="dnb-audit-graph" aria-label="Grafo somente leitura da execução">
            <?php foreach ((array)($selecionada['graph']['nodes']??[]) as $indice=>$node):
              $evento=$eventoPorNo[$node['id']]??null;
              $statusNo=(string)($evento['status']??'skipped');
            ?>
              <article class="dnb-audit-node dnb-audit-node--<?= e($statusNo) ?>">
                <span><?= e(tiposNosFollowupPr3()[$node['type']]??$node['type']) ?></span>
                <strong><?= e($node['label']??$node['id']) ?></strong>
                <small><?= $evento ? e($evento['status'].' · '.$evento['created_at']) : 'não executado' ?></small>
              </article>
              <?php if ($indice<count($selecionada['graph']['nodes'])-1): ?><span class="dnb-audit-arrow" aria-hidden="true">→</span><?php endif; ?>
            <?php endforeach; ?>
          </div>

          <h3 class="text-sm font-bold mt-5 mb-3" style="color:<?= NAVY ?>;">Linha do tempo dos nós</h3>
          <ol class="dnb-audit-timeline">
            <?php foreach ($selecionada['events'] as $evento): ?>
              <li><span class="dnb-audit-timeline__dot dnb-audit-timeline__dot--<?= e($evento['status']) ?>"></span><div><strong><?= e(tiposNosFollowupPr3()[$evento['node_type']]??$evento['node_type']) ?></strong> <?= auditoriaTag((string)$evento['status'],$rotulos) ?><small><?= e($evento['created_at']) ?><?= $evento['duration_ms']!==null?' · '.(int)$evento['duration_ms'].' ms':'' ?></small><?php if ($evento['output_summary']): ?><p><?= e($evento['output_summary']) ?></p><?php endif; ?><?php if ($evento['error_code']): ?><p class="text-red-700">Código: <?= e($evento['error_code']) ?></p><?php endif; ?></div></li>
            <?php endforeach; ?>
          </ol>
        </div>
      <?php else: ?>
        <div class="dnb-empty-state p-8"><h2>Selecione uma execução</h2><p>O detalhe mostra o caminho do grafo e o resultado sanitizado de cada nó.</p></div>
      <?php endif; ?>
    </section>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
