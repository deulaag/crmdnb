<?php
declare(strict_types=1);

$root=dirname(__DIR__);
function rd3file(string $path):string{
    global $root;
    $v=file_get_contents($root.'/'.$path);
    if($v===false){fwrite(STDERR,"FALHA: {$path} ausente\n");exit(1);}
    return $v;
}
function rd3ok(bool $cond,string $msg):void{
    if(!$cond){fwrite(STDERR,"FALHA: {$msg}\n");exit(1);}
}

$config=rd3file('includes/integration_config.php');
$service=rd3file('includes/rd_station_service.php');
$controller=rd3file('includes/rd_station_oauth_controller.php');
$page=rd3file('configuracoes.php');
$marketingConnect=rd3file('api/oauth/rdstation/marketing/connect.php');
$marketingCallback=rd3file('api/oauth/rdstation/marketing/callback.php');
$crmConnect=rd3file('api/oauth/rdstation/crm/connect.php');
$crmCallback=rd3file('api/oauth/rdstation/crm/callback.php');
$cron=rd3file('api/internal/rdstation/refresh.php');

foreach([
    'DNB_PUBLIC_BASE_URL',
    'DNB_RD_MARKETING_CLIENT_ID',
    'DNB_RD_MARKETING_CLIENT_SECRET',
    'DNB_RD_MARKETING_ACCESS_TOKEN',
    'DNB_RD_MARKETING_REFRESH_TOKEN',
    'DNB_RD_CRM_CLIENT_ID',
    'DNB_RD_CRM_CLIENT_SECRET',
    'DNB_RD_CRM_ACCESS_TOKEN',
    'DNB_RD_CRM_REFRESH_TOKEN',
] as $key) rd3ok(str_contains($config,"'{$key}'"),"chave {$key} ausente da configuração local");

rd3ok(str_contains($service,"'https://api.rd.services/auth/dialog'"),'Marketing deve usar authorization endpoint oficial');
rd3ok(str_contains($service,"'https://api.rd.services/auth/token'"),'Marketing deve usar token endpoint oficial');
rd3ok(str_contains($service,"'https://accounts.rdstation.com/oauth/authorize'"),'CRM v2 deve usar authorization endpoint oficial');
rd3ok(str_contains($service,"'https://api.rd.services/oauth2/token'"),'CRM v2 deve usar token endpoint oficial');
rd3ok(str_contains($service,"'https://api.rd.services/platform/contacts/fields'"),'teste Marketing deve validar leitura de campos');
rd3ok(str_contains($service,"'https://api.rd.services/crm/v2/users?page[size]=1'"),'teste CRM deve validar leitura de usuários');
rd3ok(str_contains($service,"'response_type' => 'code'"),'CRM deve usar authorization code');
rd3ok(str_contains($service,"'state' => \$state"),'OAuth deve enviar state');
rd3ok(str_contains($service,"'grant_type' => \$tipo === 'code' ? 'authorization_code' : 'refresh_token'"),'CRM deve usar grant types corretos');
rd3ok(str_contains($service,'$expiresAt <= time() + 300'),'token deve ser renovado antes de expirar');
rd3ok(str_contains($service,"\$payload['refresh_token'] ?? ''"),'refresh token novo deve substituir o anterior quando retornado');
rd3ok(str_contains($service,"'Authorization: Bearer ' . \$token"),'APIs RD devem usar bearer token no backend');
rd3ok(str_contains($service,"['api.rd.services']"),'cliente HTTP deve limitar chamadas ao domínio da API RD');

rd3ok(str_contains($controller,"hash('sha256',\$state)"),'state OAuth deve ser guardado como hash');
rd3ok(str_contains($controller,"time()-(int)\$saved['created_at']>900"),'state OAuth precisa expirar');
rd3ok(str_contains($controller,'hash_equals'),'state OAuth deve usar comparação segura');
rd3ok(str_contains($controller,'rdTrocarCodePorTokens'),'callback deve trocar code por tokens');
rd3ok(str_contains($controller,'rdTestarConexao'),'callback deve validar leitura após autenticar');

rd3ok(str_contains($marketingConnect,"rdOAuthStart('marketing')"),'rota Marketing connect incorreta');
rd3ok(str_contains($marketingCallback,"rdOAuthCallback('marketing')"),'rota Marketing callback incorreta');
rd3ok(str_contains($crmConnect,"rdOAuthStart('crm')"),'rota CRM connect incorreta');
rd3ok(str_contains($crmCallback,"rdOAuthCallback('crm')"),'rota CRM callback incorreta');

rd3ok(str_contains($page,"'rdstation'    => 'RD Station'"),'Configurações deve possuir aba RD Station');
foreach(['salvar_rd_base','salvar_rd_app','testar_rdstation','renovar_rdstation','desconectar_rdstation'] as $action)
    rd3ok(str_contains($page,'value="'.$action.'"')||str_contains($page,"\$acao === '{$action}'"),"ação {$action} ausente");
rd3ok(str_contains($page,"rdEstadoProduto('marketing')")&&str_contains($page,"rdEstadoProduto('crm')"),'tela deve separar Marketing e CRM');
rd3ok(str_contains($page,'Callback para cadastrar no App Publisher'),'callbacks devem ficar visíveis ao ADM');
rd3ok(str_contains($page,'Nenhuma sincronização completa'),'Fase 3 não pode sugerir sync já ativo');
rd3ok(!str_contains($page,'rdAccessTokenSalvo(')&&!str_contains($page,'rdRefreshTokenSalvo('),'tokens OAuth nunca devem ser renderizados na página');

rd3ok(str_contains($cron,"PHP_SAPI !== 'cli'"),'manutenção de tokens deve ser somente CLI');
rd3ok(str_contains($cron,'rdObterAccessToken($produto)'),'cron deve renovar apenas via serviço central');

echo "CRM Fase 3 RD Station OAuth: OK\n";
