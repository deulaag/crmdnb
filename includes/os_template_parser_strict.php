<?php
declare(strict_types=1);
require_once __DIR__ . '/os_template_service.php';

/**
 * Reconhece o rótulo no início da linha. O Iliot exporta a maior parte das
 * tabelas sem dois-pontos, enquanto alguns campos usam ':'; ambos são válidos.
 */
function regexRotuloCampoOsEstrito(string $rotulo): string
{
    $partes=preg_split('/\s+/u',trim($rotulo))?:[];
    $miolo=implode('(?:[\h\x{00A0}]|\R)+',array_map(static fn(string $p):string=>preg_quote($p,'~'),$partes));
    $doisPontos=chaveOs($rotulo)==='os'?':':':?';
    return '~^[\h\x{00A0}]*'.$miolo.'(?![\pL\pN_])[\h\x{00A0}]*'.$doisPontos.'[\h\x{00A0}]*~imu';
}

/** @return array{0:int,1:int}|null */
function localizarCampoOsEstrito(string $texto,string $key,int $offset=0): ?array
{
    $melhor=null;
    foreach(variantesRotuloOs($key) as $rotulo){
        if(!preg_match(regexRotuloCampoOsEstrito((string)$rotulo),$texto,$m,PREG_OFFSET_CAPTURE,$offset))continue;
        $loc=[(int)$m[0][1],strlen((string)$m[0][0])];
        if($melhor===null||$loc[0]<$melhor[0])$melhor=$loc;
    }
    return $melhor;
}

/** @return array<int,array{0:int,1:int}> */
function localizarTodosCamposOsEstritos(string $texto,string $key): array
{
    $locais=[];
    foreach(variantesRotuloOs($key) as $rotulo){
        preg_match_all(regexRotuloCampoOsEstrito((string)$rotulo),$texto,$matches,PREG_OFFSET_CAPTURE);
        foreach($matches[0]??[] as $match){
            $pos=(int)$match[1];$fim=$pos+strlen((string)$match[0]);
            $locais[$pos.':'.$fim]=[$pos,$fim-$pos];
        }
    }
    $locais=array_values($locais);
    usort($locais,static fn(array $a,array $b):int=>$a[0]<=>$b[0]);
    return $locais;
}

/** Regex de título de seção: ocupa somente a linha do título, sem engolir a próxima. */
function regexSecaoOsEstrita(string $titulo): string
{
    $partes=preg_split('/\s+/u',trim($titulo))?:[];
    $miolo=implode('[\h\x{00A0}]*',array_map(static fn(string $p):string=>preg_quote($p,'~'),$partes));
    return '~^[\h\x{00A0}]*'.$miolo.'[\h\x{00A0}]*(?:\([^\r\n]*\))?[\h\x{00A0}]*$~imu';
}

/** @return array{0:int,1:int}|null */
function localizarSecaoOsEstrita(string $texto,string $titulo,int $offset=0): ?array
{
    $melhor=null;
    foreach(variantesSecaoOs($titulo) as $variante){
        if(!preg_match(regexSecaoOsEstrita((string)$variante),$texto,$m,PREG_OFFSET_CAPTURE,$offset))continue;
        $loc=[(int)$m[0][1],strlen((string)$m[0][0])];
        if($melhor===null||$loc[0]<$melhor[0])$melhor=$loc;
    }
    return $melhor;
}

function recortarSecaoOsEstrita(string $texto,?string $inicio,?string $fim): string
{
    $inicioPos=0;
    if($inicio!==null){$loc=localizarSecaoOsEstrita($texto,$inicio);if(!$loc)return '';$inicioPos=$loc[0]+$loc[1];}
    $fimPos=strlen($texto);
    if($fim!==null){$loc=localizarSecaoOsEstrita($texto,$fim,$inicioPos);if($loc)$fimPos=$loc[0];}
    return trim(substr($texto,$inicioPos,max(0,$fimPos-$inicioPos)));
}

/**
 * Remove cabeçalhos e rodapés repetidos entre páginas sem apagar a continuação
 * de relatórios longos que atravessam a quebra de página.
 */
function limparRuidoPaginacaoOs(string $valor): string
{
    $valor=preg_replace(
        '~^\d{2}/\d{2}/\d{4}[\h]+\d{2}:\d{2}\R+p[áa]gina[\h]+\d+/\d+\R+.*?^\d+\R+^/\R+^\d+\R*~msiu',
        '',
        $valor
    )??$valor;
    return limparValorOs($valor);
}

/** Extrai campos pelo intervalo entre rótulos ancorados, preservando repetições. */
function extrairCamposSecaoOsEstrito(string $secao,array $campos,array &$dados): void
{
    $achados=[];
    foreach($campos as $key){
        foreach(localizarTodosCamposOsEstritos($secao,(string)$key) as $loc)$achados[]=['key'=>$key,'pos'=>$loc[0],'fim'=>$loc[0]+$loc[1]];
    }
    usort($achados,static fn(array $a,array $b):int=>$a['pos']<=>$b['pos']);
    for($i=0,$n=count($achados);$i<$n;$i++){
        $atual=$achados[$i];$fim=$i+1<$n?$achados[$i+1]['pos']:strlen($secao);
        $valor=limparRuidoPaginacaoOs(substr($secao,$atual['fim'],max(0,$fim-$atual['fim'])));
        acumularValorTemplateOs($dados,(string)$atual['key'],$valor);
    }
}

function extrairValorImediatoCampoOs(string $texto,string $key): string
{
    foreach(localizarTodosCamposOsEstritos($texto,$key) as $loc){
        $resto=substr($texto,$loc[0]+$loc[1]);
        $linhas=preg_split('/\R/u',$resto,3)?:[];
        $mesmaLinha=limparValorOs((string)($linhas[0]??''));
        if($mesmaLinha!=='')return $mesmaLinha;
        foreach(array_slice($linhas,1) as $linha){
            $valor=limparValorOs((string)$linha);
            if($valor!=='')return $valor;
        }
    }
    return '';
}

/** @return array<int,array{hora:string,evento:string,tipo:string}> */
function extrairEventosCampoOsEstrito(string $secao): array
{
    $linhas=array_values(array_filter(array_map('trim',preg_split('/\R/u',$secao)?:[]),static fn(string $v):bool=>$v!==''));
    $inicio=0;
    for($i=0,$n=count($linhas);$i<$n;$i++){
        $chave=chaveOs($linhas[$i]);
        if($chave==='hora_evento_tipo'){$inicio=$i+1;break;}
        if($chave==='hora'&&chaveOs($linhas[$i+1]??'')==='evento'&&chaveOs($linhas[$i+2]??'')==='tipo'){$inicio=$i+3;break;}
    }

    $eventos=[];
    for($i=$inicio,$n=count($linhas);$i<$n;$i++){
        $linha=$linhas[$i];
        if(preg_match('/^(\d{2}\/\d{2}\/\d{4}[\h]+\d{2}:\d{2})$/u',$linha,$m)){
            $evento=trim((string)($linhas[$i+1]??''));$tipo=trim((string)($linhas[$i+2]??''));
            if($evento!==''||$tipo!==''){$eventos[]=['hora'=>$m[1],'evento'=>$evento,'tipo'=>$tipo];$i+=2;}
            continue;
        }
        if(preg_match('/^(\d{2}\/\d{2}\/\d{4}[\h]+\d{2}:\d{2})[\h]+(.+?)[\h]+(Check-in|Check-out|Visita|Diagnóstico|Diagnostico)$/iu',$linha,$m)){
            $eventos[]=['hora'=>$m[1],'evento'=>trim($m[2]),'tipo'=>trim($m[3])];
        }
    }
    if($eventos)return $eventos;

    // Compatibilidade com PDFs/fixtures antigos no formato HORA: valor.
    $tokens=[];
    foreach(['hora'=>'evento_hora','evento'=>'evento','tipo'=>'evento_tipo'] as $dest=>$key){
        foreach(localizarTodosCamposOsEstritos($secao,$key) as $loc)$tokens[]=['key'=>$dest,'pos'=>$loc[0],'fim'=>$loc[0]+$loc[1]];
    }
    usort($tokens,static fn(array $a,array $b):int=>$a['pos']<=>$b['pos']);
    $atual=['hora'=>'','evento'=>'','tipo'=>''];
    for($i=0,$n=count($tokens);$i<$n;$i++){
        $token=$tokens[$i];$fim=$i+1<$n?$tokens[$i+1]['pos']:strlen($secao);
        $atual[$token['key']]=limparRuidoPaginacaoOs(substr($secao,$token['fim'],max(0,$fim-$token['fim'])));
    }
    return array_filter($atual,static fn(string $v):bool=>$v!=='')?[$atual]:[];
}

/**
 * Parser de produção da OS. Mantém o contrato de 72 campos e trata seções/rótulos
 * de forma estrita. O parser legado permanece apenas por compatibilidade interna.
 */
function extrairTemplateOsTextoEstrito(string $texto): array
{
    $dados=templateOsVazio();$texto=normalizarTextoOs($texto);
    foreach(secoesTemplateOsSimplificada() as $config){
        $secao=recortarSecaoOsEstrita($texto,$config['inicio'],$config['fim']);if($secao==='')continue;
        extrairCamposSecaoOsEstrito($secao,$config['campos'],$dados);
        if(!empty($config['eventos'])){
            $eventos=extrairEventosCampoOsEstrito($secao);
            if($eventos){$dados['eventos_campo']=$eventos;$dados['evento_hora']=$eventos[0]['hora']??'';$dados['evento']=$eventos[0]['evento']??'';$dados['evento_tipo']=$eventos[0]['tipo']??'';}
        }
    }

    // O extrator de alguns PDFs coloca o cabeçalho no fim da página. Estes
    // campos são únicos e podem ser recuperados no documento inteiro.
    foreach(['os','codigo_checklist','data_envio_sistema','endereco'] as $key){
        if(trim((string)($dados[$key]??''))==='')$dados[$key]=extrairValorImediatoCampoOs($texto,$key);
    }
    $dados['_raw_text']=$texto;
    return $dados;
}
