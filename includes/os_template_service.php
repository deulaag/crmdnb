<?php
declare(strict_types=1);

/**
 * Campos do TEMPLATE: ORDEM DE SERVIÇO DE CAMPO SIMPLIFICADA.
 * Os rótulos abaixo seguem o PDF oficial, inclusive a grafia NOME/CPNJ e
 * Respónsavel, para que PDF, JSON, tela de conferência e oportunidade usem
 * exatamente o mesmo contrato de dados.
 */
function camposTemplateOsSimplificada(): array
{
    return [
        'os'=>'OS',
        'codigo_checklist'=>'CÓD. CHECKLIST (SERVIÇO)',
        'data_envio_sistema'=>'DATA ENVIO OS SISTEMA',
        'endereco'=>'ENDEREÇO',
        'nome_cnpj'=>'NOME/CPNJ',
        'pessoa_contato'=>'PESSOA DE CONTATO',
        'telefone_1'=>'TELEFONE 1',
        'email'=>'EMAIL',
        'identificacao_localidade'=>'IDENTIFICAÇÃO DA LOCALIDADE',
        'projeto_contrato'=>'PROJETO/CONTRATO',
        'formulario_servico'=>'FORMULÁRIO DE SERVIÇO',
        'profissionais'=>'PROFISSIONAIS',
        'descricao_atividade'=>'DESCRIÇÃO DA ATIVIDADE',
        'tipo_servico'=>'TIPO DE SERVIÇO',
        'evento_hora'=>'HORA',
        'evento'=>'EVENTO',
        'evento_tipo'=>'TIPO',
        'por_profissional'=>'POR PROFISSIONAL',
        'total_hh'=>'TOTAL HH',
        'tempo_trabalho'=>'Tempo total de trabalho',
        'tempo_deslocamento'=>'Tempo total de deslocamento',
        'informacoes_cliente'=>'Informações do cliente',
        'responsavel_cliente'=>'Respónsavel',
        'numero_contato'=>'Nº Contato',
        'email_cliente'=>'Email',
        'setor'=>'Setor',
        'local_instalacao'=>'Local da Instalação',
        'vendedor'=>'Vendedor',
        'equipamento_operando'=>'Equipamento está operando normalmente?',
        'instalacao_conforme'=>'A instalação está conforme recomendação do fabricante?',
        'possui_secador'=>'A instalação possui secador de ar?',
        'secador_funcionando'=>'O secador de ar está funcionando corretamente?',
        'possui_reservatorio_dreno'=>'A instalação possui reservatório de ar? reservatório possui dreno?',
        'agua_dreno'=>'Sai água pelo dreno do reservatório?',
        'possui_filtros'=>'A instalação possui filtros de ar?',
        'filtros_dreno'=>'Os filtros possuem dreno?',
        'fotos'=>'Fotos',
        'foto_1'=>'Detalhes foto 1',
        'foto_2'=>'Detalhes foto 2',
        'foto_3'=>'Detalhes foto 3',
        'foto_4'=>'Detalhes foto 4',
        'relatorio_descritivo'=>'Relatório descritivo',
        'ordem_compressor'=>'COMPRESSOR',
        'ordem_secador'=>'SECADOR DE AR',
        'ordem_pre_filtro'=>'PRÉ-FILTRO',
        'ordem_pos_filtro'=>'PÓS-FILTRO',
        'ordem_reservatorio'=>'RESERVATÓRIO',
        'compressores'=>'Compressores',
        'equipamento_comentario'=>'Comentário',
        'fabricante'=>'Fabricante',
        'modelos'=>'Modelos',
        'numero_serie'=>'Numero de serie',
        'horimetro'=>'Horimetro (horas totais)',
        'registro_manutencao'=>'Registro de manutenção existente?',
        'pecas_originais'=>'Cliente utiliza peças originais?',
        'tipo_oleo'=>'Tipo de óleo utilizado',
        'indicador_preventiva'=>'Indicador de Manutenção preventiva',
        'plano_preventiva_apresentado'=>'Foi apresentado ao cliente o plano de manutenção preventiva (1x ao ano)?',
        'interesse_preventiva'=>'Cliente demonstrou interesse?',
        'informacao_oportunidade'=>'Informação da Oportunidade',
        'agua_linha'=>'Presença excessiva de água na linha de ar',
        'oleo_ar'=>'Contaminação por óleo no ar comprimido',
        'paradas_frequentes'=>'Paradas frequentes ou falhas inesperadas',
        'queda_pressao'=>'Queda de pressão durante a operação',
        'consumo_energia'=>'Consumo elevado de energia',
        'ruidos_vibracoes'=>'Ruídos ou vibrações anormais',
        'vazamentos'=>'Vazamentos frequentes na rede',
        'qualidade_ar'=>'Dificuldade em manter qualidade do ar',
        'perda_producao'=>'Perda de produção por falha do sistema',
        'preventiva_regular'=>'Manutenção preventiva realizada regularmente',
        'oportunidade_comercial'=>'Oportunidade Comercial',
        'oportunidade_comentario'=>'Comentário',
        'relatorio_visita_tecnica'=>'Relatório de Visita Técnica',
        'proximos_passos'=>'Próximos Passos',
        'assinatura_vendedor'=>'Assinatura do Vendedor',
    ];
}

/** Estrutura visual e semântica do PDF, na ordem em que aparece no template. */
function gruposTemplateOsSimplificada(): array
{
    return [
        ['id'=>'cabecalho','titulo'=>'Ordem de Serviço','campos'=>['os','codigo_checklist','data_envio_sistema']],
        ['id'=>'identificacao_cliente','titulo'=>'Identificação do Cliente','campos'=>['endereco','nome_cnpj','pessoa_contato','telefone_1','email','identificacao_localidade']],
        ['id'=>'detalhamento_servico','titulo'=>'Detalhamento do Serviço','campos'=>['projeto_contrato','formulario_servico','profissionais','descricao_atividade','tipo_servico']],
        ['id'=>'eventos_campo','titulo'=>'Eventos em Campo','campos'=>['evento_hora','evento','evento_tipo'],'eventos'=>true],
        ['id'=>'totais','titulo'=>'Totais','campos'=>['por_profissional','total_hh','tempo_trabalho','tempo_deslocamento']],
        ['id'=>'informacao_cliente','titulo'=>'0 - Informação do Cliente','campos'=>['informacoes_cliente','responsavel_cliente','numero_contato','email_cliente','setor','local_instalacao','vendedor']],
        ['id'=>'condicoes_instalacao','titulo'=>'1 - Condições Gerais da Instalação','campos'=>['equipamento_operando','instalacao_conforme','possui_secador','secador_funcionando','possui_reservatorio_dreno','agua_dreno','possui_filtros','filtros_dreno','fotos','foto_1','foto_2','foto_3','foto_4','relatorio_descritivo']],
        ['id'=>'ordem_instalacao','titulo'=>'2 - Ordem de Instalação dos Equipamentos','campos'=>['ordem_compressor','ordem_secador','ordem_pre_filtro','ordem_pos_filtro','ordem_reservatorio']],
        ['id'=>'dados_tecnicos','titulo'=>'3 - Dados Técnicos dos Equipamentos e Fotografias Gerais','campos'=>['compressores','equipamento_comentario','fabricante','modelos','numero_serie','horimetro','registro_manutencao','pecas_originais','tipo_oleo']],
        ['id'=>'preventiva','titulo'=>'4 - Abordagem Comercial - Manutenção Preventiva','campos'=>['indicador_preventiva','plano_preventiva_apresentado','interesse_preventiva']],
        ['id'=>'percepcao_cliente','titulo'=>'5 - Percepção do Cliente - Problemas Comuns','campos'=>['informacao_oportunidade','agua_linha','oleo_ar','paradas_frequentes','queda_pressao','consumo_energia','ruidos_vibracoes','vazamentos','qualidade_ar','perda_producao','preventiva_regular']],
        ['id'=>'oportunidades','titulo'=>'6 - Oportunidades Identificadas','campos'=>['oportunidade_comercial','oportunidade_comentario','relatorio_visita_tecnica','proximos_passos','assinatura_vendedor']],
    ];
}

/** Limites reais das seções do PDF para evitar que rótulos repetidos caiam no campo errado. */
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
        ['inicio'=>'2-ORDEM DE INSTALAÇÃO DOS EQUIPAMENTOS','fim'=>'3-DADOS TÉCNICOS DOS EQUIPAMENTOS E FOTOGRAFIAS GERAIS.','campos'=>['relatorio_descritivo','ordem_compressor','ordem_secador','ordem_pre_filtro','ordem_pos_filtro','ordem_reservatorio']],
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

function totalCamposTemplateOs(): int
{
    return count(camposTemplateOsSimplificada());
}

function normalizarTextoOs(string $texto): string
{
    $texto=str_replace(
        ["\r\n","\r","\f","\xC2\xA0","\u{FB00}","\u{FB01}","\u{FB02}","\u{FB03}","\u{FB04}",'–','—'],
        ["\n","\n","\n"," ",'ff','fi','fl','ffi','ffl','-','-'],
        $texto
    );
    $texto=preg_replace('/[\t ]+/u',' ',$texto)??$texto;
    $texto=preg_replace('/ *\n */u',"\n",$texto)??$texto;
    $texto=preg_replace('/\n{3,}/u',"\n\n",$texto)??$texto;
    return trim($texto);
}

/** Variações toleradas na leitura; o nome canônico gravado continua sendo o do PDF oficial. */
function variantesRotuloOs(string $key): array
{
    $label=camposTemplateOsSimplificada()[$key]??$key;
    $extra=[
        'codigo_checklist'=>['COD. CHECKLIST (SERVIÇO)','CÓD CHECKLIST (SERVIÇO)','COD CHECKLIST (SERVICO)'],
        'nome_cnpj'=>['NOME/CNPJ','NOME / CNPJ','NOME / CPNJ','NOME/CPNJ'],
        'responsavel_cliente'=>['Responsável','Responsavel','Respónsavel'],
        'numero_contato'=>['Nº Contato','N° Contato','No Contato','Numero Contato','Número Contato'],
        'numero_serie'=>['Numero de serie','Número de série','Numero de série','Nº de série'],
        'horimetro'=>['Horimetro (horas totais)','Horímetro (horas totais)'],
        'identificacao_localidade'=>['IDENTIFICAÇÃO DA LOCALIDADE','IDENTIFICACAO DA LOCALIDADE'],
        'descricao_atividade'=>['DESCRIÇÃO DA ATIVIDADE','DESCRICAO DA ATIVIDADE'],
        'formulario_servico'=>['FORMULÁRIO DE SERVIÇO','FORMULARIO DE SERVICO'],
        'tipo_servico'=>['TIPO DE SERVIÇO','TIPO DE SERVICO'],
        'relatorio_descritivo'=>['Relatório descritivo','Relatorio descritivo'],
        'ordem_pre_filtro'=>['PRÉ-FILTRO','PRE-FILTRO','PRÉ FILTRO','PRE FILTRO'],
        'ordem_pos_filtro'=>['PÓS-FILTRO','POS-FILTRO','PÓS FILTRO','POS FILTRO'],
        'modelos'=>['Modelos','Modelo'],
        'possui_filtros'=>['A instalação possui filtros de ar?','A instalação possui ﬁltros de ar?'],
        'filtros_dreno'=>['Os filtros possuem dreno?','Os ﬁltros possuem dreno?'],
        'plano_preventiva_apresentado'=>['Foi apresentado ao cliente o plano de manutenção preventiva (1x ao ano)?','Foi apresentado ao cliente o plano de manutenção preventiva 1x ao ano)?'],
        'relatorio_visita_tecnica'=>['Relatório de Visita Técnica','Relatorio de Visita Tecnica','Relatório Comercial de Visita Técnica','Relatorio Comercial de Visita Tecnica'],
        'proximos_passos'=>['Próximos Passos','Proximos Passos'],
    ];
    return array_values(array_unique(array_merge([$label],$extra[$key]??[])));
}

function regexTextoFlexOs(string $rotulo,bool $ancorarLinha=true): string
{
    $partes=preg_split('/\s+/u',trim($rotulo))?:[];
    $miolo=implode('(?:[\h\x{00A0}]|\R)+',array_map(static fn(string $p):string=>preg_quote($p,'~'),$partes));
    $inicio=$ancorarLinha?'^[\h\x{00A0}]*':'(?<![\pL\pN])';
    return '~'.$inicio.$miolo.'[\h\x{00A0}]*:?\s*~imu';
}

/** @return array{0:int,1:int}|null */
function localizarTextoOs(string $texto,array $variantes,int $offset=0,bool $ancorarLinha=true): ?array
{
    $melhor=null;
    foreach($variantes as $rotulo){
        if(!preg_match(regexTextoFlexOs((string)$rotulo,$ancorarLinha),$texto,$m,PREG_OFFSET_CAPTURE,$offset))continue;
        $loc=[(int)$m[0][1],strlen((string)$m[0][0])];
        if($melhor===null||$loc[0]<$melhor[0])$melhor=$loc;
    }
    return $melhor;
}

function variantesSecaoOs(string $titulo): array
{
    $key=chaveOs($titulo);
    $map=[
        'identificacao_do_cliente'=>['IDENTIFICAÇÃO DO CLIENTE','IDENTIFICACAO DO CLIENTE'],
        'detalhamento_do_servico'=>['DETALHAMENTO DO SERVIÇO','DETALHAMENTO DO SERVICO'],
        'eventos_em_campo'=>['EVENTOS EM CAMPO'],
        'totais'=>['TOTAIS'],
        'itens_verificados'=>['ITENS VERIFICADOS'],
        '0_informacao_do_cliente'=>['0-INFORMAÇÃO DO CLIENTE','0 - INFORMAÇÃO DO CLIENTE','0-INFORMACAO DO CLIENTE'],
        '1_condicoes_gerais_da_instalacao'=>['1- CONDIÇÕES GERAIS DA INSTALAÇÃO','1 - CONDIÇÕES GERAIS DA INSTALAÇÃO','1- CONDICOES GERAIS DA INSTALACAO'],
        '2_ordem_de_instalacao_dos_equipamentos'=>['2-ORDEM DE INSTALAÇÃO DOS EQUIPAMENTOS','2 - ORDEM DE INSTALAÇÃO DOS EQUIPAMENTOS','2-ORDEM DE INSTALACAO DOS EQUIPAMENTOS'],
        '3_dados_tecnicos_dos_equipamentos_e_fotografias_gerais'=>['3-DADOS TÉCNICOS DOS EQUIPAMENTOS E FOTOGRAFIAS GERAIS.','3 - DADOS TÉCNICOS DOS EQUIPAMENTOS E FOTOGRAFIAS GERAIS','3-DADOS TECNICOS DOS EQUIPAMENTOS E FOTOGRAFIAS GERAIS.'],
        '4_abordagem_comercial_manutencao_preventiva'=>['4-ABORDAGEM COMERCIAL - MANUTENÇÃO PREVENTIVA','4 - ABORDAGEM COMERCIAL - MANUTENÇÃO PREVENTIVA','4-ABORDAGEM COMERCIAL - MANUTENCAO PREVENTIVA'],
        '5_percepcao_do_cliente_problemas_comuns'=>['5-PERCEPÇÃO DO CLIENTE - PROBLEMAS COMUNS','5 - PERCEPÇÃO DO CLIENTE - PROBLEMAS COMUNS','5-PERCEPCAO DO CLIENTE - PROBLEMAS COMUNS'],
        '6_oportunidades_identificadas'=>['6-OPORTUNIDADES IDENTIFICADAS','6 - OPORTUNIDADES IDENTIFICADAS'],
    ];
    return $map[$key]??[$titulo];
}

function recortarSecaoOs(string $texto,?string $inicio,?string $fim): string
{
    $inicioPos=0;
    if($inicio!==null){
        $loc=localizarTextoOs($texto,variantesSecaoOs($inicio));
        if(!$loc)return '';
        $inicioPos=$loc[0]+$loc[1];
    }
    $fimPos=strlen($texto);
    if($fim!==null){
        $locFim=localizarTextoOs($texto,variantesSecaoOs($fim),$inicioPos);
        if($locFim)$fimPos=$locFim[0];
    }
    return trim(substr($texto,$inicioPos,max(0,$fimPos-$inicioPos)));
}

function limparValorOs(string $valor): string
{
    $valor=preg_replace('/^[\s:\-–—|]+/u','',$valor)??$valor;
    $valor=preg_replace('/[\t ]+/u',' ',$valor)??$valor;
    $valor=preg_replace('/ *\n */u',"\n",$valor)??$valor;
    $valor=preg_replace('/\n{3,}/u',"\n\n",$valor)??$valor;
    return trim($valor);
}

/** Extrai um bloco inteiro, usando o próximo rótulo da mesma seção como limite. */
function extrairCamposDaSecaoOs(string $secao,array $campos,array &$dados): void
{
    $achados=[];
    foreach($campos as $key){
        $loc=localizarTextoOs($secao,variantesRotuloOs((string)$key));
        if($loc)$achados[]=['key'=>$key,'pos'=>$loc[0],'fim'=>$loc[0]+$loc[1]];
    }
    usort($achados,static fn(array $a,array $b):int=>$a['pos']<=>$b['pos']);
    for($i=0,$n=count($achados);$i<$n;$i++){
        $atual=$achados[$i];
        $fim=$i+1<$n?$achados[$i+1]['pos']:strlen($secao);
        $valor=limparValorOs(substr($secao,$atual['fim'],max(0,$fim-$atual['fim'])));
        $dados[$atual['key']]=$valor;
    }
}

/** @return array<int,array{hora:string,evento:string,tipo:string}> */
function extrairEventosCampoOs(string $secao): array
{
    $tokens=[];
    foreach(['hora'=>'HORA','evento'=>'EVENTO','tipo'=>'TIPO'] as $key=>$label){
        preg_match_all(regexTextoFlexOs($label,false),$secao,$ms,PREG_OFFSET_CAPTURE);
        foreach($ms[0]??[] as $m)$tokens[]=['key'=>$key,'pos'=>(int)$m[1],'fim'=>(int)$m[1]+strlen((string)$m[0])];
    }
    usort($tokens,static fn(array $a,array $b):int=>$a['pos']<=>$b['pos']);
    $eventos=[];$atual=['hora'=>'','evento'=>'','tipo'=>''];
    for($i=0,$n=count($tokens);$i<$n;$i++){
        $t=$tokens[$i];$fim=$i+1<$n?$tokens[$i+1]['pos']:strlen($secao);
        $valor=limparValorOs(substr($secao,$t['fim'],max(0,$fim-$t['fim'])));
        if($t['key']==='hora'&&array_filter($atual,static fn($v)=>trim((string)$v)!=='')){
            $eventos[]=$atual;$atual=['hora'=>'','evento'=>'','tipo'=>''];
        }
        $atual[$t['key']]=$valor;
    }
    if(array_filter($atual,static fn($v)=>trim((string)$v)!==''))$eventos[]=$atual;
    return array_values($eventos);
}

function acumularValorTemplateOs(array &$dados,string $dest,string $valor): void
{
    $valor=trim($valor);if($valor==='')return;
    $atual=trim((string)($dados[$dest]??''));
    if($atual===''){$dados[$dest]=$valor;return;}
    if($atual===$valor||str_contains($atual,$valor))return;
    $dados[$dest]=$atual."\n".$valor;
}

/** Resolve chaves JSON levando o caminho/seção em conta, inclusive Email e Comentário repetidos. */
function destinoCampoJsonOs(string $chave,array $caminho,array $dados): ?string
{
    $campos=camposTemplateOsSimplificada();
    if(array_key_exists($chave,$campos))return $chave;
    $nk=chaveOs($chave);$ctx=chaveOs(implode(' ',$caminho));

    if(in_array($nk,['comentario','comments','comment'],true)){
        if(str_contains($ctx,'oportun'))return 'oportunidade_comentario';
        if(str_contains($ctx,'dados_tecnicos')||str_contains($ctx,'equipamento')||str_contains($ctx,'compressor'))return 'equipamento_comentario';
        return 'oportunidade_comentario'; // compatibilidade com JSON simples antigo.
    }
    if(in_array($nk,['email','e_mail'],true)){
        if(str_contains($ctx,'0_informacao')||str_contains($ctx,'informacoes_do_cliente')||str_contains($ctx,'itens_verificados'))return 'email_cliente';
        return 'email';
    }
    if(in_array($nk,['responsavel','responsavel_cliente'],true))return 'responsavel_cliente';
    if($nk==='tipo'&&str_contains($ctx,'evento'))return 'evento_tipo';
    if($nk==='hora'&&str_contains($ctx,'evento'))return 'evento_hora';
    if($nk==='evento'&&str_contains($ctx,'evento'))return 'evento';

    $aliases=[
        'cliente'=>'nome_cnpj','empresa'=>'nome_cnpj','razao_social'=>'nome_cnpj','razao_social_cnpj'=>'nome_cnpj','cnpj'=>'nome_cnpj',
        'nome_cnpj'=>'nome_cnpj','nome_cpnj'=>'nome_cnpj','contato'=>'pessoa_contato','pessoa_de_contato'=>'pessoa_contato',
        'telefone'=>'telefone_1','fone'=>'telefone_1','telefone1'=>'telefone_1','numero_contato'=>'numero_contato',
        'data'=>'data_envio_sistema','data_envio'=>'data_envio_sistema','atividade'=>'descricao_atividade','descricao'=>'descricao_atividade',
        'oportunidade'=>'oportunidade_comercial','proximo_passo'=>'proximos_passos','proximos_passos'=>'proximos_passos',
    ];
    if(isset($aliases[$nk]))return $aliases[$nk];

    $porRotulo=[];
    foreach($campos as $key=>$label)$porRotulo[chaveOs($label)][]=$key;
    $matches=$porRotulo[$nk]??[];
    if(count($matches)===1)return $matches[0];
    if(count($matches)>1){
        if(in_array('oportunidade_comentario',$matches,true)&&str_contains($ctx,'oportun'))return 'oportunidade_comentario';
        if(in_array('equipamento_comentario',$matches,true))return 'equipamento_comentario';
    }
    return null;
}

function normalizarTemplateOsJson(array $entrada): array
{
    $dados=templateOsVazio();$eventos=[];$fotosItens=[];$equipamentosItens=[];
    $walk=function(array $arr,array $path=[])use(&$walk,&$dados,&$eventos,&$fotosItens,&$equipamentosItens):void{
        foreach($arr as $k=>$v){
            $novoPath=array_merge($path,[(string)$k]);$ctx=chaveOs(implode(' ',$novoPath));
            if(is_array($v)){
                if(str_contains($ctx,'evento')&&array_is_list($v)){
                    foreach($v as $ev)if(is_array($ev)){
                        $eventos[]=[
                            'hora'=>trim((string)($ev['hora']??$ev['HORA']??'')),
                            'evento'=>trim((string)($ev['evento']??$ev['EVENTO']??$ev['descricao']??'')),
                            'tipo'=>trim((string)($ev['tipo']??$ev['TIPO']??'')),
                        ];
                    }
                }
                if(str_contains($ctx,'foto')&&array_is_list($v))$fotosItens=$v;
                if((str_contains($ctx,'compressor')||str_contains($ctx,'equipamento'))&&array_is_list($v))$equipamentosItens=$v;
                $walk($v,$novoPath);continue;
            }
            $dest=destinoCampoJsonOs((string)$k,$novoPath,$dados);
            if($dest)acumularValorTemplateOs($dados,$dest,(string)$v);
        }
    };
    $walk($entrada);
    $eventos=array_values(array_filter($eventos,static fn(array $e):bool=>(bool)array_filter($e,static fn($v)=>trim((string)$v)!=='')));
    if($eventos){
        $dados['eventos_campo']=$eventos;
        $dados['evento_hora']=$eventos[0]['hora']??'';
        $dados['evento']=$eventos[0]['evento']??'';
        $dados['evento_tipo']=$eventos[0]['tipo']??'';
    }
    if($fotosItens)$dados['_fotos_json']=$fotosItens;
    if($equipamentosItens)$dados['_equipamentos_json']=$equipamentosItens;
    $dados['_raw_json']=$entrada;
    return $dados;
}

function extrairTemplateOsTexto(string $texto): array
{
    $dados=templateOsVazio();
    $texto=normalizarTextoOs($texto);
    foreach(secoesTemplateOsSimplificada() as $config){
        $secao=recortarSecaoOs($texto,$config['inicio'],$config['fim']);
        if($secao==='')continue;
        extrairCamposDaSecaoOs($secao,$config['campos'],$dados);
        if(!empty($config['eventos'])){
            $eventos=extrairEventosCampoOs($secao);
            if($eventos){
                $dados['eventos_campo']=$eventos;
                $dados['evento_hora']=$eventos[0]['hora']??'';
                $dados['evento']=$eventos[0]['evento']??'';
                $dados['evento_tipo']=$eventos[0]['tipo']??'';
            }
        }
    }
    $dados['_raw_text']=$texto;
    return $dados;
}

/**
 * Combina extrações do texto corrido e do texto reconstruído por coordenadas.
 * Campo a campo, a versão não vazia e menos contaminada por outros rótulos vence.
 */
function mesclarTemplatesExtraidosOs(array ...$templates): array
{
    $out=templateOsVazio();$labels=array_values(camposTemplateOsSimplificada());
    $qualidade=static function(string $valor)use($labels):int{
        $valor=trim($valor);if($valor==='')return -100000;
        $score=min(mb_strlen($valor),800);
        foreach($labels as $label){
            $k=chaveOs($label);if($k!==''&&str_contains(chaveOs($valor),$k))$score-=300;
        }
        if(mb_strlen($valor)>2500)$score-=1000;
        return $score;
    };
    foreach(array_keys(camposTemplateOsSimplificada()) as $key){
        $melhor='';$score=-100001;
        foreach($templates as $t){$v=trim((string)($t[$key]??''));$s=$qualidade($v);if($s>$score){$melhor=$v;$score=$s;}}
        $out[$key]=$melhor;
    }
    $melhoresEventos=[];
    foreach($templates as $t){$ev=$t['eventos_campo']??[];if(is_array($ev)&&count($ev)>count($melhoresEventos))$melhoresEventos=$ev;}
    if($melhoresEventos){$out['eventos_campo']=$melhoresEventos;$out['evento_hora']=$melhoresEventos[0]['hora']??$out['evento_hora'];$out['evento']=$melhoresEventos[0]['evento']??$out['evento'];$out['evento_tipo']=$melhoresEventos[0]['tipo']??$out['evento_tipo'];}
    foreach($templates as $t){
        foreach(['_raw_text','_raw_layout_text','_raw_json','_fotos_json','_equipamentos_json'] as $extra){
            if(isset($t[$extra])&&!isset($out[$extra]))$out[$extra]=$t[$extra];
        }
    }
    return $out;
}

/** Monta o template definitivo a partir da conferência humana da tela. */
function templateOsDoFormulario(array $post,array $base=[]): array
{
    $out=templateOsVazio();
    foreach($base as $k=>$v)$out[$k]=$v;
    $campos=is_array($post['os_fields']??null)?$post['os_fields']:[];
    foreach(array_keys(camposTemplateOsSimplificada()) as $key){
        if(array_key_exists($key,$campos))$out[$key]=trim((string)$campos[$key]);
    }
    $eventos=[];
    foreach((array)($post['eventos_campo']??[]) as $ev){
        if(!is_array($ev))continue;
        $row=['hora'=>trim((string)($ev['hora']??'')),'evento'=>trim((string)($ev['evento']??'')),'tipo'=>trim((string)($ev['tipo']??''))];
        if(array_filter($row,static fn($v)=>$v!==''))$eventos[]=$row;
    }
    $out['eventos_campo']=$eventos;
    $out['evento_hora']=$eventos[0]['hora']??'';
    $out['evento']=$eventos[0]['evento']??'';
    $out['evento_tipo']=$eventos[0]['tipo']??'';
    return $out;
}

function dadosBasicosDaOs(array $os): array
{
    $nomeCnpj=trim((string)($os['nome_cnpj']??''));$cnpj='';$empresa=$nomeCnpj;
    if(preg_match('/(\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2})/',$nomeCnpj,$m)){
        $cnpj=$m[1];$empresa=trim(str_replace($m[1],'',$nomeCnpj)," -|/\t\n\r\0\x0B");
    }
    if($empresa==='')$empresa=trim((string)($os['informacoes_cliente']??''));
    $data=trim((string)($os['data_envio_sistema']??''));
    if(preg_match('#^(\d{2})[/\-](\d{2})[/\-](\d{4})#',$data,$m))$data="{$m[3]}-{$m[2]}-{$m[1]}";
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$data))$data='';
    $resumo=array_filter([
        (string)($os['descricao_atividade']??''),(string)($os['relatorio_descritivo']??''),
        (string)($os['relatorio_visita_tecnica']??''),(string)($os['equipamento_comentario']??''),
        (string)($os['oportunidade_comentario']??'')
    ],static fn(string $v):bool=>trim($v)!=='');
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
    if(!empty($os['eventos_campo'])&&is_array($os['eventos_campo']))$n+=max(0,count($os['eventos_campo'])-1);
    return $n;
}

function templateOsJson(array $os): string
{
    return json_encode($os,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
