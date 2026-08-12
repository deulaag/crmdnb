<?php
require_once __DIR__ . '/includes/rbac.php';
if (!usuarioLogado()) {
    header('Location: /login.php');
    exit;
}
header('Location: ' . paginaInicialPorPapel());
exit;
