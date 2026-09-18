<?php
declare(strict_types=1);

require_once __DIR__ . '/crm_core_service.php';
require_once __DIR__ . '/crm_phase2_service.php';
require_once __DIR__ . '/rd_discovery_service.php';

final class RdSyncRateLimitException extends RuntimeException {}

function rdSyncDisponivel(PDO $pdo): bool
{
    static $cache=null;
    if($cache!==null)return $cache;
    if(!rdDiscoveryDisponivel($pdo)||!crmPhase2Disponivel($pdo))return $cache=false;
    try{
        $stmt=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('rd_sync_jobs','rd_sync_logs','rd_sync_conflicts')");
        return $cache=((int)$stmt->fetchColumn()===3);
    }catch(Throwable){return $cache=false;}
}

/** @return array<string,int> */
function rdSyncCounterDefault(): array
{
    return [
        'pages'=>0,'marketing_contacts_seen'=>0,'marketing_contacts_detailed'=>0,'crm_companies_seen'=>0,'crm_contacts_seen'=>0,'crm_deals_seen'=>0,
        'companies_created'=>0,'companies_updated'=>0,'contacts_created'=>0,'contacts_updated'=>0,
        'negotiations_created'=>0,'negotiations_updated'=>0,'links_created'=>0,'conflicts'=>0,'skipped'=>0,
    ];
}

/** @return array<string,mixed> */
function rdSyncJsonObject(?string $json): array
{
    $v=json_decode((string)$json,true);
    return is_array($v)?$v:[];
}

function rdSyncLog(PDO $pdo,int $jobId,string $level,string $scope,string $message,array $context=[]): void
{
    if(!in_array($level,['info','success','warning','error'],true))$level='info';
    $stmt=$pdo->prepare('INSERT INTO rd_sync_logs (job_id,level,scope,message,context_json) VALUES (?,?,?,?,?)');
    $stmt->execute([$jobId,$level,mb_substr($scope,0,60),mb_substr($message,0,500),$context?json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);
}

/** @return array<string,mixed>|null */
function rdSyncJob(PDO $pdo,int $jobId): ?array
{
    if(!rdSyncDisponivel($pdo)||$jobId<=0)return null;
    $stmt=$pdo->prepare('SELECT j.*,u.nome created_by_name FROM rd_sync_jobs j LEFT JOIN usuarios u ON u.id=j.created_by_user_id WHERE j.id=?');
    $stmt->execute([$jobId]);$row=$stmt->fetch();if(!$row)return null;
    $row['state']=rdSyncJsonObject($row['state_json']??'');
    $row['counters']=array_merge(rdSyncCounterDefault(),rdSyncJsonObject($row['counters_json']??''));
    return $row;
}

/** @return array<int,array<string,mixed>> */
function rdSyncRecentJobs(PDO $pdo,int $limit=10): array
{
    if(!rdSyncDisponivel($pdo))return [];
    $limit=max(1,min(30,$limit));
    return $pdo->query("SELECT j.*,u.nome created_by_name,(SELECT COUNT(*) FROM rd_sync_conflicts c WHERE c.job_id=j.id AND c.status='pending') pending_conflicts FROM rd_sync_jobs j LEFT JOIN usuarios u ON u.id=j.created_by_user_id ORDER BY j.id DESC LIMIT {$limit}")->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function rdSyncLogs(PDO $pdo,int $jobId,int $afterId=0,int $limit=200): array
{
    if(!rdSyncDisponivel($pdo))return [];
    $limit=max(1,min(500,$limit));
    $stmt=$pdo->prepare("SELECT id,level,scope,message,created_at FROM rd_sync_logs WHERE job_id=? AND id>? ORDER BY id ASC LIMIT {$limit}");
    $stmt->execute([$jobId,max(0,$afterId)]);return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function rdSyncConflicts(PDO $pdo,int $jobId,int $limit=100): array
{
    if(!rdSyncDisponivel($pdo))return [];
    $limit=max(1,min(300,$limit));
    $stmt=$pdo->prepare("SELECT * FROM rd_sync_conflicts WHERE job_id=? AND status='pending' ORDER BY id DESC LIMIT {$limit}");
    $stmt->execute([$jobId]);return $stmt->fetchAll();
}

/** @return array<int,string> */
function rdSyncPrerequisiteIssues(PDO $pdo): array
{
    $issues=[];
    if(!rdSyncDisponivel($pdo))return ['Instale as migrações das Fases 1 a 5.'];
    if(!rdEstadoProduto('marketing')['connected'])$issues[]='RD Station Marketing não está conectado.';
    if(!rdEstadoProduto('crm')['connected'])$issues[]='RD Station CRM não está conectado.';
    foreach(['name'=>'contact.name','email'=>'contact.email'] as $source=>$target){
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM rd_discovery_items d
          JOIN rd_field_mappings m ON m.product='marketing' AND m.source_entity='contact'
            AND m.source_key=COALESCE(NULLIF(d.api_identifier,''),d.external_id) AND m.enabled=1
          WHERE d.product='marketing' AND d.resource_type='contact_field' AND d.active=1
            AND d.selected_for_sync=1 AND COALESCE(NULLIF(d.api_identifier,''),d.external_id)=?
            AND CONCAT(m.target_entity,'.',m.target_field)=?");
        $stmt->execute([$source,$target]);
        if((int)$stmt->fetchColumn()!==1)$issues[]='Mapeie e selecione o campo '.$source.' do RD Marketing para '.$target.'.';
    }
    $cnpjMap=(int)$pdo->query("SELECT COUNT(*) FROM rd_field_mappings WHERE product='crm' AND source_entity='organization' AND target_entity='company' AND target_field='cnpj' AND enabled=1")->fetchColumn();
    if($cnpjMap===0)$issues[]='Mapeie o campo CNPJ das Empresas do RD CRM para company.cnpj antes da importação, para a deduplicação ser segura.';
    if((int)$pdo->query("SELECT COUNT(*) FROM rd_pipeline_mappings WHERE enabled=1")->fetchColumn()===0){
        $issues[]='Descubra e habilite ao menos um funil/etapa do RD CRM na Fase 4.';
    }
    return $issues;
}

/** @return array<string,mixed> */
function rdSyncCriarJob(PDO $pdo,int $userId): array
{
    if(!rdSyncDisponivel($pdo))throw new RuntimeException('Instale a migração da Fase 5.');
    $issues=rdSyncPrerequisiteIssues($pdo);
    if($issues)throw new RuntimeException('Pré-requisitos da sincronização: '.implode(' ', $issues));

    $active=$pdo->query("SELECT id FROM rd_sync_jobs WHERE status IN ('queued','running','paused') ORDER BY id DESC LIMIT 1")->fetchColumn();
    if($active){
        $job=rdSyncJob($pdo,(int)$active);
        if($job)return $job;
    }

    $since=(new DateTimeImmutable('now'))->modify('-6 months')->setTime(0,0,0)->format('Y-m-d H:i:s');
    $counters=rdSyncCounterDefault();
    $state=['marketing_segmentation_id'=>null,'marketing_detail_cursor'=>0,'retry_after'=>0];
    $stmt=$pdo->prepare("INSERT INTO rd_sync_jobs (mode,status,stage,since_at,page_number,page_size,state_json,counters_json,created_by_user_id) VALUES ('first_sync','queued','init',?,1,100,?,?,NULLIF(?,0))");
    $stmt->execute([$since,json_encode($state),json_encode($counters),$userId]);
    $id=(int)$pdo->lastInsertId();
    rdSyncLog($pdo,$id,'info','job','Primeira sincronização criada. Janela de negociações: últimos 6 meses.',['since_at'=>$since]);
    return rdSyncJob($pdo,$id)??['id'=>$id];
}

function rdSyncSetJob(PDO $pdo,int $jobId,string $stage,int $page,array $state,array $counters,string $status='running',string $error=''): void
{
    $stmt=$pdo->prepare("UPDATE rd_sync_jobs SET stage=?,page_number=?,state_json=?,counters_json=?,status=?,last_error=NULLIF(?,''),heartbeat_at=NOW(),started_at=COALESCE(started_at,NOW()),finished_at=CASE WHEN ? IN ('completed','error','cancelled') THEN NOW() ELSE finished_at END WHERE id=?");
    $stmt->execute([$stage,max(1,$page),json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($counters,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$status,mb_substr($error,0,500),$status,$jobId]);
}

function rdSyncAdvance(PDO $pdo,array $job,string $nextStage,array $state,array $counters): void
{
    rdSyncSetJob($pdo,(int)$job['id'],$nextStage,1,$state,$counters,'running');
    rdSyncLog($pdo,(int)$job['id'],'success','stage','Etapa concluída: '.(string)$job['stage'].'. Próxima: '.$nextStage.'.');
}

function rdSyncConflict(PDO $pdo,int $jobId,string $product,string $entity,string $externalId,string $type,array $candidates,array $payload,array &$counters): void
{
    $stmt=$pdo->prepare("INSERT INTO rd_sync_conflicts (job_id,product,external_entity,external_id,conflict_type,candidates_json,payload_json,status)
      VALUES (?,?,?,?,?,?,?,'pending')
      ON DUPLICATE KEY UPDATE candidates_json=VALUES(candidates_json),payload_json=VALUES(payload_json),updated_at=NOW()");
    $stmt->execute([$jobId,$product,$entity,mb_substr($externalId,0,191),mb_substr($type,0,80),json_encode($candidates,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    if($stmt->rowCount()===1){
        $counters['conflicts']=(int)($counters['conflicts']??0)+1;
        rdSyncLog($pdo,$jobId,'warning',$entity,'Conflito enviado para revisão: '.$type.'.',['external_id'=>$externalId,'candidates'=>$candidates]);
    }
}

function rdSyncExternalId(array $item): string
{
    $a=rdDiscoveryAttrs($item);
    foreach(['id','uuid','external_id'] as $k){$v=trim((string)($a[$k]??''));if($v!=='')return mb_substr($v,0,191);}
    return '';
}

/** @return array<int,array<string,mixed>> */
function rdSyncList(?array $json,array $keys=['data','items','contacts','organizations','deals']): array
{
    if(!$json)return [];
    foreach($keys as $k){
        if(isset($json[$k])&&is_array($json[$k])){
            $v=$json[$k];
            if(array_is_list($v))return array_values(array_filter($v,'is_array'));
            if(isset($v['data'])&&is_array($v['data'])&&array_is_list($v['data']))return array_values(array_filter($v['data'],'is_array'));
        }
    }
    return rdDiscoveryExtractList($json);
}

/** @return array{status:int,json:?array,raw:string} */
function rdSyncFetch(string $product,string $url): array
{
    $res=rdApiRequest($product,'GET',$url);
    if((int)$res['status']===429)throw new RdSyncRateLimitException('RD Station informou limite temporário de requisições.');
    if((int)$res['status']<200||(int)$res['status']>=300||!is_array($res['json']))throw new RuntimeException(rdErroResposta($res,'RD Station recusou a sincronização'));
    return $res;
}

function rdSyncPayloadValue(array $attrs,string $key): mixed
{
    if(array_key_exists($key,$attrs))return $attrs[$key];
    foreach(['custom_fields','fields'] as $bucket){
        $set=$attrs[$bucket]??null;
        if(!is_array($set))continue;
        if(array_key_exists($key,$set))return $set[$key];
        foreach($set as $entry){
            if(!is_array($entry))continue;
            $ea=rdDiscoveryAttrs($entry);
            $id=(string)($ea['slug']??$ea['api_identifier']??$ea['key']??$ea['id']??'');
            if($id===$key)return $ea['value']??$ea['values']??$ea['content']??null;
        }
    }
    return null;
}

function rdSyncScalar(mixed $value): string
{
    if(is_string($value)||is_numeric($value))return trim((string)$value);
    if(is_bool($value))return $value?'1':'0';
    if(is_array($value)){
        if(isset($value['value'])&&is_scalar($value['value']))return trim((string)$value['value']);
        if(array_is_list($value)){
            $parts=[];foreach($value as $v)if(is_scalar($v)&&trim((string)$v)!=='')$parts[]=trim((string)$v);
            return implode(', ',$parts);
        }
    }
    return '';
}

/** @return array<int,string> */
function rdSyncRelationshipIds(array $item,string $relation): array
{
    $a=rdDiscoveryAttrs($item);$ids=[];$singular=rtrim($relation,'s');
    foreach([$relation.'_id',$singular.'_id'] as $k){$v=$a[$k]??null;if(is_scalar($v)&&trim((string)$v)!=='')$ids[]=(string)$v;}
    foreach([$relation.'_ids',$singular.'_ids'] as $k){$v=$a[$k]??null;if(is_array($v))foreach($v as $x)if(is_scalar($x)&&trim((string)$x)!=='')$ids[]=(string)$x;}
    foreach([$relation,$singular] as $k){
        $v=$a[$k]??null;
        if(is_array($v)){
            if(isset($v['id'])&&is_scalar($v['id']))$ids[]=(string)$v['id'];
            elseif(array_is_list($v))foreach($v as $x){if(is_array($x)&&isset($x['id']))$ids[]=(string)$x['id'];elseif(is_scalar($x))$ids[]=(string)$x;}
        }
    }
    $rels=$item['relationships']??[];
    if(is_array($rels)){
        foreach([$relation,$singular] as $k){
            $data=$rels[$k]['data']??null;
            if(is_array($data)){
                if(isset($data['id']))$ids[]=(string)$data['id'];
                elseif(array_is_list($data))foreach($data as $x)if(is_array($x)&&isset($x['id']))$ids[]=(string)$x['id'];
            }
        }
    }
    return array_values(array_unique(array_filter(array_map('trim',$ids))));
}

/** @return array<string,array{source_key:string,value:string,truth:string}> */
function rdSyncMappedValues(PDO $pdo,string $product,string $sourceEntity,array $attrs,string $targetEntity): array
{
    if($product==='marketing'){
        $stmt=$pdo->prepare("SELECT m.source_key,m.target_field,m.source_of_truth
          FROM rd_field_mappings m
          JOIN rd_discovery_items d ON d.product='marketing' AND d.resource_type='contact_field' AND d.active=1
            AND d.selected_for_sync=1 AND (d.api_identifier=m.source_key OR (d.api_identifier='' AND d.external_id=m.source_key))
          WHERE m.product=? AND m.source_entity=? AND m.target_entity=? AND m.enabled=1 ORDER BY m.id");
    }else{
        $stmt=$pdo->prepare("SELECT source_key,target_field,source_of_truth FROM rd_field_mappings WHERE product=? AND source_entity=? AND target_entity=? AND enabled=1 ORDER BY id");
    }
    $stmt->execute([$product,$sourceEntity,$targetEntity]);$out=[];
    foreach($stmt->fetchAll() as $m){
        $value=rdSyncScalar(rdSyncPayloadValue($attrs,(string)$m['source_key']));
        if($value==='')continue;
        $out[(string)$m['target_field']]=['source_key'=>(string)$m['source_key'],'value'=>$value,'truth'=>(string)$m['source_of_truth']];
    }
    return $out;
}

/** @return array<string,mixed>|null */
function rdSyncExternalLocal(PDO $pdo,string $provider,string $entity,string $externalId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM crm_external_links WHERE provider=? AND external_entity=? AND external_id=? LIMIT 1');
    $stmt->execute([$provider,$entity,$externalId]);$link=$stmt->fetch();if(!$link)return null;
    $local=buscarEntidadePorDnbId($pdo,(string)$link['dnb_id']);
    return $local?['link'=>$link,'local'=>$local]:null;
}

function rdSyncCanLinkLocal(PDO $pdo,string $provider,string $localEntity,string $dnbId,string $externalId): bool
{
    $stmt=$pdo->prepare('SELECT external_id FROM crm_external_links WHERE provider=? AND local_entity=? AND dnb_id=? LIMIT 1');
    $stmt->execute([$provider,$localEntity,$dnbId]);$existing=$stmt->fetchColumn();
    return $existing===false||(string)$existing===$externalId;
}

function rdSyncRegisterLink(PDO $pdo,string $provider,string $externalEntity,string $externalId,string $localEntity,string $dnbId,array &$counters): void
{
    registrarVinculoExternoCrm($pdo,$provider,$externalEntity,$externalId,$localEntity,$dnbId);
    $counters['links_created']=(int)($counters['links_created']??0)+1;
}

function rdSyncTouchLink(PDO $pdo,string $provider,string $externalEntity,string $externalId): void
{
    $stmt=$pdo->prepare('UPDATE crm_external_links SET last_sync_at=NOW() WHERE provider=? AND external_entity=? AND external_id=?');
    $stmt->execute([$provider,$externalEntity,$externalId]);
}

/** @return array<string,string> */
function rdSyncTableAllowed(string $entity): array
{
    return match($entity){
        'company'=>['name'=>'name','trade_name'=>'trade_name','cnpj'=>'cnpj','primary_email'=>'primary_email','phone'=>'phone','website'=>'website','address'=>'address','locality'=>'locality'],
        'contact'=>['name'=>'name','email'=>'email','phone'=>'phone','role_title'=>'role_title','company_id'=>'company_id'],
        'negotiation'=>['title'=>'title','category'=>'category','pipeline_name'=>'pipeline_name','stage_name'=>'stage_name','estimated_value'=>'estimated_value','status'=>'status','lost_reason'=>'lost_reason','assigned_to_user_id'=>'assigned_to_user_id','business_created_at'=>'business_created_at','business_updated_at'=>'business_updated_at','closed_at'=>'closed_at','company_id'=>'company_id','order_status'=>'order_status'],
        default=>[],
    };
}

function rdSyncEntityTable(string $entity): string
{
    return match($entity){'company'=>'crm_companies','contact'=>'crm_contacts','negotiation'=>'crm_negotiations',default=>throw new InvalidArgumentException('Entidade local inválida.')};
}

function rdSyncDateTime(mixed $value): ?string
{
    $raw=trim((string)$value);if($raw==='')return null;
    try{return (new DateTimeImmutable($raw))->format('Y-m-d H:i:s');}catch(Throwable){return null;}
}

/** @param array<string,array{source_key:string,value:mixed,truth:string}> $fields */
function rdSyncUpdateLocal(PDO $pdo,int $jobId,string $product,string $externalEntity,string $externalId,string $entity,int $id,array $fields,array $payload,array &$counters): void
{
    if(!$fields)return;
    $table=rdSyncEntityTable($entity);$allowed=rdSyncTableAllowed($entity);
    $stmt=$pdo->prepare("SELECT * FROM {$table} WHERE id=?");$stmt->execute([$id]);$current=$stmt->fetch();if(!$current)return;
    $sets=[];$params=[];$updatedFields=[];
    foreach($fields as $field=>$meta){
        if(!isset($allowed[$field]))continue;
        $new=$meta['value'];$truth=$meta['truth']??'rd';$old=$current[$field]??null;
        $oldNorm=is_scalar($old)?trim((string)$old):'';$newNorm=is_scalar($new)?trim((string)$new):'';
        if($newNorm==='')continue;
        if($truth==='dnb'&&$oldNorm!=='')continue;
        if($truth==='manual_review'&&$oldNorm!==''&&$oldNorm!==$newNorm){
            rdSyncConflict($pdo,$jobId,$product,$externalEntity,$externalId,'field_conflict_'.$entity.'_'.$field,[['dnb_id'=>$current['dnb_id']??'','field'=>$field,'local_value'=>$oldNorm,'rd_value'=>$newNorm]],$payload,$counters);
            continue;
        }
        if($entity==='company'&&$field==='cnpj'){
            $digits=crmCoreCnpjDigits($newNorm);
            if($digits==='')continue;
            $check=$pdo->prepare('SELECT dnb_id FROM crm_companies WHERE cnpj_digits=? AND id<>? ORDER BY id');
            $check->execute([$digits,$id]);$other=$check->fetchAll(PDO::FETCH_COLUMN);
            if($other){rdSyncConflict($pdo,$jobId,$product,$externalEntity,$externalId,'cnpj_change_collision',$other,$payload,$counters);continue;}
        }
        if($entity==='contact'&&$field==='email'){
            $normalized=crmCoreEmail($newNorm);if($normalized==='')continue;$new=$normalized;$newNorm=$normalized;
            $check=$pdo->prepare('SELECT dnb_id FROM crm_contacts WHERE LOWER(TRIM(email))=? AND id<>? ORDER BY id');
            $check->execute([$normalized,$id]);$other=$check->fetchAll(PDO::FETCH_COLUMN);
            if($other){rdSyncConflict($pdo,$jobId,$product,$externalEntity,$externalId,'email_change_collision',$other,$payload,$counters);continue;}
        }
        if($entity==='negotiation'&&$field==='status')$new=rdSyncDealStatus($newNorm);
        if($field==='estimated_value')$new=max(0,(float)$new);
        if(in_array($field,['business_created_at','business_updated_at','closed_at'],true)){
            $new=rdSyncDateTime($new);if($new===null)continue;
        }
        if(in_array($field,['assigned_to_user_id','company_id'],true)){
            $new=(int)$new;if($new<=0)continue;
        }
        $sets[]=$allowed[$field].'=?';$params[]=$new;$updatedFields[$field]=$new;
    }
    if(!$sets)return;
    if($entity==='company'&&array_key_exists('cnpj',$updatedFields)){
        $sets[]='cnpj_digits=?';$params[]=crmCoreCnpjDigits((string)$updatedFields['cnpj']);
    }
    $params[]=$id;
    $pdo->prepare("UPDATE {$table} SET ".implode(',',$sets)." WHERE id=?")->execute($params);
}

function rdSyncFindCompanyByCnpj(PDO $pdo,string $cnpj): array
{
    $digits=crmCoreCnpjDigits($cnpj);if($digits==='')return [];
    $stmt=$pdo->prepare('SELECT * FROM crm_companies WHERE cnpj_digits=? ORDER BY id');$stmt->execute([$digits]);return $stmt->fetchAll();
}

function rdSyncFindContactByEmail(PDO $pdo,string $email): array
{
    $email=crmCoreEmail($email);if($email==='')return [];
    $stmt=$pdo->prepare('SELECT * FROM crm_contacts WHERE LOWER(TRIM(email))=? ORDER BY id');$stmt->execute([$email]);return $stmt->fetchAll();
}

/** @return array<string,mixed>|null */
function rdSyncUpsertCompany(PDO $pdo,int $jobId,string $provider,string $externalId,array $attrs,array $payload,array &$counters): ?array
{
    if($externalId===''){++$counters['skipped'];return null;}
    $product=$provider==='rd_crm'?'crm':'marketing';
    $linked=rdSyncExternalLocal($pdo,$provider,'organization',$externalId);
    $mapped=rdSyncMappedValues($pdo,$product,'organization',$attrs,'company');
    $fields=array_merge([
        'name'=>['source_key'=>'name','value'=>rdSyncScalar($attrs['name']??''),'truth'=>'rd'],
        'trade_name'=>['source_key'=>'trade_name','value'=>rdSyncScalar($attrs['trade_name']??''),'truth'=>'rd'],
        'primary_email'=>['source_key'=>'email','value'=>rdSyncScalar($attrs['email']??''),'truth'=>'rd'],
        'phone'=>['source_key'=>'phone','value'=>rdSyncScalar($attrs['phone']??''),'truth'=>'rd'],
        'website'=>['source_key'=>'website','value'=>rdSyncScalar($attrs['website']??$attrs['site']??''),'truth'=>'rd'],
        'address'=>['source_key'=>'address','value'=>rdSyncScalar($attrs['address']??''),'truth'=>'rd'],
        'locality'=>['source_key'=>'locality','value'=>rdSyncScalar($attrs['city']??$attrs['locality']??''),'truth'=>'rd'],
    ],$mapped);
    $cnpj=$fields['cnpj']['value']??rdSyncScalar($attrs['cnpj']??'');
    if($cnpj!=='')$fields['cnpj']=$fields['cnpj']??['source_key'=>'cnpj','value'=>$cnpj,'truth'=>'rd'];

    if($linked){
        $local=$linked['local'];rdSyncTouchLink($pdo,$provider,'organization',$externalId);rdSyncUpdateLocal($pdo,$jobId,$product,'organization',$externalId,'company',(int)$local['id'],$fields,$payload,$counters);
        ++$counters['companies_updated'];return buscarEntidadePorDnbId($pdo,(string)$local['dnb_id']);
    }

    $candidates=$cnpj!==''?rdSyncFindCompanyByCnpj($pdo,$cnpj):[];
    if(count($candidates)>1){rdSyncConflict($pdo,$jobId,$product,'organization',$externalId,'duplicate_cnpj',array_column($candidates,'dnb_id'),$payload,$counters);return null;}
    if(count($candidates)===1){
        $local=$candidates[0];
        if(!rdSyncCanLinkLocal($pdo,$provider,'company',(string)$local['dnb_id'],$externalId)){rdSyncConflict($pdo,$jobId,$product,'organization',$externalId,'external_duplicate_company',[(string)$local['dnb_id']],$payload,$counters);return null;}
        rdSyncRegisterLink($pdo,$provider,'organization',$externalId,'company',(string)$local['dnb_id'],$counters);
        rdSyncUpdateLocal($pdo,$jobId,$product,'organization',$externalId,'company',(int)$local['id'],$fields,$payload,$counters);
        ++$counters['companies_updated'];return buscarEntidadePorDnbId($pdo,(string)$local['dnb_id']);
    }

    $name=trim((string)($fields['name']['value']??''));if($name==='')$name='Empresa RD '.$externalId;
    $row=criarEmpresaCrmCore($pdo,['name'=>$name,'trade_name'=>$fields['trade_name']['value']??'','cnpj'=>$cnpj,'email'=>$fields['primary_email']['value']??'','phone'=>$fields['phone']['value']??'','website'=>$fields['website']['value']??'','address'=>$fields['address']['value']??'','locality'=>$fields['locality']['value']??'','source'=>$provider],true);
    $pdo->prepare('UPDATE crm_companies SET created_by_user_id=COALESCE(created_by_user_id,(SELECT created_by_user_id FROM rd_sync_jobs WHERE id=?)) WHERE id=?')->execute([$jobId,(int)$row['id']]);
    rdSyncRegisterLink($pdo,$provider,'organization',$externalId,'company',(string)$row['dnb_id'],$counters);
    rdSyncUpdateLocal($pdo,$jobId,$product,'organization',$externalId,'company',(int)$row['id'],$fields,$payload,$counters);
    ++$counters['companies_created'];return buscarEntidadePorDnbId($pdo,(string)$row['dnb_id']);
}

/** @return array<string,mixed>|null */
function rdSyncMarketingCompanyFromContact(PDO $pdo,int $jobId,array $attrs,array $payload,array &$counters): ?array
{
    $mapped=rdSyncMappedValues($pdo,'marketing','contact',$attrs,'company');
    $cnpj=trim((string)($mapped['cnpj']['value']??''));
    if($cnpj===''||crmCoreCnpjDigits($cnpj)==='')return null;
    $companyAttrs=[];
    foreach($mapped as $field=>$meta)$companyAttrs[$field]=$meta['value'];
    if(trim((string)($companyAttrs['name']??''))==='')$companyAttrs['name']='Empresa '.$cnpj;
    return rdSyncUpsertCompany($pdo,$jobId,'rd_marketing','cnpj:'.crmCoreCnpjDigits($cnpj),$companyAttrs,$payload,$counters);
}

/** @return array<string,mixed>|null */
function rdSyncUpsertContact(PDO $pdo,int $jobId,string $provider,string $externalId,array $attrs,array $payload,?int $companyId,array &$counters): ?array
{
    if($externalId===''){++$counters['skipped'];return null;}
    $product=$provider==='rd_crm'?'crm':'marketing';
    if(!$companyId&&$provider==='rd_marketing'){
        $mktCompany=rdSyncMarketingCompanyFromContact($pdo,$jobId,$attrs,$payload,$counters);
        if($mktCompany)$companyId=(int)$mktCompany['id'];
    }
    $linked=rdSyncExternalLocal($pdo,$provider,'contact',$externalId);
    $mappedContact=rdSyncMappedValues($pdo,$product,'contact',$attrs,'contact');
    if($product==='marketing'){
        $fields=$mappedContact;
        if(isset($fields['email']))$fields['email']['value']=crmCoreEmail((string)$fields['email']['value']);
    }else{
        $phone=rdSyncScalar($attrs['phone']??$attrs['mobile_phone']??$attrs['personal_phone']??'');
        $fields=array_merge([
            'name'=>['source_key'=>'name','value'=>rdSyncScalar($attrs['name']??''),'truth'=>'rd'],
            'email'=>['source_key'=>'email','value'=>crmCoreEmail(rdSyncScalar($attrs['email']??'')),'truth'=>'rd'],
            'phone'=>['source_key'=>'phone','value'=>$phone,'truth'=>'rd'],
            'role_title'=>['source_key'=>'job_title','value'=>rdSyncScalar($attrs['job_title']??$attrs['title']??''),'truth'=>'rd'],
        ],$mappedContact);
    }
    if($companyId)$fields['company_id']=['source_key'=>'organization_id','value'=>$companyId,'truth'=>'rd'];

    if($linked){
        $local=$linked['local'];rdSyncTouchLink($pdo,$provider,'contact',$externalId);rdSyncUpdateLocal($pdo,$jobId,$product,'contact',$externalId,'contact',(int)$local['id'],$fields,$payload,$counters);
        ++$counters['contacts_updated'];return buscarEntidadePorDnbId($pdo,(string)$local['dnb_id']);
    }
    $email=crmCoreEmail((string)($fields['email']['value']??($provider==='rd_marketing'?rdSyncScalar($attrs['email']??''):'')));
    $candidates=$email!==''?rdSyncFindContactByEmail($pdo,$email):[];
    if(count($candidates)>1){rdSyncConflict($pdo,$jobId,$product,'contact',$externalId,'duplicate_email',array_column($candidates,'dnb_id'),$payload,$counters);return null;}
    if(count($candidates)===1){
        $local=$candidates[0];
        if(!rdSyncCanLinkLocal($pdo,$provider,'contact',(string)$local['dnb_id'],$externalId)){rdSyncConflict($pdo,$jobId,$product,'contact',$externalId,'external_duplicate_contact',[(string)$local['dnb_id']],$payload,$counters);return null;}
        rdSyncRegisterLink($pdo,$provider,'contact',$externalId,'contact',(string)$local['dnb_id'],$counters);
        rdSyncUpdateLocal($pdo,$jobId,$product,'contact',$externalId,'contact',(int)$local['id'],$fields,$payload,$counters);
        ++$counters['contacts_updated'];return buscarEntidadePorDnbId($pdo,(string)$local['dnb_id']);
    }

    $name=trim((string)($fields['name']['value']??($provider==='rd_marketing'?rdSyncScalar($attrs['name']??''):'')));if($name==='')$name=$email!==''?$email:'Contato RD '.$externalId;
    $row=criarContatoCrmCore($pdo,['company_id'=>$companyId,'name'=>$name,'email'=>$email,'phone'=>$fields['phone']['value']??'','role_title'=>$fields['role_title']['value']??'','source'=>$provider],true);
    if($email==='')$pdo->prepare("UPDATE crm_contacts SET review_status='needs_review',review_reason='Contato importado sem e-mail: conferir cadastro' WHERE id=?")->execute([(int)$row['id']]);
    $pdo->prepare('UPDATE crm_contacts SET created_by_user_id=COALESCE(created_by_user_id,(SELECT created_by_user_id FROM rd_sync_jobs WHERE id=?)) WHERE id=?')->execute([$jobId,(int)$row['id']]);
    rdSyncRegisterLink($pdo,$provider,'contact',$externalId,'contact',(string)$row['dnb_id'],$counters);
    rdSyncUpdateLocal($pdo,$jobId,$product,'contact',$externalId,'contact',(int)$row['id'],$fields,$payload,$counters);
    ++$counters['contacts_created'];return buscarEntidadePorDnbId($pdo,(string)$row['dnb_id']);
}

/** @return array<string,mixed>|null */
function rdSyncLocalByExternal(PDO $pdo,string $provider,string $externalEntity,string $externalId): ?array
{
    if($externalId==='')return null;$x=rdSyncExternalLocal($pdo,$provider,$externalEntity,$externalId);return $x['local']??null;
}

function rdSyncOwnerLocalId(PDO $pdo,string $externalId): int
{
    if($externalId==='')return 0;$stmt=$pdo->prepare('SELECT local_user_id FROM rd_user_mappings WHERE rd_user_external_id=? AND enabled=1 LIMIT 1');$stmt->execute([$externalId]);return (int)($stmt->fetchColumn()?:0);
}

/** @return array{pipeline:string,stage:string} */
function rdSyncPipelineNames(PDO $pdo,string $pipelineId,string $stageId): array
{
    $r=false;
    if($pipelineId!==''&&$stageId!==''){
        $stmt=$pdo->prepare('SELECT pipeline_name,stage_name FROM rd_pipeline_mappings WHERE enabled=1 AND rd_pipeline_external_id=? AND rd_stage_external_id=? LIMIT 1');
        $stmt->execute([$pipelineId,$stageId]);$r=$stmt->fetch();
    }
    if(!$r&&$stageId!==''){
        $stmt=$pdo->prepare('SELECT pipeline_name,stage_name FROM rd_pipeline_mappings WHERE enabled=1 AND rd_stage_external_id=? ORDER BY id LIMIT 1');
        $stmt->execute([$stageId]);$r=$stmt->fetch();
    }
    if(!$r&&$pipelineId!==''){
        $stmt=$pdo->prepare('SELECT pipeline_name FROM rd_pipeline_mappings WHERE enabled=1 AND rd_pipeline_external_id=? ORDER BY sort_order,id LIMIT 1');
        $stmt->execute([$pipelineId]);$name=$stmt->fetchColumn();
        if($name!==false)return ['pipeline'=>(string)$name,'stage'=>''];
    }
    return ['pipeline'=>(string)($r['pipeline_name']??''),'stage'=>(string)($r['stage_name']??'')];
}

function rdSyncDiscoveryName(PDO $pdo,string $type,string $externalId): string
{
    if($externalId==='')return '';$stmt=$pdo->prepare("SELECT name FROM rd_discovery_items WHERE product='crm' AND resource_type=? AND external_id=? ORDER BY active DESC,id DESC LIMIT 1");$stmt->execute([$type,$externalId]);return trim((string)($stmt->fetchColumn()?:''));
}

function rdSyncDealStatus(string $status): string
{
    return match(strtolower(trim($status))){'won'=>'won','lost'=>'lost',default=>'open'};
}

/** @return array<string,mixed>|null */
function rdSyncUpsertDeal(PDO $pdo,int $jobId,string $externalId,array $item,array &$counters): ?array
{
    if($externalId===''){++$counters['skipped'];return null;}
    $attrs=rdDiscoveryAttrs($item);$linked=rdSyncExternalLocal($pdo,'rd_crm','deal',$externalId);
    $orgId=rdSyncRelationshipIds($item,'organization')[0]??'';$company=$orgId!==''?rdSyncLocalByExternal($pdo,'rd_crm','organization',$orgId):null;
    $ownerId=rdSyncRelationshipIds($item,'owner')[0]??rdSyncScalar($attrs['owner_id']??'');
    $pipelineId=rdSyncRelationshipIds($item,'pipeline')[0]??rdSyncScalar($attrs['pipeline_id']??'');
    $stageId=rdSyncRelationshipIds($item,'stage')[0]??rdSyncScalar($attrs['stage_id']??'');
    $lostId=rdSyncRelationshipIds($item,'lost_reason')[0]??rdSyncScalar($attrs['lost_reason_id']??'');
    $names=rdSyncPipelineNames($pdo,$pipelineId,$stageId);$status=rdSyncDealStatus(rdSyncScalar($attrs['status']??''));
    $fields=array_merge([
        'title'=>['source_key'=>'name','value'=>rdSyncScalar($attrs['name']??$attrs['title']??''),'truth'=>'rd'],
        'estimated_value'=>['source_key'=>'total_price','value'=>(float)($attrs['total_price']??$attrs['value']??0),'truth'=>'rd'],
        'status'=>['source_key'=>'status','value'=>$status,'truth'=>'rd'],
        'pipeline_name'=>['source_key'=>'pipeline_id','value'=>$names['pipeline'],'truth'=>'rd'],
        'stage_name'=>['source_key'=>'stage_id','value'=>$names['stage'],'truth'=>'rd'],
        'lost_reason'=>['source_key'=>'lost_reason_id','value'=>rdSyncDiscoveryName($pdo,'lost_reason',$lostId),'truth'=>'rd'],
        'assigned_to_user_id'=>['source_key'=>'owner_id','value'=>rdSyncOwnerLocalId($pdo,$ownerId),'truth'=>'rd'],
        'business_created_at'=>['source_key'=>'created_at','value'=>rdSyncScalar($attrs['created_at']??''),'truth'=>'rd'],
        'business_updated_at'=>['source_key'=>'updated_at','value'=>rdSyncScalar($attrs['updated_at']??''),'truth'=>'rd'],
        'closed_at'=>['source_key'=>'closed_at','value'=>rdSyncScalar($attrs['closed_at']??''),'truth'=>'rd'],
        'company_id'=>['source_key'=>'organization_id','value'=>(int)($company['id']??0),'truth'=>'rd'],
    ],rdSyncMappedValues($pdo,'crm','deal',$attrs,'negotiation'));

    if($linked){
        $local=$linked['local'];rdSyncTouchLink($pdo,'rd_crm','deal',$externalId);rdSyncUpdateLocal($pdo,$jobId,'crm','deal',$externalId,'negotiation',(int)$local['id'],$fields,$item,$counters);
        $neg=buscarEntidadePorDnbId($pdo,(string)$local['dnb_id']);++$counters['negotiations_updated'];
    }else{
        $title=trim((string)($fields['title']['value']??''));if($title==='')$title='Negociação RD '.$externalId;
        $neg=criarNegociacaoCrmCore($pdo,['title'=>$title,'company_id'=>(int)($company['id']??0),'category'=>$fields['category']['value']??'','pipeline_name'=>$fields['pipeline_name']['value']??'','stage_name'=>$fields['stage_name']['value']??'','estimated_value'=>$fields['estimated_value']['value']??0,'status'=>$status,'lost_reason'=>$fields['lost_reason']['value']??'','order_status'=>'none','assigned_to_user_id'=>(int)($fields['assigned_to_user_id']['value']??0),'origin'=>'rd_crm','business_created_at'=>rdSyncDateTime($fields['business_created_at']['value']??null),'business_updated_at'=>rdSyncDateTime($fields['business_updated_at']['value']??null),'closed_at'=>rdSyncDateTime($fields['closed_at']['value']??null)]);
        rdSyncRegisterLink($pdo,'rd_crm','deal',$externalId,'negotiation',(string)$neg['dnb_id'],$counters);
        rdSyncUpdateLocal($pdo,$jobId,'crm','deal',$externalId,'negotiation',(int)$neg['id'],$fields,$item,$counters);++$counters['negotiations_created'];
    }
    if(!$neg)return null;
    $contactIds=rdSyncRelationshipIds($item,'contacts');if(!$contactIds)$contactIds=rdSyncRelationshipIds($item,'contact');
    $first=true;foreach($contactIds as $rid){$ct=rdSyncLocalByExternal($pdo,'rd_crm','contact',$rid);if(!$ct)continue;try{vincularContatoNegociacaoCrm($pdo,(int)$neg['id'],(int)$ct['id'],$first?'principal':'outro',$first);}catch(Throwable){}$first=false;}
    return $neg;
}

function rdSyncFindMarketingBaseSegmentation(): ?string
{
    for($page=1;$page<=20;$page++){
        $res=rdSyncFetch('marketing','https://api.rd.services/platform/segmentations?page='.$page.'&page_size=125');
        $items=rdSyncList($res['json'],['segmentations','contacts','data','items']);
        foreach($items as $item){$a=rdDiscoveryAttrs($item);$name=mb_strtolower(trim((string)($a['name']??'')));if($name==='todos os contatos da base de leads')return trim((string)($a['id']??''));}
        if(count($items)<125)break;
    }
    return null;
}

/** @return array{done:bool,count:int,next:int} */
function rdSyncMarketingContactsPage(PDO $pdo,array $job,array &$state,array &$counters): array
{
    $jobId=(int)$job['id'];$seg=(string)($state['marketing_segmentation_id']??'');
    if($seg===''){
        $seg=rdSyncFindMarketingBaseSegmentation()??'';
        if($seg==='')throw new RuntimeException('Não encontrei a segmentação padrão "Todos os contatos da base de Leads" no RD Marketing.');
        $state['marketing_segmentation_id']=$seg;rdSyncLog($pdo,$jobId,'success','marketing','Segmentação base localizada. Iniciando leitura dos contatos.',['segmentation_id'=>$seg]);
        return ['done'=>false,'count'=>0,'next'=>1];
    }
    $page=max(1,(int)$job['page_number']);$pageSize=50;
    $url='https://api.rd.services/platform/segmentations/'.rawurlencode($seg).'/contacts?page='.$page.'&page_size='.$pageSize.'&order=last_conversion_date:asc';
    $res=rdSyncFetch('marketing',$url);$items=rdSyncList($res['json'],['contacts','data','items']);
    foreach($items as $item){
        $attrs=rdDiscoveryAttrs($item);
        $externalId=rdSyncExternalId($item);
        if($externalId===''){
            $email=crmCoreEmail(rdSyncScalar($attrs['email']??''));
            if($email!=='')$externalId='email:'.$email;
        }
        ++$counters['marketing_contacts_seen'];
        rdSyncUpsertContact($pdo,$jobId,'rd_marketing',$externalId,$attrs,$item,null,$counters);
    }
    ++$counters['pages'];rdSyncLog($pdo,$jobId,'info','marketing','Página '.$page.' processada: '.count($items).' contato(s).');
    return ['done'=>count($items)<$pageSize,'count'=>count($items),'next'=>$page+1];
}

/** @return array{done:bool,count:int,cursor:int} */
function rdSyncMarketingDetailsBatch(PDO $pdo,array $job,array &$state,array &$counters): array
{
    $jobId=(int)$job['id'];$cursor=max(0,(int)($state['marketing_detail_cursor']??0));$limit=10;
    $stmt=$pdo->prepare("SELECT l.id link_id,l.external_id,c.id contact_id,c.email,c.dnb_id
      FROM crm_external_links l JOIN crm_contacts c ON c.dnb_id=l.dnb_id
      WHERE l.provider='rd_marketing' AND l.external_entity='contact' AND l.id>?
      ORDER BY l.id ASC LIMIT {$limit}");
    $stmt->execute([$cursor]);$rows=$stmt->fetchAll();
    foreach($rows as $row){
        $identifier='';$value='';
        $email=crmCoreEmail((string)$row['email']);
        if($email!==''){$identifier='email';$value=$email;}
        else{
            $external=(string)$row['external_id'];
            if(str_starts_with($external,'email:')){$identifier='email';$value=substr($external,6);}
            elseif(preg_match('/^[0-9a-fA-F-]{32,36}$/',$external)){$identifier='uuid';$value=$external;}
        }
        if($identifier===''){
            ++$counters['skipped'];$cursor=(int)$row['link_id'];$state['marketing_detail_cursor']=$cursor;continue;
        }
        try{
            $res=rdSyncFetch('marketing','https://api.rd.services/platform/contacts/'.$identifier.':'.rawurlencode($value));
            $payload=$res['json']??[];$attrs=rdDiscoveryAttrs($payload);
            rdSyncUpsertContact($pdo,$jobId,'rd_marketing',(string)$row['external_id'],$attrs,$payload,null,$counters);
            ++$counters['marketing_contacts_detailed'];
        }catch(RdSyncRateLimitException $e){throw $e;}
        catch(Throwable $e){
            rdSyncLog($pdo,$jobId,'warning','marketing_detail','Contato '.$row['dnb_id'].' não pôde ser detalhado: '.$e->getMessage());
        }
        $cursor=(int)$row['link_id'];$state['marketing_detail_cursor']=$cursor;
    }
    $state['marketing_detail_cursor']=$cursor;
    ++$counters['pages'];
    rdSyncLog($pdo,$jobId,'info','marketing_detail','Detalhamento individual: '.count($rows).' contato(s) processado(s).');
    return ['done'=>count($rows)<$limit,'count'=>count($rows),'cursor'=>$cursor];
}

/** @return array{done:bool,count:int,next:int} */
function rdSyncCrmPage(PDO $pdo,array $job,string $resource,string $scope,callable $handler,array &$counters,string $counterKey,string $extraQuery=''): array
{
    $page=max(1,(int)$job['page_number']);$pageSize=100;$query='page[number]='.$page.'&page[size]='.$pageSize;if($extraQuery!=='')$query.='&'.$extraQuery;
    $res=rdSyncFetch('crm','https://api.rd.services/crm/v2/'.$resource.'?'.$query);$items=rdSyncList($res['json'],['data','items',$resource]);
    foreach($items as $item){++$counters[$counterKey];$handler($item);}
    ++$counters['pages'];$done=trim((string)($res['json']['links']['next']??''))==='';
    rdSyncLog($pdo,(int)$job['id'],'info',$scope,'Página '.$page.' processada: '.count($items).' registro(s).');
    return ['done'=>$done,'count'=>count($items),'next'=>$page+1];
}

function rdSyncProcessStep(PDO $pdo,int $jobId): array
{
    if(!rdSyncDisponivel($pdo))throw new RuntimeException('Fase 5 não instalada.');
    $lockName='dnb-rd-sync-'.$jobId;$stmt=$pdo->prepare('SELECT GET_LOCK(?,0)');$stmt->execute([$lockName]);
    if((int)$stmt->fetchColumn()!==1)return rdSyncJob($pdo,$jobId)??[];
    try{
        $job=rdSyncJob($pdo,$jobId);if(!$job)throw new RuntimeException('Job de sincronização não encontrado.');
        if(in_array($job['status'],['completed','error','cancelled'],true))return $job;
        $state=$job['state'];$counters=$job['counters'];if((int)($state['retry_after']??0)>time())return $job;$state['retry_after']=0;
        if($job['status']==='queued'){rdSyncSetJob($pdo,$jobId,'init',1,$state,$counters,'running');$job=rdSyncJob($pdo,$jobId)??$job;}
        try{
            switch((string)$job['stage']){
                case 'init':
                    if(!rdEstadoProduto('marketing')['connected']||!rdEstadoProduto('crm')['connected'])throw new RuntimeException('RD Marketing e RD CRM precisam estar conectados.');
                    rdSyncLog($pdo,$jobId,'info','job','Iniciando sincronização real. Nenhum Pedido será criado automaticamente.');rdSyncAdvance($pdo,$job,'marketing_contacts',$state,$counters);break;
                case 'marketing_contacts':
                    $r=rdSyncMarketingContactsPage($pdo,$job,$state,$counters);
                    if($r['done']){rdSyncLog($pdo,$jobId,'success','marketing','Listagem base concluída. Iniciando consulta detalhada para campos personalizados.');rdSyncAdvance($pdo,$job,'marketing_contact_details',$state,$counters);}else rdSyncSetJob($pdo,$jobId,'marketing_contacts',(int)$r['next'],$state,$counters);break;
                case 'marketing_contact_details':
                    $r=rdSyncMarketingDetailsBatch($pdo,$job,$state,$counters);
                    if($r['done']){rdSyncLog($pdo,$jobId,'info','marketing','Detalhamento concluído. O campo Empresa do Marketing não é disponibilizado por esta API; Empresas serão consolidadas pelo RD CRM ou por CNPJ explicitamente mapeado.');rdSyncAdvance($pdo,$job,'crm_organizations',$state,$counters);}else rdSyncSetJob($pdo,$jobId,'marketing_contact_details',1,$state,$counters);break;
                case 'crm_organizations':
                    $r=rdSyncCrmPage($pdo,$job,'organizations','organization',function(array $item)use($pdo,$jobId,&$counters){rdSyncUpsertCompany($pdo,$jobId,'rd_crm',rdSyncExternalId($item),rdDiscoveryAttrs($item),$item,$counters);},$counters,'crm_companies_seen','sort[updated_at]=asc');
                    if($r['done'])rdSyncAdvance($pdo,$job,'crm_contacts',$state,$counters);else rdSyncSetJob($pdo,$jobId,'crm_organizations',(int)$r['next'],$state,$counters);break;
                case 'crm_contacts':
                    $r=rdSyncCrmPage($pdo,$job,'contacts','contact',function(array $item)use($pdo,$jobId,&$counters){$attrs=rdDiscoveryAttrs($item);$orgId=rdSyncRelationshipIds($item,'organization')[0]??'';$company=$orgId?rdSyncLocalByExternal($pdo,'rd_crm','organization',$orgId):null;rdSyncUpsertContact($pdo,$jobId,'rd_crm',rdSyncExternalId($item),$attrs,$item,(int)($company['id']??0),$counters);},$counters,'crm_contacts_seen','sort[created_at]=asc');
                    if($r['done'])rdSyncAdvance($pdo,$job,'crm_deals',$state,$counters);else rdSyncSetJob($pdo,$jobId,'crm_contacts',(int)$r['next'],$state,$counters);break;
                case 'crm_deals':
                    $filter='filter='.rawurlencode('created_at:>="'.date('Y-m-d H:i:s',strtotime((string)$job['since_at'])).'"').'&sort[created_at]=asc';
                    $r=rdSyncCrmPage($pdo,$job,'deals','deal',function(array $item)use($pdo,$jobId,&$counters){rdSyncUpsertDeal($pdo,$jobId,rdSyncExternalId($item),$item,$counters);},$counters,'crm_deals_seen',$filter);
                    if($r['done'])rdSyncAdvance($pdo,$job,'finish',$state,$counters);else rdSyncSetJob($pdo,$jobId,'crm_deals',(int)$r['next'],$state,$counters);break;
                case 'finish':
                    rdSyncSetJob($pdo,$jobId,'done',1,$state,$counters,'completed');rdSyncLog($pdo,$jobId,'success','job','Primeira sincronização concluída. Revise os conflitos pendentes antes da automação recorrente.');break;
                default:throw new RuntimeException('Etapa de sincronização desconhecida: '.(string)$job['stage']);
            }
        }catch(RdSyncRateLimitException $e){
            $state['retry_after']=time()+60;rdSyncSetJob($pdo,$jobId,(string)$job['stage'],(int)$job['page_number'],$state,$counters,'running');rdSyncLog($pdo,$jobId,'warning','rate_limit','Limite temporário do RD. Nova tentativa automática após o intervalo de segurança.');
        }catch(Throwable $e){
            rdSyncSetJob($pdo,$jobId,(string)$job['stage'],(int)$job['page_number'],$state,$counters,'error',$e->getMessage());rdSyncLog($pdo,$jobId,'error',(string)$job['stage'],$e->getMessage());
        }
        return rdSyncJob($pdo,$jobId)??[];
    }finally{$stmt=$pdo->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute([$lockName]);}
}

function rdSyncCancelar(PDO $pdo,int $jobId): void
{
    $job=rdSyncJob($pdo,$jobId);if(!$job||in_array($job['status'],['completed','error','cancelled'],true))return;
    rdSyncSetJob($pdo,$jobId,(string)$job['stage'],(int)$job['page_number'],$job['state'],$job['counters'],'cancelled');rdSyncLog($pdo,$jobId,'warning','job','Sincronização cancelada pelo administrador.');
}


function rdSyncRetomar(PDO $pdo,int $jobId): void
{
    $job=rdSyncJob($pdo,$jobId);
    if(!$job)throw new RuntimeException('Job de sincronização não encontrado.');
    if($job['status']!=='error')return;
    $pdo->prepare("UPDATE rd_sync_jobs SET status='running',last_error=NULL,finished_at=NULL,heartbeat_at=NOW() WHERE id=?")->execute([$jobId]);
    rdSyncLog($pdo,$jobId,'info','job','Sincronização retomada do ponto em que parou.');
}
