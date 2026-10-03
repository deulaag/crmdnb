<?php
$root = dirname(__DIR__);
$required = [
    'install.php','login.php','painel.php','orcamentos.php','pedidos.php','visitas.php','cronograma.php',
    'cadencia.php','comissoes.php','configuracoes.php','usuarios.php','includes/bootstrap.php','includes/auth.php',
    'includes/layout.php','assets/css/app.css','assets/js/app.js','assets/dnb-logo.png','config/database.example.php'
];

foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) {
        fwrite(STDERR, "Arquivo obrigatório ausente: {$file}\n");
        exit(1);
    }
}

$install = file_get_contents($root . '/install.php');
foreach (['opportunities','orders','visits','agenda','companies','contacts'] as $table) {
    if (preg_match('/INSERT\s+INTO\s+' . preg_quote($table, '/') . '\b/i', $install)) {
        fwrite(STDERR, "O instalador não pode criar registros comerciais em {$table}.\n");
        exit(1);
    }
}

$all = '';
foreach (glob($root . '/*.php') as $file) {
    $all .= file_get_contents($file);
}

if (str_contains($all, '.env')) {
    $clean = str_replace(['Não usamos <code>.env</code>.','Sem <code>.env</code>:'], '', $all);
    if (str_contains($clean, '.env')) {
        fwrite(STDERR, "Uso indevido de .env encontrado.\n");
        exit(1);
    }
}

echo "Smoke test OK\n";
