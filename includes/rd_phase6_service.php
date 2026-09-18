<?php
declare(strict_types=1);

require_once __DIR__ . '/rd_sync_service.php';
require_once __DIR__ . '/integration_config.php';

const DNB_RD_WEBHOOK_HEADER = 'X-DNB-RD-Webhook';
const DNB_RD_CRON_HEADER = 'X-DNB-RD-Cron';

function rdPhase6Disponivel(PDO $pdo): bool
{
    if(!rdSyncPhase6Disponivel($pdo))return false;
    try{
        $stmt=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='crm_external_links' AND COLUMN_NAME='remote_status') OR (TABLE_NAME='rd_sync_conflicts' AND COLUMN_NAME='resolved_at'))");
        return (int)$stmt->fetchColumn()===2;
    }catch(Throwable){return false;}
}

/** @return array{enabled:bool,interval:int,overlap:int,webhook_token:bool,cron_token:bool,webhook_url:string,cron_url:string} */
function rdPhase6Settings(PDO $pdo): array
{
    $enabled=integracaoConfigValor('DNB_RD_INCREMENTAL_ENABLED','0')==='1';
    $interval=max(5,min(1440,(int)integracaoConfigValor('DNB_RD_INCREMENTAL_MINUTES','15')));
    $overlap=max(1,min(120,(int)integracaoConfigValor('DNB_RD_INCREMENTAL_OVERLAP_MINUTES','10')));
    $base=rdPublicBaseUrl(false);
    return [
        'enabled'=>$enabled,
        'interval'=>$interval,
        'overlap'=>$overlap,
        'webhook_token'=>integracaoConfigValor('DNB_RD_WEBHOOK_TOKEN')!=='',
        'cron_token'=>integracaoConfigValor('DNB_RD_CRON_TOKEN')!=='',
        'webhook_url'=>$base!==''?$base.'/api/webhooks/rdstation/index.php':'',
        'cron_url'=>$base!==''?$base.'/api/internal/rdstation/sync.php':'',
    ];
}

/** @return array{webhook:string,cron:string} */
function rdPhase6EnsureSecrets(): array
{
    $webhook=integracaoConfigValor('DNB_RD_WEBHOOK_TOKEN');
    $cron=integracaoConfigValor('DNB_RD_CRON_TOKEN');
    $save=[];
    if($webhook===''){$webhook=bin2hex(random_bytes(32));$save['DNB_RD_WEBHOOK_TOKEN']=$webhook;}
    if($cron===''){$cron=bin2hex(random_bytes(32));$save['DNB_RD_CRON_TOKEN']=$cron;}
    if($save)salvarIntegracaoConfig($save);
    return ['webhook'=>$webhook,'cron'=>$cron];
}

function rdPhase6SalvarAutomacao(bool $enabled,int $interval,int $overlap): void
{
    rdPhase6EnsureSecrets();
    salvarIntegracaoConfig([
        'DNB_RD_INCREMENTAL_ENABLED'=>$enabled?'1':'0',
        'DNB_RD_INCREMENTAL_MINUTES'=>(string)max(5,min(1440,$interval)),
        'DNB_RD_INCREMENTAL_OVERLAP_MINUTES'=>(string)max(1,min(120,$overlap)),
    ]);
}

function rdPhase6TokenValido(string $expected,string $received): bool
{
    return $expected!==''&&$received!==''&&hash_equals($expected,$received);
}

function rdPhase6WebhookTokenRecebido(): string
{
    return trim((string)($_SERVER['HTTP_X_DNB_RD_WEBHOOK']??$_GET['token']??''));
}

function rdPhase6CronTokenRecebido(): string
{
    return trim((string)($_SERVER['HTTP_X_DNB_RD_CRON']??$_GET['token']??''));
}

function rdPhase6WebhookAutorizado(): bool
{
    return rdPhase6TokenValido(integracaoConfigValor('DNB_RD_WEBHOOK_TOKEN'),rdPhase6WebhookTokenRecebido());
}

function rdPhase6CronAutorizado(): bool
{
    return rdPhase6TokenValido(integracaoConfigValor('DNB_RD_CRON_TOKEN'),rdPhase6CronTokenRecebido());
}

function rdPhase6WebhookUrl(string $product='crm',bool $includeSecret=false): string
{
    $base=rdPublicBaseUrl(false);
    if($base==='')throw new RuntimeException('Configure a URL pública do CRM.');
    $url=$base.'/api/webhooks/rdstation/index.php?product='.rawurlencode($product);
    if($includeSecret){
        $token=rdPhase6EnsureSecrets()['webhook'];
        $url.='&token='.rawurlencode($token);
    }
    return $url;
}

function rdPhase6CronCommand(): string
{
    $base=rdPublicBaseUrl(false);
    if($base==='')return '';
    $token=rdPhase6EnsureSecrets()['cron'];
    return "curl -fsS --max-time 50 -H 'X-DNB-RD-Cron: ".$token."' '".$base."/api/internal/rdstation/sync.php'";
}

/** @return array<int,array<string,mixed>> */
function rdPhase6WebhookItems(?array $json): array
{
    if(!$json)return [];
    if(array_is_list($json))return array_values(array_filter($json,'is_array'));
    return rdSyncList($json,['data','items','webhooks','subscriptions']);
}

function rdPhase6WebhookRemoteId(array $item): string
{
    $a=rdDiscoveryAttrs($item);
    foreach(['id','uuid'] as $k){if(isset($a[$k])&&is_scalar($a[$k])&&trim((string)$a[$k])!=='')return trim((string)$a[$k]);}
    return '';
}

function rdPhase6WebhookRemoteEvent(array $item): string
{
    $a=rdDiscoveryAttrs($item);
    foreach(['event_type','event_name'] as $k){if(isset($a[$k])&&is_scalar($a[$k]))return trim((string)$a[$k]);}
    return '';
}

/** @return array<string,array<int,string>> */
function rdPhase6DesiredWebhooks(): array
{
    return [
        'marketing'=>['WEBHOOK.CONVERTED','WEBHOOK.MARKED_OPPORTUNITY'],
        'crm'=>[
            'crm_organization_created','crm_organization_updated','crm_organization_deleted',
            'crm_contact_created','crm_contact_updated','crm_contact_deleted',
            'crm_deal_created','crm_deal_updated','crm_deal_deleted',
        ],
    ];
}

/** @return array<string,array<string,mixed>> */
function rdPhase6ListRemoteWebhooks(string $product): array
{
    $url=$product==='marketing'
        ?'https://api.rd.services/integrations/webhooks'
        :'https://api.rd.services/crm/v2/webhooks?page[size]=100';
    $res=rdApiRequest($product,'GET',$url);
    if((int)$res['status']<200||(int)$res['status']>=300||!is_array($res['json'])){
        throw new RuntimeException(rdErroResposta($res,'Não foi possível listar os webhooks do RD'));
    }
    $out=[];
    foreach(rdPhase6WebhookItems($res['json']) as $item){
        $event=rdPhase6WebhookRemoteEvent($item);
        if($event!=='')$out[$event]=$item;
    }
    return $out;
}

/** @return array{status:int,json:?array,raw:string} */
function rdPhase6SendWebhookConfig(string $product,string $eventType,string $remoteId=''): array
{
    $secrets=rdPhase6EnsureSecrets();
    if($product==='marketing'){
        $data=[
            'event_type'=>$eventType,
            'entity_type'=>'CONTACT',
            'url'=>rdPhase6WebhookUrl('marketing',true),
            'http_method'=>'POST',
            'include_relations'=>['COMPANY','CONTACT_FUNNEL'],
        ];
        $url='https://api.rd.services/integrations/webhooks'.($remoteId!==''?'/'.rawurlencode($remoteId):'');
        return rdApiRequest('marketing',$remoteId!==''?'PUT':'POST',$url,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    $attrs=[
        'event_type'=>$eventType,
        'url'=>rdPhase6WebhookUrl('crm',false),
        'http_method'=>'POST',
        'auth_header'=>DNB_RD_WEBHOOK_HEADER,
        'auth_key'=>$secrets['webhook'],
    ];
    $url='https://api.rd.services/crm/v2/webhooks'.($remoteId!==''?'/'.rawurlencode($remoteId):'');
    $method=$remoteId!==''?'PUT':'POST';

    $body=json_encode(['data'=>$attrs],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $res=rdApiRequest('crm',$method,$url,$body);
    if(in_array((int)$res['status'],[400,422],true)){
        $body=json_encode(['data'=>['type'=>'webhook','attributes'=>$attrs]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $res=rdApiRequest('crm',$method,$url,$body);
    }
    return $res;
}

function rdPhase6SaveSubscription(PDO $pdo,string $product,string $eventType,string $callback,string $status,int $httpStatus=0,string $remoteId='',string $error=''): void
{
    $stmt=$pdo->prepare("INSERT INTO rd_webhook_subscriptions (product,event_type,remote_id,callback_url,status,last_http_status,last_error,registered_at,checked_at)
      VALUES (?,?,?,?,?,?,NULLIF(?,''),IF(?='active',NOW(),NULL),NOW())
      ON DUPLICATE KEY UPDATE remote_id=VALUES(remote_id),callback_url=VALUES(callback_url),status=VALUES(status),last_http_status=VALUES(last_http_status),last_error=VALUES(last_error),registered_at=IF(VALUES(status)='active',NOW(),registered_at),checked_at=NOW()");
    $stmt->execute([$product,$eventType,$remoteId!==''?$remoteId:null,$callback,$status,$httpStatus,mb_substr($error,0,500),$status]);
}

/** @return array{ok:int,error:int,messages:array<int,string>} */
function rdPhase6RegisterWebhooks(PDO $pdo): array
{
    if(!rdPhase6Disponivel($pdo))throw new RuntimeException('Instale a migração da Fase 6.');
    rdPhase6EnsureSecrets();
    $result=['ok'=>0,'error'=>0,'messages'=>[]];
    foreach(rdPhase6DesiredWebhooks() as $product=>$events){
        if(!rdEstadoProduto($product)['connected']){
            $result['error']+=count($events);$result['messages'][]=$product.': não conectado.';continue;
        }
        try{$remote=rdPhase6ListRemoteWebhooks($product);}catch(Throwable $e){$remote=[];$result['messages'][]=$product.': '.$e->getMessage();}
        foreach($events as $event){
            $remoteId=isset($remote[$event])?rdPhase6WebhookRemoteId($remote[$event]):'';
            $callback=$product==='marketing'?rdPhase6WebhookUrl('marketing',true):rdPhase6WebhookUrl('crm',false);
            try{
                $res=rdPhase6SendWebhookConfig($product,$event,$remoteId);
                $status=(int)$res['status'];
                if($status>=200&&$status<300){
                    $rid=$remoteId!==''?$remoteId:rdPhase6WebhookRemoteId(is_array($res['json'])?$res['json']:[]);
                    rdPhase6SaveSubscription($pdo,$product,$event,$callback,'active',$status,$rid);
                    $result['ok']++;$result['messages'][]=$product.' '.$event.': ativo.';
                }else{
                    $msg=rdErroResposta($res,'RD recusou o webhook');
                    rdPhase6SaveSubscription($pdo,$product,$event,$callback,'error',$status,$remoteId,$msg);
                    $result['error']++;$result['messages'][]=$product.' '.$event.': '.$msg;
                }
            }catch(Throwable $e){
                rdPhase6SaveSubscription($pdo,$product,$event,$callback,'error',0,$remoteId,$e->getMessage());
                $result['error']++;$result['messages'][]=$product.' '.$event.': '.$e->getMessage();
            }
        }
    }
    return $result;
}

/** @return array{product:string,event_name:string,external_entity:string,external_id:string,event_key:string,transaction_uuid:string} */
function rdPhase6NormalizeWebhookMeta(string $product,array $payload,string $raw): array
{
    $eventName=trim((string)($payload['event_name']??$payload['event_type']??''));
    $transaction=trim((string)($payload['transaction_uuid']??''));
    $entity='';$externalId='';
    if($product==='crm'){
        if(preg_match('/^crm_(organization|contact|deal)_/',$eventName,$m))$entity=$m[1];
        $doc=is_array($payload['document']??null)?$payload['document']:[];
        $externalId=rdSyncExternalId($doc);
    }else{
        $entity='contact';
        $contact=is_array($payload['contact']??null)?$payload['contact']:[];
        $externalId=rdSyncExternalId($contact);
        if($externalId===''){
            $email=crmCoreEmail(rdSyncScalar($contact['email']??''));
            if($email!=='')$externalId='email:'.$email;
        }
    }
    $seed=$transaction!==''?($product.':'.$transaction):($product.':'.$raw);
    return [
        'product'=>$product,'event_name'=>$eventName,'external_entity'=>$entity,'external_id'=>$externalId,
        'event_key'=>hash('sha256',$seed),'transaction_uuid'=>$transaction,
    ];
}

/** @return array{id:int,duplicate:bool,status:string} */
function rdPhase6QueueWebhook(PDO $pdo,string $product,array $payload,string $raw): array
{
    if(!rdPhase6Disponivel($pdo))throw new RuntimeException('Fase 6 não instalada.');
    if(!in_array($product,['marketing','crm'],true))throw new InvalidArgumentException('Produto RD inválido.');
    $meta=rdPhase6NormalizeWebhookMeta($product,$payload,$raw);
    if($meta['event_name']==='')throw new InvalidArgumentException('Evento RD sem event_name/event_type.');
    $allowed=rdPhase6DesiredWebhooks()[$product]??[];
    if(!in_array($meta['event_name'],$allowed,true))throw new InvalidArgumentException('Evento RD não suportado: '.$meta['event_name']);

    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($json===false)throw new RuntimeException('Payload RD inválido.');
    $stmt=$pdo->prepare("INSERT IGNORE INTO rd_webhook_events (product,event_key,transaction_uuid,event_name,external_entity,external_id,payload_json,status) VALUES (?,?,?,?,?,?,?,'pending')");
    $stmt->execute([$product,$meta['event_key'],$meta['transaction_uuid']!==''?$meta['transaction_uuid']:null,$meta['event_name'],$meta['external_entity'],$meta['external_id'],$json]);
    $duplicate=$stmt->rowCount()===0;
    $sel=$pdo->prepare('SELECT id,status FROM rd_webhook_events WHERE event_key=? LIMIT 1');$sel->execute([$meta['event_key']]);$row=$sel->fetch();
    return ['id'=>(int)($row['id']??0),'duplicate'=>$duplicate,'status'=>(string)($row['status']??'pending')];
}

/** @return array<string,mixed>|null */
function rdPhase6ExternalLink(PDO $pdo,string $provider,string $entity,string $externalId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM crm_external_links WHERE provider=? AND external_entity=? AND external_id=? LIMIT 1');
    $stmt->execute([$provider,$entity,$externalId]);$row=$stmt->fetch();return $row?:null;
}

function rdPhase6MarkLinkEvent(PDO $pdo,string $provider,string $entity,string $externalId,bool $deleted=false): void
{
    $stmt=$pdo->prepare("UPDATE crm_external_links SET remote_status=?,remote_deleted_at=?,last_event_at=NOW(),last_sync_at=IF(?='active',NOW(),last_sync_at) WHERE provider=? AND external_entity=? AND external_id=?");
    $status=$deleted?'deleted':'active';
    $stmt->execute([$status,$deleted?gmdate('Y-m-d H:i:s'):null,$status,$provider,$entity,$externalId]);
}

function rdPhase6MarkRemoteDeleted(PDO $pdo,int $jobId,string $entity,string $externalId,array $payload,array &$counters): void
{
    $link=rdPhase6ExternalLink($pdo,'rd_crm',$entity,$externalId);
    if(!$link){
        rdSyncLog($pdo,$jobId,'warning','webhook','Exclusão remota recebida sem vínculo local.',['entity'=>$entity,'external_id'=>$externalId]);
        return;
    }
    rdPhase6MarkLinkEvent($pdo,'rd_crm',$entity,$externalId,true);
    rdSyncConflict($pdo,$jobId,'crm',$entity,$externalId,'remote_deleted_'.$link['local_entity'],[(string)$link['dnb_id']],$payload,$counters);
}

/** @param array<string,int> $counters */
function rdPhase6ProcessWebhookEvent(PDO $pdo,int $jobId,array $event,array &$counters): string
{
    $payload=rdSyncJsonObject((string)$event['payload_json']);
    $product=(string)$event['product'];$eventName=(string)$event['event_name'];
    $externalId=(string)$event['external_id'];

    if($product==='marketing'){
        $contact=is_array($payload['contact']??null)?$payload['contact']:[];
        if($externalId==='')$externalId=rdSyncExternalId($contact);
        if($externalId===''){
            $email=crmCoreEmail(rdSyncScalar($contact['email']??''));
            if($email!=='')$externalId='email:'.$email;
        }
        if($externalId==='')throw new RuntimeException('Webhook Marketing sem UUID/e-mail do contato.');
        rdSyncUpsertContact($pdo,$jobId,'rd_marketing',$externalId,$contact,$contact,null,$counters);
        rdPhase6MarkLinkEvent($pdo,'rd_marketing','contact',$externalId,false);
        return 'processed';
    }

    if(!preg_match('/^crm_(organization|contact|deal)_(created|updated|deleted)$/',$eventName,$m))return 'ignored';
    $entity=$m[1];$action=$m[2];
    $doc=is_array($payload['document']??null)?$payload['document']:[];
    if($externalId==='')$externalId=rdSyncExternalId($doc);
    if($externalId==='')throw new RuntimeException('Webhook CRM sem ID externo.');

    if($action==='deleted'){
        rdPhase6MarkRemoteDeleted($pdo,$jobId,$entity,$externalId,$payload,$counters);
        return 'processed';
    }

    if($entity==='organization'){
        rdSyncUpsertCompany($pdo,$jobId,'rd_crm',$externalId,rdDiscoveryAttrs($doc),$doc,$counters);
    }elseif($entity==='contact'){
        $attrs=rdDiscoveryAttrs($doc);
        $orgId=rdSyncRelationshipIds($doc,'organization')[0]??rdSyncScalar($attrs['organization_id']??'');
        $company=$orgId!==''?rdSyncLocalByExternal($pdo,'rd_crm','organization',$orgId):null;
        rdSyncUpsertContact($pdo,$jobId,'rd_crm',$externalId,$attrs,$doc,(int)($company['id']??0),$counters);
    }elseif($entity==='deal'){
        rdSyncUpsertDeal($pdo,$jobId,$externalId,$doc,$counters);
    }
    rdPhase6MarkLinkEvent($pdo,'rd_crm',$entity,$externalId,false);
    return 'processed';
}

/** @return array{processed:int,error:int,ignored:int,job_id:int} */
function rdPhase6ProcessWebhookQueue(PDO $pdo,int $limit=20): array
{
    if(!rdPhase6Disponivel($pdo))throw new RuntimeException('Fase 6 não instalada.');
    $limit=max(1,min(100,$limit));
    $rows=$pdo->query("SELECT * FROM rd_webhook_events WHERE (status='pending' OR (status='error' AND attempts<3)) ORDER BY id ASC LIMIT {$limit}")->fetchAll();
    if(!$rows)return ['processed'=>0,'error'=>0,'ignored'=>0,'job_id'=>0];

    $counters=rdSyncCounterDefault();
    $state=['source'=>'webhook','batch_size'=>count($rows)];
    $stmt=$pdo->prepare("INSERT INTO rd_sync_jobs (mode,status,stage,since_at,page_number,page_size,state_json,counters_json,started_at,heartbeat_at) VALUES ('webhook','running','webhook_events',NOW(),1,?, ?, ?,NOW(),NOW())");
    $stmt->execute([$limit,json_encode($state),json_encode($counters)]);
    $jobId=(int)$pdo->lastInsertId();
    rdSyncLog($pdo,$jobId,'info','webhook','Processando '.count($rows).' evento(s) da fila RD.');

    $summary=['processed'=>0,'error'=>0,'ignored'=>0,'job_id'=>$jobId];
    foreach($rows as $event){
        $eventId=(int)$event['id'];
        $pdo->prepare("UPDATE rd_webhook_events SET status='processing',attempts=attempts+1,job_id=?,last_error=NULL WHERE id=?")->execute([$jobId,$eventId]);
        try{
            $status=rdPhase6ProcessWebhookEvent($pdo,$jobId,$event,$counters);
            $pdo->prepare("UPDATE rd_webhook_events SET status=?,processed_at=NOW(),last_error=NULL WHERE id=?")->execute([$status,$eventId]);
            $summary[$status==='ignored'?'ignored':'processed']++;
        }catch(Throwable $e){
            $pdo->prepare("UPDATE rd_webhook_events SET status='error',last_error=?,processed_at=NULL WHERE id=?")->execute([mb_substr($e->getMessage(),0,500),$eventId]);
            rdSyncLog($pdo,$jobId,'error','webhook_event','#'.$eventId.' '.$e->getMessage());
            $summary['error']++;
        }
    }
    $jobStatus=$summary['error']>0&&$summary['processed']===0&&$summary['ignored']===0?'error':'completed';
    $pdo->prepare("UPDATE rd_sync_jobs SET status=?,stage='done',counters_json=?,heartbeat_at=NOW(),finished_at=NOW(),last_error=? WHERE id=?")
        ->execute([$jobStatus,json_encode($counters,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$summary['error']>0?'Há eventos pendentes de nova tentativa.':null,$jobId]);
    rdSyncLog($pdo,$jobId,$summary['error']>0?'warning':'success','webhook','Lote concluído: '.$summary['processed'].' processado(s), '.$summary['ignored'].' ignorado(s), '.$summary['error'].' erro(s).');
    return $summary;
}

function rdPhase6LocalEntityForExternal(string $externalEntity): string
{
    return match($externalEntity){'organization'=>'company','contact'=>'contact','deal'=>'negotiation',default=>''};
}

/** @return array<int,string> */
function rdPhase6ConflictCandidateIds(array $conflict): array
{
    $items=rdSyncJsonObject((string)$conflict['candidates_json']);
    $ids=[];
    foreach($items as $item){
        if(is_string($item))$ids[]=strtoupper(trim($item));
        elseif(is_array($item)&&isset($item['dnb_id']))$ids[]=strtoupper(trim((string)$item['dnb_id']));
    }
    return array_values(array_unique(array_filter($ids)));
}

function rdPhase6ConflictRow(PDO $pdo,int $id): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM rd_sync_conflicts WHERE id=? LIMIT 1');$stmt->execute([$id]);$row=$stmt->fetch();return $row?:null;
}

function rdPhase6ResolveMark(PDO $pdo,int $id,string $status,string $action,int $userId,string $dnbId='',string $note=''): void
{
    $stmt=$pdo->prepare("UPDATE rd_sync_conflicts SET status=?,resolved_dnb_id=NULLIF(?,''),resolution_note=NULLIF(?,''),resolution_action=?,resolved_by_user_id=NULLIF(?,0),resolved_at=NOW(),updated_at=NOW() WHERE id=?");
    $stmt->execute([$status,$dnbId,mb_substr($note,0,255),$action,$userId,$id]);
}

function rdPhase6ReplayLinkedConflict(PDO $pdo,array $conflict,string $dnbId): void
{
    $payload=rdSyncJsonObject((string)$conflict['payload_json']);
    $product=(string)$conflict['product'];$externalEntity=(string)$conflict['external_entity'];$externalId=(string)$conflict['external_id'];
    $provider=$product==='marketing'?'rd_marketing':'rd_crm';
    $localEntity=rdPhase6LocalEntityForExternal($externalEntity);
    if($localEntity==='')throw new RuntimeException('Entidade externa incompatível com vínculo.');
    registrarVinculoExternoCrm($pdo,$provider,$externalEntity,$externalId,$localEntity,$dnbId);
    if(rdPhase6Disponivel($pdo))rdPhase6MarkLinkEvent($pdo,$provider,$externalEntity,$externalId,false);

    $counters=rdSyncCounterDefault();$jobId=(int)$conflict['job_id'];
    if($externalEntity==='organization')rdSyncUpsertCompany($pdo,$jobId,$provider,$externalId,rdDiscoveryAttrs($payload),$payload,$counters);
    elseif($externalEntity==='contact')rdSyncUpsertContact($pdo,$jobId,$provider,$externalId,rdDiscoveryAttrs($payload),$payload,null,$counters);
    elseif($externalEntity==='deal')rdSyncUpsertDeal($pdo,$jobId,$externalId,$payload,$counters);
}

function rdPhase6UseRdConflictValue(PDO $pdo,array $conflict): string
{
    if(!preg_match('/^field_conflict_(company|contact|negotiation)_(.+)$/',(string)$conflict['conflict_type'],$m)){
        throw new RuntimeException('Este conflito não é de campo.');
    }
    $entity=$m[1];$field=$m[2];
    if(!isset(rdSyncTableAllowed($entity)[$field]))throw new RuntimeException('Campo não permitido para resolução automática.');
    $items=rdSyncJsonObject((string)$conflict['candidates_json']);
    $candidate=is_array($items[0]??null)?$items[0]:[];
    $dnbId=strtoupper(trim((string)($candidate['dnb_id']??'')));
    if($dnbId==='')throw new RuntimeException('Conflito sem entidade DNB.');
    $local=buscarEntidadePorDnbId($pdo,$dnbId);
    if(!$local)throw new RuntimeException('Registro DNB do conflito não foi encontrado.');
    $value=$candidate['rd_value']??null;
    $counters=rdSyncCounterDefault();
    rdSyncUpdateLocal($pdo,(int)$conflict['job_id'],(string)$conflict['product'],(string)$conflict['external_entity'],(string)$conflict['external_id'],$entity,(int)$local['id'],[
        $field=>['source_key'=>'conflict_resolution','value'=>$value,'truth'=>'rd']
    ],rdSyncJsonObject((string)$conflict['payload_json']),$counters);
    return $dnbId;
}

function rdPhase6ResolverConflito(PDO $pdo,int $conflictId,string $action,string $selectedDnbId,int $userId,string $note=''): void
{
    if(!rdPhase6Disponivel($pdo))throw new RuntimeException('Instale a migração da Fase 6.');
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT * FROM rd_sync_conflicts WHERE id=? FOR UPDATE");$stmt->execute([$conflictId]);$conflict=$stmt->fetch();
        if(!$conflict)throw new RuntimeException('Conflito não encontrado.');
        if((string)$conflict['status']!=='pending')throw new RuntimeException('Este conflito já foi tratado.');

        if($action==='ignore'){
            rdPhase6ResolveMark($pdo,$conflictId,'ignored','ignore',$userId,'',$note?:'Ignorado pelo administrador.');
        }elseif($action==='keep_dnb'){
            $ids=rdPhase6ConflictCandidateIds($conflict);$dnbId=$selectedDnbId!==''?strtoupper($selectedDnbId):($ids[0]??'');
            rdPhase6ResolveMark($pdo,$conflictId,'resolved','keep_dnb',$userId,$dnbId,$note?:'Valor/registro DNB preservado.');
        }elseif($action==='use_rd'){
            $dnbId=rdPhase6UseRdConflictValue($pdo,$conflict);
            rdPhase6ResolveMark($pdo,$conflictId,'resolved','use_rd',$userId,$dnbId,$note?:'Valor do RD aplicado manualmente.');
        }elseif($action==='link'){
            $selectedDnbId=strtoupper(trim($selectedDnbId));
            $ids=rdPhase6ConflictCandidateIds($conflict);
            if($selectedDnbId===''||!in_array($selectedDnbId,$ids,true))throw new RuntimeException('Selecione um candidato DNB válido.');
            $local=buscarEntidadePorDnbId($pdo,$selectedDnbId);
            if(!$local)throw new RuntimeException('Registro DNB selecionado não existe.');
            rdPhase6ReplayLinkedConflict($pdo,$conflict,$selectedDnbId);
            rdPhase6ResolveMark($pdo,$conflictId,'resolved','link',$userId,$selectedDnbId,$note?:'ID externo vinculado ao registro DNB selecionado.');
        }elseif($action==='deactivate_local'){
            if(!str_starts_with((string)$conflict['conflict_type'],'remote_deleted_'))throw new RuntimeException('Ação disponível apenas para exclusão remota.');
            $ids=rdPhase6ConflictCandidateIds($conflict);$dnbId=$selectedDnbId!==''?strtoupper($selectedDnbId):($ids[0]??'');
            $local=buscarEntidadePorDnbId($pdo,$dnbId);if(!$local)throw new RuntimeException('Registro DNB não encontrado.');
            $prefix=substr($dnbId,0,3);
            if($prefix==='EMP')$pdo->prepare("UPDATE crm_companies SET status='inactive',review_status='ok',review_reason=NULL WHERE id=?")->execute([(int)$local['id']]);
            elseif($prefix==='CON')$pdo->prepare("UPDATE crm_contacts SET status='inactive',review_status='ok',review_reason=NULL WHERE id=?")->execute([(int)$local['id']]);
            else throw new RuntimeException('Negociações excluídas no RD são preservadas como histórico e não podem ser desativadas por esta ação.');
            rdPhase6ResolveMark($pdo,$conflictId,'resolved','deactivate_local',$userId,$dnbId,$note?:'Registro local desativado após exclusão no RD.');
        }else throw new InvalidArgumentException('Ação de resolução inválida.');
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

/** @return array<string,int> */
function rdPhase6Stats(PDO $pdo): array
{
    if(!rdPhase6Disponivel($pdo))return ['pending_events'=>0,'error_events'=>0,'pending_conflicts'=>0,'active_webhooks'=>0];
    return [
        'pending_events'=>(int)$pdo->query("SELECT COUNT(*) FROM rd_webhook_events WHERE status='pending'")->fetchColumn(),
        'error_events'=>(int)$pdo->query("SELECT COUNT(*) FROM rd_webhook_events WHERE status='error'")->fetchColumn(),
        'pending_conflicts'=>(int)$pdo->query("SELECT COUNT(*) FROM rd_sync_conflicts WHERE status='pending'")->fetchColumn(),
        'active_webhooks'=>(int)$pdo->query("SELECT COUNT(*) FROM rd_webhook_subscriptions WHERE status='active'")->fetchColumn(),
    ];
}

/** @return array{webhooks:array<string,int>,incremental_job:int,steps:int} */
function rdPhase6Tick(PDO $pdo,int $maxSeconds=35): array
{
    if(!rdPhase6Disponivel($pdo))throw new RuntimeException('Fase 6 não instalada.');
    $started=microtime(true);$queue=rdPhase6ProcessWebhookQueue($pdo,20);
    $jobId=0;$steps=0;
    if(integracaoConfigValor('DNB_RD_INCREMENTAL_ENABLED','0')==='1'){
        $job=rdSyncCriarIncrementalJob($pdo,0,false);
        if($job)$jobId=(int)$job['id'];
        if($jobId>0){
            while(microtime(true)-$started<$maxSeconds){
                $job=rdSyncJob($pdo,$jobId);if(!$job||in_array($job['status'],['completed','error','cancelled'],true))break;
                if((int)($job['state']['retry_after']??0)>time())break;
                rdSyncProcessStep($pdo,$jobId);$steps++;
                if($steps>=12)break;
            }
        }
    }
    return ['webhooks'=>['processed'=>$queue['processed'],'error'=>$queue['error'],'ignored'=>$queue['ignored']],'incremental_job'=>$jobId,'steps'=>$steps];
}
