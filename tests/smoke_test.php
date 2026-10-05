<?php
$root = dirname(__DIR__);
$required = [
    'install.php','login.php','painel.php','orcamentos.php','pedidos.php','visitas.php','cronograma.php','cnpja_lookup.php',
    'cadencia.php','comissoes.php','configuracoes.php','usuarios.php','includes/bootstrap.php','includes/auth.php',
    'includes/layout.php','includes/security.php','session_refresh.php','assets/css/app.css','assets/js/app.js','assets/js/auth.js','assets/js/ui.js','assets/dnb-logo.png','config/database.example.php'
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

$login = file_get_contents($root . '/login.php');
foreach (['sys_dnb_crm_user','sys_dnb_crm_pass','cf-turnstile-response','redirect_to'] as $needle) {
    if (!str_contains($login, $needle)) {
        fwrite(STDERR, "Login sem requisito de segurança: {$needle}\n");
        exit(1);
    }
}

$bootstrap = file_get_contents($root . '/includes/bootstrap.php');
foreach (["session_name('DNBCRMSESSID')", "'secure' => true", "'httponly' => true", "'samesite' => 'Lax'"] as $needle) {
    if (!str_contains($bootstrap, $needle)) {
        fwrite(STDERR, "Cookie de sessão fora do padrão: {$needle}\n");
        exit(1);
    }
}

if (preg_match("/['\"]domain['\"]\s*=>/i", $bootstrap)) {
    fwrite(STDERR, "Cookie de sessão não pode definir Domain amplo.\n");
    exit(1);
}

$confirmSearch = file_get_contents($root . '/visitas.php') . file_get_contents($root . '/orcamentos.php');
if (str_contains($confirmSearch, 'confirm(')) {
    fwrite(STDERR, "Confirmações nativas encontradas; use modal da UI.\n");
    exit(1);
}

$layout = file_get_contents($root . '/includes/layout.php');
foreach (['data-notification-modal','data-confirm-modal','assets/js/ui.js'] as $needle) {
    if (!str_contains($layout, $needle)) {
        fwrite(STDERR, "Layout sem padrão global de modal: {$needle}\n");
        exit(1);
    }
}

echo "Smoke test OK\n";
