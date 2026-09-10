<?php
require_once __DIR__.'/includes/rbac.php';
require_once __DIR__.'/includes/helpers.php';
require_once __DIR__.'/includes/lead_service.php';
require_once __DIR__.'/includes/ai_client.php';
exigirPapel(PAPEL_ADMINISTRADOR);
$pdo=db();$mes=$_GET['mes']??mesAtual();$leads=listarLeadsAcessiveis($pdo,true);$leadId=(int)($_GET['lead_id']??($leads[0]['id']??0));$lead=$leadId?buscarLeadAutorizado($pdo,$leadId):null;$historico=[];
if($lead){$s=$pdo->prepare("SELECT customer_query,response_text,status,route_name,created_at FROM ai_interactions WHERE lead_id=? ORDER BY created_at DESC LIMIT 30");$s->execute([$leadId]);$historico=array_reverse($s->fetchAll());}
$tabAtual='ia';include __DIR__.'/includes/layout_header.php';
?>
<section class="dnb-ask-ai" data-ask-ai data-endpoint="/api/v1/ai/process.php" data-csrf="<?=e(csrfToken())?>">
 <header class="dnb-ask-ai__head"><div><span class="dnb-eyebrow">Agente de IA · RAG</span><h2>Perguntar à IA</h2><p>Converse com a IA usando uma oportunidade do CRM como contexto. As respostas e análises ficam registradas no histórico da oportunidade.</p></div><?=iaConfigurada()?tag('VPS conectada','green'):tag('VPS não configurada','amber')?></header>
 <?php if(!$leads):?><div class="dnb-empty-state"><h3>Nenhuma oportunidade aberta</h3><p>Crie ou importe uma oportunidade para iniciar uma conversa contextual com a IA.</p></div><?php else:?>
 <form method="get" class="dnb-ask-ai__context"><label>Contexto da conversa<select name="lead_id" class="<?=INPUT_CLS?>" onchange="this.form.submit()"><?php foreach($leads as $l):?><option value="<?=(int)$l['id']?>" <?=$leadId===(int)$l['id']?'selected':''?>><?=e($l['cliente'])?> · <?=e($l['numero']?:'#'.$l['id'])?> · <?=e($l['etapa'])?></option><?php endforeach;?></select></label></form>
 <div class="dnb-ai-chat" data-ai-chat><?php foreach($historico as $h):?><article class="dnb-ai-chat__msg is-user"><span>Você</span><p><?=nl2br(e($h['customer_query']))?></p></article><?php if($h['response_text']):?><article class="dnb-ai-chat__msg is-ai"><span>IA · <?=e($h['route_name']?:'RAG')?></span><p><?=nl2br(e($h['response_text']))?></p></article><?php endif;?><?php endforeach;?><?php if(!$historico):?><div class="dnb-ai-chat__welcome" data-ai-welcome><h3>Com o que posso ajudar?</h3><p>Use a oportunidade selecionada como contexto para analisar, resumir, pesquisar no RAG ou definir a próxima ação comercial.</p></div><?php endif;?></div>
 <div class="dnb-ask-ai__quick"><button type="button" data-ai-prompt="Analise esta oportunidade e indique os três próximos passos mais importantes.">Analisar oportunidade</button><button type="button" data-ai-prompt="Resuma o histórico e os riscos desta negociação.">Resumir negociação</button><button type="button" data-ai-prompt="Crie uma sugestão objetiva de próxima tarefa comercial para esta oportunidade.">Criar próxima ação</button></div>
 <form class="dnb-ask-ai__composer" data-ask-ai-form><input type="hidden" name="lead_id" value="<?=$leadId?>"><textarea name="message" maxlength="<?=AI_MAX_PROMPT_LENGTH?>" required placeholder="Pergunte, pesquise ou crie qualquer coisa sobre esta oportunidade..."></textarea><div><small data-ai-chat-status></small><button type="submit" aria-label="Enviar pergunta">↑</button></div></form>
 <?php endif;?>
</section>
<?php include __DIR__.'/includes/layout_footer.php';?>
