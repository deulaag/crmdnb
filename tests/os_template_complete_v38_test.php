<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/includes/os_template_service.php';
require_once $root.'/includes/os_template_parser_strict.php';

function v38ok(bool $ok,string $msg):void{if(!$ok){fwrite(STDERR,"FALHA: {$msg}\n");exit(1);}}
function v38file(string $path):string{global $root;$v=file_get_contents($root.'/'.$path);if($v===false){fwrite(STDERR,"FALHA: {$path} ausente\n");exit(1);}return $v;}

$esperados=[
'os','codigo_checklist','data_envio_sistema','endereco','nome_cnpj','pessoa_contato','telefone_1','email','identificacao_localidade',
'projeto_contrato','formulario_servico','profissionais','descricao_atividade','tipo_servico','evento_hora','evento','evento_tipo','por_profissional','total_hh','tempo_trabalho','tempo_deslocamento',
'informacoes_cliente','responsavel_cliente','numero_contato','email_cliente','setor','local_instalacao','vendedor','equipamento_operando','instalacao_conforme','possui_secador','secador_funcionando','possui_reservatorio_dreno','agua_dreno','possui_filtros','filtros_dreno','fotos','foto_1','foto_2','foto_3','foto_4','relatorio_descritivo',
'ordem_compressor','ordem_secador','ordem_pre_filtro','ordem_pos_filtro','ordem_reservatorio','compressores','equipamento_comentario','fabricante','modelos','numero_serie','horimetro','registro_manutencao','pecas_originais','tipo_oleo','indicador_preventiva','plano_preventiva_apresentado','interesse_preventiva','informacao_oportunidade','agua_linha','oleo_ar','paradas_frequentes','queda_pressao','consumo_energia','ruidos_vibracoes','vazamentos','qualidade_ar','perda_producao','preventiva_regular','oportunidade_comercial','oportunidade_comentario','relatorio_visita_tecnica','proximos_passos','assinatura_vendedor'];
$campos=camposTemplateOsSimplificada();
v38ok(count($campos)===75,'contrato deve conter os 75 campos preenchíveis do PDF oficial');
v38ok(array_keys($campos)===$esperados,'nenhum campo do template oficial pode faltar ou mudar de posição lógica');

$partes=[];
$partes[]='TEMPLATE: ORDEM DE SERVIÇO DE CAMPO SIMPLIFICADA';
$partes[]=$campos['os'].': VAL_os';
$partes[]=$campos['codigo_checklist'].': VAL_codigo_checklist';
$partes[]=$campos['data_envio_sistema'].': VAL_data_envio_sistema';
$estrutura=[
 ['IDENTIFICAÇÃO DO CLIENTE',['endereco','nome_cnpj','pessoa_contato','telefone_1','email','identificacao_localidade']],
 ['DETALHAMENTO DO SERVIÇO',['projeto_contrato','formulario_servico','profissionais','descricao_atividade','tipo_servico']],
 ['EVENTOS EM CAMPO',['evento_hora','evento','evento_tipo']],
 ['TOTAIS',['por_profissional','total_hh','tempo_trabalho','tempo_deslocamento']],
 ['ITENS VERIFICADOS',[]],
 ['0-INFORMAÇÃO DO CLIENTE',['informacoes_cliente','responsavel_cliente','numero_contato','email_cliente','setor','local_instalacao','vendedor']],
 ['1- CONDIÇÕES GERAIS DA INSTALAÇÃO',['equipamento_operando','instalacao_conforme','possui_secador','secador_funcionando','possui_reservatorio_dreno','agua_dreno','possui_filtros','filtros_dreno','fotos','foto_1','foto_2','foto_3','foto_4','relatorio_descritivo']],
 ['2-ORDEM DE INSTALAÇÃO DOS EQUIPAMENTOS',['ordem_compressor','ordem_secador','ordem_pre_filtro','ordem_pos_filtro','ordem_reservatorio']],
 ['3-DADOS TÉCNICOS DOS EQUIPAMENTOS E FOTOGRAFIAS GERAIS.',['compressores','equipamento_comentario','fabricante','modelos','numero_serie','horimetro','registro_manutencao','pecas_originais','tipo_oleo']],
 ['4-ABORDAGEM COMERCIAL - MANUTENÇÃO PREVENTIVA',['indicador_preventiva','plano_preventiva_apresentado','interesse_preventiva']],
 ['5-PERCEPÇÃO DO CLIENTE - PROBLEMAS COMUNS',['informacao_oportunidade','agua_linha','oleo_ar','paradas_frequentes','queda_pressao','consumo_energia','ruidos_vibracoes','vazamentos','qualidade_ar','perda_producao','preventiva_regular']],
 ['6-OPORTUNIDADES IDENTIFICADAS',['oportunidade_comercial','oportunidade_comentario','relatorio_visita_tecnica','proximos_passos','assinatura_vendedor']],
];
foreach($estrutura as [$titulo,$keys]){
 $partes[]=$titulo;
 foreach($keys as $key)$partes[]=$campos[$key].': VAL_'.$key;
}
$texto=implode("\n",$partes);
$extraido=extrairTemplateOsTextoEstrito($texto);
foreach($esperados as $key)v38ok(($extraido[$key]??'')==='VAL_'.$key,"PDF deve mapear {$key} para seu campo exato");
v38ok(($extraido['equipamento_comentario']??'')==='VAL_equipamento_comentario','Comentário técnico deve ficar nos equipamentos');
v38ok(($extraido['oportunidade_comentario']??'')==='VAL_oportunidade_comentario','Comentário comercial deve ficar em oportunidades');
v38ok(count($extraido['eventos_campo']??[])===1,'evento do PDF deve ser estruturado');

// Garante que palavras iguais a rótulos dentro do conteúdo não deslocam os campos.
$textoColisao=str_replace('VAL_evento_hora','texto com EVENTO e TIPO sem dois pontos',$texto);
$colisao=extrairTemplateOsTextoEstrito($textoColisao);
v38ok(($colisao['evento_hora']??'')==='texto com EVENTO e TIPO sem dois pontos','palavras EVENTO/TIPO dentro do valor não podem virar rótulos');
v38ok(($colisao['evento']??'')==='VAL_evento','EVENTO real deve continuar no campo correto');
v38ok(($colisao['evento_tipo']??'')==='VAL_evento_tipo','TIPO real deve continuar no campo correto');

// Recorte fiel do PDF Iliot real: tabelas sem dois-pontos, título com sufixo,
// eventos tabulares, ligaturas e cabeçalho extraído no fim da página.
$iliot=<<<'TXT'
IDENTIFICAÇÃO DA LOCALIDADE
ENDEREÇO
Tv. Rio Branco, 200 - Alagoinhas - BA
IDENTIFICAÇÃO DO CLIENTE
NOME / CPNJ
Laticinio Marianna LTDA / 40.499.907/0001-61
PESSOA DE CONTATO
André Moura
DETALHAMENTO DO SERVIÇO
FORMULÁRIO DE SERVIÇO
DNB Visita Comercial - 01
PROFISSIONAIS
Rafael Cerqueira
EVENTOS EM CAMPO
HORA
EVENTO
TIPO
04/08/2026 14:28
Chegada no cliente
Check-in
04/08/2026 15:32
Saida do cliente
Check-out
TOTAIS
POR PROFISSIONAL
1:04:00
TOTAL HH
1:04:00
ITENS VERIFICADOS
0 - INFORMAÇÃO DO CLIENTE
Respónsavel:
Bruno e Carlos
Setor:
Manutenção
Vendedor:
Rafael Cerqueira
1 - CONDIÇÕES GERAIS DA INSTALAÇÃO
A instalação possui ﬁltros de ar?
SIM
Os ﬁltros possuem dreno?
SIM
2 - ORDEM DE INSTALAÇÃO DOS EQUIPAMENTOS (NUMERAR NA SEQUÊNCIA INSTALADA)
Relatório descritivo
Inspeção visual concluída
COMPRESSOR
3
SECADOR DE AR
3 - sendo 2 integrados e 1 separado
PRÉ-FILTRO
Somente nos integrados
PÓS-FILTRO
Somente nos integrados
RESERVATÓRIO
1
3 - DADOS TÉCNICOS DOS EQUIPAMENTOS E FOTOGRAFIAS GERAIS.
Fabricante:
Ingersoll
Modelos:
R37I TAS
Fabricante:
Schulz
Modelo:
SRP 3030
4 - ABORDAGEM COMERCIAL – MANUTENÇÃO PREVENTIVA
Foi apresentado ao cliente o plano de manutenção preventiva 1x ao ano)?
SIM
Cliente demonstrou interesse?
Não
5 - PERCEPÇÃO DO CLIENTE – PROBLEMAS COMUNS
Presença excessiva de água na linha de ar:
NÃO
6 - OPORTUNIDADES IDENTIFICADAS
RELATÓRIO COMERCIAL DE VISITA TÉCNICA
Avaliação técnica realizada com o cliente.
Próximos Passos
Enviar proposta comercial.
Assinatura do Vendedor:
09/09/2026 19:57
página 4/4
ORDEM DE SERVIÇO DE CAMPO SIMPLIFICADA
OS:
4435
CÓD. CHECKLIST (SERVIÇO)
Viscom1
DATA ENVIO OS SISTEMA
05/08/2026 10:51
4
/
4
TXT;
$real=extrairTemplateOsTextoEstrito($iliot);
v38ok($real['os']==='4435','OS deve ser recuperada mesmo quando o extrator coloca o cabeçalho no fim');
v38ok($real['endereco']==='Tv. Rio Branco, 200 - Alagoinhas - BA','endereço da localidade deve ser preenchido');
v38ok($real['nome_cnpj']==='Laticinio Marianna LTDA / 40.499.907/0001-61','cliente sem dois-pontos deve ser preenchido');
v38ok(count($real['eventos_campo']??[])===2,'todas as linhas de eventos do Iliot devem ser preservadas');
v38ok($real['possui_filtros']==='SIM'&&$real['filtros_dreno']==='SIM','ligaturas do PDF não podem impedir respostas SIM/NÃO');
v38ok($real['ordem_pre_filtro']==='Somente nos integrados'&&$real['ordem_reservatorio']==='1','itens completos da ordem de instalação devem ser lidos');
v38ok($real['fabricante']==="Ingersoll\nSchulz"&&$real['modelos']==="R37I TAS\nSRP 3030",'equipamentos repetidos devem ser preservados na ordem');
v38ok($real['relatorio_visita_tecnica']==='Avaliação técnica realizada com o cliente.','relatório da visita deve ser preenchido');
v38ok($real['proximos_passos']==='Enviar proposta comercial.','próximos passos devem ser preenchidos');

$json=[
 'IDENTIFICAÇÃO DO CLIENTE'=>['NOME/CNPJ'=>'Empresa JSON 12.345.678/0001-90','EMAIL'=>'principal@empresa.test','PESSOA DE CONTATO'=>'Maria'],
 'EVENTOS EM CAMPO'=>[['HORA'=>'08:00','EVENTO'=>'Chegada','TIPO'=>'Visita'],['HORA'=>'09:30','EVENTO'=>'Medição','TIPO'=>'Diagnóstico']],
 '0-INFORMAÇÃO DO CLIENTE'=>['Email'=>'operacao@empresa.test','Responsável'=>'João'],
 '3-DADOS TÉCNICOS DOS EQUIPAMENTOS E FOTOGRAFIAS GERAIS'=>['Comentário'=>'Comentário técnico JSON','Fabricante'=>'IR'],
 '6-OPORTUNIDADES IDENTIFICADAS'=>['Comentário'=>'Comentário comercial JSON','Próximos Passos'=>'Enviar proposta'],
];
$j=normalizarTemplateOsJson($json);
v38ok(($j['email']??'')==='principal@empresa.test','EMAIL da identificação deve ficar em email principal');
v38ok(($j['email_cliente']??'')==='operacao@empresa.test','Email do checklist deve ficar em email_cliente');
v38ok(($j['equipamento_comentario']??'')==='Comentário técnico JSON','JSON deve separar comentário técnico');
v38ok(($j['oportunidade_comentario']??'')==='Comentário comercial JSON','JSON deve separar comentário comercial');
v38ok(count($j['eventos_campo']??[])===2,'JSON deve preservar todos os eventos em campo');

// O JSON canônico precisa aceitar e preservar todos os 72 campos do contrato.
$jsonCompleto=[];
foreach($esperados as $key)$jsonCompleto[$key]='JSON_'.$key;
$jc=normalizarTemplateOsJson($jsonCompleto);
foreach($esperados as $key)v38ok(($jc[$key]??'')==='JSON_'.$key,"JSON deve mapear {$key} para seu campo exato");

$base=$j;$post=['os_fields'=>array_fill_keys($esperados,'')];$post['os_fields']['nome_cnpj']='Empresa corrigida 98.765.432/0001-10';$post['os_fields']['relatorio_visita_tecnica']='Texto corrigido pelo usuário';$post['eventos_campo']=[['hora'=>'10:00','evento'=>'Teste final','tipo'=>'Validação']];
$final=templateOsDoFormulario($post,$base);
v38ok($final['nome_cnpj']==='Empresa corrigida 98.765.432/0001-10','conferência humana deve sobrescrever leitura automática');
v38ok($final['relatorio_visita_tecnica']==='Texto corrigido pelo usuário','campo editado deve ser o valor persistido');
v38ok(($final['_raw_json']??null)===$json,'JSON original deve continuar preservado para auditoria');
v38ok(count($final['eventos_campo']??[])===1&&$final['evento']==='Teste final','eventos editados devem ser persistidos como estrutura final');

$visitas=v38file('visitas.php');
$jsonIni=strpos($visitas,"if(\$acao==='importar_json')");$pdfIni=strpos($visitas,"if(\$acao==='importar_pdf_preview')");$cancelIni=strpos($visitas,"if(\$acao==='cancel_preview')");$addIni=strpos($visitas,"if(\$acao==='add')");
v38ok($jsonIni!==false&&$pdfIni!==false&&$cancelIni!==false&&$addIni!==false,'ações de leitura e confirmação devem existir');
$jsonBloco=substr($visitas,$jsonIni,$pdfIni-$jsonIni);$pdfBloco=substr($visitas,$pdfIni,$cancelIni-$pdfIni);$addBloco=substr($visitas,$addIni,$jsonIni-$addIni);
v38ok(!str_contains($jsonBloco,'registrarVisitaEAbrirOportunidade'),'Ler JSON não pode criar visita/oportunidade');
v38ok(!str_contains($pdfBloco,'registrarVisitaEAbrirOportunidade'),'Ler PDF não pode criar visita/oportunidade');
v38ok(str_contains($addBloco,'registrarVisitaEAbrirOportunidade'),'somente ação add do botão final deve persistir visita/oportunidade');
v38ok(str_contains($jsonBloco,"\$_SESSION['visita_pdf_prefill']"),'JSON deve carregar somente uma prévia para conferência');
v38ok(str_contains($pdfBloco,"\$_SESSION['visita_pdf_prefill']"),'PDF deve carregar somente uma prévia para conferência');
v38ok(str_contains($visitas,'foreach(gruposTemplateOsSimplificada() as $grupo)'),'tela deve renderizar todas as seções do contrato oficial');
v38ok(str_contains($visitas,'name="os_fields['),'todos os campos devem ser editáveis na ficha antes de registrar');
v38ok(str_contains($visitas,'Registrar visita e abrir oportunidade'),'botão explícito de confirmação deve existir');

$pdfService=v38file('includes/pdf_service.php');
v38ok(str_contains($pdfService,'getDataTm'),'leitor deve reconstruir texto por coordenadas além do texto corrido');
v38ok(str_contains($pdfService,'mesclarTemplatesExtraidosOs'),'as duas estratégias de leitura devem ser mescladas campo a campo');
v38ok(str_contains($pdfService,'extrairTemplateOsTextoEstrito'),'leitor de produção deve usar o parser estrito');

$oportunidade=v38file('oportunidade.php');
v38ok(str_contains($oportunidade,'gruposTemplateOsSimplificada()'),'oportunidade deve usar o mesmo contrato completo da tela de OS');
v38ok(str_contains($oportunidade,'campos sem conteúdo aparecem como “—”'),'oportunidade não deve esconder itens do template');

echo "OS template completo v3.8: OK\n";
