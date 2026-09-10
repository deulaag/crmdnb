<?php
require_once __DIR__.'/includes/rbac.php';
require_once __DIR__.'/includes/helpers.php';
require_once __DIR__.'/includes/lead_service.php';
require_once __DIR__.'/includes/task_service.php';
require_once __DIR__.'/includes/crm_directory_service.php';
require_once __DIR__.'/includes/os_template_service.php';
require_once __DIR__.'/includes/profile_service.php';
exigirPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR);
$pdo=db();$mes=$_GET['mes']??mesAtual();$id=(int)($_GET['id']??$_POST['id']??0);$lead=$id?buscarLeadAutorizado($pdo,$id):null;
if(!$lead){http_response_code(404);$titulo='Oportunidade não encontrada';$mensagem='A oportunidade não existe ou está fora do seu escopo.';include __DIR__.'/includes/forbidden.php';exit;}
function opRedirect(int $id,string $msg=''):void{if($msg!=='')$_SESSION['flash']=$msg;header('Location: /oportunidade.php?id='.$id);exit;}
if($_SERVER['REQUEST_METHOD']==='POST'){
 exigirCsrf();$acao=(string)($_POST['acao']??'');
 try{
  if($acao==='add_task'){$assigned=(int)($lead['assigned_to_user_id']??usuarioLogado()['id']);criarTarefaOrcamento($pdo,$id,$assigned,(string)($_POST['title']??''),(string)($_POST['due_date']??''),(int)usuarioLogado()['id'],(string)($_POST['description']??''));registrarTimeline($pdo,$id,'task_created','Tarefa adicionada pela página da oportunidade.');opRedirect($id,'Tarefa criada.');}
  if($acao==='complete_task'){concluirTarefa($pdo,(int)($_POST['task_id']??0));registrarTimeline($pdo,$id,'task_completed','Tarefa concluída.');opRedirect($id,'Tarefa concluída.');}
  if($acao==='note'){$nota=trim((string)($_POST['note']??''));if($nota!=='')registrarTimeline($pdo,$id,'note',$nota);opRedirect($id,'Anotação registrada.');}
 }catch(Throwable $e){opRedirect($id,$e->getMessage());}
}
$lead=buscarLead($pdo,$id);$timeline=listarTimeline($pdo,$id);$tasks=listarTarefasOrcamento($pdo,$id);$company=null;$contact=null;
if(diretorioCrmDisponivel($pdo)&&!empty($lead['company_id'])){$s=$pdo->prepare('SELECT * FROM crm_companies WHERE id=?');$s->execute([(int)$lead['company_id']]);$company=$s->fetch()?:null;if(!empty($lead['contact_id'])){$s=$pdo->prepare('SELECT * FROM crm_contacts WHERE id=?');$s->execute([(int)$lead['contact_id']]);$contact=$s->fetch()?:null;}}
$template=[];if(!empty($lead['visit_template_json'])){try{$template=json_decode((string)$lead['visit_template_json'],true,512,JSON_THROW_ON_ERROR)?:[];}catch(Throwable){$template=[];}}
$pdfNome=(string)($lead['visit_pdf_original_name']??'');$pdfDisponivel=!empty($lead['visit_pdf_storage_name']);
if(!$pdfDisponivel&&!empty($lead['source_visit_id'])){try{$s=$pdo->prepare('SELECT pdf_storage_name,pdf_original_name FROM visitas WHERE id=? LIMIT 1');$s->execute([(int)$lead['source_visit_id']]);$pv=$s->fetch();if($pv){$pdfDisponivel=!empty($pv['pdf_storage_name']);$pdfNome=(string)($pv['pdf_original_name']??$pdfNome);}}catch(Throwable){}}
$pedido=null;$s=$pdo->prepare('SELECT * FROM pedidos WHERE orcamento_id=? ORDER BY id DESC LIMIT 1');$s->execute([$id]);$pedido=$s->fetch()?:null;
$funil=funis()[$lead['funil']?:funilDe((string)$lead['linha'])]??['etapas'=>[$lead['etapa']]];$etapas=$funil['etapas'];$etapaAtual=array_search($lead['etapa'],$etapas,true);if($etapaAtual===false)$etapaAtual=0;
$flash=$_SESSION['flash']??'';unset($_SESSION['flash']);$tabAtual='orc';include __DIR__.'/includes/layout_header.php';
$gruposOs=gruposTemplateOsSimplificada();$labelsOs=camposTemplateOsSimplificada();
?>
<?php if($flash):?><div class="dnb-alert dnb-alert--success mb-4"><?=e($flash)?></div><?php endif;?>
<section class="dnb-op-page" data-opportunity-page data-lead-id="<?=$id?>" data-csrf="<?=e(csrfToken())?>">
 <div class="dnb-op-pipeline" aria-label="Etapas da oportunidade"><?php foreach($etapas as $idx=>$et):$state=$idx<$etapaAtual?'is-done':($idx===$etapaAtual?'is-active':'');?><button type="button" class="dnb-op-pipeline__step <?=$state?>" data-move-opportunity-stage="<?=e($et)?>" <?=$lead['status']!=='Aberta'?'disabled':''?>><span><?=e($et)?></span></button><?php endforeach;?></div>
 <div class="dnb-op-layout">
  <aside class="dnb-op-aside">
   <details open><summary>Negociação</summary><div><b><?=e($lead['numero']?:'#'.$id)?></b><p><?=e($lead['linha'])?></p><p><?=fmtBRL($lead['valor'])?> · <?=e($lead['status'])?></p><small>Origem: <?=e($lead['origem'])?><?=$lead['source_visit_id']?' · Visita #'.(int)$lead['source_visit_id']:''?></small></div></details>
   <details open><summary>Contatos</summary><div><?php if($contact):?><b><?=e($contact['name'])?></b><p><?=e($contact['phone']?:'Sem telefone')?></p><p><?=e($contact['email']?:'Sem e-mail')?></p><small><?=e($contact['role_title']?:'Contato da empresa')?></small><?php else:?><p class="text-slate-500">Nenhum contato vinculado.</p><?php endif;?></div></details>
   <details open><summary>Empresa</summary><div><b><?=e($company['name']??$lead['cliente'])?></b><?php if($company):?><p><?=e($company['cnpj']?:'CNPJ não informado')?></p><p><?=e($company['address']?:'')?></p><small><?=e($company['locality']?:'')?></small><?php endif;?></div></details>
   <details open><summary>Responsável</summary><div><b><?=e($lead['assigned_to_name']?:'Não atribuído')?></b><p><?=e($lead['assigned_to_cargos']?:'')?></p></div></details>
  </aside>
  <main class="dnb-op-main">
   <section class="dnb-op-tasks"><div class="dnb-op-section-head"><div><span class="dnb-eyebrow">Próximas tarefas</span><h2>Tarefas desta oportunidade</h2></div><button type="button" class="<?=BTN_P?>" data-toggle-new-task>+ Criar tarefa</button></div>
    <form method="post" class="dnb-op-new-task" data-new-task-form hidden><?=csrfInput()?><input type="hidden" name="acao" value="add_task"><input type="hidden" name="id" value="<?=$id?>"><input name="title" required maxlength="180" class="<?=INPUT_CLS?>" placeholder="Tarefa"><input name="description" maxlength="500" class="<?=INPUT_CLS?>" placeholder="Descrição / objetivo"><input type="date" name="due_date" required class="<?=INPUT_CLS?>"><button class="<?=BTN_P?>">Adicionar</button></form>
    <div class="dnb-op-task-list"><?php foreach(array_slice($tasks,0,6) as $t):?><article><div><b><?=e($t['title'])?></b><p><?=e($t['description']??'')?></p><small><?=e(nomeUsuarioComHumor($pdo,(int)$t['assigned_to_user_id'],(string)$t['responsavel_nome']))?> · <?=e($t['due_date'])?></small></div><?php if($t['status']==='pending'):?><form method="post"><?=csrfInput()?><input type="hidden" name="acao" value="complete_task"><input type="hidden" name="task_id" value="<?=(int)$t['id']?>"><button class="<?=BTN_S?>">✓ Concluir</button></form><?php else:?><?=tag('Concluída','green')?><?php endif;?></article><?php endforeach;?><?php if(!$tasks):?><div class="dnb-empty-state"><p>Nenhuma tarefa cadastrada.</p></div><?php endif;?></div>
   </section>
   <nav class="dnb-op-tabs"><button type="button" class="is-active" data-op-tab="history">Histórico</button><button type="button" data-op-tab="tasks">Tarefas</button><button type="button" data-op-tab="os">OS / Visita</button><button type="button" data-op-tab="order">Pedido</button></nav>
   <section data-op-panel="history" class="dnb-op-panel is-active"><div class="dnb-op-note"><form method="post"><?=csrfInput()?><input type="hidden" name="acao" value="note"><textarea name="note" class="<?=INPUT_CLS?>" placeholder="Criar anotação"></textarea><button class="<?=BTN_P?>">+ Criar anotação</button></form></div><div class="dnb-timeline"><?php foreach($timeline as $ev):?><article><span></span><div><strong><?=e($ev['event_type'])?></strong><p><?=nl2br(e($ev['description']))?></p><small><?=e($ev['actor'])?> · <?=e($ev['created_at'])?></small></div></article><?php endforeach;?></div></section>
   <section data-op-panel="tasks" class="dnb-op-panel"><div class="dnb-op-task-list"><?php foreach($tasks as $t):?><article><div><b><?=e($t['title'])?></b><p><?=e($t['description']??'')?></p><small><?=e($t['due_date'])?> · <?=e($t['status'])?> · <?=e($t['board_stage']??'todo')?></small></div></article><?php endforeach;?></div></section>
   <section data-op-panel="os" class="dnb-op-panel">
    <?php if($pdfDisponivel):?><div class="dnb-op-attachment"><div><span class="dnb-eyebrow">Arquivo anexado</span><strong>PDF da Ordem de Serviço</strong><small><?=e($pdfNome?:'ordem-servico.pdf')?></small></div><a class="<?=BTN_P?>" href="/arquivo-oportunidade.php?id=<?=$id?>" target="_blank" rel="noopener">Abrir PDF original</a></div><?php endif;?>
    <?php if($template):?>
      <div class="dnb-os-completeness"><strong>Ordem de Serviço de Campo Simplificada completa</strong><span><?=totalCamposTemplateOs()?> campos do template permanecem representados abaixo; campos sem conteúdo aparecem como “—”.</span></div>
      <?php foreach($gruposOs as $grupo):?>
        <details class="dnb-os-group" open data-os-section="<?=e($grupo['id'])?>"><summary><?=e($grupo['titulo'])?></summary>
          <?php if(!empty($grupo['eventos'])):?>
            <div class="dnb-os-events">
              <?php $eventos=is_array($template['eventos_campo']??null)?$template['eventos_campo']:[];if(!$eventos)$eventos=[['hora'=>$template['evento_hora']??'','evento'=>$template['evento']??'','tipo'=>$template['evento_tipo']??'']];foreach($eventos as $idx=>$ev):?>
                <article><strong>Evento <?=($idx+1)?></strong><span><b>HORA:</b> <?=e(trim((string)($ev['hora']??''))?:'—')?> · <b>TIPO:</b> <?=e(trim((string)($ev['tipo']??''))?:'—')?></span><p><b>EVENTO:</b> <?=nl2br(e(trim((string)($ev['evento']??''))?:'—'))?></p></article>
              <?php endforeach;?>
            </div>
          <?php else:?>
            <dl><?php foreach($grupo['campos'] as $key):$value=trim((string)($template[$key]??''));?><div data-os-field="<?=e($key)?>"><dt><?=e($labelsOs[$key]??$key)?></dt><dd><?=$value!==''?nl2br(e($value)):'<span class="text-slate-400">—</span>'?></dd></div><?php endforeach;?></dl>
          <?php endif;?>
        </details>
      <?php endforeach;?>
      <?php if(!empty($template['_raw_text'])):?><details class="dnb-os-group"><summary>Texto integral extraído do PDF</summary><pre class="dnb-os-raw-text"><?=e((string)$template['_raw_text'])?></pre></details><?php endif;?>
      <?php if(!empty($template['_raw_json'])):?><details class="dnb-os-group"><summary>JSON original importado</summary><pre class="dnb-os-raw-text"><?=e((string)json_encode($template['_raw_json'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></pre></details><?php endif;?>
    <?php elseif(!$pdfDisponivel):?><div class="dnb-empty-state"><p>Esta oportunidade não possui uma Ordem de Serviço vinculada.</p></div><?php endif;?>
   </section>
   <section data-op-panel="order" class="dnb-op-panel"><?php if($pedido):?><div class="dnb-lead-summary"><div><span>Pedido</span><strong><?=e($pedido['numero'])?></strong></div><div><span>Data</span><strong><?=e($pedido['data'])?></strong></div><div><span>Valor</span><strong><?=fmtBRL($pedido['valor'])?></strong></div><div><span>Faturamento</span><strong><?=$pedido['faturado']?'Faturado':'Pendente'?></strong></div></div><?php else:?><div class="dnb-empty-state"><p>O pedido será criado automaticamente quando a oportunidade entrar em Fechamento.</p></div><?php endif;?></section>
  </main>
 </div>
</section>
<?php include __DIR__.'/includes/layout_footer.php';?>
