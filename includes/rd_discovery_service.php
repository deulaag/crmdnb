<?php
declare(strict_types=1);

require_once __DIR__ . '/rd_station_service.php';

function rdDiscoveryDisponivel(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('rd_discovery_runs','rd_discovery_items','rd_field_mappings','rd_user_mappings','rd_pipeline_mappings')");
        return $cache = ((int)$stmt->fetchColumn() === 5);
    } catch (Throwable) {
        return $cache = false;
    }
}

/** @return array<int,array<string,mixed>> */
function rdDiscoveryExtractList(?array $json): array
{
    if (!$json) return [];
    $data = $json['data'] ?? $json['items'] ?? $json['fields'] ?? $json;
    if (!is_array($data)) return [];
    if (array_is_list($data)) return array_values(array_filter($data, 'is_array'));
    return isset($data['id']) || isset($data['uuid']) || isset($data['api_identifier']) ? [$data] : [];
}

/** @return array<string,mixed> */
function rdDiscoveryAttrs(array $item): array
{
    $attrs = $item['attributes'] ?? null;
    if (!is_array($attrs)) $attrs = [];
    return array_merge($item, $attrs);
}

function rdDiscoveryLabel(mixed $value): string
{
    if (is_scalar($value)) return trim((string)$value);
    if (!is_array($value)) return '';
    foreach (['pt-BR','pt_BR','default','label','name'] as $k) {
        if (isset($value[$k]) && is_scalar($value[$k]) && trim((string)$value[$k]) !== '') return trim((string)$value[$k]);
    }
    foreach ($value as $v) {
        if (is_scalar($v) && trim((string)$v) !== '') return trim((string)$v);
    }
    return '';
}

function rdDiscoveryId(array $item, string $fallbackSeed): string
{
    $a = rdDiscoveryAttrs($item);
    foreach (['id','uuid','slug','api_identifier'] as $k) {
        $v = trim((string)($a[$k] ?? ''));
        if ($v !== '') return mb_substr($v, 0, 191);
    }
    return 'auto-' . substr(hash('sha256', $fallbackSeed . '|' . json_encode($item, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)), 0, 32);
}

function rdDiscoveryNome(array $item): string
{
    $a = rdDiscoveryAttrs($item);
    foreach (['name','label','title','email'] as $k) {
        $v = rdDiscoveryLabel($a[$k] ?? '');
        if ($v !== '') return mb_substr($v, 0, 255);
    }
    return '';
}

function rdDiscoveryApiIdentifier(array $item): string
{
    $a = rdDiscoveryAttrs($item);
    foreach (['api_identifier','slug','key'] as $k) {
        $v = trim((string)($a[$k] ?? ''));
        if ($v !== '') return mb_substr($v, 0, 191);
    }
    return '';
}

function rdDiscoveryEntity(array $item): string
{
    $a = rdDiscoveryAttrs($item);
    foreach (['entity','for','entity_type','resource'] as $k) {
        $v = trim((string)($a[$k] ?? ''));
        if ($v !== '') return mb_substr($v, 0, 60);
    }
    return '';
}

function rdDiscoveryDataType(array $item): string
{
    $a = rdDiscoveryAttrs($item);
    foreach (['data_type','field_type','type'] as $k) {
        $v = trim((string)($a[$k] ?? ''));
        if ($v !== '') return mb_substr($v, 0, 80);
    }
    return '';
}

function rdDiscoverySortOrder(array $item): int
{
    $a = rdDiscoveryAttrs($item);
    foreach (['order','position','sort_order'] as $k) {
        if (isset($a[$k]) && is_numeric($a[$k])) return (int)$a[$k];
    }
    return 0;
}

function rdDiscoveryIsCustom(array $item): bool
{
    $a = rdDiscoveryAttrs($item);
    if (array_key_exists('custom_field', $a)) return (bool)$a['custom_field'];
    if (array_key_exists('is_custom', $a)) return (bool)$a['is_custom'];
    return false;
}

/** @return array{0:int,1:int} */
function rdDiscoveryCreateRun(PDO $pdo, string $product): array
{
    if (!rdDiscoveryDisponivel($pdo)) throw new RuntimeException('Instale a migração da Fase 4.');
    $userId = (int)(usuarioLogado()['id'] ?? 0);
    $stmt = $pdo->prepare("INSERT INTO rd_discovery_runs (product,status,executed_by_user_id) VALUES (?,'running',NULLIF(?,0))");
    $stmt->execute([$product,$userId]);
    return [(int)$pdo->lastInsertId(),$userId];
}

/** @param array<string,mixed> $summary */
function rdDiscoveryFinishRun(PDO $pdo, int $runId, string $status, int $total, array $summary, string $error=''): void
{
    if (!in_array($status,['success','partial','error'],true)) $status='error';
    $stmt=$pdo->prepare('UPDATE rd_discovery_runs SET status=?,finished_at=NOW(),total_items=?,summary_json=?,error_message=NULLIF(?,\'\') WHERE id=?');
    $stmt->execute([$status,$total,json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),mb_substr($error,0,500),$runId]);
}

function rdDiscoveryDeactivateType(PDO $pdo,string $product,string $resourceType): void
{
    $pdo->prepare('UPDATE rd_discovery_items SET active=0 WHERE product=? AND resource_type=?')->execute([$product,$resourceType]);
}

/** @param array<string,mixed> $item */
function rdDiscoveryUpsert(PDO $pdo,int $runId,string $product,string $resourceType,array $item,string $parentId='',?bool $selectedDefault=null): int
{
    $id=rdDiscoveryId($item,$product.'|'.$resourceType.'|'.$parentId);
    $name=rdDiscoveryNome($item);
    $api=rdDiscoveryApiIdentifier($item);
    $entity=rdDiscoveryEntity($item);
    $dataType=rdDiscoveryDataType($item);
    $isCustom=rdDiscoveryIsCustom($item)?1:0;
    $order=rdDiscoverySortOrder($item);
    $selected=$selectedDefault===true?1:0;
    $payload=json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $stmt=$pdo->prepare("INSERT INTO rd_discovery_items
      (product,resource_type,external_id,parent_external_id,entity_type,name,api_identifier,data_type,is_custom,sort_order,active,selected_for_sync,payload_json,last_run_id,discovered_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?,?,NOW())
      ON DUPLICATE KEY UPDATE entity_type=VALUES(entity_type),name=VALUES(name),api_identifier=VALUES(api_identifier),data_type=VALUES(data_type),is_custom=VALUES(is_custom),sort_order=VALUES(sort_order),active=1,payload_json=VALUES(payload_json),last_run_id=VALUES(last_run_id),discovered_at=NOW()");
    $stmt->execute([$product,$resourceType,$id,mb_substr($parentId,0,191),$entity,$name,$api,$dataType,$isCustom,$order,$selected,$payload,$runId]);
    $stmt=$pdo->prepare('SELECT id FROM rd_discovery_items WHERE product=? AND resource_type=? AND external_id=? AND parent_external_id=?');
    $stmt->execute([$product,$resourceType,$id,mb_substr($parentId,0,191)]);
    return (int)$stmt->fetchColumn();
}

/** @return array<int,array<string,mixed>> */
function rdDiscoveryCrmFetchAll(string $path,int $maxPages=100): array
{
    $base='https://api.rd.services/crm/v2/';
    $url=str_starts_with($path,'https://')?$path:$base.ltrim($path,'/');
    $items=[];$pages=0;
    while($url!==''&&$pages<$maxPages){
        $pages++;
        $res=rdApiRequest('crm','GET',$url);
        if((int)$res['status']<200||(int)$res['status']>=300||!is_array($res['json'])){
            throw new RuntimeException(rdErroResposta($res,'Falha ao descobrir metadados do RD CRM'));
        }
        foreach(rdDiscoveryExtractList($res['json']) as $item)$items[]=$item;
        $next=trim((string)($res['json']['links']['next']??''));
        if($next===''||$next===$url){$url='';break;}
        $parts=parse_url($next);
        if(($parts['scheme']??'')!=='https'||strtolower((string)($parts['host']??''))!=='api.rd.services')throw new RuntimeException('Paginação RD retornou endpoint não permitido.');
        $url=$next;
    }
    if($pages>=$maxPages&&$url!=='')throw new RuntimeException('A descoberta atingiu o limite de paginação de segurança.');
    return $items;
}

/** @return array<string,mixed> */
function rdDiscoveryRunMarketing(PDO $pdo): array
{
    [$runId]=rdDiscoveryCreateRun($pdo,'marketing');
    $summary=[];$total=0;
    try{
        $res=rdApiRequest('marketing','GET','https://api.rd.services/platform/contacts/fields');
        if((int)$res['status']<200||(int)$res['status']>=300||!is_array($res['json']))throw new RuntimeException(rdErroResposta($res,'Falha ao consultar campos do RD Marketing'));
        $fields=rdDiscoveryExtractList($res['json']);
        rdDiscoveryDeactivateType($pdo,'marketing','contact_field');
        $defaultSelected=['email','name','job_title','personal_phone','mobile_phone','company_name','company_site','company_address'];
        foreach($fields as $field){
            $api=rdDiscoveryApiIdentifier($field);
            rdDiscoveryUpsert($pdo,$runId,'marketing','contact_field',$field,'',in_array($api,$defaultSelected,true));
            $total++;
        }
        $summary=['contact_field'=>$total];
        rdDiscoveryFinishRun($pdo,$runId,'success',$total,$summary);
        return ['ok'=>true,'run_id'=>$runId,'total'=>$total,'summary'=>$summary];
    }catch(Throwable $e){
        rdDiscoveryFinishRun($pdo,$runId,'error',$total,$summary,$e->getMessage());
        throw $e;
    }
}

/** @return array<string,mixed> */
function rdDiscoveryRunCrm(PDO $pdo): array
{
    [$runId]=rdDiscoveryCreateRun($pdo,'crm');
    $resources=[
        'user'=>'users?page[size]=100',
        'pipeline'=>'pipelines?page[size]=100&sort[order]=asc',
        'custom_field'=>'custom_fields?page[size]=100&sort[order]=asc',
        'source'=>'sources?page[size]=100&sort[name]=asc',
        'lost_reason'=>'lost_reasons?page[size]=100&sort[name]=asc',
        'segment'=>'segments?page[size]=100&sort[name]=asc',
        'campaign'=>'campaigns?page[size]=100&sort[name]=asc',
    ];
    $summary=[];$total=0;$errors=[];
    try{
        $pipelineItems=[];$pipelinesFetched=false;
        foreach($resources as $type=>$path){
            try{
                $items=rdDiscoveryCrmFetchAll($path);
                rdDiscoveryDeactivateType($pdo,'crm',$type);
                foreach($items as $item){
                    rdDiscoveryUpsert($pdo,$runId,'crm',$type,$item,'',$type==='pipeline');
                    $total++;
                    if($type==='pipeline')$pipelineItems[]=$item;
                }
                $summary[$type]=count($items);
                if($type==='pipeline')$pipelinesFetched=true;
            }catch(Throwable $e){
                $summary[$type]=0;
                $errors[]=$type.': '.$e->getMessage();
            }
        }

        if($pipelinesFetched){
            $pdo->exec("UPDATE rd_discovery_items s LEFT JOIN rd_discovery_items p ON p.product='crm' AND p.resource_type='pipeline' AND p.external_id=s.parent_external_id AND p.active=1 SET s.active=0 WHERE s.product='crm' AND s.resource_type='stage' AND p.id IS NULL");
        }
        $stageCount=0;
        foreach($pipelineItems as $pipeline){
            $pipelineId=rdDiscoveryId($pipeline,'crm|pipeline');
            try{
                $stages=rdDiscoveryCrmFetchAll('pipelines/'.rawurlencode($pipelineId).'/stages?page[size]=100&sort[order]=asc');
                $pdo->prepare("UPDATE rd_discovery_items SET active=0 WHERE product='crm' AND resource_type='stage' AND parent_external_id=?")->execute([$pipelineId]);
                foreach($stages as $stage){
                    rdDiscoveryUpsert($pdo,$runId,'crm','stage',$stage,$pipelineId,true);
                    $stageCount++;$total++;
                }
            }catch(Throwable $e){
                $errors[]='stages('.$pipelineId.'): '.$e->getMessage();
            }
        }
        $summary['stage']=$stageCount;

        rdDiscoveryRefreshDerivedMappings($pdo);
        $status=$errors?($total>0?'partial':'error'):'success';
        rdDiscoveryFinishRun($pdo,$runId,$status,$total,$summary,implode(' | ',$errors));
        return ['ok'=>$status!=='error','run_id'=>$runId,'status'=>$status,'total'=>$total,'summary'=>$summary,'errors'=>$errors];
    }catch(Throwable $e){
        rdDiscoveryFinishRun($pdo,$runId,'error',$total,$summary,$e->getMessage());
        throw $e;
    }
}

function rdDiscoveryRefreshDerivedMappings(PDO $pdo): void
{
    // Usuários: preserva o vínculo local escolhido e atualiza apenas os metadados remotos.
    $users=$pdo->query("SELECT external_id,name,payload_json FROM rd_discovery_items WHERE product='crm' AND resource_type='user' AND active=1")->fetchAll();
    $stmtUser=$pdo->prepare("INSERT INTO rd_user_mappings (rd_user_external_id,rd_user_name,rd_user_email,rd_user_active,enabled)
      VALUES (?,?,?,?,1)
      ON DUPLICATE KEY UPDATE rd_user_name=VALUES(rd_user_name),rd_user_email=VALUES(rd_user_email),rd_user_active=VALUES(rd_user_active)");
    foreach($users as $u){
        $payload=json_decode((string)$u['payload_json'],true)?:[];
        $a=rdDiscoveryAttrs($payload);
        $email=trim((string)($a['email']??''));
        $active=1;
        if(isset($a['active']))$active=(bool)$a['active']?1:0;
        elseif(isset($a['status']))$active=in_array(strtolower((string)$a['status']),['active','enabled','ativo'],true)?1:0;
        $stmtUser->execute([$u['external_id'],$u['name'],$email,$active]);
    }
    $pdo->exec("UPDATE rd_user_mappings m JOIN usuarios u ON LOWER(TRIM(u.email))=LOWER(TRIM(m.rd_user_email)) SET m.local_user_id=u.id WHERE m.local_user_id IS NULL AND TRIM(m.rd_user_email)<>'' AND u.ativo=1");

    // Funis/etapas: nomes do RD viram os nomes locais por padrão. IDs externos ficam só nesta tabela.
    $pipelines=[];
    foreach($pdo->query("SELECT external_id,name,sort_order FROM rd_discovery_items WHERE product='crm' AND resource_type='pipeline' AND active=1")->fetchAll() as $p)$pipelines[$p['external_id']]=$p;
    $stages=$pdo->query("SELECT external_id,parent_external_id,name,sort_order FROM rd_discovery_items WHERE product='crm' AND resource_type='stage' AND active=1 ORDER BY parent_external_id,sort_order,name")->fetchAll();
    $pdo->exec("UPDATE rd_pipeline_mappings m LEFT JOIN rd_discovery_items d ON d.product='crm' AND d.resource_type='stage' AND d.external_id=m.rd_stage_external_id AND d.parent_external_id=m.rd_pipeline_external_id AND d.active=1 SET m.enabled=0 WHERE d.id IS NULL");
    $stmtPipe=$pdo->prepare("INSERT INTO rd_pipeline_mappings (rd_pipeline_external_id,rd_stage_external_id,pipeline_name,stage_name,enabled,sort_order)
      VALUES (?,?,?,?,1,?)
      ON DUPLICATE KEY UPDATE pipeline_name=VALUES(pipeline_name),stage_name=VALUES(stage_name),sort_order=VALUES(sort_order)");
    foreach($stages as $s){
        $p=$pipelines[$s['parent_external_id']]??null;
        if(!$p)continue;
        $stmtPipe->execute([$p['external_id'],$s['external_id'],$p['name'],$s['name'],(int)$s['sort_order']]);
    }
}

/** @return array<int,array<string,mixed>> */
function rdDiscoveryItems(PDO $pdo,string $product,string $resourceType,bool $onlyActive=true): array
{
    if(!rdDiscoveryDisponivel($pdo))return [];
    $sql='SELECT * FROM rd_discovery_items WHERE product=? AND resource_type=?'.($onlyActive?' AND active=1':'').' ORDER BY sort_order,name,id';
    $stmt=$pdo->prepare($sql);$stmt->execute([$product,$resourceType]);return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function rdDiscoveryRecentRuns(PDO $pdo,int $limit=12): array
{
    if(!rdDiscoveryDisponivel($pdo))return [];
    $limit=max(1,min(50,$limit));
    return $pdo->query("SELECT r.*,u.nome executed_by_name FROM rd_discovery_runs r LEFT JOIN usuarios u ON u.id=r.executed_by_user_id ORDER BY r.id DESC LIMIT {$limit}")->fetchAll();
}

/** @return array<string,array<string,string>> */
function rdLocalTargetFields(): array
{
    return [
        'contact'=>[
            'name'=>'Nome','email'=>'E-mail','phone'=>'Telefone','role_title'=>'Cargo',
        ],
        'company'=>[
            'name'=>'Razão social / nome','trade_name'=>'Nome fantasia','cnpj'=>'CNPJ','primary_email'=>'E-mail','phone'=>'Telefone','website'=>'Site','address'=>'Endereço','locality'=>'Localidade',
        ],
        'negotiation'=>[
            'title'=>'Título','category'=>'Categoria','pipeline_name'=>'Funil','stage_name'=>'Etapa','estimated_value'=>'Valor estimado','status'=>'Status','lost_reason'=>'Motivo de perda','business_created_at'=>'Data de criação','business_updated_at'=>'Data de atualização','closed_at'=>'Data de fechamento',
        ],
    ];
}

function rdTargetEntityForSource(string $sourceEntity): string
{
    return match(strtolower($sourceEntity)){
        'organization','company'=>'company',
        'deal','negotiation'=>'negotiation',
        default=>'contact',
    };
}

/** @return array<string,array<string,mixed>> */
function rdFieldMappingsIndex(PDO $pdo): array
{
    if(!rdDiscoveryDisponivel($pdo))return [];
    $out=[];
    foreach($pdo->query('SELECT * FROM rd_field_mappings ORDER BY product,source_entity,source_label')->fetchAll() as $row){
        $out[$row['product'].'|'.$row['source_entity'].'|'.$row['source_key']]=$row;
    }
    return $out;
}

/** @param array<string,mixed> $rows */
function rdSalvarFieldMappings(PDO $pdo,array $rows): int
{
    if(!rdDiscoveryDisponivel($pdo))throw new RuntimeException('Instale a migração da Fase 4.');
    $targets=rdLocalTargetFields();$count=0;
    $stmt=$pdo->prepare("INSERT INTO rd_field_mappings (product,source_entity,source_key,source_label,target_entity,target_field,source_of_truth,enabled)
      VALUES (?,?,?,?,?,?,?,1)
      ON DUPLICATE KEY UPDATE source_label=VALUES(source_label),target_entity=VALUES(target_entity),target_field=VALUES(target_field),source_of_truth=VALUES(source_of_truth),enabled=1");
    $del=$pdo->prepare('DELETE FROM rd_field_mappings WHERE product=? AND source_entity=? AND source_key=?');
    foreach($rows as $row){
        if(!is_array($row))continue;
        $product=(string)($row['product']??'');if(!in_array($product,['marketing','crm'],true))continue;
        $sourceEntity=mb_substr(trim((string)($row['source_entity']??'contact')),0,60);
        $sourceKey=mb_substr(trim((string)($row['source_key']??'')),0,191);if($sourceKey==='')continue;
        if($product==='marketing'){
            $check=$pdo->prepare("SELECT name FROM rd_discovery_items WHERE product='marketing' AND resource_type='contact_field' AND active=1 AND (api_identifier=? OR external_id=?) LIMIT 1");
            $check->execute([$sourceKey,$sourceKey]);
        }else{
            $check=$pdo->prepare("SELECT name FROM rd_discovery_items WHERE product='crm' AND resource_type='custom_field' AND active=1 AND entity_type=? AND (api_identifier=? OR external_id=?) LIMIT 1");
            $check->execute([$sourceEntity,$sourceKey,$sourceKey]);
        }
        $discoveredLabel=$check->fetchColumn();
        if($discoveredLabel===false)continue;
        $targetRaw=trim((string)($row['target']??''));
        if($targetRaw===''){$del->execute([$product,$sourceEntity,$sourceKey]);continue;}
        if(!str_contains($targetRaw,'.'))continue;
        [$targetEntity,$targetField]=explode('.',$targetRaw,2);
        if(!isset($targets[$targetEntity][$targetField]))continue;
        $truth=(string)($row['source_of_truth']??'rd');if(!in_array($truth,['rd','dnb','manual_review'],true))$truth='rd';
        $label=mb_substr(trim((string)$discoveredLabel),0,255);
        $stmt->execute([$product,$sourceEntity,$sourceKey,$label,$targetEntity,$targetField,$truth]);$count++;
    }
    return $count;
}

/** @param array<int|string,mixed> $selectedIds */
function rdSalvarSelecaoMarketing(PDO $pdo,array $selectedIds): void
{
    $ids=array_values(array_unique(array_filter(array_map('intval',$selectedIds),fn($v)=>$v>0)));
    $pdo->exec("UPDATE rd_discovery_items SET selected_for_sync=0 WHERE product='marketing' AND resource_type='contact_field'");
    if(!$ids)return;
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $stmt=$pdo->prepare("UPDATE rd_discovery_items SET selected_for_sync=1 WHERE product='marketing' AND resource_type='contact_field' AND id IN ({$marks})");
    $stmt->execute($ids);
}

/** @param array<string,mixed> $mappings */
function rdSalvarUserMappings(PDO $pdo,array $mappings): int
{
    $count=0;
    $stmt=$pdo->prepare('UPDATE rd_user_mappings SET local_user_id=NULLIF(?,0),enabled=? WHERE rd_user_external_id=?');
    foreach($mappings as $externalId=>$cfg){
        if(!is_array($cfg))continue;
        $local=(int)($cfg['local_user_id']??0);
        $enabled=!empty($cfg['enabled'])?1:0;
        $stmt->execute([$local,$enabled,mb_substr((string)$externalId,0,191)]);$count++;
    }
    return $count;
}

/** @param array<string,mixed> $rows */
function rdSalvarPipelineMappings(PDO $pdo,array $rows): int
{
    $count=0;
    $stmt=$pdo->prepare('UPDATE rd_pipeline_mappings SET enabled=?,pipeline_name=?,stage_name=? WHERE id=?');
    foreach($rows as $id=>$cfg){
        if(!is_array($cfg))continue;
        $rowId=(int)$id;if($rowId<=0)continue;
        $enabled=!empty($cfg['enabled'])?1:0;
        $pipeline=mb_substr(trim((string)($cfg['pipeline_name']??'')),0,160);
        $stage=mb_substr(trim((string)($cfg['stage_name']??'')),0,160);
        if($pipeline===''||$stage==='')continue;
        $stmt->execute([$enabled,$pipeline,$stage,$rowId]);$count++;
    }
    return $count;
}

/** @return array<int,array<string,mixed>> */
function rdUserMappings(PDO $pdo): array
{
    if(!rdDiscoveryDisponivel($pdo))return [];
    return $pdo->query("SELECT m.*,u.nome local_user_name,u.email local_user_email FROM rd_user_mappings m LEFT JOIN usuarios u ON u.id=m.local_user_id ORDER BY m.rd_user_active DESC,m.rd_user_name,m.rd_user_email")->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function rdPipelineMappings(PDO $pdo): array
{
    if(!rdDiscoveryDisponivel($pdo))return [];
    return $pdo->query('SELECT * FROM rd_pipeline_mappings ORDER BY pipeline_name,sort_order,stage_name,id')->fetchAll();
}


/** @return array<string,array<int,string>> */
function rdPipelineCatalogo(PDO $pdo): array
{
    if(!rdDiscoveryDisponivel($pdo))return [];
    $out=[];
    foreach(rdPipelineMappings($pdo) as $row){
        if(empty($row['enabled']))continue;
        $pipe=trim((string)$row['pipeline_name']);$stage=trim((string)$row['stage_name']);
        if($pipe===''||$stage==='')continue;
        $out[$pipe]??=[];
        if(!in_array($stage,$out[$pipe],true))$out[$pipe][]=$stage;
    }
    return $out;
}
