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

function valid_date_string(?string $date): bool
{
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $check = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $check instanceof DateTimeImmutable && $check->format('Y-m-d') === $date;
}

function normalized_name(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
    }
    $value = strtolower($value);
    return preg_replace('/[^a-z0-9]+/', ' ', $value) ? trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value)) : trim($value);
}

function find_user_id_by_name(PDO $pdo, string $name): ?int
{
    $needle = normalized_name($name);
    if ($needle === '') {
        return null;
    }
    $users = $pdo->query('SELECT id, name FROM users WHERE active = 1')->fetchAll();
    $needleFirst = explode(' ', $needle)[0] ?? $needle;
    foreach ($users as $user) {
        $candidate = normalized_name((string) $user['name']);
        if ($candidate === $needle) {
            return (int) $user['id'];
        }
    }
    foreach ($users as $user) {
        $candidate = normalized_name((string) $user['name']);
        $candidateFirst = explode(' ', $candidate)[0] ?? $candidate;
        if ($needleFirst !== '' && $candidateFirst === $needleFirst) {
            return (int) $user['id'];
        }
    }
    return null;
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

function openai_response_text(array $response): string
{
    if (isset($response['output_text']) && is_string($response['output_text'])) {
        return trim($response['output_text']);
    }
    $parts = [];
    foreach (($response['output'] ?? []) as $item) {
        foreach (($item['content'] ?? []) as $part) {
            if (($part['type'] ?? '') === 'output_text' && isset($part['text'])) {
                $parts[] = (string) $part['text'];
            }
        }
    }
    return trim(implode("\n", $parts));
}

function openai_extract_iliot_visit(PDO $pdo, string $tmpPath, string $filename): array
{
    $config = integration_get($pdo, 'openai');
    $apiKey = secret_decrypt($config['access_token'] ?? '');
    if (empty($config['is_active']) || $apiKey === '') {
        throw new RuntimeException('Configure e ative ChatGPT / OpenAI em Configurações antes de importar PDFs.');
    }
    if (!extension_loaded('curl')) {
        throw new RuntimeException('A extensão PHP cURL precisa estar ativa no servidor.');
    }

    $bytes = file_get_contents($tmpPath);
    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('Não foi possível ler o PDF.');
    }
    if (strlen($bytes) > 20 * 1024 * 1024) {
        throw new RuntimeException('PDF acima do limite de 20 MB por arquivo.');
    }

    $extra = json_decode((string) ($config['extra_json'] ?? '{}'), true);
    $model = trim((string) ($extra['model'] ?? '')) ?: 'gpt-5.6-sol';
    $prompt = <<<'PROMPT'
Este PDF é uma ORDEM DE SERVIÇO DE CAMPO SIMPLIFICADA emitida pelo iliot (app.iliot.tech) para a DNB.
O layout pode conter: cabeçalho com "OS:", "CÓD. CHECKLIST" e "DATA ENVIO OS SISTEMA"; "IDENTIFICAÇÃO DA LOCALIDADE" com endereço; "EVENTOS EM CAMPO" com check-in/check-out; seção "0 - INFORMAÇÃO DO CLIENTE" com Responsável, Nº Contato, Setor, Local da Instalação e Vendedor; e seção "6 - OPORTUNIDADES IDENTIFICADAS".

Extraia somente informações existentes no documento.
Regras:
- os: número da OS.
- data: data do PRIMEIRO check-in em YYYY-MM-DD; se não houver, use a data de envio do sistema. Se nenhuma existir, deixe vazio.
- cliente: empresa visitada; quando não houver campo explícito, pode ser deduzida somente do comentário de oportunidades ou do local da instalação.
- vendedor: nome do campo Vendedor.
- contato: Responsável.
- fone: Nº Contato e telefones citados.
- setor: Setor.
- oportunidade: comentário da seção 6, resumido em até 300 caracteres.
- equipamentos: modelos/marcas de equipamentos ou máquinas citados, separados por vírgula.
- agendar: se houver solicitação, promessa ou necessidade de NOVA visita/retorno, resuma o objetivo em até 120 caracteres; caso contrário deixe vazio.
- data_sugerida: YYYY-MM-DD somente quando o documento indicar um prazo/data para essa nova visita; caso contrário deixe vazio.
Nunca invente dados.
PROMPT;

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'os' => ['type' => 'string'],
            'data' => ['type' => 'string'],
            'cliente' => ['type' => 'string'],
            'vendedor' => ['type' => 'string'],
            'contato' => ['type' => 'string'],
            'fone' => ['type' => 'string'],
            'setor' => ['type' => 'string'],
            'oportunidade' => ['type' => 'string'],
            'equipamentos' => ['type' => 'string'],
            'agendar' => ['type' => 'string'],
            'data_sugerida' => ['type' => 'string'],
        ],
        'required' => ['os','data','cliente','vendedor','contato','fone','setor','oportunidade','equipamentos','agendar','data_sugerida'],
    ];

    $payload = [
        'model' => $model,
        'store' => false,
        'input' => [[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'input_file',
                    'filename' => preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'visita.pdf',
                    'file_data' => 'data:application/pdf;base64,' . base64_encode($bytes),
                ],
                ['type' => 'input_text', 'text' => $prompt],
            ],
        ]],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'iliot_visit',
                'strict' => true,
                'schema' => $schema,
            ],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 150,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Falha ao consultar a OpenAI: ' . $curlError);
    }
    $response = json_decode($raw, true);
    if (!is_array($response)) {
        throw new RuntimeException('A OpenAI retornou uma resposta inválida.');
    }
    if ($status < 200 || $status >= 300) {
        $message = (string) ($response['error']['message'] ?? 'erro desconhecido');
        throw new RuntimeException('OpenAI HTTP ' . $status . ': ' . $message);
    }

    $text = openai_response_text($response);
    $data = json_decode($text, true);
    if (!is_array($data)) {
        throw new RuntimeException('A IA não retornou os campos da visita em formato válido.');
    }

    foreach (['os','data','cliente','vendedor','contato','fone','setor','oportunidade','equipamentos','agendar','data_sugerida'] as $key) {
        $data[$key] = trim((string) ($data[$key] ?? ''));
    }
    return $data;
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
