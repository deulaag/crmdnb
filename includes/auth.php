<?php
/**
 * Autenticação simples por sessão (usuário/senha) para o CRM.
 * Toda página protegida deve começar com: require_once __DIR__.'/includes/auth.php';
 */
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    $seguro = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $seguro,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function usuarioLogado(): ?array
{
    if (empty($_SESSION['usuario'])) return null;
    if (defined('DNB_TESTING') && DNB_TESTING === true) return $_SESSION['usuario'];
    static $verificado = false;
    if (!$verificado) {
        $verificado = true;
        $stmt = db()->prepare('SELECT * FROM usuarios WHERE id=? LIMIT 1');
        $stmt->execute([(int) $_SESSION['usuario']['id']]);
        $u = $stmt->fetch();
        if (!$u) {
            unset($_SESSION['usuario']);
            return null;
        }
        $status = $u['status'] ?? (!empty($u['ativo']) ? 'active' : 'inactive');
        if ($status !== 'active') {
            unset($_SESSION['usuario']);
            return null;
        }
        $_SESSION['usuario'] = [
            'id' => (int) $u['id'], 'nome' => $u['nome'], 'email' => $u['email'],
            'role' => $u['role'] ?? 'ADMINISTRADOR',
            'supervisor_id' => isset($u['supervisor_id']) ? (int) $u['supervisor_id'] : null,
            'vendedor_id' => $u['vendedor_id'] ?? null, 'status' => $status,
        ];
    }
    return $_SESSION['usuario'];
}

function exigirLogin(): void
{
    if (!usuarioLogado()) {
        $destino = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: /login.php?voltar=' . urlencode($destino));
        exit;
    }
}

function destinoAposLogin(?string $destino): string
{
    if (!$destino || !str_starts_with($destino, '/') || str_starts_with($destino, '//')) {
        return '/painel.php';
    }
    $partes = parse_url($destino);
    if ($partes === false || isset($partes['host']) || isset($partes['scheme'])) {
        return '/painel.php';
    }
    return $destino;
}

function existeAlgumUsuario(): bool
{
    $n = db()->query('SELECT COUNT(*) c FROM usuarios')->fetch()['c'] ?? 0;
    return ((int) $n) > 0;
}

function autenticar(string $email, string $senha): bool
{
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE email = ? AND ativo = 1 LIMIT 1');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u) return false;
    $status = $u['status'] ?? (!empty($u['ativo']) ? 'active' : 'inactive');
    if ($status === 'active' && password_verify($senha, $u['senha_hash'])) {
        session_regenerate_id(true);
        $_SESSION['usuario'] = [
            'id' => (int) $u['id'],
            'nome' => $u['nome'],
            'email' => $u['email'],
            'role' => $u['role'] ?? 'ADMINISTRADOR',
            'supervisor_id' => isset($u['supervisor_id']) ? (int) $u['supervisor_id'] : null,
            'vendedor_id' => $u['vendedor_id'] ?? null,
            'status' => $status,
        ];
        return true;
    }
    return false;
}

function encerrarSessao(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
