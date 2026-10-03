<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $user = null;
    if ($user !== null) {
        return $user ?: null;
    }

    $stmt = db()->prepare('SELECT id, name, email, role, active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || !(int) $user['active']) {
        $_SESSION = [];
        return null;
    }
    return $user;
}

function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        flash('warning', 'Entre com sua conta para acessar o CRM.');
        redirect('login.php');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_auth();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Acesso restrito ao administrador.');
    }
    return $user;
}
