<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/lead_service.php';
require_once __DIR__ . '/includes/os_pdf_storage.php';
exigirPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR);

$pdo=db();
$id=(int)($_GET['id']??0);
$lead=$id?buscarLeadAutorizado($pdo,$id):null;
if(!$lead){http_response_code(404);exit('Arquivo não encontrado.');}
$storage=(string)($lead['visit_pdf_storage_name']??'');
$nome=(string)($lead['visit_pdf_original_name']??'ordem-servico.pdf');
if($storage==='' && !empty($lead['source_visit_id'])){
    try{
        $s=$pdo->prepare('SELECT pdf_storage_name,pdf_original_name FROM visitas WHERE id=? LIMIT 1');
        $s->execute([(int)$lead['source_visit_id']]);$v=$s->fetch();
        if($v){$storage=(string)($v['pdf_storage_name']??'');$nome=(string)($v['pdf_original_name']??$nome);}
    }catch(Throwable){}
}
$path=caminhoPdfOsSeguro($storage);
if(!$path){http_response_code(404);exit('PDF da Ordem de Serviço não encontrado.');}
$nome=basename($nome?:'ordem-servico.pdf');
if(!str_ends_with(strtolower($nome),'.pdf'))$nome.='.pdf';
header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header("Content-Disposition: inline; filename*=UTF-8''".rawurlencode($nome));
$size=filesize($path);if($size!==false)header('Content-Length: '.$size);
readfile($path);
