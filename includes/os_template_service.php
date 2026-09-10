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

function chaveOs(string $valor): string
{
    $valor=mb_strtolower(trim($valor));
    $ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$valor);
    if ($ascii!==false) $valor=$ascii;
    return trim((string)preg_replace('/[^a-z0-9]+/','_',strtolower($valor)),'_');
}

function templateOsVazio(): array
{
    return array_fill_keys(array_keys(camposTemplateOsSimplificada()),'');
}

function normalizarTemplateOsJson(array $entrada): array
{
    $dados=templateOsVazio();
    $aliases=[];
    foreach(camposTemplateOsSimplificada() as $key=>$label){$aliases[chaveOs($key)]=$key;$aliases[chaveOs($label)]=$key;}
    $aliases+=['cliente'=>'nome_cnpj','empresa'=>'nome_cnpj','razao_social'=>'nome_cnpj','cnpj'=>'nome_cnpj','contato'=>'pessoa_contato','telefone'=>'telefone_1','fone'=>'telefone_1','oportunidade'=>'oportunidade_comercial','data'=>'data_envio_sistema','atividade'=>'descricao_atividade'];
    $walk=function(array $arr) use (&$walk,&$dados,$aliases):void{
        foreach($arr as $k=>$v){
            if(is_array($v)){$walk($v);continue;}
            $dest=$aliases[chaveOs((string)$k)]??null;
            if($dest && trim((string)$v)!=='') $dados[$dest]=trim((string)$v);
        }
    };
    $walk($entrada);
    $dados['_raw_json']=$entrada;
    return $dados;
}

function extrairTemplateOsTexto(string $texto): array
{
    $dados=templateOsVazio();
    $texto=str_replace(["\r\n","\r"],"\n",$texto);
    foreach(camposTemplateOsSimplificada() as $key=>$label){
        $pattern='/'.preg_quote($label,'/').'\s*:?\s*([^\n\r]*)/iu';
        if(preg_match($pattern,$texto,$m)) $dados[$key]=trim((string)$m[1]);
    }
    $fallbacks=[
        'nome_cnpj'=>'/(?:NOME\s*\/\s*CNPJ|NOME\s*\/\s*CPNJ|CLIENTE|EMPRESA)\s*:\s*([^\n]+)/iu',
        'pessoa_contato'=>'/(?:PESSOA\s+DE\s+CONTATO|CONTATO)\s*:\s*([^\n]+)/iu',
        'telefone_1'=>'/(?:TELEFONE\s*1|TELEFONE|FONE)\s*:\s*([^\n]+)/iu',
        'vendedor'=>'/(?:VENDEDOR|CONSULTOR|RESPONS[AÁ]VEL)\s*:\s*([^\n]+)/iu',
        'oportunidade_comercial'=>'/(?:OPORTUNIDADE\s+COMERCIAL|OPORTUNIDADES?\s+IDENTIFICADAS?)\s*:\s*([^\n]+)/iu'
    ];
    foreach($fallbacks as $key=>$regex) if(($dados[$key]??'')==='' && preg_match($regex,$texto,$m)) $dados[$key]=trim((string)$m[1]);
    $dados['_raw_text']=mb_substr($texto,0,60000);
    return $dados;
}

function dadosBasicosDaOs(array $os): array
{
    $nomeCnpj=trim((string)($os['nome_cnpj']??''));$cnpj='';$empresa=$nomeCnpj;
    if(preg_match('/(\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2})/',$nomeCnpj,$m)){$cnpj=$m[1];$empresa=trim(str_replace($m[1],'',$nomeCnpj)," -|/");}
    if($empresa==='')$empresa=trim((string)($os['informacoes_cliente']??''));
    $data=trim((string)($os['data_envio_sistema']??''));
    if(preg_match('#^(\d{2})[/\-](\d{2})[/\-](\d{4})#',$data,$m))$data="{$m[3]}-{$m[2]}-{$m[1]}";
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$data))$data='';
    $resumo=array_filter([(string)($os['descricao_atividade']??''),(string)($os['relatorio_descritivo']??''),(string)($os['relatorio_visita_tecnica']??''),(string)($os['oportunidade_comentario']??'')]);
    return ['os'=>trim((string)($os['os']??'')),'data'=>$data,'cliente'=>$empresa,'cnpj'=>$cnpj,'contato'=>trim((string)($os['pessoa_contato']?:($os['responsavel_cliente']??''))),'telefone'=>trim((string)($os['telefone_1']?:($os['numero_contato']??''))),'email'=>trim((string)($os['email']?:($os['email_cliente']??''))),'setor'=>trim((string)($os['setor']??'')),'endereco'=>trim((string)($os['endereco']??'')),'localidade'=>trim((string)($os['identificacao_localidade']?:($os['local_instalacao']??''))),'vendedor'=>trim((string)($os['vendedor']??'')),'oportunidade'=>trim((string)($os['oportunidade_comercial']?:($os['informacao_oportunidade']?:($os['proximos_passos']??'')))),'resumo'=>implode("\n",$resumo),'tipo_servico'=>trim((string)($os['tipo_servico']??''))];
}

function templateOsJson(array $os): string
{
    return json_encode($os,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
