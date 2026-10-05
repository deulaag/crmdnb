<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$redirectTo = safe_internal_redirect((string)($_GET['redirect_to'] ?? ''), '');

if (!empty($_SESSION['user_id'])) {
    try {
        audit(db(), (int)$_SESSION['user_id'], 'logout', 'user', (int)$_SESSION['user_id'], [
            'automatic' => isset($_GET['automatic']) ? 1 : 0,
        ]);
    } catch (Throwable $e) {
    }
}

destroy_auth_session();
header('Location: ' . security_login_url($redirectTo));
exit;
