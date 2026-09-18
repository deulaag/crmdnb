<?php
declare(strict_types=1);

$root=dirname(__DIR__);
function rd8file(string $path):string{
    global $root;
    $v=file_get_contents($root.'/'.$path);
    if($v===false){fwrite(STDERR,"FALHA: {$path} ausente\n");exit(1);}
    return $v;
}
function rd8ok(bool $cond,string $msg):void{
    if(!$cond){fwrite(STDERR,"FALHA: {$msg}\n");exit(1);}
}

$migration=rd8file('sql/migrations/20260918_rd_phase8_ops_audit.sql');
$service=rd8file('includes/rd_phase8_service.php');
$config=rd8file('configuracoes.php');
$panel=rd8file('includes/rd_station_sync_panel.php');
$csv=rd8file('auditoria-rd-csv.php');
$audit=rd8file('auditoria.php');

foreach(['requeue_count','last_requeued_at','last_requeued_by_user_id'] as $column)
    rd8ok(str_contains($migration,$column),'migração deve criar '.$column);
rd8ok(str_contains($migration,'CREATE TABLE IF NOT EXISTS rd_reprocess_actions'),'migração deve criar auditoria de reprocessamentos');
foreach(['event_retry','error_retry_all','job_resume','queue_process','audit_export'] as $action)
    rd8ok(str_contains($migration,"'".$action."'"),'migração deve suportar ação '.$action);
rd8ok(str_contains($migration,'requested_by_user_id'),'ações devem registrar usuário');
rd8ok(str_contains($migration,'details_json LONGTEXT'),'ações devem preservar contexto técnico sanitizado');

rd8ok(str_contains($service,"status='error' AND attempts<3"),'saúde deve separar erros ainda retryáveis');
rd8ok(str_contains($service,"status='error' AND attempts>=3"),'saúde deve identificar erros com tentativas esgotadas');
rd8ok(str_contains($service,'jobs_stale'),'saúde deve identificar job possivelmente travado');
rd8ok(str_contains($service,'last_webhook_received'),'saúde deve mostrar último webhook');
rd8ok(str_contains($service,'last_incremental_success'),'saúde deve mostrar última incremental');
rd8ok(str_contains($service,'crm_incremental_checkpoint'),'saúde deve mostrar checkpoint');

rd8ok(str_contains($service,"SET status='pending',attempts=0"),'reprocessamento deve reabrir somente evento em erro');
rd8ok(str_contains($service,'WHERE id IN ({$marks}) AND status=\'error\''),'reprocessamento não pode recolocar sucessos na fila');
rd8ok(str_contains($service,'requeue_count=requeue_count+1'),'nova tentativa deve ser auditada no evento');
rd8ok(str_contains($service,'last_requeued_by_user_id'),'nova tentativa deve registrar ADM');
rd8ok(str_contains($service,'rdPhase8RecordAction'),'reprocessamentos devem gerar trilha própria');
rd8ok(str_contains($service,'rdPhase6ProcessWebhookQueue($pdo,20)'),'processar fila agora deve reutilizar motor idempotente');
rd8ok(str_contains($service,'rdSyncRetomar($pdo,$jobId)'),'job interrompido deve retomar checkpoint');
rd8ok(str_contains($service,"mode']==='webhook'"),'job webhook deve ser reprocessado pelo evento e não retomado como sync comum');

rd8ok(str_contains($service,'nunca inclui payload_json, tokens OAuth, secrets ou credenciais'),'CSV deve explicitar sanitização');
rd8ok(!str_contains($service,"SELECT payload_json FROM"),'CSV não pode exportar payload bruto');
rd8ok(!str_contains($service,'DNB_RD_WEBHOOK_TOKEN')&&!str_contains($service,'DNB_RD_CRON_TOKEN'),'CSV/saúde não devem manipular segredos');
rd8ok(str_contains($csv,'exigirPapel(PAPEL_ADMINISTRADOR)'),'CSV operacional deve ser exclusivo do ADM');
rd8ok(str_contains($csv,'rdPhase8AuditCsv'),'endpoint deve usar exportador sanitizado');

foreach(["\$acao === 'reprocessar_rd_errors'","\$acao === 'processar_rd_queue_now'","\$acao === 'retomar_rd_failed_job'"] as $needle)
    rd8ok(str_contains($config,$needle),'Configurações deve suportar '.$needle);
rd8ok(str_contains($config,"require_once __DIR__ . '/includes/rd_phase8_service.php'"),'Configurações deve carregar Fase 8');

foreach(['Fase 8 · operação e auditoria','Saúde, erros e reprocessamento','Reprocessar somente erros','Processar fila agora','Exportar auditoria CSV','Eventos em erro','Jobs com erro','Últimas ações de reprocessamento'] as $label)
    rd8ok(str_contains($panel,$label),'painel deve mostrar '.$label);
rd8ok(str_contains($panel,'csrfInput()'),'ações operacionais devem usar CSRF');
rd8ok(str_contains($panel,'event_id'),'deve permitir reprocessar evento individual');
rd8ok(str_contains($panel,'retomar_rd_failed_job'),'deve permitir retomar job não-webhook');

rd8ok(str_contains($audit,'exigirPapel(PAPEL_ADMINISTRADOR);'),'Log de Fluxos deve ser exclusivo do ADM também no endpoint');
rd8ok(!str_contains($audit,'exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_SUPORTE)'),'endpoint não pode manter acesso direto de Supervisor/Suporte');

echo "CRM Fase 8 operação e auditoria RD: OK\n";
