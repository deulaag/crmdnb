<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/pdf_service.php';
require_once __DIR__ . '/../includes/os_template_service.php';
require_once __DIR__ . '/../includes/os_pdf_storage.php';
require_once __DIR__ . '/../includes/os_remote_analyzer.php';

header('Content-Type: application/json; charset=utf-8');
exigirPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR);

function osGatewayResponder(array $dados,int $status=200): never
{
    http_response_code($status);
    echo json_encode($dados,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function osGatewayLimparPendente(): void
{
    $pendente=$_SESSION['visita_pdf_prefill']??null;
    if(is_array($pendente)&&!empty($pendente['pdf_meta']['storage_name']))excluirPdfOsSeguro((string)$pendente['pdf_meta']['storage_name']);
    unset($_SESSION['visita_pdf_prefill']);
}

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')osGatewayResponder(['ok'=>false,'erro'=>'Método não permitido.'],405);
exigirCsrf();
$f=$_FILES['arquivo_pdf']??null;
if(!$f||($f['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)osGatewayResponder(['ok'=>false,'erro'=>'Selecione um arquivo PDF para ler.'],422);
if(($f['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK||!is_uploaded_file((string)$f['tmp_name']))osGatewayResponder(['ok'=>false,'erro'=>'Falha no upload do PDF.'],422);
if(($f['size']??0)<=0||($f['size']??0)>15*1024*1024)osGatewayResponder(['ok'=>false,'erro'=>'PDF vazio ou maior que 15MB.'],422);
if(strtolower(pathinfo((string)$f['name'],PATHINFO_EXTENSION))!=='pdf')osGatewayResponder(['ok'=>false,'erro'=>'Selecione um arquivo com extensão .pdf.'],422);

try{
    $localDoc=extrairConteudoPdfOs((string)$f['tmp_name']);
    $localTemplate=is_array($localDoc['template']??null)?$localDoc['template']:templateOsVazio();
    $meta=['engine'=>'local','total'=>totalCamposTemplateOs(),'filled'=>0,'empty'=>[],'unresolved'=>[],'confidence'=>[],'photos'=>[],'photos_count'=>0];
    $template=$localTemplate;$warning='';

    if(osAnalyzerAtivo()){
        try{
            $remote=analisarOsViaN8n((string)$f['tmp_name'],(string)$f['name']);
            $normalizado=normalizarRespostaN8nOs($remote,$localTemplate);
            $template=$normalizado['template'];$meta=$normalizado['meta'];
        }catch(Throwable $e){
            if(!osAnalyzerFallbackLocal())throw $e;
            $warning='Leitor inteligente indisponível; foi usada a leitura local como fallback. '.$e->getMessage();
        }
    }

    if(($meta['engine']??'')==='local'){
        $filled=0;foreach(array_keys(camposTemplateOsSimplificada()) as $key)if(trim((string)($template[$key]??''))!=='')$filled++;
        $meta['filled']=$filled;$meta['empty']=[];$meta['unresolved']=[];
    }

    $prefill=prefillVisitaDoTemplate($template,(string)$f['name'],'pdf');
    osGatewayLimparPendente();
    $prefill['pdf_meta']=armazenarPdfOsUpload($f);
    $prefill['preview_token']=bin2hex(random_bytes(24));
    $prefill['pdf_token']=$prefill['preview_token'];
    $prefill['analysis_meta']=$meta;
    $prefill['analyzer_source']=(string)($meta['engine']??'local');
    $prefill['analysis_warning']=$warning;
    $_SESSION['visita_pdf_prefill']=$prefill;

    $mensagem='OS analisada: '.(int)($meta['filled']??0).' de '.totalCamposTemplateOs().' campo(s) preenchido(s). Nenhuma visita ou oportunidade foi criada.';
    if($warning!=='')$mensagem.=' '.$warning;
    osGatewayResponder(['ok'=>true,'redirect'=>'/visitas.php','mensagem'=>$mensagem,'analysis'=>$meta]);
}catch(Throwable $e){
    error_log('os.analisar: '.$e->getMessage());
    osGatewayResponder(['ok'=>false,'erro'=>$e->getMessage()],422);
}
