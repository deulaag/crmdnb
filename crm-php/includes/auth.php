<?php
/**
 * Autenticação simples por sessão (usuário/senha) para o CRM.
 * Toda página protegida deve começar com: require_once __DIR__.'/includes/auth.php';
 */
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function usuarioLogado(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

function exigirLogin(): void
{
    if (!usuarioLogado()) {
        $destino = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: /login.php?voltar=' . urlencode($destino));
        exit;
    }
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
    if ($u && password_verify($senha, $u['senha_hash'])) {
        $_SESSION['usuario'] = ['id' => $u['id'], 'nome' => $u['nome'], 'email' => $u['email']];
        return true;
    }
    return false;
}

function encerrarSessao(): void
{
    $_SESSION = [];
    session_destroy();
}
