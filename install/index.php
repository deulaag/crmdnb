<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/auth.php';
require_once dirname(__DIR__).'/includes/rbac.php';
require_once dirname(__DIR__).'/includes/helpers.php';
require_once dirname(__DIR__).'/includes/migration_service.php';

exigirPapel(PAPEL_ADMINISTRADOR);
$pdo=db();$erro='';$pendentes=[];

try{$pendentes=migracoesDnbPendentes($pdo);}catch(Throwable $e){$erro=$e->getMessage();}

if($erro===''&&$pendentes===[]){
    header('Location: /painel.php');
    exit;
}

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    exigirCsrf();
    try{
        $aplicadas=aplicarMigracoesDnbPendentes($pdo,(int)usuarioLogado()['id']);
        $_SESSION['flash']='Banco atualizado com sucesso: '.count($aplicadas).' atualização(ões) aplicada(s). O instalador foi desativado automaticamente.';
        header('Location: /painel.php');
        exit;
    }catch(Throwable $e){
        error_log('install.migration: '.$e->getMessage());
        $erro='A atualização não foi concluída. '.$e->getMessage();
        try{$pendentes=migracoesDnbPendentes($pdo);}catch(Throwable){}
    }
}

$usuario=usuarioLogado();
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#003060">
  <title>Atualizar banco · DNB CRM</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Michroma&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/style.css?v=3.0.0">
</head>
<body class="dnb-body">
<main class="dnb-auth">
  <section class="dnb-auth__brand" aria-label="DNB CRM Comercial">
    <img class="dnb-auth__logo" src="/assets/dnb-logo-white.png" width="190" height="66" alt="DNB">
    <div class="dnb-auth__intro">
      <span>Atualização protegida</span>
      <h1>O banco precisa acompanhar a nova versão do CRM.</h1>
      <p>Apenas o administrador logado pode aplicar as alterações pendentes.</p>
    </div>
    <div class="dnb-auth__signature">Após a conclusão, esta página volta a ficar desativada.</div>
  </section>

  <section class="dnb-auth__panel">
    <div class="dnb-auth__card">
      <img class="dnb-auth__mobile-logo" src="/assets/dnb-logo-blue.png" width="150" height="53" alt="DNB">
      <span class="dnb-auth__eyebrow">Administrador · <?=e((string)($usuario['nome']??''))?></span>
      <h2>Atualizar banco de dados</h2>
      <p class="dnb-auth__lead"><?=count($pendentes)?> atualização(ões) pendente(s). O processo preserva os dados existentes.</p>
      <?php if($erro!==''):?><div class="dnb-alert dnb-alert--error" role="alert"><?=e($erro)?></div><?php endif;?>
      <?php if($pendentes):?>
        <ul class="text-sm text-slate-600 mb-4" aria-label="Atualizações pendentes">
          <?php foreach($pendentes as $migracao):?><li><?=e((string)$migracao['nome'])?></li><?php endforeach;?>
        </ul>
        <form method="post" class="dnb-auth__form" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Atualizando...';">
          <?=csrfInput()?>
          <button class="dnb-button dnb-button--primary dnb-auth__button" type="submit">Atualizar banco agora</button>
        </form>
      <?php endif;?>
      <div class="dnb-auth__security">Não feche esta página durante a atualização.</div>
    </div>
  </section>
</main>
</body>
</html>
