<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $pdo = db();
    if (session_is_expired($pdo)) {
        $expiredTarget = current_request_target();
        destroy_auth_session();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['flash'] = [
            'type' => 'warning',
            'message' => 'Sua sessão expirou por inatividade. Entre novamente para continuar.',
        ];
        $_SESSION['expired_redirect_to'] = $expiredTarget;

        return null;
    }

    static $user = null;
    if ($user !== null) {
        session_touch_server_activity();
        return $user ?: null;
    }

    $stmt = $pdo->prepare('SELECT id, name, email, role, active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !(int)$user['active']) {
        destroy_auth_session();
        return null;
    }

    session_touch_server_activity();
    return $user;
}

function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        $target = (string)($_SESSION['expired_redirect_to'] ?? current_request_target());
        unset($_SESSION['expired_redirect_to']);

        if (empty($_SESSION['flash'])) {
            flash('warning', 'Entre com sua conta para acessar o CRM.');
        }

        redirect(security_login_url($target));
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
