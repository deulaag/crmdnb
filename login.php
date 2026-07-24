<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (!existeAlgumUsuario()) {
    header('Location: /setup.php');
    exit;
}
if (usuarioLogado()) {
    header('Location: /painel.php');
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = (string) ($_POST['senha'] ?? '');
    if (autenticar($email, $senha)) {
        $voltar = $_GET['voltar'] ?? '/painel.php';
        header('Location: ' . ($voltar !== '' ? $voltar : '/painel.php'));
        exit;
    }
    $erro = 'E-mail ou senha inválidos.';
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · DNB CRM</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center" style="background:#f0f4f8;font-family:Arial, sans-serif;">
  <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 w-full max-w-sm">
    <div class="text-lg font-bold mb-1" style="color:#1F3864;">DNB · CRM Comercial</div>
    <div class="text-sm text-slate-500 mb-4">Entre com seu e-mail e senha.</div>
    <?php if (!empty($_GET['criado'])): ?><div class="text-sm text-green-800 bg-green-50 border border-green-200 rounded-lg p-2 mb-3">Administrador criado — faça login.</div><?php endif; ?>
    <?php if ($erro): ?><div class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg p-2 mb-3"><?= e($erro) ?></div><?php endif; ?>
    <form method="post" class="flex flex-col gap-3">
      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">E-mail</label>
        <input type="email" name="email" required autofocus class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Senha</label>
        <input type="password" name="senha" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
      </div>
      <button class="px-4 py-2 rounded-lg text-sm font-bold text-white hover:opacity-90 transition" style="background:#1F3864;">Entrar</button>
    </form>
  </div>
</body>
</html>
