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

$resumo=['propostas'=>0.0,'metaPropostas'=>0.0,'revenda'=>0.0,'metaRevenda'=>0.0,'agenciadas'=>0.0,'metaAgenciadas'=>0.0,'atividade'=>0,'metaAtividade'=>0.0,'ganhos'=>0.0];
foreach($vendedores as $v){
    $d=$calc['porPessoa'][$v['id']]??null;if(!$d)continue;
    $pol=$d['polEfetiva']?:($v['pol']??[]);
    $resumo['propostas']+=(float)($d['propostas']??0);$resumo['metaPropostas']+=(float)($d['metaProp']??0);
    $resumo['revenda']+=(float)($d['revFat']??0);
    $metaRev=(float)($pol['metaRevenda']??0);if($metaRev<=0&&!empty($pol['metaPropriasAno']))$metaRev=(float)$pol['metaPropriasAno']/12;$resumo['metaRevenda']+=$metaRev;
    $resumo['agenciadas']+=(float)($d['agInterm']??0);
    $metaAg=(float)($pol['metaAgenciadas']??0);if($metaAg<=0&&!empty($pol['metaAgenciadasAno']))$metaAg=(float)$pol['metaAgenciadasAno']/12;$resumo['metaAgenciadas']+=$metaAg;
    $resumo['atividade']+=(int)($d['vis']??0)+(int)($d['prosp']??0);$resumo['metaAtividade']+=(float)($pol['visitasMes']??0)+(float)($pol['prospecMes']??0);
    $resumo['ganhos']+=(float)($d['total']??0);
}
$contPropostas=count($calc['orcMes']);
$contRevenda=count(array_filter($calc['pedFatMes'],fn($p)=>($p['modalidade']??'')!=='agenciada'));
$contAg=count(array_filter($calc['pedFatMes'],fn($p)=>($p['modalidade']??'')==='agenciada'));
$nomeEscopo=count($vendedores)===1?($vendedores[0]['nome']??'Seu desempenho'):'Equipe no seu escopo';
$kpisTopo=[
    ['Propostas emitidas',$resumo['propostas'],$resumo['metaPropostas'],'money',$contPropostas.' proposta(s) no mês'],
    ['Revenda faturada',$resumo['revenda'],$resumo['metaRevenda'],'money',$contRevenda.' pedido(s) faturado(s)'],
    ['Agenciadas (intermediado)',$resumo['agenciadas'],$resumo['metaAgenciadas'],'money',$contAg.' venda(s) agenciada(s)'],
    ['Visitas + prospecções',(float)$resumo['atividade'],$resumo['metaAtividade'],'number',rotuloMes($mes)],
];

$tabAtual = 'painel';
include __DIR__ . '/includes/layout_header.php';
?>

<section class="dnb-overview-performance mb-4" data-collaborator-overview>
  <div class="dnb-overview-performance__head">
    <div><span class="dnb-eyebrow">Visão geral · <?=e(rotuloMes($mes))?></span><h2 class="dnb-section-title"><?=e($nomeEscopo)?></h2><p>Os números abaixo usam a mesma apuração individual de metas, oportunidades, visitas e pedidos do relatório comercial.</p></div>
    <div class="dnb-earnings-chip"><span>Ganhos / comissão apurada</span><strong><?=fmtBRL($resumo['ganhos'])?></strong><small>conforme a política comercial vigente</small></div>
  </div>
  <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mt-3">
    <?php foreach($kpisTopo as [$label,$real,$meta,$tipo,$sub]):$pct=$meta>0?($real/$meta)*100:0;?>
      <article class="dnb-kpi-goal-card" data-kpi-goal="<?=e($label)?>">
        <div class="dnb-kpi-goal-card__top"><span><?=e($label)?></span><?php if($meta>0):?><b><?=round($pct)?>%</b><?php endif;?></div>
        <strong><?=$tipo==='money'?fmtBRL($real):(string)(int)$real?></strong>
        <small><?=e($sub)?></small>
        <div class="dnb-kpi-goal-card__meta"><span>Meta: <?=$meta>0?($tipo==='money'?fmtBRL($meta):(string)(int)$meta):'não definida'?></span><?php if($meta>0):?><span><?=farol($pct)?></span><?php endif;?></div>
      </article>
    <?php endforeach;?>
  </div>
</section>

<?php if (ehOperadorVendedor()): ?>
<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Farol de metas · <?= e(rotuloMes($mes)) ?></span></div>
  <div class="p-4"><div class="overflow-x-auto"><table class="w-full text-sm min-w-[860px]"><thead><tr class="text-left text-xs text-slate-500 border-b border-slate-200"><th class="py-2 pr-2">Pessoa · política</th><th class="pr-2">Propostas (meta derivada)</th><th class="pr-2">Revenda faturada</th><th class="pr-2">Agenciadas</th><th class="pr-2">Atividade</th></tr></thead><tbody>
  <?php foreach ($vendedores as $v):$d=$calc['porPessoa'][$v['id']];$pol=$d['polEfetiva']?:$v['pol'];$metaAtiv=(float)($pol['visitasMes']??0)+(float)($pol['prospecMes']??0);$ativ=(int)$d['vis']+(int)$d['prosp'];$metaRev=$v['politica']==='consultor_senior'?((float)($pol['metaPropriasAno']??0))/12:(float)($pol['metaRevenda']??0);$metaAg=(float)($pol['metaAgenciadas']??0);if($metaAg<=0&&!empty($pol['metaAgenciadasAno']))$metaAg=(float)$pol['metaAgenciadasAno']/12;?>
    <tr class="border-b border-slate-100"><td class="py-2 pr-2"><div class="font-semibold" style="color:<?= NAVY ?>;"><?=e($v['nome'])?></div><div class="text-xs text-slate-400"><?=e($politicasArr[$v['politica']]['label']??$v['politica'])?></div><?php if($d['rampa']<1):?><?=tag('rampa mês '.$d['mesAtuacao'].' · '.round($d['rampa']*100).'%','amber')?><?php endif;?></td><td class="pr-2"><div class="text-xs text-slate-500"><?=fmtBRL($d['propostas'])?><?=$d['metaProp']?' / '.fmtBRL($d['metaProp']):''?></div><?php if($d['metaProp']>0):?><?=farol(($d['propostas']/$d['metaProp'])*100)?><?php endif;?></td><td class="pr-2"><div class="text-xs text-slate-500"><?=fmtBRL($d['revFat'])?><?=$metaRev?' / '.fmtBRL($metaRev):''?></div><?php if($metaRev>0):?><?=farol(($d['revFat']/$metaRev)*100)?><?php endif;?></td><td class="pr-2"><?php if($metaAg>0):?><div class="text-xs text-slate-500"><?=fmtBRL($d['agInterm'])?> / <?=fmtBRL($metaAg)?></div><?=farol(($d['agInterm']/$metaAg)*100)?><?php else:?><span class="text-xs text-slate-400"><?=$d['agInterm']?fmtBRL($d['agInterm']):'—'?></span><?php endif;?></td><td class="pr-2"><?php if($metaAtiv>0):?><div class="text-xs text-slate-500"><?=$ativ?> / <?=$metaAtiv?> atividades</div><?=farol(($ativ/$metaAtiv)*100)?><?php else:?><span class="text-xs text-slate-400">—</span><?php endif;?></td></tr>
  <?php endforeach;?></tbody></table></div><div class="text-xs text-slate-400 mt-3">Seu farol e a Visão geral usam os mesmos registros vinculados ao seu usuário no CRM.</div></div>
</div>
<?php endif; ?>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4 dnb-task-board-panel" data-v36-task-board data-default-mine="<?=ehOperadorVendedor()?'1':'0'?>">
  <div class="dnb-task-board-titlebar">
    <div class="dnb-task-board-titleline"><h2>Tarefas</h2><div class="dnb-view-toggle" role="group" aria-label="Visualização das tarefas"><button type="button" class="is-active" data-task-view="kanban" title="Kanban" aria-label="Visualização Kanban">▥</button><button type="button" data-task-view="list" title="Lista" aria-label="Visualização em lista">☷</button></div></div>
    <div class="dnb-task-board-actions"><span class="dnb-eyebrow">Acompanhamento diário</span><small data-task-count>Carregando tarefas…</small></div>
  </div>
  <div class="dnb-task-board-filters">
    <label><span>Funil / linha</span><select data-task-filter="funil"><option value="">Todos os funis</option><option value="equipamentos">Equipamentos</option><option value="servicos">Serviços e Locação</option><option value="pecas">Peças e Revenda</option></select></label>
    <label><span>Responsável</span><select data-task-filter="mine"><option value="<?=ehOperadorVendedor()?'1':'0'?>"><?=ehOperadorVendedor()?'Minhas tarefas':'Meu escopo'?></option><?php if(!ehOperadorVendedor()):?><option value="1">Somente minhas tarefas</option><?php endif;?></select></label>
    <label><span>Status da tarefa</span><select data-task-filter="stage"><option value="">Todos os status</option><option value="todo">A fazer</option><option value="doing">Em andamento</option><option value="waiting">Aguardando</option><option value="done">Concluídas</option></select></label>
    <label><span>Período / criação</span><select data-task-filter="period_sort"><option value="newest">Criadas mais recentes</option><option value="oldest">Criadas primeiro</option><option value="7d">Últimos 7 dias</option><option value="all">Todo o período</option></select></label>
    <label class="dnb-task-search"><span>Buscar</span><input type="search" data-task-filter="q" placeholder="Tarefa, empresa ou orçamento"></label>
  </div>
  <div data-task-kanban class="dnb-task-kanban"><div class="dnb-task-kanban-loading">Carregando tarefas…</div></div>
</section>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
