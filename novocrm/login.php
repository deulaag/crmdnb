<?php
declare(strict_types=1);

/**
 * /novocrm/login.php — apenas redireciona para o login da raiz, preservando redirect_to.
 * Não duplica login, Turnstile nem throttle.
 */
require_once __DIR__ . '/../includes/security.php';

$target = safe_internal_redirect((string)($_GET['redirect_to'] ?? ''), '/novocrm/painel.php');

header('Location: /login.php?redirect_to=' . rawurlencode($target));
exit;
