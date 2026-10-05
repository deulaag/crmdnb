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
                    'detail' => 'high',
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
        CURLOPT_TIMEOUT => 180,
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
        $code = (string) ($response['error']['code'] ?? '');
        $type = (string) ($response['error']['type'] ?? '');
        $suffix = trim(implode(' · ', array_filter([$type, $code])));
        throw new RuntimeException('OpenAI HTTP ' . $status . ': ' . $message . ($suffix !== '' ? ' (' . $suffix . ')' : ''));
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


function cnpj_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?: '';
}

function cnpj_is_valid(string $value): bool
{
    $cnpj = cnpj_digits($value);
    if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
        return false;
    }

    $calc = static function (string $base, array $weights): int {
        $sum = 0;
        foreach ($weights as $i => $weight) {
            $sum += ((int) $base[$i]) * $weight;
        }
        $rest = $sum % 11;
        return $rest < 2 ? 0 : 11 - $rest;
    };

    $d1 = $calc(substr($cnpj, 0, 12), [5,4,3,2,9,8,7,6,5,4,3,2]);
    $d2 = $calc(substr($cnpj, 0, 12) . $d1, [6,5,4,3,2,9,8,7,6,5,4,3,2]);

    return $cnpj[12] === (string) $d1 && $cnpj[13] === (string) $d2;
}

function format_cnpj(string $value): string
{
    $cnpj = cnpj_digits($value);
    if (strlen($cnpj) !== 14) {
        return $cnpj;
    }
    return substr($cnpj, 0, 2) . '.' . substr($cnpj, 2, 3) . '.' . substr($cnpj, 5, 3) . '/' . substr($cnpj, 8, 4) . '-' . substr($cnpj, 12, 2);
}

function ensure_company_cnpja_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($database === '') {
        throw new RuntimeException('Banco de dados não selecionado.');
    }

    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "companies"');
    $stmt->execute([$database]);
    $existing = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

    $columns = [
        'alias' => 'VARCHAR(190) NULL AFTER cnpj',
        'registration_status' => 'VARCHAR(80) NULL AFTER alias',
        'registration_status_date' => 'DATE NULL AFTER registration_status',
        'founded' => 'DATE NULL AFTER registration_status_date',
        'company_type' => 'VARCHAR(40) NULL AFTER founded',
        'company_size' => 'VARCHAR(120) NULL AFTER company_type',
        'legal_nature' => 'VARCHAR(190) NULL AFTER company_size',
        'capital_social' => 'DECIMAL(15,2) NULL AFTER legal_nature',
        'email' => 'VARCHAR(190) NULL AFTER capital_social',
        'phone' => 'VARCHAR(80) NULL AFTER email',
        'zip' => 'VARCHAR(20) NULL AFTER phone',
        'street' => 'VARCHAR(190) NULL AFTER zip',
        'address_number' => 'VARCHAR(40) NULL AFTER street',
        'address_details' => 'VARCHAR(190) NULL AFTER address_number',
        'district' => 'VARCHAR(120) NULL AFTER address_details',
        'main_activity_code' => 'VARCHAR(20) NULL AFTER industry',
        'cnpja_updated_at' => 'DATETIME NULL AFTER website',
        'cnpja_checked_at' => 'DATETIME NULL AFTER cnpja_updated_at',
        'cnpja_json' => 'LONGTEXT NULL AFTER cnpja_checked_at',
    ];

    foreach ($columns as $name => $definition) {
        if (isset($existing[$name])) {
            continue;
        }
        try {
            $pdo->exec('ALTER TABLE companies ADD COLUMN ' . $name . ' ' . $definition);
        } catch (Throwable $e) {
            throw new RuntimeException('Não foi possível atualizar a estrutura de clientes para a integração CNPJá: ' . $e->getMessage());
        }
    }

    $done = true;
}

function cnpja_phone_text(array $phones): string
{
    $formatted = [];
    foreach (array_slice($phones, 0, 2) as $phone) {
        if (!is_array($phone)) continue;
        $area = preg_replace('/\D+/', '', (string) ($phone['area'] ?? '')) ?: '';
        $number = preg_replace('/\D+/', '', (string) ($phone['number'] ?? '')) ?: '';
        if ($number === '') continue;
        $formatted[] = $area !== '' ? '(' . $area . ') ' . $number : $number;
    }
    return implode(' / ', $formatted);
}

function cnpja_map_office(array $office): array
{
    $company = is_array($office['company'] ?? null) ? $office['company'] : [];
    $address = is_array($office['address'] ?? null) ? $office['address'] : [];
    $status = is_array($office['status'] ?? null) ? $office['status'] : [];
    $nature = is_array($company['nature'] ?? null) ? $company['nature'] : [];
    $size = is_array($company['size'] ?? null) ? $company['size'] : [];
    $mainActivity = is_array($office['mainActivity'] ?? null) ? $office['mainActivity'] : [];
    $emails = is_array($office['emails'] ?? null) ? $office['emails'] : [];
    $phones = is_array($office['phones'] ?? null) ? $office['phones'] : [];

    $email = '';
    foreach ($emails as $entry) {
        if (is_array($entry) && trim((string) ($entry['address'] ?? '')) !== '') {
            $email = trim((string) $entry['address']);
            break;
        }
    }

    return [
        'cnpj' => cnpj_digits((string) ($office['taxId'] ?? '')),
        'name' => trim((string) ($company['name'] ?? $office['name'] ?? '')),
        'alias' => trim((string) ($office['alias'] ?? '')),
        'registration_status' => trim((string) ($status['text'] ?? '')),
        'registration_status_date' => substr((string) ($office['statusDate'] ?? ''), 0, 10),
        'founded' => substr((string) ($office['founded'] ?? ''), 0, 10),
        'company_type' => isset($office['head']) ? (!empty($office['head']) ? 'Matriz' : 'Filial') : '',
        'company_size' => trim((string) ($size['text'] ?? $size['acronym'] ?? '')),
        'legal_nature' => trim((string) ($nature['text'] ?? '')),
        'capital_social' => isset($company['equity']) ? (float) $company['equity'] : null,
        'email' => $email,
        'phone' => cnpja_phone_text($phones),
        'zip' => preg_replace('/\D+/', '', (string) ($address['zip'] ?? '')) ?: '',
        'street' => trim((string) ($address['street'] ?? '')),
        'address_number' => trim((string) ($address['number'] ?? '')),
        'address_details' => trim((string) ($address['details'] ?? '')),
        'district' => trim((string) ($address['district'] ?? '')),
        'city' => trim((string) ($address['city'] ?? '')),
        'state' => trim((string) ($address['state'] ?? '')),
        'main_activity_code' => isset($mainActivity['id']) ? (string) $mainActivity['id'] : '',
        'industry' => trim((string) ($mainActivity['text'] ?? '')),
        'cnpja_updated_at' => trim((string) ($office['updated'] ?? '')),
        'stale' => !empty($office['stale']),
        'raw' => $office,
    ];
}

function cnpja_lookup(PDO $pdo, string $cnpj, ?string $strategy = null): array
{
    $cnpj = cnpj_digits($cnpj);
    if (!cnpj_is_valid($cnpj)) {
        throw new RuntimeException('CNPJ inválido. Confira os 14 dígitos.');
    }
    if (!extension_loaded('curl')) {
        throw new RuntimeException('A extensão PHP cURL precisa estar ativa no servidor.');
    }

    $config = integration_get($pdo, 'cnpja');
    $apiKey = secret_decrypt($config['access_token'] ?? '');
    if (empty($config['is_active']) || $apiKey === '') {
        throw new RuntimeException('Configure e ative a integração CNPJá em Configurações antes de consultar o CNPJ.');
    }

    $extra = json_decode((string) ($config['extra_json'] ?? '{}'), true);
    $configuredStrategy = strtoupper(trim((string) ($extra['strategy'] ?? 'ONLINE')));
    $strategy = strtoupper(trim((string) ($strategy ?: $configuredStrategy)));
    if (!in_array($strategy, ['ONLINE', 'CACHE_IF_FRESH', 'CACHE_IF_ERROR', 'CACHE'], true)) {
        $strategy = 'ONLINE';
    }

    $maxAge = max(1, min(3650, (int) ($extra['max_age'] ?? 1)));
    $maxStale = max(1, min(3650, (int) ($extra['max_stale'] ?? 30)));

    $query = ['strategy' => $strategy, 'sync' => 'true'];
    if (in_array($strategy, ['CACHE_IF_FRESH', 'CACHE_IF_ERROR'], true)) {
        $query['maxAge'] = (string) $maxAge;
    }
    if ($strategy === 'CACHE_IF_ERROR') {
        $query['maxStale'] = (string) $maxStale;
    }

    $url = 'https://api.cnpja.com/office/' . rawurlencode($cnpj) . '?' . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $apiKey,
            'Accept: application/json',
        ],
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Falha de conexão com a CNPJá: ' . $curlError);
    }

    $response = json_decode($raw, true);
    if (!is_array($response)) {
        throw new RuntimeException('A CNPJá retornou uma resposta inválida.');
    }

    if ($status < 200 || $status >= 300) {
        $message = trim((string) ($response['message'] ?? $response['error']['message'] ?? 'erro não informado'));
        $code = $response['code'] ?? $response['error']['code'] ?? null;
        throw new RuntimeException('CNPJá HTTP ' . $status . ': ' . $message . ($code !== null ? ' (código ' . $code . ')' : ''));
    }

    $mapped = cnpja_map_office($response);
    if (($mapped['name'] ?? '') === '') {
        throw new RuntimeException('A CNPJá respondeu, mas não retornou a razão social do estabelecimento.');
    }

    $mapped['strategy'] = $strategy;
    $mapped['checked_at'] = date(DATE_ATOM);
    return $mapped;
}

function ensure_opportunity_visit_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME="opportunities" AND COLUMN_NAME="visit_id"');
    $stmt->execute([$database]);

    if (!(int) $stmt->fetchColumn()) {
        $pdo->exec('ALTER TABLE opportunities ADD COLUMN visit_id BIGINT UNSIGNED NULL AFTER company_id');
        $pdo->exec('ALTER TABLE opportunities ADD INDEX idx_opportunity_visit (visit_id)');
    }

    $done = true;
}

function company_upsert_from_customer_data(PDO $pdo, array $data, ?int $ownerUserId = null, ?array $rawLookup = null): int
{
    ensure_company_cnpja_schema($pdo);

    $cnpj = cnpj_digits((string) ($data['cnpj'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Informe a razão social do cliente.');
    }
    if ($cnpj !== '' && !cnpj_is_valid($cnpj)) {
        throw new RuntimeException('O CNPJ informado para o cliente é inválido.');
    }

    $values = [
        'name' => $name,
        'cnpj' => $cnpj !== '' ? $cnpj : null,
        'alias' => trim((string) ($data['alias'] ?? '')) ?: null,
        'registration_status' => trim((string) ($data['registration_status'] ?? '')) ?: null,
        'registration_status_date' => valid_date_string($data['registration_status_date'] ?? '') ? $data['registration_status_date'] : null,
        'founded' => valid_date_string($data['founded'] ?? '') ? $data['founded'] : null,
        'company_type' => trim((string) ($data['company_type'] ?? '')) ?: null,
        'company_size' => trim((string) ($data['company_size'] ?? '')) ?: null,
        'legal_nature' => trim((string) ($data['legal_nature'] ?? '')) ?: null,
        'capital_social' => ($data['capital_social'] ?? '') !== '' ? (float) $data['capital_social'] : null,
        'email' => filter_var((string) ($data['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? trim((string) $data['email']) : null,
        'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
        'zip' => preg_replace('/\D+/', '', (string) ($data['zip'] ?? '')) ?: null,
        'street' => trim((string) ($data['street'] ?? '')) ?: null,
        'address_number' => trim((string) ($data['address_number'] ?? '')) ?: null,
        'address_details' => trim((string) ($data['address_details'] ?? '')) ?: null,
        'district' => trim((string) ($data['district'] ?? '')) ?: null,
        'industry' => trim((string) ($data['industry'] ?? '')) ?: null,
        'main_activity_code' => preg_replace('/\D+/', '', (string) ($data['main_activity_code'] ?? '')) ?: null,
        'city' => trim((string) ($data['city'] ?? '')) ?: null,
        'state' => strtoupper(trim((string) ($data['state'] ?? ''))) ?: null,
        'owner_user_id' => $ownerUserId,
        'source' => $cnpj !== '' ? 'cnpja' : 'manual',
        'cnpja_updated_at' => !empty($data['cnpja_updated_at']) ? date('Y-m-d H:i:s', strtotime((string) $data['cnpja_updated_at'])) : null,
        'cnpja_checked_at' => $cnpj !== '' ? date('Y-m-d H:i:s') : null,
        'cnpja_json' => $rawLookup ? json_encode($rawLookup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ];

    $id = 0;
    if ($cnpj !== '') {
        $stmt = $pdo->prepare('SELECT id FROM companies WHERE cnpj = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$cnpj]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
    }

    if ($id > 0) {
        $sql = 'UPDATE companies SET
            name=:name, cnpj=:cnpj, alias=:alias, registration_status=:registration_status,
            registration_status_date=:registration_status_date, founded=:founded,
            company_type=:company_type, company_size=:company_size, legal_nature=:legal_nature,
            capital_social=:capital_social, email=:email, phone=:phone, zip=:zip,
            street=:street, address_number=:address_number, address_details=:address_details,
            district=:district, industry=:industry, main_activity_code=:main_activity_code,
            city=:city, state=:state, owner_user_id=:owner_user_id, source=:source,
            cnpja_updated_at=:cnpja_updated_at, cnpja_checked_at=:cnpja_checked_at,
            cnpja_json=COALESCE(:cnpja_json, cnpja_json)
            WHERE id=:id';
        $stmt = $pdo->prepare($sql);
        $values['id'] = $id;
        $stmt->execute($values);
        return $id;
    }

    $sql = 'INSERT INTO companies
        (name,cnpj,alias,registration_status,registration_status_date,founded,company_type,
         company_size,legal_nature,capital_social,email,phone,zip,street,address_number,
         address_details,district,industry,main_activity_code,city,state,owner_user_id,source,
         cnpja_updated_at,cnpja_checked_at,cnpja_json)
        VALUES
        (:name,:cnpj,:alias,:registration_status,:registration_status_date,:founded,:company_type,
         :company_size,:legal_nature,:capital_social,:email,:phone,:zip,:street,:address_number,
         :address_details,:district,:industry,:main_activity_code,:city,:state,:owner_user_id,:source,
         :cnpja_updated_at,:cnpja_checked_at,:cnpja_json)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    return (int) $pdo->lastInsertId();
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
