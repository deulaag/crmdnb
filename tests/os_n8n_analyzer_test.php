<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/includes/os_template_service.php';
require_once $root.'/includes/os_remote_analyzer.php';

function n8nOk(bool $cond,string $msg):void{if(!$cond){fwrite(STDERR,"FALHA: {$msg}\n");exit(1);}}
function n8nFile(string $path):string{global $root;$v=file_get_contents($root.'/'.$path);if($v===false){fwrite(STDERR,"FALHA: {$path} ausente\n");exit(1);}return $v;}

$contrato=contratoOs72ParaN8n();
n8nOk(count($contrato)===72,'contrato enviado ao n8n deve conter os 72 campos oficiais');
n8nOk(($contrato[0]['key']??'')==='os','primeiro campo do contrato deve ser OS');
n8nOk(in_array('oportunidade_comentario',array_column($contrato,'key'),true),'contrato deve conter comentário comercial independente');
n8nOk(in_array('equipamento_comentario',array_column($contrato,'key'),true),'contrato deve conter comentário técnico independente');

$local=templateOsVazio();$local['fabricante']='Fabricante local';$local['tipo_oleo']='Óleo encontrado localmente';
$payload=[
 'ok'=>true,
 'fields'=>['fabricante'=>'Fabricante n8n','modelos'=>'R11','nome_cnpj'=>'Empresa N8N'],
 'events'=>[['hora'=>'08:00','evento'=>'Chegada','tipo'=>'Visita']],
 'confidence'=>['fabricante'=>0.99],
 'empty'=>['foto_4'],
 'unresolved'=>['numero_serie'],
 'photos'=>[['slot'=>'foto_1','name'=>'foto.jpg','page'=>2,'caption'=>'Compressor','confidence'=>0.95,'content_base64'=>'NAO_DEVE_IR_PARA_SESSAO']],
];
$r=normalizarRespostaN8nOs($payload,$local);
n8nOk(($r['template']['fabricante']??'')==='Fabricante n8n','valor inteligente deve ter prioridade sobre o parser local');
n8nOk(($r['template']['tipo_oleo']??'')==='Óleo encontrado localmente','parser local deve preencher lacuna do n8n');
n8nOk(($r['template']['evento']??'')==='Chegada','eventos do n8n devem entrar no template');
n8nOk(($r['meta']['filled']??0)>=4,'meta deve contar campos efetivamente preenchidos');
n8nOk(($r['meta']['photos_count']??0)===1,'meta deve registrar quantidade de fotos detectadas');
n8nOk(!array_key_exists('content_base64',$r['meta']['photos'][0]??[]),'base64 de foto não deve ser guardado na sessão');
n8nOk(($r['meta']['unresolved'][0]??'')==='numero_serie','campos não resolvidos devem ser preservados');

$gateway=n8nFile('os/analisar.php');
$integration=n8nFile('includes/integration_config.php');
$api=n8nFile('api/v1/integrations.php');
$js=n8nFile('assets/os-analyzer.js');
$ht=n8nFile('.htaccess');
$footer=n8nFile('includes/layout_footer.php');
$docs=n8nFile('docs/os-analyzer-n8n.md');

n8nOk(str_contains($ht,'^os/analisar/?$'),'Apache deve publicar /os/analisar sem expor arquivo PHP');
n8nOk(str_contains($gateway,'analisarOsViaN8n'),'gateway deve chamar o n8n');
n8nOk(str_contains($gateway,'extrairConteudoPdfOs'),'gateway deve manter leitura local para complementar/fallback');
n8nOk(str_contains($gateway,"\$_SESSION['visita_pdf_prefill']"),'resultado deve continuar apenas como prévia antes da confirmação');
n8nOk(!str_contains($gateway,'INSERT INTO visitas'),'gateway não pode criar visita');
n8nOk(!str_contains($gateway,'INSERT INTO orcamentos'),'gateway não pode criar oportunidade');
foreach(['DNB_OS_N8N_WEBHOOK_URL','DNB_OS_N8N_TOKEN','DNB_OS_EXTRACTOR_URL','DNB_OS_AZURE_ENDPOINT','DNB_OS_AZURE_KEY','DNB_OS_AI_VISUAL_ENDPOINT'] as $key){
 n8nOk(str_contains($integration,"'{$key}'"),"integration_config deve aceitar {$key}");
 n8nOk(str_contains($api,$key),"API ADM deve expor/salvar {$key}");
}
n8nOk(str_contains($js,'https://crm.dnbair.com.br/os/analisar'),'painel ADM deve informar a porta pública oficial');
n8nOk(str_contains($js,"fetch('/os/analisar'"),'Ler PDF deve usar o gateway inteligente');
n8nOk(str_contains($footer,'os-analyzer.js?v=1.0.0'),'shell deve carregar o analisador inteligente');
n8nOk(str_contains($docs,'schema_version')&&str_contains($docs,'dnb-os-72-v1'),'documentação deve fixar versão do contrato');
n8nOk(str_contains($docs,'Registrar visita e abrir oportunidade'),'documentação deve manter criação somente após confirmação');

echo "OS n8n analyzer: OK\n";
