<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (!empty($_SESSION['user_id'])) {
    try { audit(db(), (int) $_SESSION['user_id'], 'logout', 'user', (int) $_SESSION['user_id']); } catch (Throwable $e) {}
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
}
session_destroy();
header('Location: login.php');
exit;
