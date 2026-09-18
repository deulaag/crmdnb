<?php
declare(strict_types=1);

require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/rd_station_service.php';

function rdOAuthConfigRedirect(string $message, string $type='success'): never
{
    if($type==='error') $_SESSION['flash_error']=$message;
    elseif($type==='warning') $_SESSION['flash_warning']=$message;
    else $_SESSION['flash']=$message;
    header('Location: /configuracoes.php#tab-rdstation');
    exit;
}

function rdOAuthStart(string $produto): never
{
    exigirPapel(PAPEL_ADMINISTRADOR);
    rdProdutoConfig($produto);
    if(!rdCredenciaisConfiguradas($produto)){
        rdOAuthConfigRedirect('Salve Client ID e Client Secret do '.$produto.' antes de conectar.','error');
    }
    try{
        $state=bin2hex(random_bytes(32));
        $_SESSION['rd_oauth_state'][$produto]=[
            'hash'=>hash('sha256',$state),
            'created_at'=>time(),
            'user_id'=>(int)(usuarioLogado()['id']??0),
        ];
        header('Cache-Control: no-store');
        header('Location: '.rdAuthorizationUrl($produto,$state),true,302);
        exit;
    }catch(Throwable $e){
        rdOAuthConfigRedirect('Não foi possível iniciar OAuth do RD Station: '.$e->getMessage(),'error');
    }
}

function rdOAuthCallback(string $produto): never
{
    exigirPapel(PAPEL_ADMINISTRADOR);
    rdProdutoConfig($produto);
    $saved=$_SESSION['rd_oauth_state'][$produto]??null;
    unset($_SESSION['rd_oauth_state'][$produto]);

    if(!is_array($saved)||empty($saved['hash'])||empty($saved['created_at'])){
        rdOAuthConfigRedirect('Sessão OAuth inválida ou expirada. Inicie a conexão novamente.','error');
    }
    if(time()-(int)$saved['created_at']>900){
        rdOAuthConfigRedirect('A autorização demorou demais. Inicie a conexão novamente.','error');
    }
    if((int)($saved['user_id']??0)!==(int)(usuarioLogado()['id']??0)){
        rdOAuthConfigRedirect('A autorização foi iniciada por outro usuário.','error');
    }

    $state=trim((string)($_GET['state']??''));
    if($state===''||!hash_equals((string)$saved['hash'],hash('sha256',$state))){
        rdOAuthConfigRedirect('State OAuth inválido. A conexão foi cancelada por segurança.','error');
    }
    if(isset($_GET['error'])){
        $detail=trim((string)($_GET['error_description']??$_GET['error']));
        rdOAuthConfigRedirect('RD Station não autorizou a conexão'.($detail!==''?': '.mb_substr($detail,0,180):'.'),'error');
    }
    $code=trim((string)($_GET['code']??''));
    if($code==='') rdOAuthConfigRedirect('RD Station não retornou o código de autorização.','error');

    try{
        rdTrocarCodePorTokens($produto,$code);
        $test=rdTestarConexao($produto);
        $label=(string)rdProdutoConfig($produto)['label'];
        if(!$test['ok']){
            rdOAuthConfigRedirect($label.' conectado por OAuth, porém o teste de leitura precisa de atenção: '.$test['message'],'warning');
        }
        rdOAuthConfigRedirect($label.' conectado e autenticado com sucesso.');
    }catch(Throwable $e){
        rdOAuthConfigRedirect('Falha ao concluir OAuth do RD Station: '.$e->getMessage(),'error');
    }
}
