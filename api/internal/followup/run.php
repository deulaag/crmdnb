<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/followup_service.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $limite = max(1,min(100,(int)($argv[1] ?? 20)));
    $resultado = processarMotorFollowup(db(),$limite);
    echo json_encode(['ok'=>true,'followup'=>$resultado],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $e) {
    error_log('DNB follow-up cron: '.$e->getMessage());
    echo json_encode(['ok'=>false,'error'=>'followup_failed','message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(1);
}
