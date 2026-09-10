<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/rbac.php';
require_once __DIR__.'/../../includes/crm_directory_service.php';
header('Content-Type: application/json; charset=utf-8');
exigirPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR);
$pdo=db();
if(!diretorioCrmDisponivel($pdo)){http_response_code(503);echo json_encode(['ok'=>false,'erro'=>'Execute a migração v3.6.']);exit;}
$q=trim((string)($_GET['q']??$_GET['cnpj']??''));
$companyId=(int)($_GET['company_id']??0);
if($companyId>0){echo json_encode(['ok'=>true,'contacts'=>contatosEmpresaCrm($pdo,$companyId)],JSON_UNESCAPED_UNICODE);exit;}
$companies=pesquisarEmpresasCrm($pdo,$q,10);
foreach($companies as &$c)$c['contacts']=contatosEmpresaCrm($pdo,(int)$c['id']);
unset($c);
echo json_encode(['ok'=>true,'companies'=>$companies],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
