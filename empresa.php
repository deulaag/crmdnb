<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/crm_phase2_service.php';
exigirPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR);

$pdo=db();$mes=$_GET['mes']??mesAtual();$id=(int)($_GET['id']??0);
if(!$id||!crmPhase2Disponivel($pdo)||!podeAcessarEmpresaCrm($pdo,$id)){http_response_code(404);exit('Empresa não encontrada ou fora do seu escopo.');}
$erro='';$msg='';
if(!empty($_GET['criada']))$msg='Empresa criada com sucesso.';
if($_SERVER['REQUEST_METHOD']==='POST'){
  exigirCsrf();
  try{
    $acao=(string)($_POST['acao']??'');
    if($acao==='update'){
      atualizarEmpresaCrmUi($pdo,$id,$_POST);$msg='Empresa atualizada.';
    }elseif($acao==='add_contact'){
      $contato=criarContatoCrmUi($pdo,[
        'company_id'=>$id,'name'=>$_POST['contact_name']??'','email'=>$_POST['contact_email']??'',
        'phone'=>$_POST['contact_phone']??'','role_title'=>$_POST['contact_role']??'','source'=>'manual'
      ],!empty($_POST['permitir_duplicidade']));
      $msg='Contato '.$contato['dnb_id'].' criado e vinculado.';
    }elseif($acao==='send_rd'){
      if(!temPapel(PAPEL_ADMINISTRADOR)||!rdPhase9Disponivel($pdo))throw new RuntimeException('Envio manual ao RD disponível somente para o ADM após instalar a Fase 9.');
      rdPhase9EnqueueLocalId($pdo,'company',$id,'envio_manual_empresa',(int)usuarioLogado()['id'],true);
      $msg='Empresa colocada na fila DNB → RD.';
    }
  }catch(Throwable $e){$erro=$e->getMessage();}
}
$empresa=detalharEmpresaCrmUi($pdo,$id);
if(!$empresa){http_response_code(404);exit('Empresa não encontrada.');}
$timeline=timelineEmpresaCrmUi($empresa);
$aba=(string)($_GET['aba']??'resumo');
$abas=['resumo'=>'Resumo','contatos'=>'Contatos','negociacoes'=>'Negociações','orcamentos'=>'Orçamentos','pedidos'=>'Pedidos','visitas'=>'Visitas','historico'=>'Histórico'];
if(!isset($abas[$aba]))$aba='resumo';
$tabAtual='empresas';
include __DIR__.'/includes/layout_header.php';
?>
<?php if($erro): ?><div class="dnb-alert dnb-alert--error"><?=e($erro)?></div><?php endif;?>
<?php if($msg): ?><div class="dnb-alert dnb-alert--success"><?=e($msg)?></div><?php endif;?>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4 p-4">
  <div class="flex items-start justify-between gap-4 flex-wrap">
    <div>
      <div class="flex items-center gap-2 flex-wrap"><span class="font-mono text-xs bg-slate-100 px-2 py-1 rounded"><?=e($empresa['dnb_id'])?></span><?=($empresa['review_status']??'ok')==='needs_review'?tag('Precisa conferir','amber'):tag('Cadastro OK','green')?></div>
      <h2 class="text-2xl font-black mt-2" style="color:<?=NAVY?>;"><?=e($empresa['name'])?></h2>
      <p class="text-sm text-slate-500"><?=e($empresa['trade_name']?:'')?></p>
    </div>
    <div class="flex gap-2 flex-wrap"><?php if(temPapel(PAPEL_ADMINISTRADOR)&&rdPhase9Disponivel($pdo)):?><form method="post"><?=csrfInput()?><input type="hidden" name="acao" value="send_rd"><button class="<?=BTN_S?>">Colocar na fila RD</button></form><?php endif;?><a class="<?=BTN_S?>" href="/empresas.php">← Empresas</a><button type="button" class="<?=BTN_P?>" data-modal-open="modal-editar-empresa">Editar empresa</button></div>
  </div>
  <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
    <div class="rounded-lg border p-3"><span class="text-xs text-slate-500">CNPJ</span><strong class="block mt-1"><?=e($empresa['cnpj']?:'—')?></strong></div>
    <div class="rounded-lg border p-3"><span class="text-xs text-slate-500">Contatos</span><strong class="block mt-1"><?=count($empresa['contacts'])?></strong></div>
    <div class="rounded-lg border p-3"><span class="text-xs text-slate-500">Negociações</span><strong class="block mt-1"><?=count($empresa['negotiations'])?></strong></div>
    <div class="rounded-lg border p-3"><span class="text-xs text-slate-500">Pipeline aberto</span><strong class="block mt-1"><?=fmtBRL(array_sum(array_map(fn($n)=>$n['status']==='open'?(float)$n['estimated_value']:0,$empresa['negotiations'])))?></strong></div>
  </div>
</section>

<nav class="dnb-crm-subnav mb-4" aria-label="Seções da empresa">
<?php foreach($abas as $k=>$label): ?><a class="<?= $aba===$k?'is-active':'' ?>" href="/empresa.php?id=<?=$id?>&aba=<?=urlencode($k)?>"><?=e($label)?></a><?php endforeach;?>
</nav>

<?php if($aba==='resumo'): ?>
<div class="grid lg:grid-cols-2 gap-4">
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
    <h3 class="dnb-section-title">Dados da empresa</h3>
    <dl class="dnb-detail-list mt-3">
      <div><dt>E-mail</dt><dd><?=e($empresa['primary_email']?:'—')?></dd></div>
      <div><dt>Telefone</dt><dd><?=e($empresa['phone']?:'—')?></dd></div>
      <div><dt>Website</dt><dd><?=e($empresa['website']?:'—')?></dd></div>
      <div><dt>Endereço</dt><dd><?=e($empresa['address']?:'—')?></dd></div>
      <div><dt>Localidade</dt><dd><?=e($empresa['locality']?:'—')?></dd></div>
      <div><dt>Origem</dt><dd><?=e($empresa['source']?:'manual')?></dd></div>
    </dl>
  </section>
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
    <div class="flex items-center justify-between"><h3 class="dnb-section-title">Últimas movimentações</h3><a href="/empresa.php?id=<?=$id?>&aba=historico" class="text-sm font-bold" style="color:<?=NAVY?>;">Ver histórico</a></div>
    <div class="mt-3 space-y-3"><?php foreach(array_slice($timeline,0,6) as $ev): ?><div class="border-l-2 pl-3" style="border-color:<?=NAVY2?>;"><span class="text-xs text-slate-400"><?=e(substr($ev['date'],0,10))?> · <?=e($ev['type'])?></span><strong class="block"><?=e($ev['title'])?></strong><small class="text-slate-500"><?=e($ev['meta'])?></small></div><?php endforeach;?><?php if(!$timeline):?><p class="text-sm text-slate-400">Ainda não há movimentações.</p><?php endif;?></div>
  </section>
</div>
<?php elseif($aba==='contatos'): ?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
  <div class="flex justify-between items-center gap-3"><h3 class="dnb-section-title">Funcionários / contatos</h3><button type="button" class="<?=BTN_P?>" data-modal-open="modal-novo-contato">Novo contato</button></div>
  <div class="overflow-x-auto mt-3"><table class="w-full text-sm min-w-[700px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Contato</th><th>E-mail</th><th>Telefone</th><th>Cargo</th><th>DNB ID</th><th></th></tr></thead><tbody>
  <?php foreach($empresa['contacts'] as $ct):?><tr class="border-b border-slate-100"><td class="py-2 font-semibold"><?=e($ct['name'])?></td><td><?=e($ct['email']?:'—')?></td><td><?=e($ct['phone']?:'—')?></td><td><?=e($ct['role_title']?:'—')?></td><td class="font-mono text-xs"><?=e($ct['dnb_id'])?></td><td class="text-right"><a class="<?=BTN_S?>" href="/contato.php?id=<?=(int)$ct['id']?>">Abrir</a></td></tr><?php endforeach;?>
  <?php if(!$empresa['contacts']):?><tr><td colspan="6" class="py-6 text-center text-slate-400">Nenhum contato vinculado.</td></tr><?php endif;?>
  </tbody></table></div>
</section>
<?php elseif($aba==='negociacoes'): ?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4"><h3 class="dnb-section-title">Negociações</h3><div class="overflow-x-auto mt-3"><table class="w-full text-sm min-w-[760px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Negociação</th><th>Funil</th><th>Etapa</th><th>Status</th><th>Valor estimado</th><th></th></tr></thead><tbody>
<?php foreach($empresa['negotiations'] as $n):?><tr class="border-b border-slate-100"><td class="py-2"><strong><?=e($n['title'])?></strong><small class="block font-mono"><?=e($n['dnb_id'])?></small></td><td><?=e($n['pipeline_name']?:'—')?></td><td><?=e($n['stage_name']?:'—')?></td><td><?=tag(statusNegociacaoLabel($n['status']),$n['status']==='won'?'green':($n['status']==='lost'?'red':'blue'))?></td><td><?=fmtBRL($n['estimated_value'])?></td><td class="text-right"><a class="<?=BTN_S?>" href="/negociacao.php?id=<?=(int)$n['id']?>">Abrir</a></td></tr><?php endforeach;?>
<?php if(!$empresa['negotiations']):?><tr><td colspan="6" class="py-6 text-center text-slate-400">Nenhuma negociação.</td></tr><?php endif;?>
</tbody></table></div></section>
<?php elseif($aba==='orcamentos'): ?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4"><h3 class="dnb-section-title">Orçamentos / propostas</h3><div class="overflow-x-auto mt-3"><table class="w-full text-sm min-w-[700px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Número</th><th>Data</th><th>Linha</th><th>Status</th><th>Valor</th><th></th></tr></thead><tbody><?php foreach($empresa['quotes'] as $o):?><tr class="border-b border-slate-100"><td class="py-2"><?=e($o['numero']?:'s/n')?></td><td><?=e($o['data'])?></td><td><?=e($o['linha'])?></td><td><?=e($o['status'])?></td><td><?=fmtBRL($o['valor'])?></td><td class="text-right"><a class="<?=BTN_S?>" href="/lead.php?id=<?=(int)$o['id']?>">Abrir</a></td></tr><?php endforeach;?><?php if(!$empresa['quotes']):?><tr><td colspan="6" class="py-6 text-center text-slate-400">Nenhuma proposta.</td></tr><?php endif;?></tbody></table></div></section>
<?php elseif($aba==='pedidos'): ?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4"><h3 class="dnb-section-title">Pedidos</h3><div class="overflow-x-auto mt-3"><table class="w-full text-sm min-w-[700px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Número</th><th>Data</th><th>Modalidade</th><th>Faturado</th><th>Valor</th></tr></thead><tbody><?php foreach($empresa['orders'] as $p):?><tr class="border-b border-slate-100"><td class="py-2"><?=e($p['numero']?:'s/n')?></td><td><?=e($p['data'])?></td><td><?=e($p['modalidade'])?></td><td><?=$p['faturado']?tag('Sim','green'):tag('Pendente','amber')?></td><td><?=fmtBRL($p['valor'])?></td></tr><?php endforeach;?><?php if(!$empresa['orders']):?><tr><td colspan="5" class="py-6 text-center text-slate-400">Nenhum pedido.</td></tr><?php endif;?></tbody></table></div></section>
<?php elseif($aba==='visitas'): ?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4"><h3 class="dnb-section-title">Visitas e prospecções</h3><div class="overflow-x-auto mt-3"><table class="w-full text-sm min-w-[700px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Data</th><th>OS</th><th>Tipo</th><th>Contato</th><th>Oportunidade</th></tr></thead><tbody><?php foreach($empresa['visits'] as $v):?><tr class="border-b border-slate-100"><td class="py-2"><?=e($v['data'])?></td><td><?=e($v['os']?:'—')?></td><td><?=e($v['tipo'])?></td><td><?=e($v['contato']?:'—')?></td><td><?=e(mb_substr((string)$v['oportunidade'],0,120))?></td></tr><?php endforeach;?><?php if(!$empresa['visits']):?><tr><td colspan="5" class="py-6 text-center text-slate-400">Nenhuma visita.</td></tr><?php endif;?></tbody></table></div></section>
<?php elseif($aba==='historico'): ?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4"><h3 class="dnb-section-title">Histórico consolidado</h3><div class="mt-4 space-y-3"><?php foreach($timeline as $ev):?><div class="grid grid-cols-[110px_110px_1fr] gap-3 border-b pb-3"><span class="text-xs text-slate-400"><?=e(substr($ev['date'],0,10))?></span><strong class="text-xs"><?=e($ev['type'])?></strong><div><strong><?=e($ev['title'])?></strong><small class="block text-slate-500"><?=e($ev['meta'])?></small></div></div><?php endforeach;?><?php if(!$timeline):?><p class="text-sm text-slate-400">Nenhum evento relacionado.</p><?php endif;?></div></section>
<?php endif; ?>

<div class="dnb-modal-backdrop hidden" data-modal="modal-editar-empresa" aria-hidden="true"><div class="dnb-modal dnb-modal--wide" role="dialog" aria-modal="true"><div class="dnb-modal__header"><h2>Editar empresa</h2><button type="button" data-modal-close class="dnb-icon-button">×</button></div><form method="post" class="dnb-modal__body"><?=csrfInput()?><input type="hidden" name="acao" value="update"><div class="grid md:grid-cols-2 gap-3">
<label><span>Razão social</span><input name="name" required value="<?=e($empresa['name'])?>" class="<?=INPUT_CLS?>"></label><label><span>Nome fantasia</span><input name="trade_name" value="<?=e($empresa['trade_name'])?>" class="<?=INPUT_CLS?>"></label><label><span>CNPJ</span><input name="cnpj" value="<?=e($empresa['cnpj'])?>" class="<?=INPUT_CLS?>"></label><label><span>E-mail principal</span><input type="email" name="primary_email" value="<?=e($empresa['primary_email'])?>" class="<?=INPUT_CLS?>"></label><label><span>Telefone</span><input name="phone" value="<?=e($empresa['phone'])?>" class="<?=INPUT_CLS?>"></label><label><span>Website</span><input name="website" value="<?=e($empresa['website'])?>" class="<?=INPUT_CLS?>"></label><label class="md:col-span-2"><span>Endereço</span><input name="address" value="<?=e($empresa['address'])?>" class="<?=INPUT_CLS?>"></label><label class="md:col-span-2"><span>Localidade</span><input name="locality" value="<?=e($empresa['locality'])?>" class="<?=INPUT_CLS?>"></label></div><div class="dnb-modal__footer"><button type="button" data-modal-close class="<?=BTN_S?>">Cancelar</button><button class="<?=BTN_P?>">Salvar</button></div></form></div></div>

<div class="dnb-modal-backdrop hidden" data-modal="modal-novo-contato" aria-hidden="true"><div class="dnb-modal" role="dialog" aria-modal="true"><div class="dnb-modal__header"><h2>Novo contato</h2><button type="button" data-modal-close class="dnb-icon-button">×</button></div><form method="post" class="dnb-modal__body"><?=csrfInput()?><input type="hidden" name="acao" value="add_contact"><div class="grid gap-3"><label><span>Nome *</span><input name="contact_name" required class="<?=INPUT_CLS?>"></label><label><span>E-mail</span><input type="email" name="contact_email" class="<?=INPUT_CLS?>"></label><label><span>Telefone</span><input name="contact_phone" class="<?=INPUT_CLS?>"></label><label><span>Cargo / função</span><input name="contact_role" class="<?=INPUT_CLS?>"></label></div><label class="mt-3 flex gap-2 text-sm"><input type="checkbox" name="permitir_duplicidade" value="1"><span>Se o e-mail já existir, criar e marcar para conferência.</span></label><div class="dnb-modal__footer"><button type="button" data-modal-close class="<?=BTN_S?>">Cancelar</button><button class="<?=BTN_P?>">Adicionar</button></div></form></div></div>

<?php include __DIR__.'/includes/layout_footer.php';?>
