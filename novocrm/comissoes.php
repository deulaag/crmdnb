<?php
declare(strict_types=1);

/**
 * "Comissões" virou "Equipe Comercial, Metas & Comissões" (equipe.php).
 * Redirecionamento permanente, preservando o mês escolhido.
 */
require_once __DIR__ . '/_app.php';

$target = nc_url('equipe.php');
if (isset($_GET['mes']) && preg_match('/^\d{4}-\d{2}$/', (string)$_GET['mes'])) {
    $target .= '?mes=' . rawurlencode((string)$_GET['mes']);
    if (($_GET['aba'] ?? '') === 'extrato') {
        $target .= '&aba=extrato';
    }
} elseif (($_GET['aba'] ?? '') === 'extrato') {
    $target .= '?aba=extrato';
}

header('Location: ' . $target, true, 301);
exit;
