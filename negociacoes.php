<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/crm_phase2_service.php';
require_once __DIR__ . '/includes/rd_discovery_service.php';
exigirPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR);

$pdo=db();$mes=$_GET['mes']??mesAtual();$disponivel=crmPhase2Disponivel($pdo);$erro='';$msg='';
$view=(string)($_GET['view']??'negociacoes');if(!in_array($view,['leads','negociacoes'],true))$view='negociacoes';
$modo=(string)($_GET['modo']??'lista');if(!in_array($modo,['lista','kanban'],true))$modo='lista';
$empresas=$disponivel?empresasSelecionaveisCrmUi($pdo):[];
$contatos=$disponivel?contatosSelecionaveisCrmUi($pdo):[];
$responsaveis=$disponivel?responsaveisNegociacaoCrmUi($pdo):[];
$rdPipelineCatalog=$disponivel?rdPipelineCatalogo($pdo):[];
$preContactId=(int)($_GET['contact_id']??0);$preCompanyId=0;
if($preContactId&&$disponivel&&podeAcessarContatoCrm($pdo,$preContactId)){
  $pc=detalharContatoCrmUi($pdo,$preContactId);$preCompanyId=(int)($pc['company_id']??0);
}else{$preContactId=0;}

if($_SERVER['REQUEST_METHOD']==='POST'){
  exigirCsrf();
  try{
    if(!$disponivel)throw new RuntimeException('Instale a migração da Fase 2.');
    if(($_POST['acao']??'')==='add'){
      $contactId=(int)($_POST['contact_id']??0);
      $neg=criarNegociacaoCrmUi($pdo,[
        'title'=>$_POST['title']??'','company_id'=>(int)($_POST['company_id']??0),'category'=>$_POST['category']??'',
        'pipeline_name'=>$_POST['pipeline_name']??'','stage_name'=>$_POST['stage_name']??'',
        'estimated_value'=>(float)($_POST['estimated_value']??0),'status'=>'open',
        'assigned_to_user_id'=>(int)($_POST['assigned_to_user_id']??0),'origin'=>'manual',
        'business_created_at'=>date('Y-m-d H:i:s'),
        'contacts'=>$contactId>0?[['contact_id'=>$contactId,'role'=>(string)($_POST['contact_role']??'principal'),'is_primary'=>true]]:[]
      ]);
      header('Location: /negociacao.php?id='.(int)$neg['id'].'&criada=1');exit;
    }
  }catch(Throwable $e){$erro=$e->getMessage();}
}

$filtros=[
  'q'=>trim((string)($_GET['q']??'')),
  'status'=>(string)($_GET['status']??''),
  'category'=>(string)($_GET['category']??''),
  'pipeline_name'=>(string)($_GET['pipeline_name']??''),
  'stage_name'=>(string)($_GET['stage_name']??''),
  'company_id'=>(int)($_GET['company_id']??0),
  'contact_id'=>(int)($_GET['filter_contact_id']??0),
  'owner_id'=>(int)($_GET['owner_id']??0),
  'origin'=>(string)($_GET['origin']??''),
  'date_from'=>(string)($_GET['date_from']??''),
  'date_to'=>(string)($_GET['date_to']??''),
  'value_min'=>(string)($_GET['value_min']??''),
  'value_max'=>(string)($_GET['value_max']??''),
];
$negociacoes=$disponivel?listarNegociacoesCrmUi($pdo,$filtros):[];
$leads=$disponivel&&$view==='leads'?listarLeadsPreVendasCrmUi($pdo,['q'=>$filtros['q']]):[];

$todas=$disponivel?listarNegociacoesCrmUi($pdo,[]):[];
$categorias=array_values(array_unique(array_filter(array_map(fn($n)=>(string)$n['category'],$todas))));
$funis=array_values(array_unique(array_filter(array_merge(array_map(fn($n)=>(string)$n['pipeline_name'],$todas),array_keys($rdPipelineCatalog)))));
$etapasCatalog=[];foreach($rdPipelineCatalog as $stages){foreach($stages as $stage)$etapasCatalog[]=$stage;}
$etapas=array_values(array_unique(array_filter(array_merge(array_map(fn($n)=>(string)$n['stage_name'],$todas),$etapasCatalog))));
$origens=array_values(array_unique(array_filter(array_map(fn($n)=>(string)$n['origin'],$todas))));
sort($categorias);sort($funis);sort($etapas);sort($origens);

$tabAtual='neg';include __DIR__.'/includes/layout_header.php';
?>
<?php if(!$disponivel):?><div class="dnb-alert dnb-alert--warning">A interface de Negociações requer <code>sql/migrations/20260918_crm_phase2_ui_ownership.sql</code>.</div>
<?php else:?>
<?php if($erro):?><div class="dnb-alert dnb-alert--error"><?=e($erro)?></div><?php endif;?>

<nav class="dnb-crm-subnav mb-4" aria-label="Módulo Negociações">
  <a class="<?=$view==='leads'?'is-active':''?>" href="/negociacoes.php?view=leads">Leads / Pré-vendas</a>
  <a class="<?=$view==='negociacoes'?'is-active':''?>" href="/negociacoes.php?view=negociacoes">Negociações</a>
  <a href="/orcamentos.php">Orçamentos</a>
</nav>

<?php if($view==='leads'):?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="px-4 pt-4"><span class="dnb-eyebrow">Triagem comercial</span><h2 class="dnb-section-title">Leads / Pré-vendas</h2><p class="text-sm text-slate-500 mt-1">Contatos ainda sem negociação. Os contatos trazidos do RD Marketing entrarão nesta área nas fases de integração.</p></div>
  <form method="get" class="p-4 flex gap-2 flex-wrap"><input type="hidden" name="view" value="leads"><input type="search" name="q" value="<?=e($filtros['q'])?>" placeholder="Contato, e-mail ou empresa" class="<?=INPUT_CLS?> max-w-xl"><button class="<?=BTN_S?>">Buscar</button></form>
</section>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm"><div class="p-4 overflow-x-auto"><table class="w-full text-sm min-w-[800px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Contato</th><th>Empresa</th><th>E-mail</th><th>Telefone</th><th>Origem</th><th></th></tr></thead><tbody><?php foreach($leads as $ct):?><tr class="border-b border-slate-100"><td class="py-3"><strong><?=e($ct['name'])?></strong><small class="block font-mono text-slate-400"><?=e($ct['dnb_id'])?></small></td><td><?=e($ct['company_name']?:'Sem empresa')?></td><td><?=e($ct['email']?:'—')?></td><td><?=e($ct['phone']?:'—')?></td><td><?=e($ct['source']?:'manual')?></td><td class="text-right"><a class="<?=BTN_P?>" href="/negociacoes.php?novo=1&contact_id=<?=(int)$ct['id']?>">Abrir negociação</a></td></tr><?php endforeach;?><?php if(!$leads):?><tr><td colspan="6" class="py-8 text-center text-slate-400">Nenhum lead sem negociação no seu escopo.</td></tr><?php endif;?></tbody></table></div></section>
<?php else:?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="px-4 pt-4 flex justify-between gap-3 flex-wrap"><div><span class="dnb-eyebrow">Pipeline comercial</span><h2 class="dnb-section-title">Negociações</h2><p class="text-sm text-slate-500 mt-1">O negócio é a entidade principal; propostas/orçamentos, pedidos, visitas e contatos ficam vinculados a ele.</p></div><div class="flex gap-2"><a href="<?=e('/negociacoes.php?'.http_build_query(array_merge($_GET,['modo'=>$modo==='lista'?'kanban':'lista'])))?>" class="<?=BTN_S?>"><?=$modo==='lista'?'Ver Kanban':'Ver lista'?></a><button type="button" class="<?=BTN_P?>" data-modal-open="modal-nova-negociacao">Nova negociação</button></div></div>
  <form method="get" class="p-4 dnb-neg-filters"><input type="hidden" name="view" value="negociacoes"><input type="hidden" name="modo" value="<?=e($modo)?>">
    <label><span>Busca</span><input type="search" name="q" value="<?=e($filtros['q'])?>" placeholder="Título, empresa ou DNB ID" class="<?=INPUT_CLS?>"></label>
    <label><span>Status</span><select name="status" class="<?=INPUT_CLS?>"><option value="">Todos</option><option value="open" <?=$filtros['status']==='open'?'selected':''?>>Abertas</option><option value="won" <?=$filtros['status']==='won'?'selected':''?>>Ganhas</option><option value="lost" <?=$filtros['status']==='lost'?'selected':''?>>Perdidas</option></select></label>
    <label><span>Categoria</span><select name="category" class="<?=INPUT_CLS?>"><option value="">Todas</option><?php foreach($categorias as $x):?><option <?=$filtros['category']===$x?'selected':''?>><?=e($x)?></option><?php endforeach;?></select></label>
    <label><span>Funil</span><select name="pipeline_name" class="<?=INPUT_CLS?>"><option value="">Todos</option><?php foreach($funis as $x):?><option <?=$filtros['pipeline_name']===$x?'selected':''?>><?=e($x)?></option><?php endforeach;?></select></label>
    <label><span>Etapa</span><select name="stage_name" class="<?=INPUT_CLS?>"><option value="">Todas</option><?php foreach($etapas as $x):?><option <?=$filtros['stage_name']===$x?'selected':''?>><?=e($x)?></option><?php endforeach;?></select></label>
    <label><span>Empresa</span><select name="company_id" class="<?=INPUT_CLS?>"><option value="0">Todas</option><?php foreach($empresas as $c):?><option value="<?=(int)$c['id']?>" <?=$filtros['company_id']===(int)$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></label>
    <label><span>Contato</span><select name="filter_contact_id" class="<?=INPUT_CLS?>"><option value="0">Todos</option><?php foreach($contatos as $ct):?><option value="<?=(int)$ct['id']?>" <?=$filtros['contact_id']===(int)$ct['id']?'selected':''?>><?=e($ct['name'])?></option><?php endforeach;?></select></label>
    <label><span>Responsável</span><select name="owner_id" class="<?=INPUT_CLS?>"><option value="0">Todos</option><?php foreach($responsaveis as $u):?><option value="<?=(int)$u['id']?>" <?=$filtros['owner_id']===(int)$u['id']?'selected':''?>><?=e($u['nome'])?></option><?php endforeach;?></select></label>
    <label><span>Origem</span><select name="origin" class="<?=INPUT_CLS?>"><option value="">Todas</option><?php foreach($origens as $x):?><option <?=$filtros['origin']===$x?'selected':''?>><?=e($x)?></option><?php endforeach;?></select></label>
    <label><span>Movimentada desde</span><input type="date" name="date_from" value="<?=e($filtros['date_from'])?>" class="<?=INPUT_CLS?>"></label>
    <label><span>Até</span><input type="date" name="date_to" value="<?=e($filtros['date_to'])?>" class="<?=INPUT_CLS?>"></label>
    <label><span>Valor mínimo</span><input type="number" min="0" step="0.01" name="value_min" value="<?=e($filtros['value_min'])?>" class="<?=INPUT_CLS?>"></label>
    <label><span>Valor máximo</span><input type="number" min="0" step="0.01" name="value_max" value="<?=e($filtros['value_max'])?>" class="<?=INPUT_CLS?>"></label>
    <div class="dnb-neg-filters__actions"><button class="<?=BTN_P?>">Aplicar filtros</button><a class="<?=BTN_S?>" href="/negociacoes.php?view=negociacoes&modo=<?=urlencode($modo)?>">Limpar</a></div>
  </form>
</section>

<?php if($modo==='lista'):?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm"><div class="p-4 overflow-x-auto"><table class="w-full text-sm min-w-[1100px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Negociação</th><th>Empresa</th><th>Categoria</th><th>Funil / Etapa</th><th>Responsável</th><th>Relacionamentos</th><th>Status</th><th class="text-right">Valor estimado</th><th></th></tr></thead><tbody>
<?php foreach($negociacoes as $n):?><tr class="border-b border-slate-100"><td class="py-3"><a class="font-bold" style="color:<?=NAVY?>;" href="/negociacao.php?id=<?=(int)$n['id']?>"><?=e($n['title'])?></a><small class="block font-mono text-slate-400"><?=e($n['dnb_id'])?></small></td><td><?=e($n['company_name']?:'—')?></td><td><?=e($n['category']?:'—')?></td><td><?=e($n['pipeline_name']?:'—')?> <small class="block text-slate-500"><?=e($n['stage_name']?:'—')?></small></td><td><?=e($n['owner_name']?:'Não atribuído')?></td><td class="text-xs"><?= (int)$n['contacts_count']?> contato(s) · <?= (int)$n['quotes_count']?> proposta(s) · <?= (int)$n['orders_count']?> pedido(s) · <?= (int)$n['visits_count']?> visita(s)</td><td><?=tag(statusNegociacaoLabel($n['status']),$n['status']==='won'?'green':($n['status']==='lost'?'red':'blue'))?></td><td class="text-right font-semibold"><?=fmtBRL($n['estimated_value'])?></td><td class="text-right"><a class="<?=BTN_S?>" href="/negociacao.php?id=<?=(int)$n['id']?>">Abrir</a></td></tr><?php endforeach;?>
<?php if(!$negociacoes):?><tr><td colspan="9" class="py-8 text-center text-slate-400">Nenhuma negociação encontrada.</td></tr><?php endif;?>
</tbody></table></div></section>
<?php else:
$grupos=[];foreach($negociacoes as $n){$k=trim((string)$n['stage_name'])?:'Sem etapa';$grupos[$k][]=$n;}
?>
<div class="dnb-neg-kanban"><?php foreach($grupos as $etapa=>$items):?><section class="dnb-neg-kanban__column"><header><strong><?=e($etapa)?></strong><span><?=count($items)?></span></header><div class="dnb-neg-kanban__cards"><?php foreach($items as $n):?><a href="/negociacao.php?id=<?=(int)$n['id']?>" class="dnb-neg-card"><div class="flex justify-between gap-2"><strong><?=e($n['title'])?></strong><?=tag(statusNegociacaoLabel($n['status']),$n['status']==='won'?'green':($n['status']==='lost'?'red':'blue'))?></div><small><?=e($n['company_name']?:'Sem empresa')?> · <?=e($n['category']?:'Sem categoria')?></small><div class="mt-3 flex justify-between"><span class="font-mono text-xs"><?=e($n['dnb_id'])?></span><strong><?=fmtBRL($n['estimated_value'])?></strong></div></a><?php endforeach;?></div></section><?php endforeach;?><?php if(!$grupos):?><div class="bg-white rounded-xl border p-8 text-center text-slate-400">Nenhuma negociação para exibir no Kanban.</div><?php endif;?></div>
<?php endif;?>
<?php endif;?>

<div class="dnb-modal-backdrop hidden" data-modal="modal-nova-negociacao" <?=!empty($_GET['novo'])?'data-auto-open="1"':''?> aria-hidden="true"><div class="dnb-modal dnb-modal--wide" role="dialog" aria-modal="true"><div class="dnb-modal__header"><div><span class="dnb-eyebrow">Novo negócio</span><h2>Negociação</h2></div><button type="button" data-modal-close class="dnb-icon-button">×</button></div><form method="post" class="dnb-modal__body"><?=csrfInput()?><input type="hidden" name="acao" value="add"><div class="grid md:grid-cols-2 gap-3">
<label class="md:col-span-2"><span>Título *</span><input name="title" required maxlength="220" class="<?=INPUT_CLS?>" placeholder="Ex.: Nova central de ar comprimido"></label>
<label><span>Empresa</span><select name="company_id" class="<?=INPUT_CLS?>" data-company-select><option value="0">Sem empresa</option><?php foreach($empresas as $c):?><option value="<?=(int)$c['id']?>" <?=$preCompanyId===(int)$c['id']?'selected':''?>><?=e($c['name'])?> · <?=e($c['dnb_id'])?></option><?php endforeach;?></select></label>
<label><span>Contato principal</span><select name="contact_id" class="<?=INPUT_CLS?>"><option value="0">Sem contato</option><?php foreach($contatos as $ct):?><option value="<?=(int)$ct['id']?>" <?=$preContactId===(int)$ct['id']?'selected':''?>><?=e($ct['name'])?><?=!empty($ct['company_name'])?' · '.e($ct['company_name']):' · sem empresa'?></option><?php endforeach;?></select></label>
<label><span>Categoria</span><input name="category" maxlength="120" class="<?=INPUT_CLS?>" placeholder="Uma categoria"></label>
<label><span>Papel do contato</span><select name="contact_role" class="<?=INPUT_CLS?>"><option value="principal">Principal</option><option value="decisor">Decisor</option><option value="comprador">Comprador</option><option value="tecnico">Técnico</option><option value="financeiro">Financeiro</option><option value="outro">Outro</option></select></label>
<?php if($rdPipelineCatalog):?>
<label><span>Funil</span><select name="pipeline_name" class="<?=INPUT_CLS?>" data-rd-pipeline><option value="">Selecione o funil</option><?php foreach($rdPipelineCatalog as $pipe=>$stages):?><option value="<?=e($pipe)?>"><?=e($pipe)?></option><?php endforeach;?></select></label>
<label><span>Etapa</span><select name="stage_name" class="<?=INPUT_CLS?>" data-rd-stage><option value="" data-pipeline="">Selecione o funil primeiro</option><?php foreach($rdPipelineCatalog as $pipe=>$stages):foreach($stages as $stage):?><option value="<?=e($stage)?>" data-pipeline="<?=e($pipe)?>"><?=e($stage)?></option><?php endforeach;endforeach;?></select></label>
<?php else:?>
<label><span>Funil</span><input name="pipeline_name" maxlength="160" class="<?=INPUT_CLS?>" placeholder="Configure e descubra os funis do RD Station no ADM"></label>
<label><span>Etapa</span><input name="stage_name" maxlength="160" class="<?=INPUT_CLS?>"></label>
<?php endif;?>
<label><span>Valor estimado (R$)</span><input type="number" min="0" step="0.01" name="estimated_value" class="<?=INPUT_CLS?>"></label>
<label><span>Responsável</span><select name="assigned_to_user_id" class="<?=INPUT_CLS?>"><?php foreach($responsaveis as $u):?><option value="<?=(int)$u['id']?>" <?=(int)$u['id']===(int)usuarioLogado()['id']?'selected':''?>><?=e($u['nome'])?></option><?php endforeach;?></select></label>
</div><p class="text-xs text-slate-500 mt-3">Criar negociação não cria orçamento nem pedido automaticamente.</p><div class="dnb-modal__footer"><button type="button" data-modal-close class="<?=BTN_S?>">Cancelar</button><button class="<?=BTN_P?>">Criar negociação</button></div></form></div></div>
<?php endif;?>
<?php include __DIR__.'/includes/layout_footer.php';?>
