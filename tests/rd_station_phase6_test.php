<?php
declare(strict_types=1);

$root=dirname(__DIR__);
function rd6file(string $path):string{
    global $root;
    $v=file_get_contents($root.'/'.$path);
    if($v===false){fwrite(STDERR,"FALHA: {$path} ausente\n");exit(1);}
    return $v;
}
function rd6ok(bool $cond,string $msg):void{
    if(!$cond){fwrite(STDERR,"FALHA: {$msg}\n");exit(1);}
}

$migration=rd6file('sql/migrations/20260918_rd_phase6_incremental_webhooks.sql');
$sync=rd6file('includes/rd_sync_service.php');
$service=rd6file('includes/rd_phase6_service.php');
$webhook=rd6file('api/webhooks/rdstation/index.php');
$cron=rd6file('api/internal/rdstation/sync.php');
$config=rd6file('configuracoes.php');
$panel=rd6file('includes/rd_station_sync_panel.php');
$integration=rd6file('includes/integration_config.php');

foreach(['rd_sync_state','rd_webhook_events','rd_webhook_subscriptions'] as $table)
    rd6ok(str_contains($migration,'CREATE TABLE IF NOT EXISTS '.$table),'migração deve criar '.$table);
rd6ok(str_contains($migration,"'incremental','webhook'"),'jobs devem aceitar incremental e webhook');
foreach(['resolution_action','resolved_by_user_id','resolved_at'] as $column)
    rd6ok(str_contains($migration,$column),'conflitos devem auditar '.$column);
foreach(['remote_status','remote_deleted_at','last_event_at'] as $column)
    rd6ok(str_contains($migration,$column),'vínculo externo deve rastrear '.$column);
rd6ok(str_contains($migration,'UNIQUE KEY uq_rd_webhook_event_key (event_key)'),'eventos webhook devem ser idempotentes');
rd6ok(str_contains($migration,'attempts INT NOT NULL DEFAULT 0'),'fila deve controlar tentativas');

foreach(['DNB_RD_WEBHOOK_TOKEN','DNB_RD_CRON_TOKEN','DNB_RD_INCREMENTAL_ENABLED','DNB_RD_INCREMENTAL_MINUTES','DNB_RD_INCREMENTAL_OVERLAP_MINUTES'] as $key)
    rd6ok(str_contains($integration,"'".$key."'"),'configuração deve aceitar '.$key);

rd6ok(str_contains($sync,"mode='incremental'"),'deve criar jobs incrementais');
rd6ok(str_contains($sync,'crm_incremental_checkpoint'),'deve manter checkpoint incremental');
rd6ok(str_contains($sync,"updated_at:>=")&&str_contains($sync,"updated_at:<="),'incremental deve usar janela RDQL por updated_at');
rd6ok(str_contains($sync,'cutoff_at'),'janela incremental precisa de corte fixo');
rd6ok(str_contains($sync,'DNB_RD_INCREMENTAL_OVERLAP_MINUTES'),'incremental deve aplicar sobreposição de segurança');
rd6ok(str_contains($sync,"rdSyncIncrementalExtraQuery($job,'updated_at')"),'Empresa/Negociação devem ordenar por updated_at');
rd6ok(str_contains($sync,"rdSyncIncrementalExtraQuery($job,'created_at')"),'Contato deve filtrar updated_at com ordenação suportada');
rd6ok(str_contains($sync,"'order_status'=>'none'"),'sincronização não pode fabricar Pedido histórico');
rd6ok(!str_contains($service,'INSERT INTO pedidos')&&!str_contains($service,'DELETE FROM pedidos'),'Fase 6 não pode criar/apagar Pedido');

$desired=[
 'WEBHOOK.CONVERTED','WEBHOOK.MARKED_OPPORTUNITY',
 'crm_organization_created','crm_organization_updated','crm_organization_deleted',
 'crm_contact_created','crm_contact_updated','crm_contact_deleted',
 'crm_deal_created','crm_deal_updated','crm_deal_deleted'
];
foreach($desired as $event)rd6ok(str_contains($service,"'".$event."'"),'webhook deve contemplar '.$event);
rd6ok(str_contains($service,'transaction_uuid'),'CRM webhook deve deduplicar por transaction_uuid');
rd6ok(str_contains($service,"hash('sha256'"),'evento sem transaction_uuid deve ter chave determinística');
rd6ok(str_contains($service,"status='pending' OR (status='error' AND attempts<3)"),'fila deve repetir erro com limite');
rd6ok(str_contains($service,'remote_deleted_'),'exclusão remota deve virar conflito');
rd6ok(str_contains($service,"remote_status=?"),'vínculo deve registrar exclusão remota');
rd6ok(!str_contains($service,'DELETE FROM crm_companies')&&!str_contains($service,'DELETE FROM crm_contacts')&&!str_contains($service,'DELETE FROM crm_negotiations'),'webhook não pode apagar entidades comerciais');
rd6ok(str_contains($service,"status='inactive'"),'Empresa/Contato só podem ser desativados por resolução explícita');
rd6ok(str_contains($service,'Negociações excluídas no RD são preservadas como histórico'),'negociação removida no RD deve permanecer no histórico');

foreach(["'keep_dnb'","'use_rd'","'link'","'deactivate_local'","'ignore'"] as $action)
    rd6ok(str_contains($service,$action),'resolução deve suportar '.$action);
rd6ok(str_contains($service,'resolved_by_user_id'),'resolução deve auditar usuário');
rd6ok(str_contains($service,'resolved_at=NOW()'),'resolução deve auditar data');
rd6ok(str_contains($service,'rdSyncCanLinkLocal'),'vínculo manual não pode quebrar identidade externa um-para-um');

rd6ok(str_contains($service,"'auth_header'=>DNB_RD_WEBHOOK_HEADER"),'CRM webhook deve usar cabeçalho customizado');
rd6ok(str_contains($service,"rdPhase6WebhookUrl('marketing',true)"),'Marketing webhook deve carregar token no callback porque não há auth_header documentado');
rd6ok(str_contains($service,"'include_relations'=>['COMPANY','CONTACT_FUNNEL']"),'Marketing deve solicitar relações úteis');
rd6ok(str_contains($service,"'https://api.rd.services/crm/v2/webhooks"),'deve gerenciar webhooks do CRM v2');
rd6ok(str_contains($service,"'https://api.rd.services/integrations/webhooks"),'deve gerenciar webhooks do Marketing');

rd6ok(str_contains($webhook,'rdPhase6WebhookAutorizado()'),'endpoint público deve autenticar webhook');
rd6ok(str_contains($webhook,'payload_too_large'),'endpoint deve limitar payload');
rd6ok(str_contains($webhook,'rdPhase6QueueWebhook'),'receiver deve apenas enfileirar evento');
rd6ok(!str_contains($webhook,'rdPhase6ProcessWebhookQueue'),'receiver não deve processar carga pesada inline');
rd6ok(str_contains($webhook,"202"),'novo evento deve ser aceito rapidamente');
rd6ok(str_contains($cron,'rdPhase6CronAutorizado()'),'cron deve exigir token próprio');
rd6ok(str_contains($cron,'rdPhase6Tick'),'cron deve processar fila e incremental');

foreach(["\$acao === 'salvar_rd_automation'","\$acao === 'registrar_rd_webhooks'","\$acao === 'iniciar_rd_incremental'","\$acao === 'resolver_rd_conflict'"] as $needle)
    rd6ok(str_contains($config,$needle),'ADM precisa da ação '.$needle);
foreach(['Fase 6 · automação contínua','Sincronizar alterações agora','Registrar/atualizar','Comando sugerido para o Cron Job do cPanel','Desativar DNB','Usar RD','Manter DNB'] as $label)
    rd6ok(str_contains($panel,$label),'painel deve mostrar '.$label);
rd6ok(str_contains($panel,'csrfInput()'),'ações do painel devem usar CSRF');

echo "CRM Fase 6 sincronização contínua RD: OK\n";
