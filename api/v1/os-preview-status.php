<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/rbac.php';
header('Content-Type: application/json; charset=utf-8');
exigirPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR);

$prefill=$_SESSION['visita_pdf_prefill']??null;
if(!is_array($prefill)){
    echo json_encode(['ok'=>true,'pending'=>false],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
$meta=is_array($prefill['analysis_meta']??null)?$prefill['analysis_meta']:[];
echo json_encode([
    'ok'=>true,
    'pending'=>true,
    'source'=>(string)($prefill['analyzer_source']??$prefill['fonte']??'local'),
    'warning'=>(string)($prefill['analysis_warning']??''),
    'file'=>(string)($prefill['pdf_meta']['original_name']??$prefill['arquivo_original']??''),
    'analysis'=>$meta,
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
