<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/rd_phase6_service.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rdWebhookOut(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if(!rdPhase6WebhookAutorizado()){
    rdWebhookOut(['ok'=>false,'error'=>'unauthorized'],401);
}

$pdo=db();
if(!rdPhase6Disponivel($pdo)){
    rdWebhookOut(['ok'=>false,'error'=>'phase6_not_installed'],503);
}

$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='GET'){
    rdWebhookOut(['ok'=>true,'status'=>'ready']);
}
if($method!=='POST'){
    rdWebhookOut(['ok'=>false,'error'=>'method_not_allowed'],405);
}

$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if($length>1048576)rdWebhookOut(['ok'=>false,'error'=>'payload_too_large'],413);

$raw=file_get_contents('php://input');
if($raw===false)$raw='';
if(trim($raw)===''){
    // O RD pode validar o callback antes de efetivar a assinatura.
    rdWebhookOut(['ok'=>true,'status'=>'validation']);
}
$payload=json_decode($raw,true);
if(!is_array($payload))rdWebhookOut(['ok'=>false,'error'=>'invalid_json'],400);

$product=strtolower(trim((string)($_GET['product']??'')));
if(!in_array($product,['marketing','crm'],true)){
    $event=(string)($payload['event_name']??$payload['event_type']??'');
    $product=str_starts_with($event,'crm_')?'crm':'marketing';
}

try{
    $queued=rdPhase6QueueWebhook($pdo,$product,$payload,$raw);
    rdWebhookOut([
        'ok'=>true,
        'accepted'=>true,
        'duplicate'=>$queued['duplicate'],
        'event_id'=>$queued['id'],
        'status'=>$queued['status'],
    ],$queued['duplicate']?200:202);
}catch(InvalidArgumentException $e){
    rdWebhookOut(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('rd-webhook: '.$e->getMessage());
    rdWebhookOut(['ok'=>false,'error'=>'internal_error'],500);
}
