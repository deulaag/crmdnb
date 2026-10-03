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
$html = file_get_contents($root . '/install.php');
foreach (['Laticínios Santa Maria','Betânia Alvoar','Vopak','Bracell','Rafael Cerqueira','Raquel'] as $demo) {
    if (str_contains($html, $demo)) {
        fwrite(STDERR, "Dado demonstrativo indevido encontrado: {$demo}\n");
        exit(1);
    }
}
$all = '';
foreach (glob($root . '/*.php') as $file) $all .= file_get_contents($file);
if (str_contains($all, '.env')) {
    // A única menção permitida é a explicação de que não usamos .env nas configurações/instalação.
    $clean = str_replace(['Não usamos <code>.env</code>.','Sem <code>.env</code>:'], '', $all);
    if (str_contains($clean, '.env')) {
        fwrite(STDERR, "Uso indevido de .env encontrado.\n");
        exit(1);
    }
}
echo "Smoke test OK\n";
