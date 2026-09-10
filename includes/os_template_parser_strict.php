<?php
declare(strict_types=1);
require_once __DIR__ . '/os_template_service.php';

/** Regex de rótulo preenchível: exige ':' real e nunca casa dentro de uma resposta. */
function regexRotuloCampoOsEstrito(string $rotulo): string
{
    $partes=preg_split('/\s+/u',trim($rotulo))?:[];
    $miolo=implode('(?:[\h\x{00A0}]|\R)+',array_map(static fn(string $p):string=>preg_quote($p,'~'),$partes));
    return '~(?<![\pL\pN_])'.$miolo.'(?![\pL\pN_])[\h\x{00A0}]*:[\h\x{00A0}]*~iu';
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

/** Regex de título de seção: ocupa somente a linha do título, sem engolir a próxima. */
function regexSecaoOsEstrita(string $titulo): string
{
    $partes=preg_split('/\s+/u',trim($titulo))?:[];
    $miolo=implode('[\h\x{00A0}]*',array_map(static fn(string $p):string=>preg_quote($p,'~'),$partes));
    return '~^[\h\x{00A0}]*'.$miolo.'[\h\x{00A0}]*$~imu';
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
 * Extrai todos os campos da seção pelo intervalo entre rótulos reais.
 * Como o ':' é obrigatório, palavras existentes dentro dos valores não viram campos.
 */
function extrairCamposSecaoOsEstrito(string $secao,array $campos,array &$dados): void
{
    $achados=[];
    foreach($campos as $key){$loc=localizarCampoOsEstrito($secao,(string)$key);if($loc)$achados[]=['key'=>$key,'pos'=>$loc[0],'fim'=>$loc[0]+$loc[1]];}
    usort($achados,static fn(array $a,array $b):int=>$a['pos']<=>$b['pos']);
    for($i=0,$n=count($achados);$i<$n;$i++){
        $atual=$achados[$i];$fim=$i+1<$n?$achados[$i+1]['pos']:strlen($secao);
        $dados[$atual['key']]=limparValorOs(substr($secao,$atual['fim'],max(0,$fim-$atual['fim'])));
    }
}

/** @return array<int,array{hora:string,evento:string,tipo:string}> */
function extrairEventosCampoOsEstrito(string $secao): array
{
    $tokens=[];
    foreach(['hora'=>'evento_hora','evento'=>'evento','tipo'=>'evento_tipo'] as $dest=>$key){
        foreach(variantesRotuloOs($key) as $rotulo){
            preg_match_all(regexRotuloCampoOsEstrito((string)$rotulo),$secao,$ms,PREG_OFFSET_CAPTURE);
            foreach($ms[0]??[] as $m)$tokens[]=['key'=>$dest,'pos'=>(int)$m[1],'fim'=>(int)$m[1]+strlen((string)$m[0])];
        }
    }
    usort($tokens,static fn(array $a,array $b):int=>$a['pos']<=>$b['pos']);
    // Remove o mesmo token encontrado por duas variantes equivalentes.
    $tokens=array_values(array_reduce($tokens,static function(array $acc,array $t):array{
        $assinatura=$t['key'].'@'.$t['pos'];foreach($acc as $a)if(($a['_sig']??'')===$assinatura)return $acc;$t['_sig']=$assinatura;$acc[]=$t;return $acc;
    },[]));
    $eventos=[];$atual=['hora'=>'','evento'=>'','tipo'=>''];
    for($i=0,$n=count($tokens);$i<$n;$i++){
        $t=$tokens[$i];$fim=$i+1<$n?$tokens[$i+1]['pos']:strlen($secao);
        $valor=limparValorOs(substr($secao,$t['fim'],max(0,$fim-$t['fim'])));
        if($t['key']==='hora'&&array_filter($atual,static fn($v)=>trim((string)$v)!=='')){$eventos[]=$atual;$atual=['hora'=>'','evento'=>'','tipo'=>''];}
        $atual[$t['key']]=$valor;
    }
    if(array_filter($atual,static fn($v)=>trim((string)$v)!==''))$eventos[]=$atual;
    return array_values($eventos);
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
    $dados['_raw_text']=$texto;
    return $dados;
}
