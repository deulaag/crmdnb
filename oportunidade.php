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
$gruposOs=[
 'Cabeçalho da OS'=>['os','codigo_checklist','data_envio_sistema'],
 'Identificação do cliente'=>['endereco','nome_cnpj','pessoa_contato','telefone_1','email','identificacao_localidade'],
 'Detalhamento do serviço'=>['projeto_contrato','formulario_servico','profissionais','descricao_atividade','tipo_servico'],
 'Totais de campo'=>['por_profissional','total_hh','tempo_trabalho','tempo_deslocamento'],
 'Informações do cliente · checklist'=>['informacoes_cliente','responsavel_cliente','numero_contato','email_cliente','setor','local_instalacao','vendedor'],
 'Condições gerais da instalação'=>['equipamento_operando','instalacao_conforme','possui_secador','secador_funcionando','possui_reservatorio_dreno','agua_dreno','possui_filtros','filtros_dreno','fotos','foto_1','foto_2','foto_3','foto_4','relatorio_descritivo'],
 'Ordem de instalação dos equipamentos'=>['ordem_compressor','ordem_secador'],
 'Dados técnicos dos equipamentos'=>['compressores','equipamento_comentario','fabricante','modelos','numero_serie','horimetro','registro_manutencao','pecas_originais','tipo_oleo'],
 'Abordagem comercial · manutenção preventiva'=>['indicador_preventiva','plano_preventiva_apresentado','interesse_preventiva'],
 'Percepção do cliente · problemas comuns'=>['informacao_oportunidade','agua_linha','oleo_ar','paradas_frequentes','queda_pressao','consumo_energia','ruidos_vibracoes','vazamentos','qualidade_ar','perda_producao','preventiva_regular'],
 'Oportunidades identificadas'=>['oportunidade_comercial','oportunidade_comentario','relatorio_visita_tecnica','proximos_passos','assinatura_vendedor']
];
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
      <?php foreach($gruposOs as $grupo=>$keys):$temGrupo=false;foreach($keys as $key)if(trim((string)($template[$key]??''))!==''){$temGrupo=true;break;}if(!$temGrupo)continue;?><details class="dnb-os-group" open><summary><?=e($grupo)?></summary><dl><?php foreach($keys as $key):$value=trim((string)($template[$key]??''));if($value==='')continue;$label=camposTemplateOsSimplificada()[$key]??$key;?><div><dt><?=e($label)?></dt><dd><?=nl2br(e($value))?></dd></div><?php endforeach;?></dl></details><?php endforeach;?>
      <?php if(!empty($template['eventos_campo'])&&is_array($template['eventos_campo'])):?><details class="dnb-os-group" open><summary>Eventos em campo</summary><div class="dnb-os-events"><?php foreach($template['eventos_campo'] as $idx=>$ev):?><article><strong>Evento <?=($idx+1)?></strong><span><?=e((string)($ev['hora']??''))?><?=!empty($ev['tipo'])?' · '.e((string)$ev['tipo']):''?></span><p><?=nl2br(e((string)($ev['evento']??'')))?></p></article><?php endforeach;?></div></details><?php elseif(trim((string)($template['evento']??''))!==''):?><details class="dnb-os-group" open><summary>Eventos em campo</summary><dl><div><dt>HORA</dt><dd><?=e((string)($template['evento_hora']??''))?></dd></div><div><dt>EVENTO</dt><dd><?=nl2br(e((string)$template['evento']))?></dd></div><div><dt>TIPO</dt><dd><?=e((string)($template['evento_tipo']??''))?></dd></div></dl></details><?php endif;?>
      <?php if(!empty($template['_raw_text'])):?><details class="dnb-os-group"><summary>Texto integral extraído do PDF</summary><pre class="dnb-os-raw-text"><?=e((string)$template['_raw_text'])?></pre></details><?php endif;?>
    <?php elseif(!$pdfDisponivel):?><div class="dnb-empty-state"><p>Esta oportunidade não nasceu de uma OS importada.</p></div><?php endif;?>
   </section>
   <section data-op-panel="order" class="dnb-op-panel"><?php if($pedido):?><div class="dnb-lead-summary"><div><span>Pedido</span><strong><?=e($pedido['numero'])?></strong></div><div><span>Data</span><strong><?=e($pedido['data'])?></strong></div><div><span>Valor</span><strong><?=fmtBRL($pedido['valor'])?></strong></div><div><span>Faturamento</span><strong><?=$pedido['faturado']?'Faturado':'Pendente'?></strong></div></div><?php else:?><div class="dnb-empty-state"><p>O pedido será criado automaticamente quando a oportunidade entrar em Fechamento.</p></div><?php endif;?></section>
  </main>
 </div>
</section>
<?php include __DIR__.'/includes/layout_footer.php';?>
