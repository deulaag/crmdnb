<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = (string) ($_POST['csrf_token'] ?? '');
    $known = (string) ($_SESSION['csrf_token'] ?? '');
    if ($known === '' || !hash_equals($known, $sent)) {
        http_response_code(419);
        exit('Sessão expirada. Atualize a página e tente novamente.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pull_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function money(float|int|string|null $value): string
{
    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function current_month(): string
{
    return date('Y-m');
}

function valid_month(?string $month): string
{
    $month = (string) $month;
    return preg_match('/^\d{4}-\d{2}$/', $month) ? $month : current_month();
}

function generate_opportunity_number(PDO $pdo, ?int $sellerId, string $date): string
{
    $initials = 'DNB';
    if ($sellerId) {
        $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$sellerId]);
        $name = trim((string) $stmt->fetchColumn());
        if ($name !== '') {
            $parts = preg_split('/\s+/', $name) ?: [];
            $first = $parts[0][0] ?? '';
            $lastPart = $parts[count($parts) - 1] ?? '';
            $last = $lastPart !== '' ? $lastPart[0] : '';
            $initials = strtoupper($first . ($last ?: '')) ?: 'DNB';
        }
    }

    $timestamp = strtotime($date) ?: time();
    $ym = date('Y-m', $timestamp);
    $month = date('m', $timestamp);
    $year = date('Y', $timestamp);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM opportunities WHERE DATE_FORMAT(opportunity_date, '%Y-%m') = ? AND (seller_user_id <=> ?)");
    $stmt->execute([$ym, $sellerId]);
    $seq = (int) $stmt->fetchColumn() + 1;

    do {
        $number = sprintf('%s-%03d/%s/%s', $initials, $seq, $month, $year);
        $check = $pdo->prepare('SELECT COUNT(*) FROM opportunities WHERE number = ?');
        $check->execute([$number]);
        $exists = (int) $check->fetchColumn() > 0;
        $seq++;
    } while ($exists);

    return $number;
}

function app_key_bytes(): string
{
    $hex = (string) (app_config()['app_key'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/i', $hex)) {
        return hex2bin($hex) ?: hash('sha256', $hex, true);
    }
    return hash('sha256', $hex, true);
}

function secret_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $cipher = 'aes-256-gcm';
    $iv = random_bytes(openssl_cipher_iv_length($cipher));
    $tag = '';
    $encrypted = openssl_encrypt($plain, $cipher, app_key_bytes(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($encrypted === false) {
        throw new RuntimeException('Não foi possível proteger a credencial.');
    }
    return base64_encode($iv . $tag . $encrypted);
}

function secret_decrypt(?string $payload): string
{
    $payload = (string) $payload;
    if ($payload === '') {
        return '';
    }
    $raw = base64_decode($payload, true);
    $cipher = 'aes-256-gcm';
    $ivLen = openssl_cipher_iv_length($cipher);
    if ($raw === false || strlen($raw) < $ivLen + 16) {
        return '';
    }
    $iv = substr($raw, 0, $ivLen);
    $tag = substr($raw, $ivLen, 16);
    $encrypted = substr($raw, $ivLen + 16);
    $plain = openssl_decrypt($encrypted, $cipher, app_key_bytes(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

function integration_get(PDO $pdo, string $provider): array
{
    $stmt = $pdo->prepare('SELECT * FROM integration_settings WHERE provider = ? LIMIT 1');
    $stmt->execute([$provider]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function integration_upsert(PDO $pdo, string $provider, array $data, int $userId): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO integration_settings (provider, client_id, client_secret, access_token, refresh_token, extra_json, is_active, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), client_secret=VALUES(client_secret), access_token=VALUES(access_token), refresh_token=VALUES(refresh_token), extra_json=VALUES(extra_json), is_active=VALUES(is_active), updated_by=VALUES(updated_by), updated_at=CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        $provider,
        $data['client_id'] ?? null,
        isset($data['client_secret']) ? secret_encrypt((string) $data['client_secret']) : null,
        isset($data['access_token']) ? secret_encrypt((string) $data['access_token']) : null,
        isset($data['refresh_token']) ? secret_encrypt((string) $data['refresh_token']) : null,
        json_encode($data['extra'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        !empty($data['is_active']) ? 1 : 0,
        $userId,
    ]);
}

function audit(PDO $pdo, int $userId, string $action, string $entity, ?int $entityId = null, array $context = []): void
{
    $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, context_json, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $userId,
        $action,
        $entity,
        $entityId,
        json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);
}
