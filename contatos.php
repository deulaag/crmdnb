<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/crm_phase2_service.php';
exigirPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR);

$pdo=db();$mes=$_GET['mes']??mesAtual();$erro='';$disponivel=crmPhase2Disponivel($pdo);
$empresas=$disponivel?empresasSelecionaveisCrmUi($pdo):[];
if($_SERVER['REQUEST_METHOD']==='POST'){
  exigirCsrf();
  try{
    if(!$disponivel)throw new RuntimeException('Instale a migração da Fase 2.');
    if(($_POST['acao']??'')==='add'){
      $contato=criarContatoCrmUi($pdo,[
        'company_id'=>(int)($_POST['company_id']??0),'name'=>$_POST['name']??'','email'=>$_POST['email']??'',
        'phone'=>$_POST['phone']??'','role_title'=>$_POST['role_title']??'','source'=>'manual'
      ],!empty($_POST['permitir_duplicidade']));
      header('Location: /contato.php?id='.(int)$contato['id'].'&criado=1');exit;
    }
  }catch(Throwable $e){$erro=$e->getMessage();}
}
$filtros=['q'=>trim((string)($_GET['q']??'')),'sem_empresa'=>(string)($_GET['sem_empresa']??''),'review'=>(string)($_GET['review']??'')];
$contatos=$disponivel?listarContatosCrmUi($pdo,$filtros):[];
$tabAtual='contatos';include __DIR__.'/includes/layout_header.php';
?>
<?php if(!$disponivel):?><div class="dnb-alert dnb-alert--warning">Instale <code>sql/migrations/20260918_crm_phase2_ui_ownership.sql</code> para ativar Contatos.</div>
<?php else:?>
<?php if($erro):?><div class="dnb-alert dnb-alert--error"><?=e($erro)?></div><?php endif;?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="px-4 pt-4 flex items-start justify-between gap-3 flex-wrap"><div><span class="dnb-eyebrow">Diretório comercial</span><h2 class="dnb-section-title">Contatos</h2><p class="text-sm text-slate-500 mt-1">Cada contato pertence a no máximo uma empresa. Contatos ainda não associados ficam disponíveis para triagem.</p></div><button type="button" class="<?=BTN_P?>" data-modal-open="modal-novo-contato">Novo contato</button></div>
  <form method="get" class="p-4 grid md:grid-cols-[1fr_180px_180px_auto] gap-2"><input type="hidden" name="mes" value="<?=e($mes)?>"><input type="search" name="q" value="<?=e($filtros['q'])?>" placeholder="Nome, e-mail, telefone, empresa ou DNB ID" class="<?=INPUT_CLS?>"><select name="sem_empresa" class="<?=INPUT_CLS?>"><option value="">Todos</option><option value="1" <?=$filtros['sem_empresa']==='1'?'selected':''?>>Sem empresa</option></select><select name="review" class="<?=INPUT_CLS?>"><option value="">Todas as revisões</option><option value="needs_review" <?=$filtros['review']==='needs_review'?'selected':''?>>Precisa conferir</option></select><button class="<?=BTN_S?>">Filtrar</button></form>
</section>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm">
<div class="px-4 pt-4 flex justify-between"><h2 class="dnb-section-title">Pessoas conhecidas</h2><span class="text-xs text-slate-500"><?=count($contatos)?> contato(s)</span></div>
<div class="p-4 overflow-x-auto"><table class="w-full text-sm min-w-[900px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Contato</th><th>Empresa</th><th>E-mail</th><th>Telefone</th><th>Negociações</th><th>Revisão</th><th></th></tr></thead><tbody>
<?php foreach($contatos as $ct):?><tr class="border-b border-slate-100"><td class="py-3"><a href="/contato.php?id=<?=(int)$ct['id']?>" class="font-bold" style="color:<?=NAVY?>;"><?=e($ct['name'])?></a><small class="block font-mono text-slate-400"><?=e($ct['dnb_id'])?><?=!empty($ct['role_title'])?' · '.e($ct['role_title']):''?></small></td><td><?php if($ct['company_id']):?><a href="/empresa.php?id=<?=(int)$ct['company_id']?>" class="font-semibold"><?=e($ct['company_name'])?></a><?php else:?><?=tag('Sem empresa','amber')?><?php endif;?></td><td><?=e($ct['email']?:'—')?></td><td><?=e($ct['phone']?:'—')?></td><td><?=(int)$ct['negotiations_count']?></td><td><?=($ct['review_status']??'ok')==='needs_review'?tag('Conferir','amber'):tag('OK','green')?></td><td class="text-right"><a class="<?=BTN_S?>" href="/contato.php?id=<?=(int)$ct['id']?>">Abrir</a></td></tr><?php endforeach;?>
<?php if(!$contatos):?><tr><td colspan="7" class="py-8 text-center text-slate-400">Nenhum contato encontrado no seu escopo.</td></tr><?php endif;?>
</tbody></table></div>
</section>

<div class="dnb-modal-backdrop hidden" data-modal="modal-novo-contato" aria-hidden="true"><div class="dnb-modal" role="dialog" aria-modal="true"><div class="dnb-modal__header"><div><span class="dnb-eyebrow">Novo cadastro</span><h2>Contato</h2></div><button type="button" data-modal-close class="dnb-icon-button">×</button></div><form method="post" class="dnb-modal__body"><?=csrfInput()?><input type="hidden" name="acao" value="add"><div class="grid gap-3"><label><span>Nome *</span><input name="name" required class="<?=INPUT_CLS?>"></label><label><span>Empresa</span><select name="company_id" class="<?=INPUT_CLS?>"><option value="0">Sem empresa</option><?php foreach($empresas as $c):?><option value="<?=(int)$c['id']?>"><?=e($c['name'])?> · <?=e($c['dnb_id'])?></option><?php endforeach;?></select></label><label><span>E-mail</span><input type="email" name="email" class="<?=INPUT_CLS?>"></label><label><span>Telefone</span><input name="phone" class="<?=INPUT_CLS?>"></label><label><span>Cargo / função</span><input name="role_title" class="<?=INPUT_CLS?>"></label></div><label class="mt-3 flex gap-2 text-sm"><input type="checkbox" name="permitir_duplicidade" value="1"><span>Se o e-mail já existir, criar e marcar para conferência.</span></label><div class="dnb-modal__footer"><button type="button" data-modal-close class="<?=BTN_S?>">Cancelar</button><button class="<?=BTN_P?>">Criar contato</button></div></form></div></div>
<?php endif;?>
<?php include __DIR__.'/includes/layout_footer.php';?>
