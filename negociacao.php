<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/crm_phase2_service.php';
require_once __DIR__ . '/includes/rd_discovery_service.php';
exigirPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR);

$pdo=db();$mes=$_GET['mes']??mesAtual();$id=(int)($_GET['id']??0);
if(!$id||!crmPhase2Disponivel($pdo)||!podeAcessarNegociacaoCrm($pdo,$id)){http_response_code(404);exit('Negociação não encontrada ou fora do seu escopo.');}
$erro='';$msg=!empty($_GET['criada'])?'Negociação criada com sucesso.':'';

if($_SERVER['REQUEST_METHOD']==='POST'){
    exigirCsrf();
    try{
        $acao=(string)($_POST['acao']??'');
        if($acao==='update'){
            atualizarNegociacaoCrmUi($pdo,$id,$_POST);$msg='Negociação atualizada.';
        }elseif($acao==='add_contact'){
            vincularContatoNegociacaoCrmUi($pdo,$id,(int)($_POST['contact_id']??0),(string)($_POST['role']??'outro'),!empty($_POST['is_primary']));
            $msg='Contato vinculado à negociação.';
        }elseif($acao==='remove_contact'){
            removerContatoNegociacaoCrmUi($pdo,$id,(int)($_POST['contact_id']??0));$msg='Contato removido da negociação.';
        }elseif($acao==='link_quote'){
            vincularOrcamentoNegociacaoCrmUi($pdo,$id,(int)($_POST['orcamento_id']??0));$msg='Orçamento vinculado à negociação.';
        }
    }catch(Throwable $e){$erro=$e->getMessage();}
}
$neg=detalharNegociacaoCrmUi($pdo,$id);if(!$neg){http_response_code(404);exit('Negociação não encontrada.');}
$empresas=empresasSelecionaveisCrmUi($pdo);$contatos=contatosSelecionaveisCrmUi($pdo,$neg['company_id']?(int)$neg['company_id']:null);$responsaveis=responsaveisNegociacaoCrmUi($pdo);$orcamentosVinculaveis=orcamentosVinculaveisCrmUi($pdo,$id);$rdPipelineCatalog=rdPipelineCatalogo($pdo);
$tabAtual='neg';include __DIR__.'/includes/layout_header.php';
?>
<?php if($erro):?><div class="dnb-alert dnb-alert--error"><?=e($erro)?></div><?php endif;?>
<?php if($msg):?><div class="dnb-alert dnb-alert--success"><?=e($msg)?></div><?php endif;?>

<nav class="dnb-crm-subnav mb-4" aria-label="Módulo Negociações"><a href="/negociacoes.php?view=leads">Leads / Pré-vendas</a><a class="is-active" href="/negociacoes.php">Negociações</a><a href="/orcamentos.php">Orçamentos</a></nav>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4 p-4">
  <div class="flex justify-between items-start gap-4 flex-wrap">
    <div><div class="flex gap-2 items-center flex-wrap"><span class="font-mono text-xs bg-slate-100 px-2 py-1 rounded"><?=e($neg['dnb_id'])?></span><?=tag(statusNegociacaoLabel($neg['status']),$neg['status']==='won'?'green':($neg['status']==='lost'?'red':'blue'))?><?php if($neg['order_status']==='pending'):?><?=tag('Pedido pendente','amber')?><?php elseif($neg['order_status']==='generated'):?><?=tag('Pedido gerado','green')?><?php endif;?></div><h2 class="text-2xl font-black mt-2" style="color:<?=NAVY?>;"><?=e($neg['title'])?></h2><p class="text-sm text-slate-500"><?=e($neg['company_name']?:'Sem empresa vinculada')?> · <?=e($neg['owner_name']?:'Sem responsável')?></p></div>
    <div class="flex gap-2"><a href="/negociacoes.php" class="<?=BTN_S?>">← Negociações</a><button type="button" class="<?=BTN_P?>" data-modal-open="modal-editar-negociacao">Editar negociação</button></div>
  </div>
  <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
    <div class="rounded-lg border p-3"><span class="text-xs text-slate-500">Valor estimado</span><strong class="block mt-1"><?=fmtBRL($neg['estimated_value'])?></strong></div>
    <div class="rounded-lg border p-3"><span class="text-xs text-slate-500">Categoria</span><strong class="block mt-1"><?=e($neg['category']?:'—')?></strong></div>
    <div class="rounded-lg border p-3"><span class="text-xs text-slate-500">Funil</span><strong class="block mt-1"><?=e($neg['pipeline_name']?:'—')?></strong></div>
    <div class="rounded-lg border p-3"><span class="text-xs text-slate-500">Etapa</span><strong class="block mt-1"><?=e($neg['stage_name']?:'—')?></strong></div>
  </div>
</section>

<div class="grid xl:grid-cols-2 gap-4">
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
    <div class="flex justify-between items-center gap-2"><div><span class="dnb-eyebrow">Relacionamento</span><h3 class="dnb-section-title">Contatos da negociação</h3></div><button type="button" class="<?=BTN_S?>" data-modal-open="modal-vincular-contato">Adicionar contato</button></div>
    <div class="mt-3 space-y-2"><?php foreach($neg['contacts'] as $ct):?><div class="border rounded-lg p-3 flex justify-between items-center gap-3"><div><a href="/contato.php?id=<?=(int)$ct['id']?>" class="font-bold" style="color:<?=NAVY?>;"><?=e($ct['name'])?></a><small class="block text-slate-500"><?=e($ct['email']?:'sem e-mail')?> · <?=e($ct['role'])?><?=$ct['is_primary']?' · principal':''?></small></div><form method="post" onsubmit="return confirm('Remover este contato da negociação?');"><?=csrfInput()?><input type="hidden" name="acao" value="remove_contact"><input type="hidden" name="contact_id" value="<?=(int)$ct['id']?>"><button class="<?=BTN_S?>">Remover</button></form></div><?php endforeach;?><?php if(!$neg['contacts']):?><p class="text-sm text-slate-400">Nenhum contato vinculado.</p><?php endif;?></div>
  </section>

  <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
    <div class="flex justify-between items-center gap-2"><div><span class="dnb-eyebrow">Comercial</span><h3 class="dnb-section-title">Orçamentos / propostas</h3></div><div class="flex gap-2"><button type="button" class="<?=BTN_S?>" data-modal-open="modal-vincular-orcamento">Vincular existente</button><a class="<?=BTN_P?>" href="/orcamentos.php?negotiation_id=<?=$id?>">Novo orçamento</a></div></div>
    <div class="mt-3 space-y-2"><?php foreach($neg['quotes'] as $o):?><div class="border rounded-lg p-3 flex justify-between items-center gap-3"><div><a href="/lead.php?id=<?=(int)$o['id']?>" class="font-bold" style="color:<?=NAVY?>;"><?=e($o['numero']?:'Orçamento #'.$o['id'])?></a><small class="block text-slate-500"><?=e($o['data'])?> · <?=e($o['linha'])?> · <?=e($o['status'])?></small></div><strong><?=fmtBRL($o['valor'])?></strong></div><?php endforeach;?><?php if(!$neg['quotes']):?><p class="text-sm text-slate-400">Nenhum orçamento vinculado. A negociação pode existir normalmente sem proposta.</p><?php endif;?></div>
  </section>

  <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
    <div><span class="dnb-eyebrow">Conversão</span><h3 class="dnb-section-title">Pedidos</h3></div>
    <div class="mt-3 space-y-2"><?php foreach($neg['orders'] as $p):?><div class="border rounded-lg p-3 flex justify-between items-center"><div><strong><?=e($p['numero']?:'Pedido #'.$p['id'])?></strong><small class="block text-slate-500"><?=e($p['data'])?> · <?=e($p['modalidade'])?><?=$p['faturado']?' · faturado':''?></small></div><strong><?=fmtBRL($p['valor'])?></strong></div><?php endforeach;?><?php if(!$neg['orders']):?><div class="rounded-lg border border-dashed p-4 text-sm text-slate-500"><?=$neg['status']==='won'?'Negociação ganha, mas ainda sem pedido. O status permanece Pedido pendente até a geração.':'Nenhum pedido gerado.'?><?php if($neg['status']==='won'&&!empty($neg['quotes'])):$baseQuote=$neg['quotes'][0];?><div class="mt-3"><a class="<?=BTN_P?>" href="/orcamentos.php?converter=<?=(int)$baseQuote['id']?>">Gerar Pedido a partir de <?=e($baseQuote['numero']?:'orçamento #'.$baseQuote['id'])?></a></div><?php elseif($neg['status']==='won'):?><div class="mt-2 text-xs text-amber-700">Crie ou vincule um orçamento antes de gerar o pedido.</div><?php endif;?></div><?php endif;?></div>
  </section>

  <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
    <div><span class="dnb-eyebrow">Campo</span><h3 class="dnb-section-title">Visitas</h3></div>
    <div class="mt-3 space-y-2"><?php foreach($neg['visits'] as $v):?><div class="border rounded-lg p-3"><div class="flex justify-between gap-2"><strong><?=e($v['os']?:'Visita #'.$v['id'])?></strong><span class="text-xs text-slate-400"><?=e($v['data'])?></span></div><small class="block text-slate-500"><?=e($v['tipo'])?> · <?=e(mb_substr((string)$v['oportunidade'],0,140))?></small></div><?php endforeach;?><?php if(!$neg['visits']):?><p class="text-sm text-slate-400">Nenhuma visita vinculada.</p><?php endif;?></div>
  </section>
</div>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4 mt-4">
  <span class="dnb-eyebrow">Resumo</span><h3 class="dnb-section-title">Dados do negócio</h3>
  <dl class="dnb-detail-list mt-3"><div><dt>Empresa</dt><dd><?php if($neg['company_id']):?><a href="/empresa.php?id=<?=(int)$neg['company_id']?>" class="font-bold" style="color:<?=NAVY?>;"><?=e($neg['company_name'])?></a><?php else:?>—<?php endif;?></dd></div><div><dt>Responsável</dt><dd><?=e($neg['owner_name']?:'—')?></dd></div><div><dt>Origem</dt><dd><?=e($neg['origin']?:'manual')?></dd></div><div><dt>Motivo de perda</dt><dd><?=e($neg['lost_reason']?:'—')?></dd></div><div><dt>Criada</dt><dd><?=e((string)($neg['business_created_at']?:$neg['created_at']))?></dd></div><div><dt>Última movimentação</dt><dd><?=e((string)($neg['business_updated_at']?:$neg['updated_at']))?></dd></div></dl>
</section>

<div class="dnb-modal-backdrop hidden" data-modal="modal-editar-negociacao" aria-hidden="true"><div class="dnb-modal dnb-modal--wide" role="dialog" aria-modal="true"><div class="dnb-modal__header"><h2>Editar negociação</h2><button type="button" data-modal-close class="dnb-icon-button">×</button></div><form method="post" class="dnb-modal__body"><?=csrfInput()?><input type="hidden" name="acao" value="update"><div class="grid md:grid-cols-2 gap-3"><label class="md:col-span-2"><span>Título</span><input name="title" required value="<?=e($neg['title'])?>" class="<?=INPUT_CLS?>"></label><label><span>Empresa</span><select name="company_id" class="<?=INPUT_CLS?>"><option value="0">Sem empresa</option><?php foreach($empresas as $c):?><option value="<?=(int)$c['id']?>" <?=(int)$neg['company_id']===(int)$c['id']?'selected':''?>><?=e($c['name'])?> · <?=e($c['dnb_id'])?></option><?php endforeach;?></select></label><label><span>Categoria</span><input name="category" value="<?=e($neg['category'])?>" class="<?=INPUT_CLS?>"></label><?php if($rdPipelineCatalog):?>
<label><span>Funil</span><select name="pipeline_name" class="<?=INPUT_CLS?>" data-rd-pipeline><?php $currentPipe=(string)$neg['pipeline_name']; if($currentPipe!==''&&!array_key_exists($currentPipe,$rdPipelineCatalog)):?><option value="<?=e($currentPipe)?>" selected><?=e($currentPipe)?> · legado</option><?php endif;?><option value="">Sem funil</option><?php foreach($rdPipelineCatalog as $pipe=>$stages):?><option value="<?=e($pipe)?>" <?=$currentPipe===$pipe?'selected':''?>><?=e($pipe)?></option><?php endforeach;?></select></label>
<label><span>Etapa</span><select name="stage_name" class="<?=INPUT_CLS?>" data-rd-stage><?php $currentStage=(string)$neg['stage_name']; $knownStage=$currentPipe!==''&&isset($rdPipelineCatalog[$currentPipe])&&in_array($currentStage,$rdPipelineCatalog[$currentPipe],true); if($currentStage!==''&&!$knownStage):?><option value="<?=e($currentStage)?>" data-pipeline="<?=e($currentPipe)?>" selected><?=e($currentStage)?> · legado</option><?php endif;?><option value="" data-pipeline="">Sem etapa</option><?php foreach($rdPipelineCatalog as $pipe=>$stages):foreach($stages as $stage):?><option value="<?=e($stage)?>" data-pipeline="<?=e($pipe)?>" <?=$currentPipe===$pipe&&$currentStage===$stage?'selected':''?>><?=e($stage)?></option><?php endforeach;endforeach;?></select></label>
<?php else:?><label><span>Funil</span><input name="pipeline_name" value="<?=e($neg['pipeline_name'])?>" class="<?=INPUT_CLS?>"></label><label><span>Etapa</span><input name="stage_name" value="<?=e($neg['stage_name'])?>" class="<?=INPUT_CLS?>"></label><?php endif;?><label><span>Valor estimado</span><input type="number" min="0" step="0.01" name="estimated_value" value="<?=e((string)$neg['estimated_value'])?>" class="<?=INPUT_CLS?>"></label><label><span>Status</span><select name="status" class="<?=INPUT_CLS?>"><option value="open" <?=$neg['status']==='open'?'selected':''?>>Aberta</option><option value="won" <?=$neg['status']==='won'?'selected':''?>>Ganha</option><option value="lost" <?=$neg['status']==='lost'?'selected':''?>>Perdida</option></select></label><label><span>Responsável</span><select name="assigned_to_user_id" class="<?=INPUT_CLS?>"><?php foreach($responsaveis as $u):?><option value="<?=(int)$u['id']?>" <?=(int)$neg['assigned_to_user_id']===(int)$u['id']?'selected':''?>><?=e($u['nome'])?></option><?php endforeach;?></select></label><label class="md:col-span-2"><span>Motivo de perda</span><input name="lost_reason" value="<?=e($neg['lost_reason'])?>" class="<?=INPUT_CLS?>"></label></div><div class="dnb-modal__footer"><button type="button" data-modal-close class="<?=BTN_S?>">Cancelar</button><button class="<?=BTN_P?>">Salvar</button></div></form></div></div>

<div class="dnb-modal-backdrop hidden" data-modal="modal-vincular-contato" aria-hidden="true"><div class="dnb-modal" role="dialog" aria-modal="true"><div class="dnb-modal__header"><h2>Adicionar contato</h2><button type="button" data-modal-close class="dnb-icon-button">×</button></div><form method="post" class="dnb-modal__body"><?=csrfInput()?><input type="hidden" name="acao" value="add_contact"><div class="grid gap-3"><label><span>Contato</span><select name="contact_id" required class="<?=INPUT_CLS?>"><option value="">Selecione</option><?php foreach($contatos as $ct):?><option value="<?=(int)$ct['id']?>"><?=e($ct['name'])?> · <?=e($ct['email']?:$ct['dnb_id'])?></option><?php endforeach;?></select></label><label><span>Papel</span><select name="role" class="<?=INPUT_CLS?>"><option value="principal">Principal</option><option value="decisor">Decisor</option><option value="comprador">Comprador</option><option value="tecnico">Técnico</option><option value="financeiro">Financeiro</option><option value="outro">Outro</option></select></label><label class="flex gap-2 items-center"><input type="checkbox" name="is_primary" value="1"><span>Marcar como contato principal</span></label></div><div class="dnb-modal__footer"><button type="button" data-modal-close class="<?=BTN_S?>">Cancelar</button><button class="<?=BTN_P?>">Vincular</button></div></form></div></div>

<div class="dnb-modal-backdrop hidden" data-modal="modal-vincular-orcamento" aria-hidden="true"><div class="dnb-modal" role="dialog" aria-modal="true"><div class="dnb-modal__header"><h2>Vincular orçamento existente</h2><button type="button" data-modal-close class="dnb-icon-button">×</button></div><form method="post" class="dnb-modal__body"><?=csrfInput()?><input type="hidden" name="acao" value="link_quote"><label><span>Orçamento</span><select name="orcamento_id" required class="<?=INPUT_CLS?>"><option value="">Selecione</option><?php foreach($orcamentosVinculaveis as $o):?><option value="<?=(int)$o['id']?>"><?=e($o['numero']?:'#'.$o['id'])?> · <?=e($o['cliente'])?> · <?=fmtBRL($o['valor'])?></option><?php endforeach;?></select></label><p class="text-xs text-slate-500 mt-2">Se o orçamento estiver em uma negociação de transição sem outros vínculos, ele poderá ser associado a esta negociação.</p><div class="dnb-modal__footer"><button type="button" data-modal-close class="<?=BTN_S?>">Cancelar</button><button class="<?=BTN_P?>">Vincular</button></div></form></div></div>

<?php include __DIR__.'/includes/layout_footer.php';?>
