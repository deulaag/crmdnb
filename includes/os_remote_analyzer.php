<?php
declare(strict_types=1);

require_once __DIR__ . '/integration_config.php';
require_once __DIR__ . '/os_template_service.php';

function osAnalyzerAtivo(): bool
{
    return integracaoConfigBool('DNB_OS_N8N_ENABLED', false);
}

function osAnalyzerFallbackLocal(): bool
{
    return integracaoConfigBool('DNB_OS_N8N_FALLBACK_LOCAL', true);
}

function osAnalyzerConfigurado(): bool
{
    return osAnalyzerAtivo()
        && integracaoConfigValor('DNB_OS_N8N_WEBHOOK_URL') !== ''
        && integracaoConfigValor('DNB_OS_N8N_TOKEN') !== '';
}

function osAnalyzerSanitizarHeader(string $valor): string
{
    return str_replace(["\r", "\n"], '', trim($valor));
}

/** @return array<int,array{key:string,label:string,section:string}> */
function contratoOs72ParaN8n(): array
{
    $saida=[];
    $labels=camposTemplateOsSimplificada();
    foreach(gruposTemplateOsSimplificada() as $grupo){
        foreach($grupo['campos'] as $key){
            $saida[]=['key'=>(string)$key,'label'=>(string)($labels[$key]??$key),'section'=>(string)$grupo['titulo']];
        }
    }
    return $saida;
}

/** @return array<string,mixed> */
function analisarOsViaN8n(string $arquivoPdf,string $nomeOriginal): array
{
    if(!osAnalyzerConfigurado())throw new RuntimeException('Leitor inteligente da OS não está configurado pelo ADM.');
    if(!function_exists('curl_init'))throw new RuntimeException('Extensão cURL do PHP não está disponível no servidor.');

    $url=integracaoConfigValor('DNB_OS_N8N_WEBHOOK_URL');
    $token=integracaoConfigValor('DNB_OS_N8N_TOKEN');
    $timeout=max(15,min(240,(int)integracaoConfigValor('DNB_OS_N8N_TIMEOUT','120')));

    $headers=[
        'Accept: application/json',
        'Authorization: Bearer '.osAnalyzerSanitizarHeader($token),
        'X-DNB-Schema-Version: dnb-os-72-v1',
        'X-DNB-Public-Gateway: https://crm.dnbair.com.br/os/analisar',
    ];
    $configHeaders=[
        'X-DNB-Extractor-URL'=>'DNB_OS_EXTRACTOR_URL',
        'X-DNB-Extractor-Token'=>'DNB_OS_EXTRACTOR_TOKEN',
        'X-DNB-Azure-Endpoint'=>'DNB_OS_AZURE_ENDPOINT',
        'X-DNB-Azure-Key'=>'DNB_OS_AZURE_KEY',
        'X-DNB-Azure-Model'=>'DNB_OS_AZURE_MODEL',
        'X-DNB-Azure-Api-Version'=>'DNB_OS_AZURE_API_VERSION',
        'X-DNB-AI-Visual-Endpoint'=>'DNB_OS_AI_VISUAL_ENDPOINT',
        'X-DNB-AI-Visual-Token'=>'DNB_OS_AI_VISUAL_TOKEN',
        'X-DNB-AI-Visual-Model'=>'DNB_OS_AI_VISUAL_MODEL',
        'X-DNB-AI-Visual-Enabled'=>'DNB_OS_AI_VISUAL_ENABLED',
    ];
    foreach($configHeaders as $header=>$key){
        $valor=integracaoConfigValor($key);
        if($valor!=='')$headers[]=$header.': '.osAnalyzerSanitizarHeader($valor);
    }

    $post=[
        'arquivo_pdf'=>new CURLFile($arquivoPdf,'application/pdf',basename($nomeOriginal)),
        'schema_version'=>'dnb-os-72-v1',
        'schema_json'=>json_encode(contratoOs72ParaN8n(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'expected_fields'=>(string)totalCamposTemplateOs(),
        'request_id'=>bin2hex(random_bytes(12)),
    ];

    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$post,
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>12,
        CURLOPT_TIMEOUT=>$timeout,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    $body=curl_exec($ch);
    $errno=curl_errno($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($body===false||$errno)throw new RuntimeException('n8n indisponível para analisar a OS: '.$error);
    if($status<200||$status>=300)throw new RuntimeException('n8n respondeu HTTP '.$status.' ao analisar a OS.');

    try{$payload=json_decode((string)$body,true,512,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('n8n devolveu uma resposta JSON inválida.');}
    if(!is_array($payload))throw new RuntimeException('n8n devolveu uma resposta vazia.');
    if(array_key_exists('ok',$payload)&&!$payload['ok'])throw new RuntimeException(trim((string)($payload['error']??$payload['erro']??'Falha informada pelo n8n.')));
    return $payload;
}

/** @return array{template:array,meta:array} */
function normalizarRespostaN8nOs(array $payload,array $localTemplate=[]): array
{
    $rawFields=$payload['fields']??$payload['template']??($payload['data']['fields']??$payload['data']??[]);
    if(!is_array($rawFields))$rawFields=[];
    $remote=normalizarTemplateOsJson($rawFields);

    $eventos=$payload['events']??$payload['eventos_campo']??($payload['data']['events']??null);
    if(is_array($eventos)&&$eventos){
        $remote['eventos_campo']=[];
        foreach($eventos as $ev){
            if(!is_array($ev))continue;
            $row=['hora'=>trim((string)($ev['hora']??$ev['HORA']??'')),'evento'=>trim((string)($ev['evento']??$ev['EVENTO']??$ev['descricao']??'')),'tipo'=>trim((string)($ev['tipo']??$ev['TIPO']??''))];
            if(array_filter($row,static fn($v)=>$v!==''))$remote['eventos_campo'][]=$row;
        }
        if($remote['eventos_campo']){
            $remote['evento_hora']=$remote['eventos_campo'][0]['hora'];
            $remote['evento']=$remote['eventos_campo'][0]['evento'];
            $remote['evento_tipo']=$remote['eventos_campo'][0]['tipo'];
        }
    }

    // O resultado inteligente tem preferência; a leitura PHP só cobre lacunas.
    $final=templateOsVazio();
    foreach(array_keys(camposTemplateOsSimplificada()) as $key){
        $rv=trim((string)($remote[$key]??''));$lv=trim((string)($localTemplate[$key]??''));
        $final[$key]=$rv!==''?$rv:$lv;
    }
    $final['eventos_campo']=!empty($remote['eventos_campo'])?$remote['eventos_campo']:($localTemplate['eventos_campo']??[]);
    if($final['eventos_campo']){
        $final['evento_hora']=$final['eventos_campo'][0]['hora']??$final['evento_hora'];
        $final['evento']=$final['eventos_campo'][0]['evento']??$final['evento'];
        $final['evento_tipo']=$final['eventos_campo'][0]['tipo']??$final['evento_tipo'];
    }
    foreach(['_raw_text','_raw_layout_text'] as $extra)if(isset($localTemplate[$extra]))$final[$extra]=$localTemplate[$extra];

    $filled=0;foreach(array_keys(camposTemplateOsSimplificada()) as $key)if(trim((string)($final[$key]??''))!=='')$filled++;
    $unresolved=$payload['unresolved']??$payload['campos_nao_resolvidos']??[];if(!is_array($unresolved))$unresolved=[];
    $empty=$payload['empty']??$payload['campos_vazios']??[];if(!is_array($empty))$empty=[];
    $confidence=$payload['confidence']??$payload['confianca']??[];if(!is_array($confidence))$confidence=[];
    $photos=$payload['photos']??$payload['fotos']??[];if(!is_array($photos))$photos=[];
    // Não mantém base64 em sessão; fotos binárias terão armazenamento próprio na etapa seguinte.
    $photoMeta=[];
    foreach($photos as $foto){
        if(!is_array($foto))continue;
        $photoMeta[]=[
            'slot'=>mb_substr((string)($foto['slot']??$foto['campo']??''),0,40),
            'name'=>mb_substr((string)($foto['name']??$foto['nome']??''),0,180),
            'page'=>(int)($foto['page']??$foto['pagina']??0),
            'caption'=>mb_substr((string)($foto['caption']??$foto['descricao']??''),0,800),
            'confidence'=>isset($foto['confidence'])?(float)$foto['confidence']:null,
        ];
    }
    return ['template'=>$final,'meta'=>[
        'engine'=>'n8n','total'=>totalCamposTemplateOs(),'filled'=>$filled,'empty'=>array_values($empty),'unresolved'=>array_values($unresolved),
        'confidence'=>$confidence,'photos'=>$photoMeta,'photos_count'=>count($photoMeta),
        'extractor'=>trim((string)($payload['extractor']??'')),'azure_status'=>trim((string)($payload['azure_status']??'')),
    ]];
}
