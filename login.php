<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/rbac.php';

if (!existeAlgumUsuario()) {
    header('Location: /setup.php');
    exit;
}
if (usuarioLogado()) {
    header('Location: ' . paginaInicialPorPapel());
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = (string) ($_POST['senha'] ?? '');
    if (autenticar($email, $senha)) {
        $voltar = isset($_GET['voltar']) ? destinoAposLogin($_GET['voltar']) : paginaInicialPorPapel();
        header('Location: ' . $voltar);
        exit;
    }
    $erro = 'E-mail ou senha inválidos.';
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#003060">
<title>Entrar · DNB CRM</title>
<link rel="stylesheet" href="/assets/style.css?v=4.3.0">
<link rel="stylesheet" href="/assets/crm-pilot-ui.css?v=4.3.0">
</head>
<body class="dnb-body">
<main class="dnb-auth">
  <section class="dnb-auth__brand" aria-label="DNB CRM Comercial">
    <img class="dnb-auth__logo" src="/assets/dnb-logo-white.png" width="190" height="66" alt="DNB">
    <div class="dnb-auth__intro">
      <span>CRM Comercial</span>
      <h1>Gestão que transforma oportunidades em resultados.</h1>
      <p>Metas, funil, pedidos, visitas e comissões reunidos para dar clareza à operação comercial.</p>
    </div>
    <div class="dnb-auth__signature">Dados integrados para decisões mais rápidas.</div>
  </section>

  <section class="dnb-auth__panel">
    <div class="dnb-auth__card">
      <img class="dnb-auth__mobile-logo" src="/assets/dnb-logo-blue.png" width="150" height="53" alt="DNB">
      <span class="dnb-auth__eyebrow">Acesso seguro</span>
      <h2>Bem-vindo</h2>
      <p class="dnb-auth__lead">Entre com suas credenciais para acessar o CRM.</p>
      <?php if (!empty($_GET['criado'])): ?><div class="dnb-alert dnb-alert--success" role="status">Administrador criado. Faça seu primeiro acesso.</div><?php endif; ?>
      <?php if ($erro): ?><div class="dnb-alert dnb-alert--error" role="alert"><?= e($erro) ?></div><?php endif; ?>
      <form method="post" class="dnb-auth__form">
        <div class="dnb-auth__field">
          <label for="email">E-mail</label>
          <input id="email" type="email" name="email" required autofocus autocomplete="username" inputmode="email" value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <div class="dnb-auth__field">
          <label for="senha">Senha</label>
          <input id="senha" type="password" name="senha" required autocomplete="current-password">
        </div>
        <button class="dnb-button dnb-button--primary dnb-auth__button" type="submit">Entrar no CRM</button>
      </form>
      <div class="dnb-auth__security">Conexão protegida e acesso restrito à equipe DNB.</div>
    </div>
  </section>
</main>
<script src="/assets/ui.js?v=3.0.0"></script>
</body>
</html>
