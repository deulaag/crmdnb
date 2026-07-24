<?php
/**
 * Cabeçalho comum a todas as páginas internas.
 * Espera (opcionais): $tabAtual (string), $mes (string YYYY-MM)
 */
$tabAtual = $tabAtual ?? '';
$mes = $mes ?? mesAtual();
$usuario = usuarioLogado();

$abas = [
    ['painel', 'Painel', 'painel.php'],
    ['orc', 'Orçamentos', 'orcamentos.php'],
    ['ped', 'Pedidos', 'pedidos.php'],
    ['vis', 'Visitas', 'visitas.php'],
    ['cad', 'Cadência', 'cadencia.php'],
    ['com', 'Comissões', 'comissoes.php'],
    ['cfg', 'Configurações', 'configuracoes.php'],
];
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(APP_NOME) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="min-h-screen" style="background:#f0f4f8;font-family:Arial, sans-serif;">

<div class="px-5 pt-5 pb-3 flex items-end justify-between flex-wrap gap-3" style="border-bottom:3px solid <?= NAVY ?>;">
  <div>
    <div class="text-lg font-bold" style="color:<?= NAVY ?>;">DNB · CRM Comercial</div>
    <div class="text-xs text-slate-500">Políticas de comissão vigentes · metas derivadas por conversão · dados compartilhados entre a equipe</div>
  </div>
  <div class="flex items-end gap-3 flex-wrap">
    <form method="get" class="flex flex-col">
      <label class="block text-xs font-semibold text-slate-600 mb-1">Mês de referência</label>
      <input type="month" name="mes" value="<?= e($mes) ?>" class="<?= INPUT_CLS ?>" onchange="this.form.submit()">
    </form>
    <?php if ($usuario): ?>
      <div class="text-xs text-slate-500 pb-2">
        <?= e($usuario['nome']) ?> · <a href="/logout.php" class="underline">sair</a>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="px-5 pt-3 flex gap-2 flex-wrap">
  <?php foreach ($abas as [$k, $l, $href]):
      $ativo = $tabAtual === $k;
      $qs = $mes ? ('?mes=' . urlencode($mes)) : '';
  ?>
    <a href="<?= e($href . $qs) ?>" class="px-4 py-2 rounded-t-lg text-sm font-bold transition"
       style="<?= $ativo ? ('background:' . NAVY . ';color:#fff;') : 'background:#fff;color:#64748b;border:1px solid #e2e8f0;' ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</div>

<div class="p-5 max-w-6xl">
