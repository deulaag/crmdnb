<?php
/**
 * Criação do primeiro usuário administrador.
 * Só funciona enquanto a tabela "usuarios" estiver vazia — depois de criado
 * o primeiro usuário, esta página se desativa sozinha (redireciona ao login).
 * Apague este arquivo do servidor depois de concluir, se preferir.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (existeAlgumUsuario()) {
    header('Location: /login.php');
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = (string) ($_POST['senha'] ?? '');
    $senha2 = (string) ($_POST['senha2'] ?? '');
    if ($nome === '' || $email === '' || $senha === '') {
        $erro = 'Preencha nome, e-mail e senha.';
    } elseif (strlen($senha) < 8) {
        $erro = 'A senha precisa ter pelo menos 8 caracteres.';
    } elseif ($senha !== $senha2) {
        $erro = 'As senhas não coincidem.';
    } else {
        $stmt = db()->prepare('INSERT INTO usuarios (nome, email, senha_hash, ativo) VALUES (?, ?, ?, 1)');
        $stmt->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
        header('Location: /login.php?criado=1');
        exit;
    }
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#003060">
<title>Configuração inicial · DNB CRM</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Michroma&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css?v=2.0.0">
</head>
<body class="dnb-body">
<main class="dnb-auth">
  <section class="dnb-auth__brand" aria-label="DNB CRM Comercial">
    <img class="dnb-auth__logo" src="/assets/dnb-logo-white.png" width="190" height="66" alt="DNB">
    <div class="dnb-auth__intro">
      <span>Configuração inicial</span>
      <h1>Prepare o CRM para sua equipe comercial.</h1>
      <p>Crie a conta administradora que dará início à operação no ambiente DNB.</p>
    </div>
    <div class="dnb-auth__signature">Esta etapa será desativada após a criação da conta.</div>
  </section>

  <section class="dnb-auth__panel">
    <div class="dnb-auth__card">
      <img class="dnb-auth__mobile-logo" src="/assets/dnb-logo-blue.png" width="150" height="53" alt="DNB">
      <span class="dnb-auth__eyebrow">Primeiro acesso</span>
      <h2>Criar administrador</h2>
      <p class="dnb-auth__lead">Informe os dados da pessoa responsável pelo sistema.</p>
      <?php if ($erro): ?><div class="dnb-alert dnb-alert--error" role="alert"><?= e($erro) ?></div><?php endif; ?>
      <form method="post" class="dnb-auth__form">
        <div class="dnb-auth__field">
          <label for="nome">Nome completo</label>
          <input id="nome" name="nome" required autocomplete="name" value="<?= e($_POST['nome'] ?? '') ?>">
        </div>
        <div class="dnb-auth__field">
          <label for="email">E-mail</label>
          <input id="email" type="email" name="email" required autocomplete="username" inputmode="email" value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <div class="dnb-auth__field">
          <label for="senha">Senha <span>(mínimo de 8 caracteres)</span></label>
          <input id="senha" type="password" name="senha" required minlength="8" autocomplete="new-password">
        </div>
        <div class="dnb-auth__field">
          <label for="senha2">Confirmar senha</label>
          <input id="senha2" type="password" name="senha2" required minlength="8" autocomplete="new-password">
        </div>
        <button class="dnb-button dnb-button--primary dnb-auth__button" type="submit">Criar administrador</button>
      </form>
      <div class="dnb-auth__security">As credenciais são armazenadas com proteção criptográfica.</div>
    </div>
  </section>
</main>
<script src="/assets/ui.js?v=2.0.0"></script>
</body>
</html>
