<?php
declare(strict_types=1);

require_once __DIR__ . '/rd_phase8_service.php';

final class RdPhase9BlockedException extends RuntimeException {}
final class RdPhase9DependencyException extends RuntimeException {}
final class RdPhase9RateLimitException extends RuntimeException {
    public function __construct(public readonly int $retryAfter=60,string $message='Limite temporário do RD CRM.') { parent::__construct($message); }
}

function rdPhase9Disponivel(PDO $pdo): bool
{
    if(!rdPhase8Disponivel($pdo))return false;
    try{
        $n=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('rd_outbound_field_rules','rd_outbound_queue')")->fetchColumn();
        return $n===2;
    }catch(Throwable){return false;}
}

function rdPhase9AutoEnabled(): bool
{
    return integracaoConfigValor('DNB_RD_OUTBOUND_AUTO_ENABLED','0')==='1';
}

/** @return array<int,array<string,mixed>> */
function rdPhase9Rules(PDO $pdo,?string $entity=null): array
{
    if(!rdPhase9Disponivel($pdo))return [];
    if($entity!==null){
        $stmt=$pdo->prepare('SELECT * FROM rd_outbound_field_rules WHERE entity=? ORDER BY sort_order,id');$stmt->execute([$entity]);return $stmt->fetchAll();
    }
    return $pdo->query('SELECT * FROM rd_outbound_field_rules ORDER BY entity,sort_order,id')->fetchAll();
}

function rdPhase9Truth(PDO $pdo,string $entity,string $field,string $default='rd'): string
{
    if(!rdPhase9Disponivel($pdo))return $default;
    $stmt=$pdo->prepare('SELECT source_of_truth FROM rd_outbound_field_rules WHERE entity=? AND local_field=? AND enabled=1 LIMIT 1');
    $stmt->execute([$entity,$field]);$v=$stmt->fetchColumn();
    return in_array($v,['rd','dnb','manual_review'],true)?(string)$v:$default;
}

/** @param array<string,mixed> $rows */
function rdPhase9SaveRules(PDO $pdo,array $rows): int
{
    if(!rdPhase9Disponivel($pdo))throw new RuntimeException('Instale a migração da Fase 9.');
    $stmt=$pdo->prepare("UPDATE rd_outbound_field_rules SET source_of_truth=?,enabled=? WHERE id=?");
    $count=0;
    foreach($rows as $id=>$cfg){
        if(!is_array($cfg))continue;
        $truth=(string)($cfg['source_of_truth']??'rd');
        if(!in_array($truth,['rd','dnb','manual_review'],true))$truth='rd';
        $stmt->execute([$truth,!empty($cfg['enabled'])?1:0,(int)$id]);$count++;
    }
    return $count;
}

/** @return array<string,mixed>|null */
function rdPhase9Local(PDO $pdo,string $entity,string $dnbId): ?array
{
    $table=match($entity){'company'=>'crm_companies','contact'=>'crm_contacts','negotiation'=>'crm_negotiations',default=>''};
    if($table==='')return null;
    $stmt=$pdo->prepare("SELECT * FROM {$table} WHERE dnb_id=? LIMIT 1");$stmt->execute([$dnbId]);$row=$stmt->fetch();
    return $row?:null;
}

function rdPhase9ExternalId(PDO $pdo,string $entity,string $dnbId): string
{
    $remote=match($entity){'company'=>'organization','contact'=>'contact','negotiation'=>'deal',default=>''};
    if($remote==='')return '';
    $stmt=$pdo->prepare("SELECT external_id FROM crm_external_links WHERE provider='rd_crm' AND local_entity=? AND dnb_id=? AND external_entity=? AND COALESCE(remote_status,'active')<>'deleted' LIMIT 1");
    $stmt->execute([$entity,$dnbId,$remote]);return trim((string)($stmt->fetchColumn()?:''));
}

function rdPhase9EnqueueEntity(PDO $pdo,string $entity,string $dnbId,string $reason='alteracao_local',array $changedFields=[],int $userId=0,bool $force=false): int
{
    if(!rdPhase9Disponivel($pdo))return 0;
    if(!$force&&!rdPhase9AutoEnabled())return 0;
    if(!in_array($entity,['company','contact','negotiation'],true))throw new InvalidArgumentException('Entidade de saída inválida.');
    $dnbId=strtoupper(trim($dnbId));if($dnbId===''||!rdPhase9Local($pdo,$entity,$dnbId))return 0;

    $stmt=$pdo->prepare("SELECT id FROM rd_outbound_queue WHERE entity=? AND dnb_id=? AND status IN ('pending','processing') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$entity,$dnbId]);$existing=(int)($stmt->fetchColumn()?:0);
    $json=$changedFields?json_encode(array_values(array_unique(array_map('strval',$changedFields))),JSON_UNESCAPED_UNICODE):null;
    if($existing>0){
        $pdo->prepare("UPDATE rd_outbound_queue SET reason=?,changed_fields_json=COALESCE(?,changed_fields_json),requested_by_user_id=COALESCE(NULLIF(?,0),requested_by_user_id),available_at=LEAST(available_at,NOW()),updated_at=NOW() WHERE id=?")
            ->execute([mb_substr($reason,0,160),$json,$userId,$existing]);
        return $existing;
    }
    $stmt=$pdo->prepare("INSERT INTO rd_outbound_queue (entity,dnb_id,reason,changed_fields_json,requested_by_user_id) VALUES (?,?,?,?,NULLIF(?,0))");
    $stmt->execute([$entity,$dnbId,mb_substr($reason,0,160),$json,$userId]);return (int)$pdo->lastInsertId();
}

function rdPhase9EnqueueLocalId(PDO $pdo,string $entity,int $localId,string $reason='manual',int $userId=0,bool $force=true): int
{
    $table=match($entity){'company'=>'crm_companies','contact'=>'crm_contacts','negotiation'=>'crm_negotiations',default=>throw new InvalidArgumentException('Entidade inválida.')};
    $stmt=$pdo->prepare("SELECT dnb_id FROM {$table} WHERE id=?");$stmt->execute([$localId]);$dnbId=(string)($stmt->fetchColumn()?:'');
    if($dnbId==='')throw new RuntimeException('Registro DNB não encontrado.');
    return rdPhase9EnqueueEntity($pdo,$entity,$dnbId,$reason,[],$userId,$force);
}

function rdPhase9RdqlString(string $value): string
{
    return '"'.str_replace(['\\','"'],['\\\\','\\"'],$value).'"';
}

/** @return array<int,array<string,mixed>> */
function rdPhase9List(string $resource,string $filter,int $limit=3): array
{
    $url='https://api.rd.services/crm/v2/'.$resource.'?filter='.rawurlencode($filter).'&page[size]='.max(1,min(10,$limit));
    $res=rdApiRequest('crm','GET',$url);
    if((int)$res['status']===429)throw new RdPhase9RateLimitException();
    if((int)$res['status']<200||(int)$res['status']>=300||!is_array($res['json']))throw new RuntimeException(rdErroResposta($res,'Falha na busca preventiva do RD CRM'));
    return rdSyncList($res['json'],['data','items',$resource]);
}

function rdPhase9ResponseId(?array $json): string
{
    if(!$json)return '';
    $item=$json['data']??$json;
    if(is_array($item)){
        $attrs=rdDiscoveryAttrs($item);
        foreach(['id','uuid'] as $k)if(isset($attrs[$k])&&is_scalar($attrs[$k]))return trim((string)$attrs[$k]);
    }
    return '';
}

function rdPhase9CustomMappingForTarget(PDO $pdo,string $entity,string $targetField): ?array
{
    $sourceEntity=match($entity){'company'=>'organization','contact'=>'contact','negotiation'=>'deal',default=>''};
    if($sourceEntity==='')return null;
    $stmt=$pdo->prepare("SELECT * FROM rd_field_mappings WHERE product='crm' AND source_entity=? AND target_entity=? AND target_field=? AND source_of_truth='dnb' AND enabled=1 ORDER BY id LIMIT 1");
    $stmt->execute([$sourceEntity,$entity,$targetField]);$row=$stmt->fetch();return $row?:null;
}

/** @return array<string,mixed> */
function rdPhase9CustomFields(PDO $pdo,string $entity,array $local): array
{
    $sourceEntity=match($entity){'company'=>'organization','contact'=>'contact','negotiation'=>'deal',default=>''};
    if($sourceEntity==='')return [];
    $stmt=$pdo->prepare("SELECT source_key,target_field FROM rd_field_mappings WHERE product='crm' AND source_entity=? AND target_entity=? AND source_of_truth='dnb' AND enabled=1 ORDER BY id");
    $stmt->execute([$sourceEntity,$entity]);$out=[];
    foreach($stmt->fetchAll() as $m){
        $field=(string)$m['target_field'];if(!array_key_exists($field,$local))continue;
        $value=$local[$field];if($value===null||$value==='')continue;
        $out[(string)$m['source_key']]=$value;
    }
    return $out;
}

function rdPhase9MappedUser(PDO $pdo,int $localUserId): string
{
    if($localUserId<=0)return '';
    $stmt=$pdo->prepare("SELECT rd_user_external_id FROM rd_user_mappings WHERE local_user_id=? AND enabled=1 AND rd_user_active=1 ORDER BY id LIMIT 1");
    $stmt->execute([$localUserId]);return trim((string)($stmt->fetchColumn()?:''));
}

function rdPhase9MappedStage(PDO $pdo,string $pipeline,string $stage): string
{
    if($stage==='')return '';
    $stmt=$pdo->prepare("SELECT rd_stage_external_id FROM rd_pipeline_mappings WHERE enabled=1 AND pipeline_name=? AND stage_name=? ORDER BY id LIMIT 1");
    $stmt->execute([$pipeline,$stage]);return trim((string)($stmt->fetchColumn()?:''));
}

function rdPhase9PrimaryContactExternal(PDO $pdo,int $negotiationId): string
{
    $stmt=$pdo->prepare("SELECT ct.dnb_id FROM crm_negotiation_contacts nc JOIN crm_contacts ct ON ct.id=nc.contact_id WHERE nc.negotiation_id=? ORDER BY nc.is_primary DESC,nc.id ASC LIMIT 1");
    $stmt->execute([$negotiationId]);$dnb=(string)($stmt->fetchColumn()?:'');
    return $dnb!==''?rdPhase9ExternalId($pdo,'contact',$dnb):'';
}

function rdPhase9OrganizationExternal(PDO $pdo,?int $companyId): string
{
    if(!$companyId)return '';
    $stmt=$pdo->prepare('SELECT dnb_id FROM crm_companies WHERE id=?');$stmt->execute([$companyId]);$dnb=(string)($stmt->fetchColumn()?:'');
    return $dnb!==''?rdPhase9ExternalId($pdo,'company',$dnb):'';
}

/** @return array<string,mixed> */
function rdPhase9Payload(PDO $pdo,string $entity,array $local,bool $creating): array
{
    $rules=[];foreach(rdPhase9Rules($pdo,$entity) as $r)if(!empty($r['enabled'])&&$r['source_of_truth']==='dnb')$rules[(string)$r['local_field']]=$r;
    $data=[];

    if($entity==='company'){
        if(isset($rules['name'])||$creating)$data['name']=(string)$local['name'];
        if(isset($rules['website'])&&trim((string)$local['website'])!=='')$data['url']=(string)$local['website'];
        if(isset($rules['address'])&&trim((string)$local['address'])!=='')$data['address']=['line'=>(string)$local['address']];
    }elseif($entity==='contact'){
        if(isset($rules['name'])||$creating)$data['name']=(string)$local['name'];
        if(isset($rules['role_title'])&&trim((string)$local['role_title'])!=='')$data['job_title']=(string)$local['role_title'];
        if(isset($rules['email'])&&trim((string)$local['email'])!=='')$data['emails']=[['email'=>(string)$local['email']]];
        if(isset($rules['phone'])&&trim((string)$local['phone'])!=='')$data['phones']=[['phone'=>(string)$local['phone'],'type'=>'work']];
        if(isset($rules['company_id'])&&!empty($local['company_id'])){
            $org=rdPhase9OrganizationExternal($pdo,(int)$local['company_id']);if($org==='')throw new RdPhase9DependencyException('A Empresa do Contato ainda está aguardando vínculo com o RD CRM.');
            $data['organization_id']=$org;
        }
    }elseif($entity==='negotiation'){
        if(isset($rules['title'])||$creating)$data['name']=(string)$local['title'];
        if(isset($rules['estimated_value']))$data['total_price']=(float)$local['estimated_value'];
        if(isset($rules['company_id'])&&!empty($local['company_id'])){
            $org=rdPhase9OrganizationExternal($pdo,(int)$local['company_id']);if($org==='')throw new RdPhase9DependencyException('A Empresa da Negociação ainda está aguardando vínculo com o RD CRM.');
            $data['organization_id']=$org;
        }
        if(isset($rules['assigned_to_user_id'])&&!empty($local['assigned_to_user_id'])){
            $owner=rdPhase9MappedUser($pdo,(int)$local['assigned_to_user_id']);if($owner==='')throw new RdPhase9BlockedException('O responsável local ainda não está mapeado para um usuário do RD CRM.');
            $data['owner_id']=$owner;
        }
        if(isset($rules['stage_name'])){
            $stage=rdPhase9MappedStage($pdo,(string)$local['pipeline_name'],(string)$local['stage_name']);if($stage==='')throw new RdPhase9BlockedException('Funil/etapa da Negociação ainda não está mapeado para o RD CRM.');
            $data['stage_id']=$stage;
        }
        if(isset($rules['primary_contact'])){
            $stmt=$pdo->prepare('SELECT COUNT(*) FROM crm_negotiation_contacts WHERE negotiation_id=?');$stmt->execute([(int)$local['id']]);$hasContacts=(int)$stmt->fetchColumn()>0;
            $contact=rdPhase9PrimaryContactExternal($pdo,(int)$local['id']);
            if($contact!=='')$data['contact_id']=$contact;
            elseif($hasContacts)throw new RdPhase9DependencyException('O contato principal da Negociação ainda está aguardando vínculo com o RD CRM.');
        }
        if(!$creating&&isset($rules['status'])){
            $data['status']=match((string)$local['status']){'won'=>'won','lost'=>'lost',default=>'ongoing'};
        }
        if(!$creating&&isset($rules['lost_reason'])&&(string)$local['status']==='lost'&&trim((string)$local['lost_reason'])!==''){
            $stmt=$pdo->prepare("SELECT external_id FROM rd_discovery_items WHERE product='crm' AND resource_type='lost_reason' AND active=1 AND LOWER(TRIM(name))=LOWER(TRIM(?)) ORDER BY id LIMIT 1");
            $stmt->execute([(string)$local['lost_reason']]);$lost=(string)($stmt->fetchColumn()?:'');if($lost!=='')$data['lost_reason_id']=$lost;
        }
        if($creating)$data['status']='ongoing';
    }

    $custom=rdPhase9CustomFields($pdo,$entity,$local);if($custom)$data['custom_fields']=$custom;
    return ['data'=>$data];
}

function rdPhase9PreflightLink(PDO $pdo,string $entity,array $local): string
{
    if($entity==='company'){
        $cnpj=trim((string)($local['cnpj']??''));
        $map=$cnpj!==''?rdPhase9CustomMappingForTarget($pdo,'company','cnpj'):null;
        if($map){
            $items=rdPhase9List('organizations','@'.$map['source_key'].':'.rdPhase9RdqlString($cnpj),2);
            if(count($items)>1)throw new RdPhase9BlockedException('Mais de uma Empresa do RD corresponde ao CNPJ local. Revise antes de vincular.');
            if(count($items)===1)return rdSyncExternalId($items[0]);
        }
        $name=trim((string)$local['name']);if($name!==''){
            $items=rdPhase9List('organizations','name:'.rdPhase9RdqlString($name),2);
            if(count($items)===1)return rdSyncExternalId($items[0]);
        }
    }elseif($entity==='contact'){
        $email=crmCoreEmail((string)($local['email']??''));
        if($email!==''){
            $items=rdPhase9List('contacts','email:'.rdPhase9RdqlString($email),2);
            if(count($items)>1)throw new RdPhase9BlockedException('Mais de um Contato do RD corresponde ao e-mail local. Revise antes de vincular.');
            if(count($items)===1)return rdSyncExternalId($items[0]);
        }
    }
    return '';
}

function rdPhase9RegisterLink(PDO $pdo,string $entity,string $dnbId,string $externalId): void
{
    $remote=match($entity){'company'=>'organization','contact'=>'contact','negotiation'=>'deal'};
    $counters=rdSyncCounterDefault();
    if(!rdSyncCanLinkLocal($pdo,'rd_crm',$entity,$dnbId,$externalId))throw new RdPhase9BlockedException('Este registro DNB já possui outro vínculo do RD CRM.');
    rdSyncRegisterLink($pdo,'rd_crm',$remote,$externalId,$entity,$dnbId,$counters);
}

function rdPhase9EnsureDependenciesQueued(PDO $pdo,string $entity,array $local,int $userId): void
{
    if($entity==='contact'&&!empty($local['company_id'])){
        $stmt=$pdo->prepare('SELECT dnb_id FROM crm_companies WHERE id=?');$stmt->execute([(int)$local['company_id']]);$dnb=(string)($stmt->fetchColumn()?:'');
        if($dnb!==''&&rdPhase9ExternalId($pdo,'company',$dnb)==='')rdPhase9EnqueueEntity($pdo,'company',$dnb,'dependencia_contato',[],$userId,true);
    }
    if($entity==='negotiation'){
        if(!empty($local['company_id'])){
            $stmt=$pdo->prepare('SELECT dnb_id FROM crm_companies WHERE id=?');$stmt->execute([(int)$local['company_id']]);$dnb=(string)($stmt->fetchColumn()?:'');
            if($dnb!==''&&rdPhase9ExternalId($pdo,'company',$dnb)==='')rdPhase9EnqueueEntity($pdo,'company',$dnb,'dependencia_negociacao',[],$userId,true);
        }
        $stmt=$pdo->prepare("SELECT ct.dnb_id FROM crm_negotiation_contacts nc JOIN crm_contacts ct ON ct.id=nc.contact_id WHERE nc.negotiation_id=? ORDER BY nc.is_primary DESC,nc.id ASC");
        $stmt->execute([(int)$local['id']]);foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $dnb)if(rdPhase9ExternalId($pdo,'contact',(string)$dnb)==='')rdPhase9EnqueueEntity($pdo,'contact',(string)$dnb,'dependencia_negociacao',[],$userId,true);
    }
}

/** @return array{status:int,external_id:string,operation:string} */
function rdPhase9Send(PDO $pdo,string $entity,string $dnbId,int $userId=0): array
{
    if(!rdEstadoProduto('crm')['connected'])throw new RuntimeException('RD CRM não está conectado.');
    $local=rdPhase9Local($pdo,$entity,$dnbId);if(!$local)throw new RuntimeException('Registro DNB não encontrado.');
    if(in_array($entity,['company','contact'],true)&&($local['status']??'active')!=='active')throw new RdPhase9BlockedException('Registro local inativo não é enviado automaticamente ao RD.');
    if(in_array($entity,['company','contact'],true)&&($local['review_status']??'ok')==='needs_review')throw new RdPhase9BlockedException('Registro está marcado para revisão de duplicidade e não será enviado ao RD.');

    rdPhase9EnsureDependenciesQueued($pdo,$entity,$local,$userId);
    $external=rdPhase9ExternalId($pdo,$entity,$dnbId);
    if($external==='')$external=rdPhase9PreflightLink($pdo,$entity,$local);
    if($external!=='')rdPhase9RegisterLink($pdo,$entity,$dnbId,$external);

    $creating=$external==='';
    $resource=match($entity){'company'=>'organizations','contact'=>'contacts','negotiation'=>'deals'};
    $payload=rdPhase9Payload($pdo,$entity,$local,$creating);
    $data=$payload['data']??[];if(!is_array($data)||!$data)throw new RdPhase9BlockedException('Nenhum campo desta entidade está configurado como propriedade do CRM DNB.');
    $url='https://api.rd.services/crm/v2/'.$resource.($creating?'':'/'.rawurlencode($external));
    $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $res=rdApiRequest('crm',$creating?'POST':'PUT',$url,$body===false?'{}':$body);
    if((int)$res['status']===429)throw new RdPhase9RateLimitException(60);
    if((int)$res['status']<200||(int)$res['status']>=300)throw new RuntimeException(rdErroResposta($res,'RD CRM recusou a saída do CRM DNB'));

    if($creating){
        $external=rdPhase9ResponseId($res['json']);
        if($external==='')throw new RuntimeException('RD CRM criou o registro, mas não retornou o ID necessário para vinculação segura.');
        rdPhase9RegisterLink($pdo,$entity,$dnbId,$external);
    }else{
        $remote=match($entity){'company'=>'organization','contact'=>'contact','negotiation'=>'deal'};
        rdSyncTouchLink($pdo,'rd_crm',$remote,$external);
    }
    return ['status'=>(int)$res['status'],'external_id'=>$external,'operation'=>$creating?'create':'update'];
}

/** @return array{processed:int,success:int,error:int,blocked:int,retry:int} */
function rdPhase9ProcessQueue(PDO $pdo,int $limit=10,bool $force=false): array
{
    if(!rdPhase9Disponivel($pdo)||(!$force&&!rdPhase9AutoEnabled()))return ['processed'=>0,'success'=>0,'error'=>0,'blocked'=>0,'retry'=>0];
    $limit=max(1,min(50,$limit));$summary=['processed'=>0,'success'=>0,'error'=>0,'blocked'=>0,'retry'=>0];
    for($i=0;$i<$limit;$i++){
        $pdo->beginTransaction();
        try{
            $row=$pdo->query("SELECT * FROM rd_outbound_queue WHERE status IN ('pending','error') AND attempts<5 AND available_at<=NOW() ORDER BY CASE entity WHEN 'company' THEN 1 WHEN 'contact' THEN 2 ELSE 3 END,id ASC LIMIT 1 FOR UPDATE")->fetch();
            if(!$row){$pdo->commit();break;}
            $id=(int)$row['id'];
            $pdo->prepare("UPDATE rd_outbound_queue SET status='processing',attempts=attempts+1,started_at=NOW(),last_error=NULL WHERE id=?")->execute([$id]);
            $pdo->commit();
            $summary['processed']++;
            try{
                $result=rdPhase9Send($pdo,(string)$row['entity'],(string)$row['dnb_id'],(int)($row['requested_by_user_id']??0));
                $pdo->prepare("UPDATE rd_outbound_queue SET status='success',external_id=?,last_http_status=?,last_error=NULL,processed_at=NOW() WHERE id=?")->execute([$result['external_id'],$result['status'],$id]);
                $summary['success']++;
            }catch(RdPhase9RateLimitException $e){
                $pdo->prepare("UPDATE rd_outbound_queue SET status='pending',available_at=DATE_ADD(NOW(),INTERVAL ? SECOND),last_http_status=429,last_error=? WHERE id=?")->execute([$e->retryAfter,mb_substr($e->getMessage(),0,500),$id]);
                $summary['retry']++;break;
            }catch(RdPhase9DependencyException $e){
                $pdo->prepare("UPDATE rd_outbound_queue SET status='pending',attempts=GREATEST(attempts-1,0),available_at=DATE_ADD(NOW(),INTERVAL 15 SECOND),last_error=? WHERE id=?")->execute([mb_substr($e->getMessage(),0,500),$id]);
                $summary['retry']++;
            }catch(RdPhase9BlockedException $e){
                $pdo->prepare("UPDATE rd_outbound_queue SET status='blocked',last_error=?,processed_at=NOW() WHERE id=?")->execute([mb_substr($e->getMessage(),0,500),$id]);
                $summary['blocked']++;
            }catch(Throwable $e){
                $stmt=$pdo->prepare("UPDATE rd_outbound_queue SET status=IF(attempts>=5,'error','pending'),available_at=DATE_ADD(NOW(),INTERVAL 60 SECOND),last_error=?,processed_at=IF(attempts>=5,NOW(),NULL) WHERE id=?");
                $stmt->execute([mb_substr($e->getMessage(),0,500),$id]);$summary['error']++;
            }
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();throw $e;
        }
    }
    return $summary;
}

/** @return array<string,int> */
function rdPhase9Stats(PDO $pdo): array
{
    if(!rdPhase9Disponivel($pdo))return ['pending'=>0,'processing'=>0,'success'=>0,'error'=>0,'blocked'=>0];
    $out=['pending'=>0,'processing'=>0,'success'=>0,'error'=>0,'blocked'=>0];
    foreach($pdo->query("SELECT status,COUNT(*) c FROM rd_outbound_queue GROUP BY status")->fetchAll() as $r)$out[(string)$r['status']]=(int)$r['c'];
    return $out;
}

/** @return array<int,array<string,mixed>> */
function rdPhase9RecentQueue(PDO $pdo,int $limit=40): array
{
    if(!rdPhase9Disponivel($pdo))return [];
    $limit=max(1,min(100,$limit));
    return $pdo->query("SELECT q.*,u.nome requested_by_name FROM rd_outbound_queue q LEFT JOIN usuarios u ON u.id=q.requested_by_user_id ORDER BY q.id DESC LIMIT {$limit}")->fetchAll();
}
