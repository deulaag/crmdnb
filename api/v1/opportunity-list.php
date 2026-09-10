<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/rbac.php';
require_once __DIR__.'/../../includes/helpers.php';
require_once __DIR__.'/../../includes/lead_service.php';
require_once __DIR__.'/../../includes/crm_directory_service.php';
header('Content-Type: application/json; charset=utf-8');
exigirPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR);
$pdo=db();
$items=listarLeadsAcessiveis($pdo);
$f=[
 'q'=>mb_strtolower(trim((string)($_GET['q']??''))), 'funil'=>trim((string)($_GET['funil']??'')),
 'responsavel'=>(int)($_GET['responsavel']??0), 'linha'=>trim((string)($_GET['linha']??'')),
 'origem'=>trim((string)($_GET['origem']??'')), 'automacao'=>trim((string)($_GET['automacao']??'')),
];
$items=array_values(array_filter($items,static function(array $o)use($f):bool{
 if(($o['status']??'')!=='Aberta')return false;
 if($f['q']!==''&&!str_contains(mb_strtolower(implode(' ',[(string)($o['cliente']??''),(string)($o['numero']??''),(string)($o['linha']??'')])), $f['q']))return false;
 if($f['funil']!==''&&funilDe((string)($o['linha']??''))!==$f['funil'])return false;
 if($f['responsavel']>0&&(int)($o['assigned_to_user_id']??0)!==$f['responsavel'])return false;
 if($f['linha']!==''&&(string)($o['linha']??'')!==$f['linha'])return false;
 if($f['origem']!==''&&(string)($o['origem']??'')!==$f['origem'])return false;
 if($f['automacao']==='active'&&(!empty($o['cad_pausada'])||($o['status']??'')!=='Aberta'))return false;
 if($f['automacao']==='paused'&&(empty($o['cad_pausada'])||($o['status']??'')!=='Aberta'))return false;
 return true;
}));
$out=[];
foreach($items as $o){
 $company=null;$contact=null;
 if(diretorioCrmDisponivel($pdo)&&!empty($o['company_id'])){
   $stmt=$pdo->prepare('SELECT id,name,cnpj,address,locality FROM crm_companies WHERE id=?');$stmt->execute([(int)$o['company_id']]);$company=$stmt->fetch()?:null;
   if(!empty($o['contact_id'])){$stmt=$pdo->prepare('SELECT id,name,phone,email,role_title FROM crm_contacts WHERE id=?');$stmt->execute([(int)$o['contact_id']]);$contact=$stmt->fetch()?:null;}
 }
 $out[]=['id'=>(int)$o['id'],'numero'=>(string)($o['numero']??''),'cliente'=>(string)($o['cliente']??''),'linha'=>(string)($o['linha']??''),'funil'=>funilDe((string)($o['linha']??'')),'etapa'=>(string)($o['etapa']??''),'valor'=>(float)($o['valor']??0),'origem'=>(string)($o['origem']??''),'responsavel'=>(string)($o['assigned_to_name']??''),'data'=>(string)($o['data']??''),'company'=>$company,'contact'=>$contact,'source_visit_id'=>(int)($o['source_visit_id']??0)];
}
echo json_encode(['ok'=>true,'count'=>count($out),'items'=>$out],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
