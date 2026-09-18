<?php
declare(strict_types=1);

require_once __DIR__ . '/rd_phase6_service.php';

function rdPhase8Disponivel(PDO $pdo): bool
{
    if(!rdPhase6Disponivel($pdo))return false;
    try{
        $tables=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rd_reprocess_actions'")->fetchColumn();
        $columns=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rd_webhook_events' AND COLUMN_NAME IN ('requeue_count','last_requeued_at','last_requeued_by_user_id')")->fetchColumn();
        return $tables===1&&$columns===3;
    }catch(Throwable){return false;}
}

function rdPhase8Scalar(PDO $pdo,string $sql,mixed $default=0): mixed
{
    try{$v=$pdo->query($sql)->fetchColumn();return $v===false?$default:$v;}catch(Throwable){return $default;}
}

/** @return array<string,mixed> */
function rdPhase8Health(PDO $pdo): array
{
    $marketing=rdEstadoProduto('marketing');
    $crm=rdEstadoProduto('crm');
    $desired=0;foreach(rdPhase6DesiredWebhooks() as $events)$desired+=count($events);

    $h=[
        'marketing_connected'=>!empty($marketing['connected']),
        'crm_connected'=>!empty($crm['connected']),
        'automation_enabled'=>integracaoConfigValor('DNB_RD_INCREMENTAL_ENABLED','0')==='1',
        'webhooks_expected'=>$desired,
        'webhooks_active'=>(int)rdPhase8Scalar($pdo,"SELECT COUNT(*) FROM rd_webhook_subscriptions WHERE status='active'"),
        'webhooks_error'=>(int)rdPhase8Scalar($pdo,"SELECT COUNT(*) FROM rd_webhook_subscriptions WHERE status='error'"),
        'events_pending'=>(int)rdPhase8Scalar($pdo,"SELECT COUNT(*) FROM rd_webhook_events WHERE status='pending'"),
        'events_processing'=>(int)rdPhase8Scalar($pdo,"SELECT COUNT(*) FROM rd_webhook_events WHERE status='processing'"),
        'events_retryable'=>(int)rdPhase8Scalar($pdo,"SELECT COUNT(*) FROM rd_webhook_events WHERE status='error' AND attempts<3"),
        'events_exhausted'=>(int)rdPhase8Scalar($pdo,"SELECT COUNT(*) FROM rd_webhook_events WHERE status='error' AND attempts>=3"),
        'jobs_failed'=>(int)rdPhase8Scalar($pdo,"SELECT COUNT(*) FROM rd_sync_jobs WHERE status='error' AND mode<>'webhook'"),
        'jobs_stale'=>(int)rdPhase8Scalar($pdo,"SELECT COUNT(*) FROM rd_sync_jobs WHERE status IN ('queued','running','paused') AND COALESCE(heartbeat_at,created_at)<DATE_SUB(NOW(),INTERVAL 15 MINUTE)"),
        'conflicts_pending'=>(int)rdPhase8Scalar($pdo,"SELECT COUNT(*) FROM rd_sync_conflicts WHERE status='pending'"),
        'last_webhook_received'=>(string)rdPhase8Scalar($pdo,"SELECT COALESCE(MAX(received_at),'') FROM rd_webhook_events",''),
        'last_webhook_processed'=>(string)rdPhase8Scalar($pdo,"SELECT COALESCE(MAX(processed_at),'') FROM rd_webhook_events WHERE status IN ('processed','ignored')",''),
        'last_incremental_success'=>(string)rdPhase8Scalar($pdo,"SELECT COALESCE(MAX(finished_at),'') FROM rd_sync_jobs WHERE mode='incremental' AND status='completed'",''),
        'last_first_sync_success'=>(string)rdPhase8Scalar($pdo,"SELECT COALESCE(MAX(finished_at),'') FROM rd_sync_jobs WHERE mode='first_sync' AND status='completed'",''),
        'checkpoint'=>rdSyncStateGet($pdo,'crm_incremental_checkpoint',''),
    ];

    if(!$h['marketing_connected']||!$h['crm_connected']||$h['webhooks_error']>0||$h['events_exhausted']>0||$h['jobs_failed']>0||$h['jobs_stale']>0){
        $h['status']='error';$h['status_label']='Atenção necessária';
    }elseif($h['events_retryable']>0||$h['conflicts_pending']>0){
        $h['status']='warning';$h['status_label']='Revisão pendente';
    }else{
        $h['status']='ok';$h['status_label']='Operacional';
    }
    return $h;
}

/** @return array<int,array<string,mixed>> */
function rdPhase8ErrorEvents(PDO $pdo,int $limit=40): array
{
    $limit=max(1,min(200,$limit));
    $sql="SELECT e.*,u.nome requeued_by_name
      FROM rd_webhook_events e
      LEFT JOIN usuarios u ON u.id=e.last_requeued_by_user_id
      WHERE e.status='error'
      ORDER BY e.attempts>=3 DESC,e.id DESC LIMIT {$limit}";
    return $pdo->query($sql)->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function rdPhase8FailedJobs(PDO $pdo,int $limit=30): array
{
    $limit=max(1,min(100,$limit));
    $sql="SELECT j.*,u.nome created_by_name,
      (SELECT COUNT(*) FROM rd_sync_conflicts c WHERE c.job_id=j.id AND c.status='pending') pending_conflicts
      FROM rd_sync_jobs j
      LEFT JOIN usuarios u ON u.id=j.created_by_user_id
      WHERE j.status='error'
      ORDER BY j.id DESC LIMIT {$limit}";
    return $pdo->query($sql)->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function rdPhase8Actions(PDO $pdo,int $limit=50): array
{
    $limit=max(1,min(200,$limit));
    $sql="SELECT a.*,u.nome requested_by_name
      FROM rd_reprocess_actions a
      LEFT JOIN usuarios u ON u.id=a.requested_by_user_id
      ORDER BY a.id DESC LIMIT {$limit}";
    return $pdo->query($sql)->fetchAll();
}

function rdPhase8RecordAction(PDO $pdo,string $actionType,string $targetType,?int $targetId,int $affected,int $userId,string $note='',array $details=[]): int
{
    if(!rdPhase8Disponivel($pdo))throw new RuntimeException('Instale a migração da Fase 8.');
    $stmt=$pdo->prepare("INSERT INTO rd_reprocess_actions (action_type,target_type,target_id,affected_count,requested_by_user_id,note,details_json)
      VALUES (?,?,?,?,NULLIF(?,0),NULLIF(?,''),?)");
    $json=$details?json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
    $stmt->execute([$actionType,$targetType,$targetId,$affected,$userId,mb_substr($note,0,500),$json]);
    return (int)$pdo->lastInsertId();
}

/**
 * Reabre somente eventos que já estão em erro.
 * O payload original é preservado; apenas o estado/tentativas são reiniciados.
 */
function rdPhase8RequeueErrors(PDO $pdo,int $userId,?int $eventId=null): int
{
    if(!rdPhase8Disponivel($pdo))throw new RuntimeException('Instale a migração da Fase 8.');
    $pdo->beginTransaction();
    try{
        $where="status='error'";$params=[];
        if($eventId!==null){$where.=' AND id=?';$params[]=$eventId;}
        $stmt=$pdo->prepare("SELECT id,product,event_name,external_entity,external_id,attempts,last_error FROM rd_webhook_events WHERE {$where} ORDER BY id FOR UPDATE");
        $stmt->execute($params);$rows=$stmt->fetchAll();
        if(!$rows){$pdo->rollBack();return 0;}

        $ids=array_map('intval',array_column($rows,'id'));
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $sql="UPDATE rd_webhook_events
          SET status='pending',attempts=0,job_id=NULL,processed_at=NULL,
              requeue_count=requeue_count+1,last_requeued_at=NOW(),last_requeued_by_user_id=NULLIF(?,0)
          WHERE id IN ({$marks}) AND status='error'";
        $up=$pdo->prepare($sql);$up->execute(array_merge([$userId],$ids));$affected=$up->rowCount();

        $detailRows=[];
        foreach(array_slice($rows,0,100) as $row)$detailRows[]=[
            'id'=>(int)$row['id'],'product'=>(string)$row['product'],'event'=>(string)$row['event_name'],
            'entity'=>(string)$row['external_entity'],'external_id'=>(string)$row['external_id'],
            'attempts'=>(int)$row['attempts'],'last_error'=>(string)$row['last_error'],
        ];
        rdPhase8RecordAction(
            $pdo,
            $eventId!==null?'event_retry':'error_retry_all',
            $eventId!==null?'webhook_event':'queue',
            $eventId,
            $affected,
            $userId,
            $eventId!==null?'Evento RD reaberto para nova tentativa.':'Somente eventos RD em erro foram reabertos para nova tentativa.',
            ['events'=>$detailRows,'truncated'=>count($rows)>100]
        );
        $pdo->commit();
        return $affected;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function rdPhase8ResumeJob(PDO $pdo,int $jobId,int $userId): void
{
    if(!rdPhase8Disponivel($pdo))throw new RuntimeException('Instale a migração da Fase 8.');
    $job=rdSyncJob($pdo,$jobId);
    if(!$job)throw new RuntimeException('Execução RD não encontrada.');
    if((string)$job['status']!=='error')throw new RuntimeException('Somente uma execução com erro pode ser retomada.');
    if((string)$job['mode']==='webhook')throw new RuntimeException('Jobs de webhook são reprocessados pelos eventos com erro, não pelo job de lote.');
    rdSyncRetomar($pdo,$jobId);
    rdPhase8RecordAction($pdo,'job_resume','sync_job',$jobId,1,$userId,'Execução RD retomada do checkpoint salvo.',[
        'mode'=>$job['mode'],'stage'=>$job['stage'],'page'=>(int)$job['page_number'],'last_error'=>$job['last_error'],
    ]);
}

function rdPhase8ProcessQueueNow(PDO $pdo,int $userId): array
{
    if(!rdPhase8Disponivel($pdo))throw new RuntimeException('Instale a migração da Fase 8.');
    $result=rdPhase6ProcessWebhookQueue($pdo,20);
    $affected=(int)$result['processed']+(int)$result['ignored']+(int)$result['error'];
    rdPhase8RecordAction($pdo,'queue_process','queue',null,$affected,$userId,'Fila de webhooks processada manualmente pelo administrador.',$result);
    return $result;
}

function rdPhase8CsvCell(mixed $value): string
{
    $s=str_replace(["\r","\n"],' ',(string)$value);
    return '"'.str_replace('"','""',$s).'"';
}

/** Exportação sanitizada: nunca inclui payload_json, tokens OAuth, secrets ou credenciais. */
function rdPhase8AuditCsv(PDO $pdo,int $userId): string
{
    if(!rdPhase8Disponivel($pdo))throw new RuntimeException('Instale a migração da Fase 8.');
    $lines=[];$append=static function(array $row)use(&$lines):void{$lines[]=implode(';',array_map('rdPhase8CsvCell',$row));};
    $health=rdPhase8Health($pdo);

    $append(['DNB CRM','Auditoria operacional RD Station',date('d/m/Y H:i:s')]);
    $append([]);
    $append(['SAÚDE DA INTEGRAÇÃO']);
    $append(['Indicador','Valor']);
    foreach([
        'Status'=>$health['status_label'],
        'RD Marketing conectado'=>$health['marketing_connected']?'Sim':'Não',
        'RD CRM conectado'=>$health['crm_connected']?'Sim':'Não',
        'Automação incremental'=>$health['automation_enabled']?'Ativa':'Inativa',
        'Webhooks ativos'=>$health['webhooks_active'].' / '.$health['webhooks_expected'],
        'Assinaturas com erro'=>$health['webhooks_error'],
        'Eventos aguardando'=>$health['events_pending'],
        'Eventos em processamento'=>$health['events_processing'],
        'Erros com nova tentativa automática'=>$health['events_retryable'],
        'Erros com tentativas esgotadas'=>$health['events_exhausted'],
        'Jobs de sync com erro'=>$health['jobs_failed'],
        'Jobs possivelmente travados'=>$health['jobs_stale'],
        'Conflitos pendentes'=>$health['conflicts_pending'],
        'Último webhook recebido'=>$health['last_webhook_received'],
        'Último webhook processado'=>$health['last_webhook_processed'],
        'Última incremental concluída'=>$health['last_incremental_success'],
        'Checkpoint CRM'=>$health['checkpoint'],
    ] as $label=>$value)$append([$label,$value]);

    $append([]);$append(['EXECUÇÕES RD']);
    $append(['ID','Modo','Status','Etapa','Página','Criado por','Criado em','Iniciado em','Heartbeat','Finalizado em','Último erro']);
    $jobs=$pdo->query("SELECT j.*,u.nome created_by_name FROM rd_sync_jobs j LEFT JOIN usuarios u ON u.id=j.created_by_user_id ORDER BY j.id DESC LIMIT 300")->fetchAll();
    foreach($jobs as $j)$append([(int)$j['id'],$j['mode'],$j['status'],$j['stage'],(int)$j['page_number'],$j['created_by_name']??'sistema',$j['created_at'],$j['started_at'],$j['heartbeat_at'],$j['finished_at'],$j['last_error']]);

    $append([]);$append(['EVENTOS WEBHOOK']);
    $append(['ID','Produto','Evento','Entidade','ID externo','Status','Tentativas','Reprocessamentos','Recebido','Processado','Último reprocessamento','Último erro']);
    $events=$pdo->query("SELECT id,product,event_name,external_entity,external_id,status,attempts,requeue_count,received_at,processed_at,last_requeued_at,last_error FROM rd_webhook_events ORDER BY id DESC LIMIT 500")->fetchAll();
    foreach($events as $e)$append([(int)$e['id'],$e['product'],$e['event_name'],$e['external_entity'],$e['external_id'],$e['status'],(int)$e['attempts'],(int)$e['requeue_count'],$e['received_at'],$e['processed_at'],$e['last_requeued_at'],$e['last_error']]);

    $append([]);$append(['CONFLITOS']);
    $append(['ID','Job','Produto','Entidade','ID externo','Tipo','Status','Ação','DNB resolvido','Criado','Resolvido','Observação']);
    $conflicts=$pdo->query("SELECT id,job_id,product,external_entity,external_id,conflict_type,status,resolution_action,resolved_dnb_id,created_at,resolved_at,resolution_note FROM rd_sync_conflicts ORDER BY id DESC LIMIT 500")->fetchAll();
    foreach($conflicts as $x)$append([(int)$x['id'],(int)$x['job_id'],$x['product'],$x['external_entity'],$x['external_id'],$x['conflict_type'],$x['status'],$x['resolution_action'],$x['resolved_dnb_id'],$x['created_at'],$x['resolved_at'],$x['resolution_note']]);

    $append([]);$append(['REPROCESSAMENTOS']);
    $append(['ID','Ação','Alvo','ID alvo','Quantidade','Solicitado por','Data','Observação']);
    $actions=$pdo->query("SELECT a.*,u.nome requested_by_name FROM rd_reprocess_actions a LEFT JOIN usuarios u ON u.id=a.requested_by_user_id ORDER BY a.id DESC LIMIT 500")->fetchAll();
    foreach($actions as $a)$append([(int)$a['id'],$a['action_type'],$a['target_type'],$a['target_id'],(int)$a['affected_count'],$a['requested_by_name']??'sistema',$a['created_at'],$a['note']]);

    rdPhase8RecordAction($pdo,'audit_export','audit',null,1,$userId,'Auditoria operacional RD exportada em CSV.');
    return "\xEF\xBB\xBF".implode("\r\n",$lines)."\r\n";
}
