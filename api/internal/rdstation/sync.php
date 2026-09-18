<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/rd_phase6_service.php';
require_once __DIR__ . '/../../../includes/rd_phase9_service.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rdCronOut(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if(!rdPhase6CronAutorizado())rdCronOut(['ok'=>false,'error'=>'unauthorized'],401);
if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true))rdCronOut(['ok'=>false,'error'=>'method_not_allowed'],405);

$pdo=db();
if(!rdPhase6Disponivel($pdo))rdCronOut(['ok'=>false,'error'=>'phase6_not_installed'],503);

try{
    $result=rdPhase6Tick($pdo,35);
    rdCronOut(['ok'=>true,'result'=>$result,'stats'=>rdPhase6Stats($pdo)]);
}catch(Throwable $e){
    error_log('rd-cron: '.$e->getMessage());
    rdCronOut(['ok'=>false,'error'=>$e->getMessage()],422);
}
