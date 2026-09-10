<?php
/** Extração robusta do TEMPLATE: ORDEM DE SERVIÇO DE CAMPO SIMPLIFICADA. */
require_once __DIR__ . '/os_template_service.php';
require_once __DIR__ . '/os_template_parser_strict.php';

/** Reconstrói linhas da página pelas coordenadas do texto para preservar label -> valor. */
function reconstruirTextoPaginaPdf(array $dataTm): string
{
    $itens=[];
    foreach($dataTm as $item){
        if(!is_array($item)||!isset($item[0],$item[1])||!is_array($item[0])||count($item[0])<6)continue;
        $texto=trim((string)$item[1]);if($texto==='')continue;
        $itens[]=['x'=>(float)$item[0][4],'y'=>(float)$item[0][5],'texto'=>$texto];
    }
    usort($itens,static function(array $a,array $b):int{
        if(abs($a['y']-$b['y'])>2.5)return $a['y']<$b['y']?1:-1;
        return $a['x']<=>$b['x'];
    });
    $linhas=[];
    foreach($itens as $item){
        $idx=count($linhas)-1;
        if($idx<0||abs($linhas[$idx]['y']-$item['y'])>2.5){
            $linhas[]=['y'=>$item['y'],'itens'=>[$item]];
            continue;
        }
        $linhas[$idx]['itens'][]=$item;
    }
    $saida=[];
    foreach($linhas as $linha){
        usort($linha['itens'],static fn(array $a,array $b):int=>$a['x']<=>$b['x']);
        $partes=[];
        foreach($linha['itens'] as $item)$partes[]=$item['texto'];
        $saida[]=trim(implode(' ',$partes));
    }
    return normalizarTextoOs(implode("\n",$saida));
}

function pontuarMarcadoresTemplateOs(string $texto): int
{
    $score=0;
    foreach(array_keys(camposTemplateOsSimplificada()) as $key)if(localizarCampoOsEstrito($texto,(string)$key))$score++;
    foreach(['IDENTIFICAÇÃO DO CLIENTE','DETALHAMENTO DO SERVIÇO','EVENTOS EM CAMPO','TOTAIS','0-INFORMAÇÃO DO CLIENTE','1- CONDIÇÕES GERAIS DA INSTALAÇÃO','2-ORDEM DE INSTALAÇÃO DOS EQUIPAMENTOS','3-DADOS TÉCNICOS DOS EQUIPAMENTOS E FOTOGRAFIAS GERAIS.','4-ABORDAGEM COMERCIAL - MANUTENÇÃO PREVENTIVA','5-PERCEPÇÃO DO CLIENTE - PROBLEMAS COMUNS','6-OPORTUNIDADES IDENTIFICADAS'] as $secao){
        if(localizarSecaoOsEstrita($texto,$secao))$score+=3;
    }
    return $score;
}

/**
 * Lê o PDF uma única vez e produz duas visões: texto corrido e texto por posição.
 * As duas são interpretadas pelo parser estrito e mescladas campo a campo.
 * O parser só reconhece rótulos reais terminados por ':', evitando confundir
 * palavras HORA/EVENTO/TIPO ou outros nomes de campos que apareçam nas respostas.
 *
 * @return array{texto:string,texto_layout:string,template:array}
 */
function extrairConteudoPdfOs(string $caminhoArquivo): array
{
    $autoload=__DIR__.'/../vendor/autoload.php';
    if(!file_exists($autoload))throw new RuntimeException('Extração de PDF indisponível: dependência não instalada no servidor.');
    require_once $autoload;

    try{
        $parser=new \Smalot\PdfParser\Parser();
        $pdf=$parser->parseFile($caminhoArquivo);
        $textoPaginas=[];$layoutPaginas=[];
        foreach($pdf->getPages() as $page){
            $texto=trim((string)$page->getText());if($texto!=='')$textoPaginas[]=$texto;
            try{$layout=reconstruirTextoPaginaPdf($page->getDataTm());if($layout!=='')$layoutPaginas[]=$layout;}catch(Throwable){/* usa texto corrido como fallback */}
        }
        $texto=normalizarTextoOs(implode("\n\n",$textoPaginas));
        $layout=normalizarTextoOs(implode("\n\n",$layoutPaginas));
    }catch(Throwable $e){
        throw new RuntimeException('Não foi possível ler o PDF enviado. Verifique se o arquivo não está corrompido.');
    }

    if($texto===''&&$layout==='')throw new RuntimeException('O PDF não contém texto extraível. Use um PDF gerado pelo template da OS ou preencha os campos manualmente.');

    $templates=[];
    if($layout!=='')$templates[]=extrairTemplateOsTextoEstrito($layout);
    if($texto!=='')$templates[]=extrairTemplateOsTextoEstrito($texto);
    $template=$templates?mesclarTemplatesExtraidosOs(...$templates):templateOsVazio();
    $template['_raw_text']=$texto!==''?$texto:$layout;
    if($layout!=='')$template['_raw_layout_text']=$layout;

    return ['texto'=>$texto,'texto_layout'=>$layout,'template'=>$template];
}

/** Compatibilidade com chamadas antigas: devolve a representação com mais marcadores do template. */
function extrairTextoPdf(string $caminhoArquivo): string
{
    $doc=extrairConteudoPdfOs($caminhoArquivo);
    $texto=$doc['texto'];$layout=$doc['texto_layout'];
    return pontuarMarcadoresTemplateOs($layout)>=pontuarMarcadoresTemplateOs($texto)&&$layout!==''?$layout:$texto;
}

function prefillVisitaDoTemplate(array $template,string $nomeArquivo='',string $fonte='manual'): array
{
    $basicos=dadosBasicosDaOs($template);
    return [
        'os'=>$basicos['os'],'cliente'=>$basicos['cliente'],'cnpj'=>$basicos['cnpj'],'data'=>$basicos['data'],
        'vendedor'=>$basicos['vendedor'],'contato'=>$basicos['contato'],'telefone'=>$basicos['telefone'],'email'=>$basicos['email'],
        'setor'=>$basicos['setor'],'endereco'=>$basicos['endereco'],'localidade'=>$basicos['localidade'],
        'oportunidade'=>$basicos['oportunidade'],'resumo'=>$basicos['resumo'],'tipo_servico'=>$basicos['tipo_servico'],
        'template'=>$template,'template_json'=>templateOsJson($template),
        'campos_extraidos'=>contarCamposPreenchidosTemplateOs($template),'campos_template'=>totalCamposTemplateOs(),
        'arquivo_original'=>$nomeArquivo!==''?mb_substr(basename($nomeArquivo),0,240):'',
        'fonte'=>$fonte,
    ];
}

function mapearVisitaDeDocumentoPdf(array $documento,string $nomeArquivo): array
{
    $template=is_array($documento['template']??null)?$documento['template']:templateOsVazio();
    return prefillVisitaDoTemplate($template,$nomeArquivo,'pdf');
}

function mapearVisitaDeTextoPdf(string $texto,string $nomeArquivo): array
{
    return prefillVisitaDoTemplate(extrairTemplateOsTextoEstrito($texto),$nomeArquivo,'pdf');
}

function mapearVisitaDeJson(array $dados,string $nomeArquivo=''): array
{
    return prefillVisitaDoTemplate(normalizarTemplateOsJson($dados),$nomeArquivo,'json');
}
