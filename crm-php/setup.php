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
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configuração inicial · DNB CRM</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center" style="background:#f0f4f8;font-family:Arial, sans-serif;">
  <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 w-full max-w-sm">
    <div class="text-lg font-bold mb-1" style="color:#1F3864;">DNB · CRM Comercial</div>
    <div class="text-sm text-slate-500 mb-4">Primeiro acesso — crie o usuário administrador.</div>
    <?php if ($erro): ?><div class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg p-2 mb-3"><?= e($erro) ?></div><?php endif; ?>
    <form method="post" class="flex flex-col gap-3">
      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Nome</label>
        <input name="nome" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" value="<?= e($_POST['nome'] ?? '') ?>">
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">E-mail</label>
        <input type="email" name="email" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Senha (mín. 8 caracteres)</label>
        <input type="password" name="senha" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Confirmar senha</label>
        <input type="password" name="senha2" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
      </div>
      <button class="px-4 py-2 rounded-lg text-sm font-bold text-white hover:opacity-90 transition" style="background:#1F3864;">Criar administrador</button>
    </form>
  </div>
</body>
</html>
