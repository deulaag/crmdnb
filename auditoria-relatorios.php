<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/audit_dashboard_service.php';
exigirPapel(PAPEL_ADMINISTRADOR);

$pdo=db();
$mes=$_GET['mes']??mesAtual();
$meses=mesesAuditoriaPainel($pdo);
if($mes&&!in_array($mes,$meses,true))$meses=array_values(array_unique(array_merge([$mes],$meses)));
$relatorios=[];
foreach($meses as $m){try{$relatorios[$m]=relatorioAuditoriaPainelMes($pdo,$m);}catch(Throwable $e){$relatorios[$m]=['mes'=>$m,'rotulo'=>rotuloMes($m),'erro'=>$e->getMessage()];}}

function auditPct(float $real,float $meta): float{return $meta>0?($real/$meta)*100:0;}
function auditData(?string $data): string{return $data?date('d/m/Y',strtotime($data)):'—';}

$tabAtual='auditpanel';
include __DIR__ . '/includes/layout_header.php';
?>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="p-4 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
    <div><span class="dnb-eyebrow">Auditoria · exclusivo ADM</span><h2 class="dnb-section-title">Relatório histórico do Painel</h2><p class="text-sm text-slate-500 mt-1">Cada mês é apurado pelo mesmo motor usado no Painel e nas Comissões. Negociações ficam separadas de Propostas e cada competência pode ser exportada em PDF ou CSV.</p></div>
    <div class="text-xs text-slate-500 rounded-lg border border-slate-200 px-3 py-2 bg-slate-50"><b><?=count($meses)?></b> competência(s) disponível(is)</div>
  </div>
</section>

<nav class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4 p-3 flex gap-2 flex-wrap" aria-label="Atalhos de meses">
  <?php foreach($meses as $m):?><a class="<?=BTN_S?>" href="#mes-<?=e($m)?>"><?=e(rotuloMes($m))?></a><?php endforeach;?>
</nav>

<?php foreach($relatorios as $m=>$r):?>
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-5 overflow-hidden" id="mes-<?=e($m)?>">
    <div class="p-4 border-b border-slate-200 flex flex-col md:flex-row md:items-center justify-between gap-3" style="background:linear-gradient(135deg,#f7fafc,#eef5fb)">
      <div><span class="dnb-eyebrow">Competência <?=e($m)?></span><h2 class="dnb-section-title"><?=e($r['rotulo'])?></h2><p class="text-xs text-slate-500">Relatório consolidado e independente deste mês.</p></div>
      <?php if(empty($r['erro'])):?><div class="flex gap-2 flex-wrap"><a class="<?=BTN_P?>" href="/auditoria-relatorios-pdf.php?mes=<?=urlencode($m)?>">Exportar PDF</a><a class="<?=BTN_S?>" href="/auditoria-relatorios-csv.php?mes=<?=urlencode($m)?>">Exportar CSV</a></div><?php endif;?>
    </div>
    <?php if(!empty($r['erro'])):?><div class="dnb-alert dnb-alert--warning m-4"><?=e($r['erro'])?></div><?php else:$s=$r['resumo'];?>
      <div class="p-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3 mb-4">
          <?php $cards=[
            ['Propostas emitidas',(float)$s['propostas'],(float)$s['metaPropostas'],'money',(int)$r['contagens']['propostas'].' proposta(s)'],
            ['Revenda faturada',(float)$s['revenda'],(float)$s['metaRevenda'],'money',(int)$r['contagens']['revenda'].' pedido(s)'],
            ['Agenciadas',(float)$s['agenciadas'],(float)$s['metaAgenciadas'],'money',(int)$r['contagens']['agenciadas'].' venda(s)'],
            ['Visitas + prospecções',(float)$s['atividade'],(float)$s['metaAtividade'],'number',(int)$r['contagens']['atividade'].' atividade(s)'],
          ];foreach($cards as [$label,$real,$meta,$tipo,$sub]):$pct=auditPct($real,$meta);?>
            <article class="dnb-kpi-goal-card"><div class="dnb-kpi-goal-card__top"><span><?=e($label)?></span><?php if($meta>0):?><b><?=round($pct)?>%</b><?php endif;?></div><strong><?=$tipo==='money'?fmtBRL($real):(string)(int)$real?></strong><small><?=e($sub)?></small><div class="dnb-kpi-goal-card__meta"><span>Meta: <?=$meta>0?($tipo==='money'?fmtBRL($meta):(string)(int)$meta):'não definida'?></span><?php if($meta>0):?><div><?=farol($pct)?></div><?php endif;?></div></article>
          <?php endforeach;?>
          <article class="dnb-kpi-goal-card"><div class="dnb-kpi-goal-card__top"><span>Ganhos / comissão</span></div><strong><?=fmtBRL((float)$s['ganhos'])?></strong><small>apuração da equipe</small><div class="dnb-kpi-goal-card__meta"><span>Políticas vigentes no mês</span></div></article>
        </div>

        <?php $rdS=$r['rd_negotiations']['summary']??[];$rdItems=$r['rd_negotiations']['items']??[];?>
        <div class="rounded-xl border border-slate-200 mb-4 overflow-hidden">
          <div class="p-3 border-b border-slate-200 bg-slate-50"><span class="dnb-eyebrow">Núcleo CRM</span><h3 class="font-bold" style="color:<?=NAVY?>;">Negociações</h3><p class="text-xs text-slate-500 mt-1">Negociações não são somadas ao KPI “Propostas emitidas”. Ganhos sem Pedido ficam visíveis para conferência, mas não geram Pedido automaticamente.</p></div>
          <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-px bg-slate-200">
            <div class="bg-white p-3"><small class="block text-slate-500">Movimentadas</small><strong><?=(int)($rdS['total_count']??0)?></strong><span class="block text-xs text-slate-500"><?=fmtBRL((float)($rdS['total_value']??0))?></span></div>
            <div class="bg-white p-3"><small class="block text-slate-500">Criadas</small><strong><?=(int)($rdS['created_count']??0)?></strong><span class="block text-xs text-slate-500"><?=fmtBRL((float)($rdS['created_value']??0))?></span></div>
            <div class="bg-white p-3"><small class="block text-slate-500">Ganhas</small><strong class="text-emerald-700"><?=(int)($rdS['won_count']??0)?></strong><span class="block text-xs text-slate-500"><?=fmtBRL((float)($rdS['won_value']??0))?></span></div>
            <div class="bg-white p-3"><small class="block text-slate-500">Perdidas</small><strong class="text-rose-700"><?=(int)($rdS['lost_count']??0)?></strong><span class="block text-xs text-slate-500"><?=fmtBRL((float)($rdS['lost_value']??0))?></span></div>
            <div class="bg-white p-3"><small class="block text-slate-500">Origem RD</small><strong><?=(int)($rdS['rd_count']??0)?></strong><span class="block text-xs text-slate-500"><?=fmtBRL((float)($rdS['rd_value']??0))?></span></div>
            <div class="bg-white p-3"><small class="block text-slate-500">Ganhas sem Pedido</small><strong class="<?=((int)($rdS['won_without_order_count']??0)>0)?'text-amber-700':''?>"><?=(int)($rdS['won_without_order_count']??0)?></strong><span class="block text-xs text-slate-500"><?=fmtBRL((float)($rdS['won_without_order_value']??0))?></span></div>
          </div>
        </div>

        <details class="border border-slate-200 rounded-xl mb-3" open>
          <summary class="cursor-pointer p-3 font-bold" style="color:<?=NAVY?>;">Negociações · <?=count($rdItems)?></summary>
          <div class="overflow-x-auto px-3 pb-3"><table class="w-full text-sm min-w-[1280px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">DNB ID</th><th>Título</th><th>Empresa</th><th>Responsável</th><th>Categoria</th><th>Funil</th><th>Etapa</th><th>Status</th><th>Origem</th><th>Propostas</th><th>Pedidos</th><th class="text-right">Valor</th></tr></thead><tbody>
          <?php foreach($rdItems as $n):?><tr class="border-b border-slate-100"><td class="py-2 font-mono text-xs"><?=e((string)($n['dnb_id']??''))?></td><td><?=e((string)($n['title']??''))?></td><td><?=e((string)($n['company_name']??'—'))?></td><td><?=e((string)($n['owner_name']??'—'))?></td><td><?=e((string)($n['category']??'—'))?></td><td><?=e((string)($n['pipeline_name']??'—'))?></td><td><?=e((string)($n['stage_name']??'—'))?></td><td><?=e((string)($n['status']??''))?></td><td><?=e((string)($n['origin']??''))?></td><td><?=(int)($n['quotes_count']??0)?></td><td><?=(int)($n['orders_count']??0)?></td><td class="text-right font-semibold"><?=fmtBRL((float)($n['estimated_value']??0))?></td></tr><?php endforeach;?>
          <?php if(!$rdItems):?><tr><td colspan="12" class="py-4 text-center text-slate-400">Nenhuma negociação movimentada nesta competência.</td></tr><?php endif;?></tbody></table></div>
        </details>

        <?php if(!empty($r['rd_pipeline'])):?><details class="border border-slate-200 rounded-xl mb-3">
          <summary class="cursor-pointer p-3 font-bold" style="color:<?=NAVY?>;">Funis e etapas · <?=count($r['rd_pipeline'])?></summary>
          <div class="overflow-x-auto px-3 pb-3"><table class="w-full text-sm min-w-[760px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Funil</th><th>Etapa</th><th>Negociações</th><th>Abertas</th><th>Ganhas</th><th>Perdidas</th><th class="text-right">Valor</th></tr></thead><tbody><?php foreach($r['rd_pipeline'] as $g):?><tr class="border-b border-slate-100"><td class="py-2 font-semibold"><?=e($g['pipeline'])?></td><td><?=e($g['stage'])?></td><td><?=(int)$g['count']?></td><td><?=(int)$g['open']?></td><td><?=(int)$g['won']?></td><td><?=(int)$g['lost']?></td><td class="text-right font-semibold"><?=fmtBRL((float)$g['value'])?></td></tr><?php endforeach;?></tbody></table></div>
        </details><?php endif;?>

        <details class="border border-slate-200 rounded-xl mb-3">
          <summary class="cursor-pointer p-3 font-bold" style="color:<?=NAVY?>;">Oportunidades / propostas · <?=count($r['orcamentos'])?></summary>
          <div class="overflow-x-auto px-3 pb-3"><table class="w-full text-sm min-w-[980px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Data</th><th>Número</th><th>Cliente</th><th>Responsável</th><th>Linha</th><th>Etapa</th><th>Status</th><th class="text-right">Valor</th></tr></thead><tbody><?php foreach($r['orcamentos'] as $o):?><tr class="border-b border-slate-100"><td class="py-2"><?=e(auditData((string)$o['data']))?></td><td><?=e((string)($o['numero']?:'#'.$o['id']))?></td><td><?=e($o['cliente'])?></td><td><?=e($o['_responsavel'])?></td><td><?=e((string)($o['linha']??''))?></td><td><?=e((string)($o['etapa']??''))?></td><td><?=e((string)($o['status']??''))?></td><td class="text-right font-semibold"><?=fmtBRL((float)$o['valor'])?></td></tr><?php endforeach;?><?php if(!$r['orcamentos']):?><tr><td colspan="8" class="py-4 text-center text-slate-400">Nenhuma proposta nesta competência.</td></tr><?php endif;?></tbody></table></div>
        </details>

        <details class="border border-slate-200 rounded-xl mb-3">
          <summary class="cursor-pointer p-3 font-bold" style="color:<?=NAVY?>;">Pedidos faturados · <?=count($r['pedidos'])?></summary>
          <div class="overflow-x-auto px-3 pb-3"><table class="w-full text-sm min-w-[900px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Faturamento</th><th>Número</th><th>Cliente</th><th>Responsável</th><th>Modalidade</th><th>Linha</th><th class="text-right">Valor</th></tr></thead><tbody><?php foreach($r['pedidos'] as $p):?><tr class="border-b border-slate-100"><td class="py-2"><?=e(auditData((string)($p['data_fat']?:$p['data'])))?></td><td><?=e((string)($p['numero']?:'#'.$p['id']))?></td><td><?=e($p['cliente'])?></td><td><?=e($p['_responsavel'])?></td><td><?=e(ucfirst((string)($p['modalidade']??'')))?></td><td><?=e((string)($p['linha']??''))?></td><td class="text-right font-semibold"><?=fmtBRL((float)$p['valor'])?></td></tr><?php endforeach;?><?php if(!$r['pedidos']):?><tr><td colspan="7" class="py-4 text-center text-slate-400">Nenhum pedido faturado nesta competência.</td></tr><?php endif;?></tbody></table></div>
        </details>

        <details class="border border-slate-200 rounded-xl mb-3">
          <summary class="cursor-pointer p-3 font-bold" style="color:<?=NAVY?>;">Visitas e prospecções · <?=count($r['visitas'])?></summary>
          <div class="overflow-x-auto px-3 pb-3"><table class="w-full text-sm min-w-[850px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Data</th><th>OS</th><th>Cliente</th><th>Responsável</th><th>Tipo</th><th>Oportunidade</th></tr></thead><tbody><?php foreach($r['visitas'] as $v):?><tr class="border-b border-slate-100"><td class="py-2"><?=e(auditData((string)$v['data']))?></td><td><?=e((string)($v['os']?:'—'))?></td><td><?=e($v['cliente'])?></td><td><?=e($v['_responsavel'])?></td><td><?=($v['tipo']??'')==='prospeccao'?tag('Prospecção','blue'):tag('Visita','green')?></td><td><?=e(mb_strimwidth((string)($v['oportunidade']??''),0,110,'…'))?></td></tr><?php endforeach;?><?php if(!$r['visitas']):?><tr><td colspan="6" class="py-4 text-center text-slate-400">Nenhuma atividade nesta competência.</td></tr><?php endif;?></tbody></table></div>
        </details>

        <details class="border border-slate-200 rounded-xl">
          <summary class="cursor-pointer p-3 font-bold" style="color:<?=NAVY?>;">Apuração por colaborador · <?=count($r['pessoas'])?></summary>
          <div class="overflow-x-auto px-3 pb-3"><table class="w-full text-sm min-w-[1050px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Colaborador</th><th>Propostas / meta</th><th>Revenda / meta</th><th>Agenciadas / meta</th><th>Atividades / meta</th><th class="text-right">Ganhos</th></tr></thead><tbody><?php foreach($r['pessoas'] as $p):?><tr class="border-b border-slate-100"><td class="py-2 font-semibold"><?=e($p['nome'])?></td><td><?=fmtBRL((float)$p['propostas'])?> / <?=fmtBRL((float)$p['meta_propostas'])?></td><td><?=fmtBRL((float)$p['revenda'])?> / <?=fmtBRL((float)$p['meta_revenda'])?></td><td><?=fmtBRL((float)$p['agenciadas'])?> / <?=fmtBRL((float)$p['meta_agenciadas'])?></td><td><?=(int)$p['visitas']+(int)$p['prospeccoes']?> / <?=(int)$p['meta_atividade']?></td><td class="text-right font-semibold"><?=fmtBRL((float)$p['ganhos'])?></td></tr><?php endforeach;?></tbody></table></div>
        </details>
      </div>
    <?php endif;?>
  </section>
<?php endforeach;?>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
