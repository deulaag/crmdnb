<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/crm_phase2_service.php';
exigirPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR);

$pdo=db();$mes=$_GET['mes']??mesAtual();$id=(int)($_GET['id']??0);
if(!$id||!crmPhase2Disponivel($pdo)||!podeAcessarContatoCrm($pdo,$id)){http_response_code(404);exit('Contato não encontrado ou fora do seu escopo.');}
$erro='';$msg=!empty($_GET['criado'])?'Contato criado com sucesso.':'';
if($_SERVER['REQUEST_METHOD']==='POST'){
  exigirCsrf();
  try{
    $acao=(string)($_POST['acao']??'');
    if($acao==='update'){atualizarContatoCrmUi($pdo,$id,$_POST);$msg='Contato atualizado.';}
    elseif($acao==='send_rd'){
      if(!temPapel(PAPEL_ADMINISTRADOR)||!rdPhase9Disponivel($pdo))throw new RuntimeException('Envio manual ao RD disponível somente para o ADM após instalar a Fase 9.');
      rdPhase9EnqueueLocalId($pdo,'contact',$id,'envio_manual_contato',(int)usuarioLogado()['id'],true);
      $msg='Contato colocado na fila DNB → RD.';
    }
  }catch(Throwable $e){$erro=$e->getMessage();}
}
$contato=detalharContatoCrmUi($pdo,$id);if(!$contato){http_response_code(404);exit('Contato não encontrado.');}
$empresas=empresasSelecionaveisCrmUi($pdo);
$aba=(string)($_GET['aba']??'dados');$abas=['dados'=>'Dados','empresa'=>'Empresa','negociacoes'=>'Negociações','marketing'=>'Marketing','visitas'=>'Visitas','historico'=>'Histórico'];if(!isset($abas[$aba]))$aba='dados';
$timeline=[];
foreach($contato['negotiations'] as $n)$timeline[]=['date'=>(string)($n['business_updated_at']??$n['updated_at']??''),'type'=>'Negociação','title'=>(string)$n['title'],'meta'=>statusNegociacaoLabel((string)$n['status'])];
foreach($contato['visits'] as $v)$timeline[]=['date'=>(string)($v['data']??''),'type'=>'Visita','title'=>(string)($v['os']?:'Visita'),'meta'=>(string)($v['oportunidade']??'')];
usort($timeline,static fn($a,$b)=>strcmp($b['date'],$a['date']));
$tabAtual='contatos';include __DIR__.'/includes/layout_header.php';
?>
<?php if($erro):?><div class="dnb-alert dnb-alert--error"><?=e($erro)?></div><?php endif;?>
<?php if($msg):?><div class="dnb-alert dnb-alert--success"><?=e($msg)?></div><?php endif;?>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4 p-4">
  <div class="flex items-start justify-between gap-4 flex-wrap">
    <div><div class="flex gap-2 items-center flex-wrap"><span class="font-mono text-xs bg-slate-100 px-2 py-1 rounded"><?=e($contato['dnb_id'])?></span><?=($contato['review_status']??'ok')==='needs_review'?tag('Precisa conferir','amber'):tag('Cadastro OK','green')?></div><h2 class="text-2xl font-black mt-2" style="color:<?=NAVY?>;"><?=e($contato['name'])?></h2><p class="text-sm text-slate-500"><?=e($contato['role_title']?:'Contato comercial')?></p></div>
    <div class="flex gap-2 flex-wrap"><?php if(temPapel(PAPEL_ADMINISTRADOR)&&rdPhase9Disponivel($pdo)):?><form method="post"><?=csrfInput()?><input type="hidden" name="acao" value="send_rd"><button class="<?=BTN_S?>">Colocar na fila RD</button></form><?php endif;?><a href="/contatos.php" class="<?=BTN_S?>">← Contatos</a><button type="button" class="<?=BTN_P?>" data-modal-open="modal-editar-contato">Editar contato</button></div>
  </div>
  <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
    <div class="rounded-lg border p-3"><span class="text-xs text-slate-500">Empresa</span><strong class="block mt-1"><?=e($contato['company_name']?:'Sem empresa')?></strong></div>
    <div class="rounded-lg border p-3"><span class="text-xs text-slate-500">E-mail</span><strong class="block mt-1 break-all"><?=e($contato['email']?:'—')?></strong></div>
    <div class="rounded-lg border p-3"><span class="text-xs text-slate-500">Negociações</span><strong class="block mt-1"><?=count($contato['negotiations'])?></strong></div>
    <div class="rounded-lg border p-3"><span class="text-xs text-slate-500">Visitas</span><strong class="block mt-1"><?=count($contato['visits'])?></strong></div>
  </div>
</section>

<nav class="dnb-crm-subnav mb-4" aria-label="Seções do contato"><?php foreach($abas as $k=>$label):?><a class="<?=$aba===$k?'is-active':''?>" href="/contato.php?id=<?=$id?>&aba=<?=urlencode($k)?>"><?=e($label)?></a><?php endforeach;?></nav>

<?php if($aba==='dados'):?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4"><h3 class="dnb-section-title">Dados do contato</h3><dl class="dnb-detail-list mt-3"><div><dt>Nome</dt><dd><?=e($contato['name'])?></dd></div><div><dt>E-mail</dt><dd><?=e($contato['email']?:'—')?></dd></div><div><dt>Telefone</dt><dd><?=e($contato['phone']?:'—')?></dd></div><div><dt>Cargo / função</dt><dd><?=e($contato['role_title']?:'—')?></dd></div><div><dt>Origem</dt><dd><?=e($contato['source']?:'manual')?></dd></div><div><dt>Revisão</dt><dd><?=e($contato['review_reason']?:'Sem pendências')?></dd></div></dl></section>
<?php elseif($aba==='empresa'):?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4"><h3 class="dnb-section-title">Empresa vinculada</h3><?php if($contato['company_id']):?><div class="mt-3 border rounded-lg p-4 flex justify-between items-center gap-3"><div><strong><?=e($contato['company_name'])?></strong><small class="block font-mono text-slate-400"><?=e($contato['company_dnb_id'])?></small></div><a class="<?=BTN_S?>" href="/empresa.php?id=<?=(int)$contato['company_id']?>">Abrir empresa</a></div><?php else:?><div class="dnb-alert dnb-alert--warning mt-3">Este contato ainda está em <strong>Contatos sem empresa</strong>. Use “Editar contato” para associá-lo quando a empresa for identificada.</div><?php endif;?></section>
<?php elseif($aba==='negociacoes'):?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4"><div class="flex justify-between items-center"><h3 class="dnb-section-title">Negociações relacionadas</h3><a class="<?=BTN_P?>" href="/negociacoes.php?novo=1&contact_id=<?=$id?>">Nova negociação</a></div><div class="overflow-x-auto mt-3"><table class="w-full text-sm min-w-[760px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Negociação</th><th>Papel</th><th>Empresa</th><th>Funil / etapa</th><th>Status</th><th>Valor</th><th></th></tr></thead><tbody><?php foreach($contato['negotiations'] as $n):?><tr class="border-b border-slate-100"><td class="py-2"><strong><?=e($n['title'])?></strong><small class="block font-mono"><?=e($n['dnb_id'])?></small></td><td><?=e($n['role'])?><?=$n['is_primary']?' · principal':''?></td><td><?=e($n['company_name']?:'—')?></td><td><?=e($n['pipeline_name']?:'—')?> / <?=e($n['stage_name']?:'—')?></td><td><?=tag(statusNegociacaoLabel($n['status']),$n['status']==='won'?'green':($n['status']==='lost'?'red':'blue'))?></td><td><?=fmtBRL($n['estimated_value'])?></td><td class="text-right"><a class="<?=BTN_S?>" href="/negociacao.php?id=<?=(int)$n['id']?>">Abrir</a></td></tr><?php endforeach;?><?php if(!$contato['negotiations']):?><tr><td colspan="7" class="py-6 text-center text-slate-400">Contato ainda não participa de negociação.</td></tr><?php endif;?></tbody></table></div></section>
<?php elseif($aba==='marketing'):?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4"><span class="dnb-eyebrow">RD Station Marketing</span><h3 class="dnb-section-title">Marketing</h3><p class="text-sm text-slate-500 mt-2">A estrutura está reservada para origem, tags, estágio de funil, conversões e campos escolhidos pelo ADM. Nenhum dado externo é simulado nesta fase.</p><div class="mt-4 rounded-lg border border-dashed p-6 text-center text-slate-400">A conexão e o mapeamento do RD Station serão habilitados nas próximas fases.</div></section>
<?php elseif($aba==='visitas'):?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4"><h3 class="dnb-section-title">Visitas relacionadas</h3><div class="overflow-x-auto mt-3"><table class="w-full text-sm min-w-[700px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Data</th><th>OS</th><th>Tipo</th><th>Empresa</th><th>Oportunidade</th></tr></thead><tbody><?php foreach($contato['visits'] as $v):?><tr class="border-b border-slate-100"><td class="py-2"><?=e($v['data'])?></td><td><?=e($v['os']?:'—')?></td><td><?=e($v['tipo'])?></td><td><?=e($v['cliente'])?></td><td><?=e(mb_substr((string)$v['oportunidade'],0,120))?></td></tr><?php endforeach;?><?php if(!$contato['visits']):?><tr><td colspan="5" class="py-6 text-center text-slate-400">Nenhuma visita vinculada.</td></tr><?php endif;?></tbody></table></div></section>
<?php else:?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4"><h3 class="dnb-section-title">Histórico do contato</h3><div class="mt-4 space-y-3"><?php foreach($timeline as $ev):?><div class="grid grid-cols-[110px_110px_1fr] gap-3 border-b pb-3"><span class="text-xs text-slate-400"><?=e(substr($ev['date'],0,10))?></span><strong class="text-xs"><?=e($ev['type'])?></strong><div><strong><?=e($ev['title'])?></strong><small class="block text-slate-500"><?=e(mb_substr($ev['meta'],0,160))?></small></div></div><?php endforeach;?><?php if(!$timeline):?><p class="text-sm text-slate-400">Nenhum evento relacionado.</p><?php endif;?></div></section>
<?php endif;?>

<div class="dnb-modal-backdrop hidden" data-modal="modal-editar-contato" aria-hidden="true"><div class="dnb-modal" role="dialog" aria-modal="true"><div class="dnb-modal__header"><h2>Editar contato</h2><button type="button" data-modal-close class="dnb-icon-button">×</button></div><form method="post" class="dnb-modal__body"><?=csrfInput()?><input type="hidden" name="acao" value="update"><div class="grid gap-3"><label><span>Nome *</span><input name="name" required value="<?=e($contato['name'])?>" class="<?=INPUT_CLS?>"></label><label><span>Empresa</span><select name="company_id" class="<?=INPUT_CLS?>"><option value="0">Sem empresa</option><?php foreach($empresas as $c):?><option value="<?=(int)$c['id']?>" <?=(int)$contato['company_id']===(int)$c['id']?'selected':''?>><?=e($c['name'])?> · <?=e($c['dnb_id'])?></option><?php endforeach;?></select></label><label><span>E-mail</span><input type="email" name="email" value="<?=e($contato['email'])?>" class="<?=INPUT_CLS?>"></label><label><span>Telefone</span><input name="phone" value="<?=e($contato['phone'])?>" class="<?=INPUT_CLS?>"></label><label><span>Cargo / função</span><input name="role_title" value="<?=e($contato['role_title'])?>" class="<?=INPUT_CLS?>"></label></div><div class="dnb-modal__footer"><button type="button" data-modal-close class="<?=BTN_S?>">Cancelar</button><button class="<?=BTN_P?>">Salvar</button></div></form></div></div>
<?php include __DIR__.'/includes/layout_footer.php';?>
