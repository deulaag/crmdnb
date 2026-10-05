<?php
declare(strict_types=1);

function security_scope(): string
{
    return 'dnb_crm';
}

function safe_internal_redirect(?string $value, string $fallback = 'painel.php'): string
{
    $value = trim((string)$value);
    if ($value === '') return $fallback;

    $value = str_replace(["\r","\n","\0"], '', $value);
    if (str_contains($value, '\\') || str_starts_with($value, '//')) return $fallback;

    $parts = parse_url($value);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return $fallback;
    }

    $path = (string)($parts['path'] ?? '');
    if ($path === '' || str_contains($path, '..')) return $fallback;

    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

    if (str_starts_with($path, '/')) return $path . $query . $fragment;
    if (!preg_match('/^[A-Za-z0-9_\-\.\/]+$/', $path)) return $fallback;

    return $path . $query . $fragment;
}

function current_request_target(): string
{
    return safe_internal_redirect((string)($_SERVER['REQUEST_URI'] ?? '/'), 'painel.php');
}

function security_settings(PDO $pdo): array
{
    $cfg = integration_get($pdo, 'security');
    $extra = json_decode((string)($cfg['extra_json'] ?? '{}'), true);
    $extra = is_array($extra) ? $extra : [];

    $timeoutMs = max(300000, min(43200000, (int)($extra['inactivity_timeout_ms'] ?? 1800000)));
    $warningMs = max(15000, min(600000, (int)($extra['warning_before_timeout_ms'] ?? 60000)));

    if ($warningMs >= $timeoutMs) {
        $warningMs = min(60000, max(15000, $timeoutMs - 15000));
    }

    return [
        'inactivity_timeout_ms' => $timeoutMs,
        'warning_before_timeout_ms' => $warningMs,
    ];
}

function turnstile_settings(PDO $pdo): array
{
    $cfg = integration_get($pdo, 'turnstile');
    return [
        'enabled' => !empty($cfg['is_active']),
        'site_key' => trim((string)($cfg['client_id'] ?? '')),
        'secret_key' => secret_decrypt($cfg['client_secret'] ?? ''),
    ];
}

function verify_turnstile(PDO $pdo, string $token, ?string $remoteIp = null): bool
{
    $cfg = turnstile_settings($pdo);
    if (!$cfg['enabled']) return true;

    if ($cfg['site_key'] === '' || $cfg['secret_key'] === '') {
        throw new RuntimeException('A proteção anti-bot está ativa, mas não está configurada corretamente.');
    }

    $token = trim($token);
    if ($token === '' || !extension_loaded('curl')) return false;

    $payload = ['secret' => $cfg['secret_key'], 'response' => $token];
    $remoteIp = trim((string)$remoteIp);
    if ($remoteIp !== '') $payload['remoteip'] = $remoteIp;

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query($payload),
    ]);

    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $status < 200 || $status >= 300) return false;

    $response = json_decode($raw, true);
    return is_array($response) && !empty($response['success']);
}

function ensure_login_attempts_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            identifier_hash CHAR(64) NOT NULL,
            ip_address VARCHAR(45) NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_attempt_identifier_time (identifier_hash, attempted_at),
            INDEX idx_login_attempt_ip_time (ip_address, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $done = true;
}

function login_identifier_hash(string $identifier): string
{
    return hash_hmac('sha256', strtolower(trim($identifier)), app_key_bytes());
}

function login_throttle_allowed(PDO $pdo, string $identifier, string $ipAddress): bool
{
    ensure_login_attempts_schema($pdo);

    $hash = login_identifier_hash($identifier);

    $q = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE identifier_hash=? AND success=0 AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)'
    );
    $q->execute([$hash]);
    $identifierFailures = (int)$q->fetchColumn();

    $q = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip_address=? AND success=0 AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)'
    );
    $q->execute([$ipAddress]);
    $ipFailures = (int)$q->fetchColumn();

    return $identifierFailures < 8 && $ipFailures < 25;
}

function login_attempt_record(PDO $pdo, string $identifier, string $ipAddress, bool $success): void
{
    ensure_login_attempts_schema($pdo);

    $hash = login_identifier_hash($identifier);

    if ($success) {
        $q = $pdo->prepare('DELETE FROM login_attempts WHERE identifier_hash=? OR ip_address=?');
        $q->execute([$hash, $ipAddress]);
        return;
    }

    $q = $pdo->prepare('INSERT INTO login_attempts (identifier_hash, ip_address, success) VALUES (?, ?, 0)');
    $q->execute([$hash, $ipAddress]);

    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 2 DAY)');
}

function session_mark_authenticated(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_activity_server'] = time();
}

function session_touch_server_activity(): void
{
    if (!empty($_SESSION['user_id'])) $_SESSION['last_activity_server'] = time();
}

function session_is_expired(PDO $pdo): bool
{
    if (empty($_SESSION['user_id'])) return false;

    $settings = security_settings($pdo);
    $last = (int)($_SESSION['last_activity_server'] ?? $_SESSION['authenticated_at'] ?? time());
    return (time() - $last) >= (int)ceil($settings['inactivity_timeout_ms'] / 1000);
}

function destroy_auth_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => $params['samesite'] ?: 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function security_login_url(?string $redirectTo = null): string
{
    $target = safe_internal_redirect($redirectTo, '');
    return $target !== '' ? 'login.php?redirect_to=' . rawurlencode($target) : 'login.php';
}
