<?php
declare(strict_types=1);

function camposTemplateOsSimplificada(): array
{
    return [
        'os'=>'OS','codigo_checklist'=>'CÓD. CHECKLIST (SERVIÇO)','data_envio_sistema'=>'DATA ENVIO OS SISTEMA',
        'endereco'=>'ENDEREÇO','nome_cnpj'=>'NOME/CPNJ','pessoa_contato'=>'PESSOA DE CONTATO','telefone_1'=>'TELEFONE 1','email'=>'EMAIL','identificacao_localidade'=>'IDENTIFICAÇÃO DA LOCALIDADE',
        'projeto_contrato'=>'PROJETO/CONTRATO','formulario_servico'=>'FORMULÁRIO DE SERVIÇO','profissionais'=>'PROFISSIONAIS','descricao_atividade'=>'DESCRIÇÃO DA ATIVIDADE','tipo_servico'=>'TIPO DE SERVIÇO',
        'evento_hora'=>'HORA','evento'=>'EVENTO','evento_tipo'=>'TIPO','por_profissional'=>'POR PROFISSIONAL','total_hh'=>'TOTAL HH','tempo_trabalho'=>'Tempo total de trabalho','tempo_deslocamento'=>'Tempo total de deslocamento',
        'informacoes_cliente'=>'Informações do cliente','responsavel_cliente'=>'Respónsavel','numero_contato'=>'Nº Contato','email_cliente'=>'Email','setor'=>'Setor','local_instalacao'=>'Local da Instalação','vendedor'=>'Vendedor',
        'equipamento_operando'=>'Equipamento está operando normalmente?','instalacao_conforme'=>'A instalação está conforme recomendação do fabricante?','possui_secador'=>'A instalação possui secador de ar?','secador_funcionando'=>'O secador de ar está funcionando corretamente?','possui_reservatorio_dreno'=>'A instalação possui reservatório de ar? reservatório possui dreno?','agua_dreno'=>'Sai água pelo dreno do reservatório?','possui_filtros'=>'A instalação possui filtros de ar?','filtros_dreno'=>'Os filtros possuem dreno?',
        'fotos'=>'Fotos','foto_1'=>'Detalhes foto 1','foto_2'=>'Detalhes foto 2','foto_3'=>'Detalhes foto 3','foto_4'=>'Detalhes foto 4','relatorio_descritivo'=>'Relatório descritivo',
        'ordem_compressor'=>'COMPRESSOR','ordem_secador'=>'SECADOR DE AR','compressores'=>'Compressores','equipamento_comentario'=>'Comentário','fabricante'=>'Fabricante','modelos'=>'Modelos','numero_serie'=>'Numero de serie','horimetro'=>'Horimetro (horas totais)','registro_manutencao'=>'Registro de manutenção existente?','pecas_originais'=>'Cliente utiliza peças originais?','tipo_oleo'=>'Tipo de óleo utilizado',
        'indicador_preventiva'=>'Indicador de Manutenção preventiva','plano_preventiva_apresentado'=>'Foi apresentado ao cliente o plano de manutenção preventiva (1x ao ano)?','interesse_preventiva'=>'Cliente demonstrou interesse?',
        'informacao_oportunidade'=>'Informação da Oportunidade','agua_linha'=>'Presença excessiva de água na linha de ar','oleo_ar'=>'Contaminação por óleo no ar comprimido','paradas_frequentes'=>'Paradas frequentes ou falhas inesperadas','queda_pressao'=>'Queda de pressão durante a operação','consumo_energia'=>'Consumo elevado de energia','ruidos_vibracoes'=>'Ruídos ou vibrações anormais','vazamentos'=>'Vazamentos frequentes na rede','qualidade_ar'=>'Dificuldade em manter qualidade do ar','perda_producao'=>'Perda de produção por falha do sistema','preventiva_regular'=>'Manutenção preventiva realizada regularmente',
        'oportunidade_comercial'=>'Oportunidade Comercial','oportunidade_comentario'=>'Comentário','relatorio_visita_tecnica'=>'Relatório de Visita Técnica','proximos_passos'=>'Próximos Passos','assinatura_vendedor'=>'Assinatura do Vendedor',
    ];
}

/** Ordem e limites reais das seções do template oficial. */
function secoesTemplateOsSimplificada(): array
{
    return [
        ['inicio'=>null,'fim'=>'IDENTIFICAÇÃO DO CLIENTE','campos'=>['os','codigo_checklist','data_envio_sistema']],
        ['inicio'=>'IDENTIFICAÇÃO DO CLIENTE','fim'=>'DETALHAMENTO DO SERVIÇO','campos'=>['endereco','nome_cnpj','pessoa_contato','telefone_1','email','identificacao_localidade']],
        ['inicio'=>'DETALHAMENTO DO SERVIÇO','fim'=>'EVENTOS EM CAMPO','campos'=>['projeto_contrato','formulario_servico','profissionais','descricao_atividade','tipo_servico']],
        ['inicio'=>'EVENTOS EM CAMPO','fim'=>'TOTAIS','campos'=>['evento_hora','evento','evento_tipo'],'eventos'=>true],
        ['inicio'=>'TOTAIS','fim'=>'ITENS VERIFICADOS','campos'=>['por_profissional','total_hh','tempo_trabalho','tempo_deslocamento']],
        ['inicio'=>'0-INFORMAÇÃO DO CLIENTE','fim'=>'1- CONDIÇÕES GERAIS DA INSTALAÇÃO','campos'=>['informacoes_cliente','responsavel_cliente','numero_contato','email_cliente','setor','local_instalacao','vendedor']],
        ['inicio'=>'1- CONDIÇÕES GERAIS DA INSTALAÇÃO','fim'=>'2-ORDEM DE INSTALAÇÃO DOS EQUIPAMENTOS','campos'=>['equipamento_operando','instalacao_conforme','possui_secador','secador_funcionando','possui_reservatorio_dreno','agua_dreno','possui_filtros','filtros_dreno','fotos','foto_1','foto_2','foto_3','foto_4','relatorio_descritivo']],
        ['inicio'=>'2-ORDEM DE INSTALAÇÃO DOS EQUIPAMENTOS','fim'=>'3-DADOS TÉCNICOS DOS EQUIPAMENTOS E FOTOGRAFIAS GERAIS.','campos'=>['ordem_compressor','ordem_secador']],
        ['inicio'=>'3-DADOS TÉCNICOS DOS EQUIPAMENTOS E FOTOGRAFIAS GERAIS.','fim'=>'4-ABORDAGEM COMERCIAL - MANUTENÇÃO PREVENTIVA','campos'=>['compressores','equipamento_comentario','fabricante','modelos','numero_serie','horimetro','registro_manutencao','pecas_originais','tipo_oleo']],
        ['inicio'=>'4-ABORDAGEM COMERCIAL - MANUTENÇÃO PREVENTIVA','fim'=>'5-PERCEPÇÃO DO CLIENTE - PROBLEMAS COMUNS','campos'=>['indicador_preventiva','plano_preventiva_apresentado','interesse_preventiva']],
        ['inicio'=>'5-PERCEPÇÃO DO CLIENTE - PROBLEMAS COMUNS','fim'=>'6-OPORTUNIDADES IDENTIFICADAS','campos'=>['informacao_oportunidade','agua_linha','oleo_ar','paradas_frequentes','queda_pressao','consumo_energia','ruidos_vibracoes','vazamentos','qualidade_ar','perda_producao','preventiva_regular']],
        ['inicio'=>'6-OPORTUNIDADES IDENTIFICADAS','fim'=>null,'campos'=>['oportunidade_comercial','oportunidade_comentario','relatorio_visita_tecnica','proximos_passos','assinatura_vendedor']],
    ];
}

function chaveOs(string $valor): string
{
    $valor=mb_strtolower(trim($valor));
    $ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$valor);
    if($ascii!==false)$valor=$ascii;
    return trim((string)preg_replace('/[^a-z0-9]+/','_',strtolower($valor)),'_');
}

function templateOsVazio(): array
{
    return array_fill_keys(array_keys(camposTemplateOsSimplificada()),'');
}

function regexMarcadorOs(string $marcador): string
{
    $partes=preg_split('/\s+/u',trim($marcador))?:[];
    $regex=implode('\\s+',array_map(static fn(string $p):string=>preg_quote($p,'/'),$partes));
    return '/'.$regex.'/iu';
}

/** @return array{0:int,1:int}|null posição e tamanho em bytes. */
function localizarMarcadorOs(string $texto,string $marcador,int $offset=0): ?array
{
    if($marcador==='')return null;
    if(!preg_match(regexMarcadorOs($marcador),$texto,$m,PREG_OFFSET_CAPTURE,$offset))return null;
    return [(int)$m[0][1],strlen((string)$m[0][0])];
}

function recortarSecaoOs(string $texto,?string $inicio,?string $fim): string
{
    $inicioPos=0;
    if($inicio!==null){$loc=localizarMarcadorOs($texto,$inicio);if(!$loc)return '';$inicioPos=$loc[0]+$loc[1];}
    $fimPos=strlen($texto);
    if($fim!==null){$locFim=localizarMarcadorOs($texto,$fim,$inicioPos);if($locFim)$fimPos=$locFim[0];}
    return trim(substr($texto,$inicioPos,max(0,$fimPos-$inicioPos)));
}

function limparValorOs(string $valor): string
{
    $valor=preg_replace('/^[\s:\-–—|]+/u','',$valor)??$valor;
    $valor=preg_replace('/[\t ]+/u',' ',$valor)??$valor;
    $valor=preg_replace('/\n[\t ]+/u',"\n",$valor)??$valor;
    $valor=preg_replace('/\n{3,}/u',"\n\n",$valor)??$valor;
    return trim($valor);
}

/** Extrai todos os campos de uma seção usando o próximo rótulo como limite, preservando texto multilinha. */
function extrairCamposDaSecaoOs(string $secao,array $campos,array &$dados): void
{
    $labels=camposTemplateOsSimplificada();$achados=[];
    foreach($campos as $key){
        $label=$labels[$key]??'';$loc=localizarMarcadorOs($secao,$label);
        if($loc)$achados[]=['key'=>$key,'pos'=>$loc[0],'fim'=>$loc[0]+$loc[1]];
    }
    usort($achados,static fn(array $a,array $b):int=>$a['pos']<=>$b['pos']);
    $n=count($achados);
    for($i=0;$i<$n;$i++){
        $a=$achados[$i];$fim=$i+1<$n?$achados[$i+1]['pos']:strlen($secao);
        $valor=limparValorOs(substr($secao,$a['fim'],max(0,$fim-$a['fim'])));
        if($valor!=='')$dados[$a['key']]=$valor;
    }
}

/** @return array<int,array{hora:string,evento:string,tipo:string}> */
function extrairEventosCampoOs(string $secao): array
{
    $tokens=[];
    foreach(['hora'=>'HORA','evento'=>'EVENTO','tipo'=>'TIPO'] as $key=>$label){
        preg_match_all(regexMarcadorOs($label),$secao,$ms,PREG_OFFSET_CAPTURE);
        foreach($ms[0]??[] as $m)$tokens[]=['key'=>$key,'pos'=>(int)$m[1],'fim'=>(int)$m[1]+strlen((string)$m[0])];
    }
    usort($tokens,static fn(array $a,array $b):int=>$a['pos']<=>$b['pos']);
    $eventos=[];$atual=['hora'=>'','evento'=>'','tipo'=>''];$n=count($tokens);
    for($i=0;$i<$n;$i++){
        $t=$tokens[$i];$fim=$i+1<$n?$tokens[$i+1]['pos']:strlen($secao);
        $valor=limparValorOs(substr($secao,$t['fim'],max(0,$fim-$t['fim'])));
        if($t['key']==='hora' && array_filter($atual)){$eventos[]=$atual;$atual=['hora'=>'','evento'=>'','tipo'=>''];}
        $atual[$t['key']]=$valor;
    }
    if(array_filter($atual))$eventos[]=$atual;
    return array_values(array_filter($eventos,static fn(array $e):bool=>(bool)array_filter($e)));
}

function normalizarTemplateOsJson(array $entrada): array
{
    $dados=templateOsVazio();$aliases=[];
    foreach(camposTemplateOsSimplificada() as $key=>$label){$aliases[chaveOs($key)]=$key;if(chaveOs($label)!=='comentario')$aliases[chaveOs($label)]=$key;}
    $aliases+=['cliente'=>'nome_cnpj','empresa'=>'nome_cnpj','razao_social'=>'nome_cnpj','cnpj'=>'nome_cnpj','contato'=>'pessoa_contato','telefone'=>'telefone_1','fone'=>'telefone_1','oportunidade'=>'oportunidade_comercial','data'=>'data_envio_sistema','atividade'=>'descricao_atividade'];
    $eventos=[];
    $walk=function(array $arr,array $path=[])use(&$walk,&$dados,&$eventos,$aliases):void{
        foreach($arr as $k=>$v){
            $novoPath=array_merge($path,[(string)$k]);$nk=chaveOs((string)$k);$contexto=chaveOs(implode(' ',$novoPath));
            if(is_array($v)){
                if(str_contains($contexto,'evento') && array_is_list($v)){
                    foreach($v as $ev)if(is_array($ev))$eventos[]=['hora'=>(string)($ev['hora']??$ev['HORA']??''),'evento'=>(string)($ev['evento']??$ev['EVENTO']??''),'tipo'=>(string)($ev['tipo']??$ev['TIPO']??'')];
                }
                $walk($v,$novoPath);continue;
            }
            $valor=trim((string)$v);if($valor==='')continue;
            if($nk==='comentario'){
                $dest=str_contains($contexto,'oportun')?'oportunidade_comentario':(str_contains($contexto,'tecn')||str_contains($contexto,'equip')?'equipamento_comentario':($dados['equipamento_comentario']===''?'equipamento_comentario':'oportunidade_comentario'));
            }else{$dest=$aliases[$nk]??null;}
            if($dest)$dados[$dest]=$valor;
        }
    };
    $walk($entrada);
    if($eventos)$dados['eventos_campo']=$eventos;
    $dados['_raw_json']=$entrada;
    return $dados;
}

function extrairTemplateOsTexto(string $texto): array
{
    $dados=templateOsVazio();
    $texto=str_replace(["\r\n","\r"],"\n",$texto);
    foreach(secoesTemplateOsSimplificada() as $config){
        $secao=recortarSecaoOs($texto,$config['inicio'],$config['fim']);
        if($secao==='')continue;
        extrairCamposDaSecaoOs($secao,$config['campos'],$dados);
        if(!empty($config['eventos'])){
            $eventos=extrairEventosCampoOs($secao);
            if($eventos){
                $dados['eventos_campo']=$eventos;
                $dados['evento_hora']=$eventos[0]['hora']??'';$dados['evento']=$eventos[0]['evento']??'';$dados['evento_tipo']=$eventos[0]['tipo']??'';
            }
        }
    }
    $fallbacks=[
        'nome_cnpj'=>'/(?:NOME\s*\/\s*CNPJ|NOME\s*\/\s*CPNJ|CLIENTE|EMPRESA)\s*:\s*([^\n]+)/iu',
        'pessoa_contato'=>'/(?:PESSOA\s+DE\s+CONTATO|CONTATO)\s*:\s*([^\n]+)/iu',
        'telefone_1'=>'/(?:TELEFONE\s*1|TELEFONE|FONE)\s*:\s*([^\n]+)/iu',
        'vendedor'=>'/(?:VENDEDOR|CONSULTOR)\s*:\s*([^\n]+)/iu',
        'oportunidade_comercial'=>'/(?:OPORTUNIDADE\s+COMERCIAL|OPORTUNIDADES?\s+IDENTIFICADAS?)\s*:\s*([^\n]+)/iu',
    ];
    foreach($fallbacks as $key=>$regex)if(($dados[$key]??'')===''&&preg_match($regex,$texto,$m))$dados[$key]=limparValorOs((string)$m[1]);
    $dados['_raw_text']=mb_substr($texto,0,100000);
    return $dados;
}

function dadosBasicosDaOs(array $os): array
{
    $nomeCnpj=trim((string)($os['nome_cnpj']??''));$cnpj='';$empresa=$nomeCnpj;
    if(preg_match('/(\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2})/',$nomeCnpj,$m)){$cnpj=$m[1];$empresa=trim(str_replace($m[1],'',$nomeCnpj)," -|/\t\n\r\0\x0B");}
    if($empresa==='')$empresa=trim((string)($os['informacoes_cliente']??''));
    $data=trim((string)($os['data_envio_sistema']??''));
    if(preg_match('#^(\d{2})[/\-](\d{2})[/\-](\d{4})#',$data,$m))$data="{$m[3]}-{$m[2]}-{$m[1]}";
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$data))$data='';
    $resumo=array_filter([(string)($os['descricao_atividade']??''),(string)($os['relatorio_descritivo']??''),(string)($os['relatorio_visita_tecnica']??''),(string)($os['equipamento_comentario']??''),(string)($os['oportunidade_comentario']??'')],static fn(string $v):bool=>trim($v)!=='');
    return [
        'os'=>trim((string)($os['os']??'')),'data'=>$data,'cliente'=>$empresa,'cnpj'=>$cnpj,
        'contato'=>trim((string)(($os['pessoa_contato']??'')!==''?$os['pessoa_contato']:($os['responsavel_cliente']??''))),
        'telefone'=>trim((string)(($os['telefone_1']??'')!==''?$os['telefone_1']:($os['numero_contato']??''))),
        'email'=>trim((string)(($os['email']??'')!==''?$os['email']:($os['email_cliente']??''))),
        'setor'=>trim((string)($os['setor']??'')),'endereco'=>trim((string)($os['endereco']??'')),
        'localidade'=>trim((string)(($os['identificacao_localidade']??'')!==''?$os['identificacao_localidade']:($os['local_instalacao']??''))),
        'vendedor'=>trim((string)($os['vendedor']??'')),
        'oportunidade'=>trim((string)(($os['oportunidade_comercial']??'')!==''?$os['oportunidade_comercial']:(($os['informacao_oportunidade']??'')!==''?$os['informacao_oportunidade']:($os['proximos_passos']??'')))),
        'resumo'=>implode("\n",$resumo),'tipo_servico'=>trim((string)($os['tipo_servico']??'')),
    ];
}

function contarCamposPreenchidosTemplateOs(array $os): int
{
    $n=0;foreach(array_keys(camposTemplateOsSimplificada()) as $key)if(trim((string)($os[$key]??''))!=='')$n++;
    if(!empty($os['eventos_campo'])&&is_array($os['eventos_campo']))$n+=count($os['eventos_campo']);
    return $n;
}

function templateOsJson(array $os): string
{
    return json_encode($os,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
