<?php
declare(strict_types=1);

require_once __DIR__ . '/crm_core_service.php';
require_once __DIR__ . '/rbac.php';

function crmPhase2Disponivel(PDO $pdo): bool
{
    if(!crmCoreFase1Disponivel($pdo)) return false;
    try{
        $stmt=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='crm_companies' AND COLUMN_NAME='created_by_user_id') OR (TABLE_NAME='crm_contacts' AND COLUMN_NAME='created_by_user_id'))");
        return (int)$stmt->fetchColumn()===2;
    }catch(Throwable){return false;}
}

/** @return array{0:string,1:array<int,int>} */
function crmEscopoNegociacao(PDO $pdo, string $alias='n'): array
{
    if (temPapel(PAPEL_ADMINISTRADOR)) return ['1=1',[]];
    $ids=idsUsuariosDaEquipe($pdo);
    if(!$ids)return ['1=0',[]];
    return [$alias.'.assigned_to_user_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids];
}

/** @return array{0:string,1:array<int,int>} */
function crmEscopoEmpresa(PDO $pdo, string $alias='c'): array
{
    if (temPapel(PAPEL_ADMINISTRADOR)) return ['1=1',[]];
    $ids=idsUsuariosDaEquipe($pdo);
    if(!$ids)return ['1=0',[]];
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $sql="(
      {$alias}.created_by_user_id IN ({$marks})
      OR EXISTS(SELECT 1 FROM crm_negotiations n WHERE n.company_id={$alias}.id AND n.assigned_to_user_id IN ({$marks}))
      OR EXISTS(SELECT 1 FROM orcamentos o WHERE o.company_id={$alias}.id AND o.assigned_to_user_id IN ({$marks}))
      OR EXISTS(SELECT 1 FROM pedidos p WHERE p.company_id={$alias}.id AND p.assigned_to_user_id IN ({$marks}))
      OR EXISTS(SELECT 1 FROM visitas v WHERE v.company_id={$alias}.id AND v.assigned_to_user_id IN ({$marks}))
    )";
    return [$sql,array_merge($ids,$ids,$ids,$ids,$ids)];
}

/** @return array{0:string,1:array<int,int>} */
function crmEscopoContato(PDO $pdo, string $alias='ct'): array
{
    if (temPapel(PAPEL_ADMINISTRADOR)) return ['1=1',[]];
    $ids=idsUsuariosDaEquipe($pdo);
    if(!$ids)return ['1=0',[]];
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $sql="(
      {$alias}.created_by_user_id IN ({$marks})
      OR EXISTS(SELECT 1 FROM crm_negotiation_contacts nc JOIN crm_negotiations n ON n.id=nc.negotiation_id WHERE nc.contact_id={$alias}.id AND n.assigned_to_user_id IN ({$marks}))
      OR EXISTS(SELECT 1 FROM orcamentos o WHERE o.contact_id={$alias}.id AND o.assigned_to_user_id IN ({$marks}))
      OR EXISTS(SELECT 1 FROM visitas v WHERE v.contact_id={$alias}.id AND v.assigned_to_user_id IN ({$marks}))
    )";
    return [$sql,array_merge($ids,$ids,$ids,$ids)];
}

function podeAcessarEmpresaCrm(PDO $pdo,int $id): bool
{
    [$where,$params]=crmEscopoEmpresa($pdo,'c');$params[]=$id;
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM crm_companies c WHERE {$where} AND c.id=?");$stmt->execute($params);
    return (int)$stmt->fetchColumn()>0;
}
function podeAcessarContatoCrm(PDO $pdo,int $id): bool
{
    [$where,$params]=crmEscopoContato($pdo,'ct');$params[]=$id;
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM crm_contacts ct WHERE {$where} AND ct.id=?");$stmt->execute($params);
    return (int)$stmt->fetchColumn()>0;
}
function podeAcessarNegociacaoCrm(PDO $pdo,int $id): bool
{
    [$where,$params]=crmEscopoNegociacao($pdo,'n');$params[]=$id;
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM crm_negotiations n WHERE {$where} AND n.id=?");$stmt->execute($params);
    return (int)$stmt->fetchColumn()>0;
}

/** @return array<string,mixed> */
function criarEmpresaCrmUi(PDO $pdo,array $dados,bool $permitirDuplicidade=false): array
{
    if(!crmPhase2Disponivel($pdo))throw new RuntimeException('Instale a migração da Fase 2.');
    $row=criarEmpresaCrmCore($pdo,$dados,$permitirDuplicidade);
    $id=(int)($row['id']??0);
    if($id>0){
        $pdo->prepare('UPDATE crm_companies SET created_by_user_id=COALESCE(created_by_user_id,?) WHERE id=?')->execute([(int)usuarioLogado()['id'],$id]);
        $row['created_by_user_id']=(int)usuarioLogado()['id'];
    }
    return $row;
}

/** @return array<string,mixed> */
function criarContatoCrmUi(PDO $pdo,array $dados,bool $permitirDuplicidade=false): array
{
    if(!crmPhase2Disponivel($pdo))throw new RuntimeException('Instale a migração da Fase 2.');
    $companyId=(int)($dados['company_id']??0);
    if($companyId>0&&!podeAcessarEmpresaCrm($pdo,$companyId))throw new RuntimeException('Empresa fora do seu escopo.');
    $row=criarContatoCrmCore($pdo,$dados,$permitirDuplicidade);
    $id=(int)($row['id']??0);
    if($id>0){
        $pdo->prepare('UPDATE crm_contacts SET created_by_user_id=COALESCE(created_by_user_id,?) WHERE id=?')->execute([(int)usuarioLogado()['id'],$id]);
        $row['created_by_user_id']=(int)usuarioLogado()['id'];
    }
    return $row;
}

/** @return array<int,array<string,mixed>> */
function listarEmpresasCrmUi(PDO $pdo,array $filtros=[]): array
{
    [$scope,$params]=crmEscopoEmpresa($pdo,'c');
    $where=[$scope];
    $q=trim((string)($filtros['q']??''));
    if($q!==''){
        $where[]='(c.name LIKE ? OR c.trade_name LIKE ? OR c.cnpj LIKE ? OR c.dnb_id LIKE ?)';
        $like='%'.$q.'%';array_push($params,$like,$like,$like,$like);
    }
    if(($filtros['review']??'')==='needs_review')$where[]="c.review_status='needs_review'";
    if(($filtros['status']??'')==='inactive')$where[]="c.status='inactive'"; else $where[]="c.status='active'";
    $sql='SELECT c.*,
      (SELECT COUNT(*) FROM crm_contacts ct WHERE ct.company_id=c.id AND ct.status=\'active\') contacts_count,
      (SELECT COUNT(*) FROM crm_negotiations n WHERE n.company_id=c.id) negotiations_count,
      (SELECT COALESCE(SUM(n.estimated_value),0) FROM crm_negotiations n WHERE n.company_id=c.id AND n.status=\'open\') open_value
      FROM crm_companies c WHERE '.implode(' AND ',$where).' ORDER BY c.name,c.id LIMIT 500';
    $stmt=$pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function listarContatosCrmUi(PDO $pdo,array $filtros=[]): array
{
    [$scope,$params]=crmEscopoContato($pdo,'ct');
    $where=[$scope];
    $q=trim((string)($filtros['q']??''));
    if($q!==''){
        $where[]='(ct.name LIKE ? OR ct.email LIKE ? OR ct.phone LIKE ? OR ct.dnb_id LIKE ? OR c.name LIKE ?)';
        $like='%'.$q.'%';array_push($params,$like,$like,$like,$like,$like);
    }
    if(($filtros['sem_empresa']??'')==='1')$where[]='ct.company_id IS NULL';
    if(($filtros['review']??'')==='needs_review')$where[]="ct.review_status='needs_review'";
    $sql='SELECT ct.*,c.name company_name,c.dnb_id company_dnb_id,
      (SELECT COUNT(*) FROM crm_negotiation_contacts nc WHERE nc.contact_id=ct.id) negotiations_count
      FROM crm_contacts ct LEFT JOIN crm_companies c ON c.id=ct.company_id
      WHERE '.implode(' AND ',$where)." AND ct.status='active' ORDER BY ct.name,ct.id LIMIT 500";
    $stmt=$pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function listarNegociacoesCrmUi(PDO $pdo,array $filtros=[]): array
{
    [$scope,$params]=crmEscopoNegociacao($pdo,'n');
    $where=[$scope];
    foreach(['status','category','pipeline_name','stage_name'] as $key){
        $value=trim((string)($filtros[$key]??''));
        if($value!==''){$where[]='n.'.$key.'=?';$params[]=$value;}
    }
    $company=(int)($filtros['company_id']??0);if($company>0){$where[]='n.company_id=?';$params[]=$company;}
    $owner=(int)($filtros['owner_id']??0);if($owner>0){$where[]='n.assigned_to_user_id=?';$params[]=$owner;}
    $q=trim((string)($filtros['q']??''));
    if($q!==''){
        $where[]='(n.title LIKE ? OR n.dnb_id LIKE ? OR c.name LIKE ?)';
        $like='%'.$q.'%';array_push($params,$like,$like,$like);
    }
    $sql='SELECT n.*,c.name company_name,c.dnb_id company_dnb_id,u.nome owner_name,
      (SELECT COUNT(*) FROM crm_negotiation_contacts nc WHERE nc.negotiation_id=n.id) contacts_count,
      (SELECT COUNT(*) FROM orcamentos o WHERE o.negotiation_id=n.id) quotes_count,
      (SELECT COUNT(*) FROM pedidos p WHERE p.negotiation_id=n.id) orders_count,
      (SELECT COUNT(*) FROM visitas v WHERE v.negotiation_id=n.id) visits_count
      FROM crm_negotiations n
      LEFT JOIN crm_companies c ON c.id=n.company_id
      LEFT JOIN usuarios u ON u.id=n.assigned_to_user_id
      WHERE '.implode(' AND ',$where).' ORDER BY COALESCE(n.business_updated_at,n.updated_at) DESC,n.id DESC LIMIT 750';
    $stmt=$pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function listarLeadsPreVendasCrmUi(PDO $pdo,array $filtros=[]): array
{
    [$scope,$params]=crmEscopoContato($pdo,'ct');
    $where=[$scope,"ct.status='active'","NOT EXISTS(SELECT 1 FROM crm_negotiation_contacts nc WHERE nc.contact_id=ct.id)"];
    $q=trim((string)($filtros['q']??''));if($q!==''){$where[]='(ct.name LIKE ? OR ct.email LIKE ? OR c.name LIKE ?)';$like='%'.$q.'%';array_push($params,$like,$like,$like);}
    $sql='SELECT ct.*,c.name company_name,c.dnb_id company_dnb_id FROM crm_contacts ct LEFT JOIN crm_companies c ON c.id=ct.company_id WHERE '.implode(' AND ',$where).' ORDER BY ct.created_at DESC,ct.id DESC LIMIT 500';
    $stmt=$pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function responsaveisNegociacaoCrmUi(PDO $pdo): array
{
    if(temPapel(PAPEL_ADMINISTRADOR)){
        return $pdo->query("SELECT id,nome FROM usuarios WHERE status='active' ORDER BY nome,id")->fetchAll();
    }
    $ids=idsUsuariosDaEquipe($pdo);if(!$ids)return [];
    $stmt=$pdo->prepare("SELECT id,nome FROM usuarios WHERE status='active' AND id IN (".implode(',',array_fill(0,count($ids),'?')).") ORDER BY nome,id");
    $stmt->execute($ids);return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function empresasSelecionaveisCrmUi(PDO $pdo): array
{
    [$scope,$params]=crmEscopoEmpresa($pdo,'c');
    $stmt=$pdo->prepare("SELECT id,dnb_id,name,cnpj FROM crm_companies c WHERE c.status='active' AND ({$scope}) ORDER BY c.name LIMIT 1000");
    $stmt->execute($params);return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function contatosSelecionaveisCrmUi(PDO $pdo,?int $companyId=null): array
{
    [$scope,$params]=crmEscopoContato($pdo,'ct');
    $where=["ct.status='active'","({$scope})"];
    if($companyId){$where[]='ct.company_id=?';$params[]=$companyId;}
    $stmt=$pdo->prepare('SELECT ct.id,ct.dnb_id,ct.name,ct.email,ct.company_id,c.name company_name FROM crm_contacts ct LEFT JOIN crm_companies c ON c.id=ct.company_id WHERE '.implode(' AND ',$where).' ORDER BY ct.name LIMIT 1000');
    $stmt->execute($params);return $stmt->fetchAll();
}

/** @return array<string,mixed>|null */
function detalharEmpresaCrmUi(PDO $pdo,int $id): ?array
{
    if(!podeAcessarEmpresaCrm($pdo,$id))return null;
    $stmt=$pdo->prepare('SELECT * FROM crm_companies WHERE id=?');$stmt->execute([$id]);$company=$stmt->fetch();if(!$company)return null;

    $stmt=$pdo->prepare('SELECT * FROM crm_contacts WHERE company_id=? ORDER BY name,id');$stmt->execute([$id]);$company['contacts']=$stmt->fetchAll();

    [$nScope,$nParams]=crmEscopoNegociacao($pdo,'n');$nParams[]=$id;
    $stmt=$pdo->prepare("SELECT n.*,u.nome owner_name FROM crm_negotiations n LEFT JOIN usuarios u ON u.id=n.assigned_to_user_id WHERE {$nScope} AND n.company_id=? ORDER BY COALESCE(n.business_updated_at,n.updated_at) DESC");$stmt->execute($nParams);$company['negotiations']=$stmt->fetchAll();

    [$oScope,$oParams]=filtroEscopoAtribuicao($pdo,'o.assigned_to_user_id');$oParams[]=$id;
    $stmt=$pdo->prepare("SELECT o.* FROM orcamentos o WHERE {$oScope} AND o.company_id=? ORDER BY o.data DESC,o.id DESC");$stmt->execute($oParams);$company['quotes']=$stmt->fetchAll();

    [$pScope,$pParams]=filtroEscopoAtribuicao($pdo,'p.assigned_to_user_id');$pParams[]=$id;
    $stmt=$pdo->prepare("SELECT p.* FROM pedidos p WHERE {$pScope} AND p.company_id=? ORDER BY p.data DESC,p.id DESC");$stmt->execute($pParams);$company['orders']=$stmt->fetchAll();

    [$vScope,$vParams]=filtroEscopoAtribuicao($pdo,'v.assigned_to_user_id');$vParams[]=$id;
    $stmt=$pdo->prepare("SELECT v.* FROM visitas v WHERE {$vScope} AND v.company_id=? ORDER BY v.data DESC,v.id DESC");$stmt->execute($vParams);$company['visits']=$stmt->fetchAll();
    return $company;
}

/** @return array<string,mixed>|null */
function detalharContatoCrmUi(PDO $pdo,int $id): ?array
{
    if(!podeAcessarContatoCrm($pdo,$id))return null;
    $stmt=$pdo->prepare('SELECT ct.*,c.name company_name,c.dnb_id company_dnb_id FROM crm_contacts ct LEFT JOIN crm_companies c ON c.id=ct.company_id WHERE ct.id=?');$stmt->execute([$id]);$contact=$stmt->fetch();if(!$contact)return null;
    [$scope,$params]=crmEscopoNegociacao($pdo,'n');$params[]=$id;
    $stmt=$pdo->prepare("SELECT n.*,nc.role,nc.is_primary,c.name company_name,u.nome owner_name FROM crm_negotiation_contacts nc JOIN crm_negotiations n ON n.id=nc.negotiation_id LEFT JOIN crm_companies c ON c.id=n.company_id LEFT JOIN usuarios u ON u.id=n.assigned_to_user_id WHERE {$scope} AND nc.contact_id=? ORDER BY COALESCE(n.business_updated_at,n.updated_at) DESC");$stmt->execute($params);$contact['negotiations']=$stmt->fetchAll();

    [$vScope,$vParams]=filtroEscopoAtribuicao($pdo,'v.assigned_to_user_id');$vParams[]=$id;
    $stmt=$pdo->prepare("SELECT v.* FROM visitas v WHERE {$vScope} AND v.contact_id=? ORDER BY v.data DESC,v.id DESC");$stmt->execute($vParams);$contact['visits']=$stmt->fetchAll();
    return $contact;
}

/** @return array<string,mixed>|null */
function detalharNegociacaoCrmUi(PDO $pdo,int $id): ?array
{
    if(!podeAcessarNegociacaoCrm($pdo,$id))return null;
    $stmt=$pdo->prepare('SELECT n.*,c.name company_name,c.dnb_id company_dnb_id,u.nome owner_name FROM crm_negotiations n LEFT JOIN crm_companies c ON c.id=n.company_id LEFT JOIN usuarios u ON u.id=n.assigned_to_user_id WHERE n.id=?');$stmt->execute([$id]);$n=$stmt->fetch();if(!$n)return null;

    $stmt=$pdo->prepare('SELECT ct.*,nc.role,nc.is_primary FROM crm_negotiation_contacts nc JOIN crm_contacts ct ON ct.id=nc.contact_id WHERE nc.negotiation_id=? ORDER BY nc.is_primary DESC,ct.name');$stmt->execute([$id]);$n['contacts']=$stmt->fetchAll();

    [$oScope,$oParams]=filtroEscopoAtribuicao($pdo,'o.assigned_to_user_id');$oParams[]=$id;
    $stmt=$pdo->prepare("SELECT o.* FROM orcamentos o WHERE {$oScope} AND o.negotiation_id=? ORDER BY o.data DESC,o.id DESC");$stmt->execute($oParams);$n['quotes']=$stmt->fetchAll();

    [$pScope,$pParams]=filtroEscopoAtribuicao($pdo,'p.assigned_to_user_id');$pParams[]=$id;
    $stmt=$pdo->prepare("SELECT p.* FROM pedidos p WHERE {$pScope} AND p.negotiation_id=? ORDER BY p.data DESC,p.id DESC");$stmt->execute($pParams);$n['orders']=$stmt->fetchAll();

    [$vScope,$vParams]=filtroEscopoAtribuicao($pdo,'v.assigned_to_user_id');$vParams[]=$id;
    $stmt=$pdo->prepare("SELECT v.* FROM visitas v WHERE {$vScope} AND v.negotiation_id=? ORDER BY v.data DESC,v.id DESC");$stmt->execute($vParams);$n['visits']=$stmt->fetchAll();
    return $n;
}

function atualizarEmpresaCrmUi(PDO $pdo,int $id,array $dados): void
{
    if(!podeAcessarEmpresaCrm($pdo,$id))throw new RuntimeException('Empresa fora do seu escopo.');
    $nome=trim((string)($dados['name']??''));if($nome==='')throw new InvalidArgumentException('Informe o nome da empresa.');
    $cnpj=trim((string)($dados['cnpj']??''));$digits=crmCoreCnpjDigits($cnpj);$dups=$digits!==''?empresasDuplicadasPorCnpj($pdo,$digits,$id):[];
    if($dups&&!temPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR))throw new DomainException('CNPJ duplicado precisa de revisão administrativa.');
    $review=$dups?'needs_review':'ok';$reason=$dups?'CNPJ duplicado: conferir antes de mesclar':null;
    $stmt=$pdo->prepare('UPDATE crm_companies SET name=?,trade_name=?,cnpj=NULLIF(?,\'\'),cnpj_digits=NULLIF(?,\'\'),primary_email=?,phone=?,website=?,address=?,locality=?,review_status=?,review_reason=? WHERE id=?');
    $stmt->execute([
        mb_substr($nome,0,200),mb_substr(trim((string)($dados['trade_name']??'')),0,200),mb_substr($cnpj,0,18),mb_substr($digits,0,14),
        mb_substr(crmCoreEmail((string)($dados['primary_email']??'')),0,190),mb_substr(trim((string)($dados['phone']??'')),0,40),mb_substr(trim((string)($dados['website']??'')),0,255),
        mb_substr(trim((string)($dados['address']??'')),0,255),mb_substr(trim((string)($dados['locality']??'')),0,180),$review,$reason,$id
    ]);
}

function atualizarContatoCrmUi(PDO $pdo,int $id,array $dados): void
{
    if(!podeAcessarContatoCrm($pdo,$id))throw new RuntimeException('Contato fora do seu escopo.');
    $nome=trim((string)($dados['name']??''));if($nome==='')throw new InvalidArgumentException('Informe o nome do contato.');
    $email=crmCoreEmail((string)($dados['email']??''));$dups=$email!==''?contatosDuplicadosPorEmail($pdo,$email,$id):[];
    if($dups&&!temPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR))throw new DomainException('E-mail duplicado precisa de revisão administrativa.');
    $companyId=(int)($dados['company_id']??0);$companyId=$companyId>0?$companyId:null;
    if($companyId&&!podeAcessarEmpresaCrm($pdo,$companyId))throw new RuntimeException('Empresa fora do seu escopo.');
    $stmt=$pdo->prepare('UPDATE crm_contacts SET company_id=?,name=?,phone=?,email=?,role_title=?,review_status=?,review_reason=? WHERE id=?');
    $stmt->execute([$companyId,mb_substr($nome,0,180),mb_substr(trim((string)($dados['phone']??'')),0,40),mb_substr($email,0,190),mb_substr(trim((string)($dados['role_title']??'')),0,120),$dups?'needs_review':'ok',$dups?'E-mail duplicado: conferir antes de mesclar':null,$id]);
}

function atualizarNegociacaoCrmUi(PDO $pdo,int $id,array $dados): void
{
    if(!podeAcessarNegociacaoCrm($pdo,$id))throw new RuntimeException('Negociação fora do seu escopo.');
    $titulo=trim((string)($dados['title']??''));if($titulo==='')throw new InvalidArgumentException('Informe o título da negociação.');
    $companyId=(int)($dados['company_id']??0);$companyId=$companyId>0?$companyId:null;
    if($companyId&&!podeAcessarEmpresaCrm($pdo,$companyId))throw new RuntimeException('Empresa fora do seu escopo.');
    $owner=(int)($dados['assigned_to_user_id']??0);if($owner<=0)$owner=(int)usuarioLogado()['id'];
    $permitidos=array_column(responsaveisNegociacaoCrmUi($pdo),'id');if(!in_array($owner,array_map('intval',$permitidos),true))throw new RuntimeException('Responsável fora do seu escopo.');
    $status=crmCoreStatusNegociacao((string)($dados['status']??'open'));
    $stmt=$pdo->prepare('UPDATE crm_negotiations SET title=?,company_id=?,category=?,pipeline_name=?,stage_name=?,estimated_value=?,status=?,lost_reason=?,assigned_to_user_id=?,business_updated_at=NOW(),closed_at=CASE WHEN ? IN (\'won\',\'lost\') THEN COALESCE(closed_at,NOW()) ELSE NULL END WHERE id=?');
    $stmt->execute([mb_substr($titulo,0,220),$companyId,mb_substr(trim((string)($dados['category']??'')),0,120),mb_substr(trim((string)($dados['pipeline_name']??'')),0,160),mb_substr(trim((string)($dados['stage_name']??'')),0,160),max(0,(float)($dados['estimated_value']??0)),$status,mb_substr(trim((string)($dados['lost_reason']??'')),0,255),$owner,$status,$id]);
}

function removerContatoNegociacaoCrmUi(PDO $pdo,int $negotiationId,int $contactId): void
{
    if(!podeAcessarNegociacaoCrm($pdo,$negotiationId))throw new RuntimeException('Negociação fora do seu escopo.');
    $pdo->prepare('DELETE FROM crm_negotiation_contacts WHERE negotiation_id=? AND contact_id=?')->execute([$negotiationId,$contactId]);
}

function vincularOrcamentoNegociacaoCrmUi(PDO $pdo,int $negotiationId,int $orcamentoId): void
{
    if(!podeAcessarNegociacaoCrm($pdo,$negotiationId)||!podeAcessarLead($pdo,$orcamentoId))throw new RuntimeException('Registro fora do seu escopo.');
    $stmt=$pdo->prepare('SELECT company_id,contact_id FROM orcamentos WHERE id=?');$stmt->execute([$orcamentoId]);$o=$stmt->fetch();if(!$o)throw new RuntimeException('Orçamento não encontrado.');
    $pdo->prepare('UPDATE orcamentos SET negotiation_id=? WHERE id=?')->execute([$negotiationId,$orcamentoId]);
    if(!empty($o['contact_id']))vincularContatoNegociacaoCrm($pdo,$negotiationId,(int)$o['contact_id'],'principal',false);
}

/** @return array<int,array<string,mixed>> */
function timelineEmpresaCrmUi(array $company): array
{
    $events=[];
    foreach($company['negotiations']??[] as $n)$events[]=['date'=>(string)($n['business_updated_at']??$n['updated_at']??''),'type'=>'Negociação','title'=>(string)$n['title'],'meta'=>(string)$n['dnb_id']];
    foreach($company['quotes']??[] as $o)$events[]=['date'=>(string)($o['data']??''),'type'=>'Orçamento','title'=>(string)($o['numero']?:'s/n'),'meta'=>fmtBRL($o['valor']??0)];
    foreach($company['orders']??[] as $p)$events[]=['date'=>(string)($p['data']??''),'type'=>'Pedido','title'=>(string)($p['numero']?:'s/n'),'meta'=>fmtBRL($p['valor']??0)];
    foreach($company['visits']??[] as $v)$events[]=['date'=>(string)($v['data']??''),'type'=>'Visita','title'=>(string)($v['os']?:'Visita'),'meta'=>(string)($v['tipo']??'')];
    usort($events,static fn($a,$b)=>strcmp($b['date'],$a['date']));
    return $events;
}

function statusNegociacaoLabel(string $status): string
{
    return ['open'=>'Aberta','won'=>'Ganha','lost'=>'Perdida'][$status]??$status;
}
