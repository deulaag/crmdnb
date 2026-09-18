<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/rd_phase8_service.php';
exigirPapel(PAPEL_ADMINISTRADOR);

try{
    $pdo=db();
    $csv=rdPhase8AuditCsv($pdo,(int)(usuarioLogado()['id']??0));
}catch(Throwable $e){
    error_log('rd.audit.csv: '.$e->getMessage());
    http_response_code(500);
    exit('Não foi possível exportar a auditoria operacional do RD.');
}

$nome='auditoria-integracao-rd-'.date('Ymd-His').'.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$nome.'"');
header('Content-Length: '.strlen($csv));
header('Cache-Control: private, no-store, max-age=0');
echo $csv;
