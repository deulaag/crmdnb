<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo ?? 'Acesso não autorizado') ?> | DNB CRM</title>
<link rel="stylesheet" href="/assets/style.css?v=3.0.0">
</head>
<body class="dnb-body">
<main class="dnb-state-page">
  <section class="dnb-state-card">
    <span class="dnb-eyebrow">DNB CRM</span>
    <h1><?= e($titulo ?? 'Acesso não autorizado') ?></h1>
    <p><?= e($mensagem ?? 'Você não possui acesso a este recurso.') ?></p>
    <a class="dnb-button dnb-button--primary" href="<?= e(paginaInicialPorPapel()) ?>">Voltar ao CRM</a>
  </section>
</main>
</body>
</html>
