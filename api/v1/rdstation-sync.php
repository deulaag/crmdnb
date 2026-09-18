<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/rd_sync_service.php';

header('Content-Type: application/json; charset=utf-8');
exigirPapel(PAPEL_ADMINISTRADOR);
$pdo=db();

function rdSyncApiOut(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function rdSyncApiState(PDO $pdo,int $jobId,int $after=0): array
{
    $job=rdSyncJob($pdo,$jobId);
    if(!$job)return ['ok'=>false,'error'=>'Job não encontrado.'];
    return [
        'ok'=>true,
        'job'=>[
            'id'=>(int)$job['id'],'status'=>$job['status'],'stage'=>$job['stage'],'since_at'=>$job['since_at'],
            'page_number'=>(int)$job['page_number'],'started_at'=>$job['started_at'],'finished_at'=>$job['finished_at'],
            'last_error'=>$job['last_error'],'counters'=>$job['counters'],'retry_after'=>(int)($job['state']['retry_after']??0),
        ],
        'logs'=>rdSyncLogs($pdo,$jobId,$after,250),
        'pending_conflicts'=>count(rdSyncConflicts($pdo,$jobId,300)),
        'csrf'=>csrfToken(),
    ];
}

$method=$_SERVER['REQUEST_METHOD']??'GET';
$jobId=(int)($_GET['job_id']??$_POST['job_id']??0);

if($method==='GET'){
    if($jobId<=0)rdSyncApiOut(['ok'=>false,'error'=>'job_id obrigatório.'],422);
    rdSyncApiOut(rdSyncApiState($pdo,$jobId,(int)($_GET['after']??0)));
}
if($method!=='POST')rdSyncApiOut(['ok'=>false,'error'=>'Método não permitido.'],405);

exigirCsrf();
$raw=file_get_contents('php://input');
$data=json_decode((string)$raw,true);
if(is_array($data)){
    $jobId=(int)($data['job_id']??$jobId);
    $action=(string)($data['action']??'step');
}else{
    $action=(string)($_POST['action']??'step');
}
if($jobId<=0)rdSyncApiOut(['ok'=>false,'error'=>'job_id obrigatório.'],422);

try{
    if($action==='cancel'){
        rdSyncCancelar($pdo,$jobId);
    }elseif($action==='resume'){
        rdSyncRetomar($pdo,$jobId);
    }elseif($action==='step'){
        rdSyncProcessStep($pdo,$jobId);
    }else{
        rdSyncApiOut(['ok'=>false,'error'=>'Ação inválida.'],422);
    }
    rdSyncApiOut(rdSyncApiState($pdo,$jobId,(int)($data['after']??$_POST['after']??0)));
}catch(Throwable $e){
    error_log('rdstation-sync: '.$e->getMessage());
    rdSyncApiOut(['ok'=>false,'error'=>$e->getMessage()],422);
}
