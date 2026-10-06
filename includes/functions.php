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

function branding_settings(PDO $pdo): array
{
    $cfg = integration_get($pdo, 'branding');
    $extra = json_decode((string)($cfg['extra_json'] ?? '{}'), true);
    return is_array($extra) ? $extra : [];
}

function branding_storage_dir(): string
{
    return dirname(__DIR__) . '/storage/branding';
}

function branding_logo_path(PDO $pdo, string $kind = 'portal'): string
{
    if (!in_array($kind, ['portal', 'pdf'], true)) {
        $kind = 'portal';
    }

    $settings = branding_settings($pdo);
    $relative = trim((string)($settings[$kind . '_logo'] ?? ''));
    if ($relative !== '') {
        $root = dirname(__DIR__);
        $candidate = $root . '/' . ltrim($relative, '/');
        $brandingDir = branding_storage_dir();

        $realFile = is_file($candidate) ? realpath($candidate) : false;
        $realDir = is_dir($brandingDir) ? realpath($brandingDir) : false;

        if ($realFile !== false && $realDir !== false && str_starts_with($realFile, $realDir . DIRECTORY_SEPARATOR)) {
            return $realFile;
        }
    }

    return dirname(__DIR__) . '/assets/dnb-logo.png';
}

function branding_logo_url(PDO $pdo, string $kind = 'portal'): string
{
    $path = branding_logo_path($pdo, $kind);
    $default = dirname(__DIR__) . '/assets/dnb-logo.png';

    if ($path === $default || !is_file($path)) {
        return 'assets/dnb-logo.png';
    }

    return 'branding_asset.php?kind=' . rawurlencode($kind) . '&v=' . rawurlencode((string)filemtime($path));
}

function branding_save_logo_upload(array $file, string $kind): array
{
    if (!in_array($kind, ['portal', 'pdf'], true)) {
        throw new RuntimeException('Tipo de logo inválido.');
    }

    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Selecione uma imagem para enviar.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no upload da logo. Código: ' . $error . '.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Upload de logo inválido.');
    }
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('A logo deve ter no máximo 5 MB.');
    }

    $info = @getimagesize($tmp);
    if (!is_array($info) || empty($info[0]) || empty($info[1])) {
        throw new RuntimeException('O arquivo enviado não é uma imagem válida.');
    }

    $width = (int)$info[0];
    $height = (int)$info[1];
    if ($width > 8000 || $height > 8000) {
        throw new RuntimeException('A imagem é grande demais. Use no máximo 8000 × 8000 px.');
    }

    $mime = (string)($info['mime'] ?? '');
    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Formato não permitido. Envie PNG, JPG ou WEBP.');
    }

    $dir = branding_storage_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível preparar a pasta de branding.');
    }

    foreach (glob($dir . '/' . $kind . '-logo.*') ?: [] as $old) {
        if (is_file($old)) {
            @unlink($old);
        }
    }

    $filename = $kind . '-logo.' . $extensions[$mime];
    $destination = $dir . '/' . $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Não foi possível salvar a logo enviada.');
    }

    @chmod($destination, 0644);

    return [
        'relative_path' => 'storage/branding/' . $filename,
        'mime' => $mime,
        'width' => $width,
        'height' => $height,
        'size' => $size,
    ];
}

function branding_remove_logo(PDO $pdo, string $kind): void
{
    if (!in_array($kind, ['portal', 'pdf'], true)) {
        return;
    }

    $settings = branding_settings($pdo);
    $relative = trim((string)($settings[$kind . '_logo'] ?? ''));
    if ($relative === '') {
        return;
    }

    $root = dirname(__DIR__);
    $candidate = $root . '/' . ltrim($relative, '/');
    $brandingDir = branding_storage_dir();

    $realFile = is_file($candidate) ? realpath($candidate) : false;
    $realDir = is_dir($brandingDir) ? realpath($brandingDir) : false;

    if ($realFile !== false && $realDir !== false && str_starts_with($realFile, $realDir . DIRECTORY_SEPARATOR)) {
        @unlink($realFile);
    }
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
- agendar: ESTE CAMPO É OBRIGATÓRIO. Sempre gere uma recomendação objetiva de retorno/nova visita em até 120 caracteres, baseada somente no conteúdo do relatório. Se o documento trouxer uma solicitação, promessa ou necessidade explícita, use-a. Se não trouxer, proponha o próximo passo comercial/técnico coerente com as oportunidades, equipamentos e observações realmente encontrados no relatório, sem criar fatos novos.
- data_sugerida: ESTE CAMPO É OBRIGATÓRIO. Se o documento indicar prazo/data de retorno, use essa data em YYYY-MM-DD. Se não indicar, use como regra do CRM a data da visita + 60 dias.
Nunca invente fatos do cliente. A recomendação de retorno pode ser inferida a partir do conteúdo real do relatório e a data padrão pode seguir a regra +60 dias do CRM.
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

    $baseDate = valid_date_string($data['data'] ?? '')
        ? new DateTimeImmutable((string)$data['data'])
        : new DateTimeImmutable('today');

    if ($data['agendar'] === '') {
        if ($data['oportunidade'] !== '') {
            $data['agendar'] = 'Retornar ao cliente para acompanhar a oportunidade identificada na visita.';
        } elseif ($data['equipamentos'] !== '') {
            $data['agendar'] = 'Realizar nova visita para acompanhar os equipamentos citados no relatório.';
        } else {
            $data['agendar'] = 'Realizar retorno comercial para acompanhar os pontos registrados na visita.';
        }
    }

    if (!valid_date_string($data['data_sugerida'] ?? '')) {
        $data['data_sugerida'] = $baseDate->modify('+60 days')->format('Y-m-d');
    }

    return $data;
}


function commission_policy_definitions(): array
{
    return [
        'vendedor_ir' => [
            'label' => 'Vendedor IR (v3)',
            'regime' => 'mensal',
            'defaults' => [
                'metaRevenda' => 500000,
                'metaAgenciadas' => 300000,
                'pctPecasServ' => 0.5,
                'pctAgenciada' => 1,
                'visitasMes' => 60,
                'prospecMes' => 0,
                'convIR' => 40,
                'convEquip' => 20,
                'convDemais' => 15,
            ],
        ],
        'vendedor_externo' => [
            'label' => 'Vendedor Externo (rev02 — jul/2026)',
            'regime' => 'mensal',
            'defaults' => [
                'metaRevenda' => 500000,
                'metaAgenciadas' => 300000,
                'pctPecasServ' => 0.5,
                'pctAgenciada' => 1,
                'bonifKpiPct' => 100,
                'visitasMes' => 40,
                'prospecMes' => 0,
                'convIR' => 40,
                'convEquip' => 20,
                'convDemais' => 15,
            ],
        ],
        'suporte_ir' => [
            'label' => 'Suporte em Vendas IR (v3)',
            'regime' => 'mensal',
            'defaults' => [
                'metaRevenda' => 500000,
                'metaAgenciadas' => 300000,
                'pctPecasServ' => 0.25,
                'pctAgenciada' => 0.5,
                'prospecMes' => 80,
                'visitasMes' => 0,
                'convIR' => 40,
                'convEquip' => 20,
                'convDemais' => 15,
            ],
        ],
        'interno' => [
            'label' => 'Vendedor Interno Sênior (PCV-INT-001)',
            'regime' => 'mensal',
            'defaults' => [
                'metaRevenda' => 500000,
                'metaAgenciadas' => 0,
                'pctPecasServ' => 0.5,
                'pctAgenciada' => 1,
                'tetoBonusMes' => 9600,
                'visitasMes' => 0,
                'prospecMes' => 40,
                'convIR' => 0,
                'convEquip' => 0,
                'convDemais' => 15,
                'pipelineMin' => 1700000,
                'cotacoesMin' => 80,
                'oportunidadesMin' => 60,
                'convMin' => 25,
            ],
        ],
        'representante' => [
            'label' => 'Representante Comercial Autônomo (PCV-REP-001)',
            'regime' => 'mensal',
            'defaults' => [
                'metaRevenda' => 300000,
                'metaAgenciadas' => 0,
                'metaCompressores' => 200000,
                'metaAcessorios' => 100000,
                'pctComDNB' => 30,
                'pctMargem' => 5,
                'bonifKpiPct' => 20,
                'pipelineMin' => 1000000,
                'cotacoesMin' => 48,
                'convMin' => 25,
                'visitasMes' => 40,
                'prospecMes' => 0,
                'convIR' => 40,
                'convEquip' => 20,
                'convDemais' => 15,
            ],
        ],
        'consultor_senior' => [
            'label' => 'Consultor Comercial Sênior (v2 — anual)',
            'regime' => 'anual',
            'defaults' => [
                'metaPropriasAno' => 6300000,
                'metaOrientadoAno' => 3150000,
                'metaAgenciadasAno' => 2500000,
                'pctAgenciada' => 6,
                'tetoAgMes' => 8000,
                'tetoAgAno' => 60000,
                'tetoPropriasAno' => 60000,
                'tetoOrientAno' => 20000,
                'tetoGlobalAno' => 120000,
                'metaPropostasMes' => 3119792,
                'visitasMes' => 40,
                'prospecMes' => 0,
            ],
        ],
        'sem_politica' => [
            'label' => 'Sem política definida',
            'regime' => 'mensal',
            'defaults' => [
                'metaRevenda' => 0,
                'metaAgenciadas' => 0,
                'pctPecasServ' => 0,
                'pctAgenciada' => 0,
                'visitasMes' => 0,
                'prospecMes' => 0,
                'convIR' => 40,
                'convEquip' => 20,
                'convDemais' => 15,
            ],
        ],
    ];
}

function commission_policy_field_labels(string $policyKey): array
{
    if ($policyKey === 'consultor_senior') {
        return [
            'metaPropriasAno' => 'Meta anual próprias (R$)',
            'metaOrientadoAno' => 'Meta anual orientado (R$)',
            'metaAgenciadasAno' => 'Meta anual agenciadas (R$)',
            'metaPropostasMes' => 'Meta propostas/mês (R$)',
            'pctAgenciada' => '% s/ comissão DNB (agenc.)',
            'tetoAgMes' => 'Teto agenc./mês (R$)',
            'tetoGlobalAno' => 'Teto global/ano (R$)',
            'visitasMes' => 'Visitas/mês',
        ];
    }

    if ($policyKey === 'interno') {
        return [
            'metaRevenda' => 'Meta revenda/mês (R$)',
            'pctPecasServ' => '% peças/serviços',
            'pctAgenciada' => '% s/ comissão DNB (agenc.)',
            'tetoBonusMes' => 'Teto bônus/mês (R$)',
            'prospecMes' => 'Prospecções/mês',
            'cotacoesMin' => 'Cotações mín./mês',
            'pipelineMin' => 'Pipeline mínimo (R$)',
        ];
    }

    if ($policyKey === 'representante') {
        return [
            'metaRevenda' => 'Meta mensal (rev+agenc.) (R$)',
            'pctComDNB' => '% s/ comissão DNB',
            'pctMargem' => '% s/ margem revenda',
            'bonifKpiPct' => 'Bonificação KPIs (%)',
            'visitasMes' => 'Visitas/mês (KPI)',
            'cotacoesMin' => 'Cotações mín./mês',
            'pipelineMin' => 'Pipeline mínimo (R$)',
        ];
    }

    return [
        'metaRevenda' => 'Meta revenda/mês (R$)',
        'metaAgenciadas' => 'Meta agenciadas/mês (R$)',
        'pctPecasServ' => '% peças/serviços',
        'pctAgenciada' => '% s/ comissão DNB (agenc.)',
        'visitasMes' => 'Visitas/mês',
        'convIR' => 'Conversão IR peças/serv (%)',
        'convEquip' => 'Conversão equipamentos (%)',
    ];
}

function ensure_commission_policy_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_commission_policies (
            user_id INT UNSIGNED PRIMARY KEY,
            operation_role ENUM('vendedor','suporte') NOT NULL DEFAULT 'vendedor',
            policy_key VARCHAR(50) NOT NULL DEFAULT 'sem_politica',
            params_json LONGTEXT NULL,
            linked_user_id INT UNSIGNED NULL,
            linked_user_id2 INT UNSIGNED NULL,
            mentor_user_id INT UNSIGNED NULL,
            hired_month CHAR(7) NULL,
            active_operation TINYINT(1) NOT NULL DEFAULT 0,
            updated_by INT UNSIGNED NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_policy_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_policy_linked_user FOREIGN KEY (linked_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_policy_linked_user2 FOREIGN KEY (linked_user_id2) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_policy_mentor FOREIGN KEY (mentor_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_policy_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $done = true;
}

function user_commission_policy(PDO $pdo, int $userId): array
{
    ensure_commission_policy_schema($pdo);
    $definitions = commission_policy_definitions();

    $q = $pdo->prepare('SELECT * FROM user_commission_policies WHERE user_id=? LIMIT 1');
    $q->execute([$userId]);
    $row = $q->fetch(PDO::FETCH_ASSOC) ?: [];

    $policyKey = (string)($row['policy_key'] ?? 'sem_politica');
    if (!isset($definitions[$policyKey])) {
        $policyKey = 'sem_politica';
    }

    $saved = json_decode((string)($row['params_json'] ?? '{}'), true);
    $saved = is_array($saved) ? $saved : [];

    return [
        'user_id' => $userId,
        'operation_role' => (string)($row['operation_role'] ?? 'vendedor'),
        'policy_key' => $policyKey,
        'params' => array_merge($definitions[$policyKey]['defaults'], $saved),
        'linked_user_id' => isset($row['linked_user_id']) ? (int)$row['linked_user_id'] : null,
        'linked_user_id2' => isset($row['linked_user_id2']) ? (int)$row['linked_user_id2'] : null,
        'mentor_user_id' => isset($row['mentor_user_id']) ? (int)$row['mentor_user_id'] : null,
        'hired_month' => (string)($row['hired_month'] ?? ''),
        'active_operation' => array_key_exists('active_operation', $row) ? (bool)$row['active_operation'] : false,
    ];
}

function save_user_commission_policy(PDO $pdo, int $userId, array $input, int $updatedBy): void
{
    ensure_commission_policy_schema($pdo);
    $definitions = commission_policy_definitions();

    $policyKey = (string)($input['policy_key'] ?? 'sem_politica');
    if (!isset($definitions[$policyKey])) {
        throw new RuntimeException('Política de comissão inválida.');
    }

    $operationRole = (string)($input['operation_role'] ?? 'vendedor');
    if (!in_array($operationRole, ['vendedor','suporte'], true)) {
        $operationRole = 'vendedor';
    }

    $params = $definitions[$policyKey]['defaults'];
    foreach ($params as $key => $default) {
        if (isset($input['params'][$key]) && $input['params'][$key] !== '') {
            $params[$key] = (float)$input['params'][$key];
        }
    }

    $linked1 = !empty($input['linked_user_id']) ? (int)$input['linked_user_id'] : null;
    $linked2 = !empty($input['linked_user_id2']) ? (int)$input['linked_user_id2'] : null;
    $mentor = !empty($input['mentor_user_id']) ? (int)$input['mentor_user_id'] : null;
    $hiredMonth = preg_match('/^\d{4}-\d{2}$/', (string)($input['hired_month'] ?? ''))
        ? (string)$input['hired_month']
        : null;

    $q = $pdo->prepare(
        'INSERT INTO user_commission_policies
         (user_id,operation_role,policy_key,params_json,linked_user_id,linked_user_id2,mentor_user_id,hired_month,active_operation,updated_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
         operation_role=VALUES(operation_role),policy_key=VALUES(policy_key),params_json=VALUES(params_json),
         linked_user_id=VALUES(linked_user_id),linked_user_id2=VALUES(linked_user_id2),mentor_user_id=VALUES(mentor_user_id),
         hired_month=VALUES(hired_month),active_operation=VALUES(active_operation),updated_by=VALUES(updated_by),
         updated_at=CURRENT_TIMESTAMP'
    );

    $q->execute([
        $userId,
        $operationRole,
        $policyKey,
        json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $linked1,
        $linked2,
        $mentor,
        $hiredMonth,
        !empty($input['active_operation']) ? 1 : 0,
        $updatedBy,
    ]);
}

function product_line_id_from_text(PDO $pdo, string $text): ?int
{
    $needle = normalized_name($text);
    if ($needle === '') return null;

    $lines = $pdo->query('SELECT id,name FROM product_lines WHERE active=1 ORDER BY sort_order')->fetchAll();

    foreach ($lines as $line) {
        if (normalized_name((string)$line['name']) === $needle) {
            return (int)$line['id'];
        }
    }

    $rules = [
        'Parker Filtração de Processos' => ['filtracao de processos','filtracao processo','process filter'],
        'Parker Filtração GSF' => ['gsf','filtracao gsf'],
        'Parker Automação' => ['parker automacao','automacao parker'],
        'Parker Conexões' => ['parker conexoes','conexoes parker'],
        'Peças ARO' => ['peca aro','pecas aro'],
        'Bombas ARO' => ['bomba aro','bombas aro','aro pump'],
        'Compressores IR (agenciamento)' => ['compressor','compressores','ingersoll rand compressor'],
        'Serviço Manutenção IR' => ['manutencao','reparo','servico ir','assistencia tecnica'],
        'Locação' => ['locacao','aluguel'],
        'Peças IR' => ['peca ir','pecas ir','ingersoll rand peca'],
    ];

    foreach ($rules as $lineName => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($needle, normalized_name($keyword))) {
                foreach ($lines as $line) {
                    if ((string)$line['name'] === $lineName) {
                        return (int)$line['id'];
                    }
                }
            }
        }
    }

    return null;
}

function openai_call_json_schema(PDO $pdo, array $content, string $schemaName, array $schema, string $fallbackModel = 'gpt-5.6-sol'): array
{
    $config = integration_get($pdo, 'openai');
    $apiKey = secret_decrypt($config['access_token'] ?? '');
    if (empty($config['is_active']) || $apiKey === '') {
        throw new RuntimeException('Configure e ative ChatGPT / OpenAI em Configurações.');
    }
    if (!extension_loaded('curl')) {
        throw new RuntimeException('A extensão PHP cURL precisa estar ativa no servidor.');
    }

    $extra = json_decode((string)($config['extra_json'] ?? '{}'), true);
    $model = trim((string)($extra['model'] ?? '')) ?: $fallbackModel;

    $payload = [
        'model' => $model,
        'store' => false,
        'input' => [[
            'role' => 'user',
            'content' => $content,
        ]],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => $schemaName,
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
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Falha ao consultar a OpenAI: ' . $error);
    }

    $response = json_decode($raw, true);
    if (!is_array($response) || $status < 200 || $status >= 300) {
        $message = is_array($response) ? (string)($response['error']['message'] ?? 'erro não informado') : 'resposta inválida';
        throw new RuntimeException('OpenAI HTTP ' . $status . ': ' . $message);
    }

    $data = json_decode(openai_response_text($response), true);
    if (!is_array($data)) {
        throw new RuntimeException('A IA não retornou dados estruturados válidos.');
    }

    return $data;
}

function openai_read_salesforce_report(PDO $pdo, array $file): array
{
    $name = (string)($file['name'] ?? 'relatorio');
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Não foi possível receber o relatório do Salesforce.');
    }
    if ($size <= 0 || $size > 20 * 1024 * 1024) {
        throw new RuntimeException('O relatório do Salesforce deve ter no máximo 20 MB.');
    }

    $itemSchema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'deal_id' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'client' => ['type' => 'string'],
            'amount' => ['type' => 'number'],
            'stage' => ['type' => 'string'],
            'owner' => ['type' => 'string'],
            'commission' => ['type' => 'number'],
            'loss_reason' => ['type' => 'string'],
        ],
        'required' => ['deal_id','name','client','amount','stage','owner','commission','loss_reason'],
    ];

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'opportunities' => [
                'type' => 'array',
                'items' => $itemSchema,
            ],
        ],
        'required' => ['opportunities'],
    ];

    $prompt = <<<'PROMPT'
Você está lendo um relatório semanal de oportunidades agenciadas da Ingersoll Rand / Salesforce para o CRM DNB.
Extraia as oportunidades do relatório, sem inventar dados.

Para cada oportunidade retorne:
- deal_id: ID da oportunidade no Salesforce, se existir.
- name: nome/número da oportunidade.
- client: conta/cliente.
- amount: valor total da oportunidade.
- stage: fase/Stage exatamente como aparece.
- owner: proprietário/vendedor.
- commission: comissão DNB, apenas se o relatório trouxer esse valor; caso contrário 0.
- loss_reason: motivo da perda somente se a oportunidade estiver perdida e o relatório trouxer esse motivo; caso contrário deixe vazio.

Ignore totais, cabeçalhos repetidos, linhas vazias, subtotais e registros que não representem uma oportunidade.
PROMPT;

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $content = [];

    if ($ext === 'pdf') {
        $bytes = file_get_contents($tmp);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Não foi possível ler o PDF do Salesforce.');
        }

        $content[] = [
            'type' => 'input_file',
            'filename' => preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'salesforce.pdf',
            'file_data' => 'data:application/pdf;base64,' . base64_encode($bytes),
        ];
        $content[] = ['type' => 'input_text', 'text' => $prompt];
    } else {
        $rows = rows_to_assoc(spreadsheet_rows_from_upload($file));
        if (!$rows) {
            throw new RuntimeException('O relatório do Salesforce está vazio.');
        }
        if (count($rows) > 350) {
            throw new RuntimeException('O relatório possui mais de 350 oportunidades. Divida-o em arquivos menores para leitura segura pela IA.');
        }

        $content[] = [
            'type' => 'input_text',
            'text' => $prompt . "\n\nDADOS DO RELATÓRIO:\n" . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    $result = openai_call_json_schema($pdo, $content, 'salesforce_opportunities', $schema);
    return is_array($result['opportunities'] ?? null) ? $result['opportunities'] : [];
}

function openai_build_quote_reminder_email(PDO $pdo, array $opportunity, array $latestNotes = []): array
{
    $notes = [];
    foreach (array_slice($latestNotes, 0, 5) as $note) {
        $notes[] = trim((string)($note['created_at'] ?? '')) . ' · ' . trim((string)($note['author_name'] ?? '')) . ': ' . trim((string)($note['note'] ?? ''));
    }

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'subject' => ['type' => 'string'],
            'text' => ['type' => 'string'],
        ],
        'required' => ['subject','text'],
    ];

    $prompt = 'Escreva um lembrete interno, curto e profissional, em português do Brasil, para o vendedor e o suporte da DNB. '
        . 'Existe uma oportunidade originada de visita que continua com a pendência "Realizar orçamento". '
        . 'Não invente informações. O texto deve pedir ação hoje e mencionar cliente, número da oportunidade e quantos dias a pendência está aberta. '
        . 'Não use markdown.\n\n'
        . 'Oportunidade: ' . (string)($opportunity['number'] ?? '') . "\n"
        . 'Cliente: ' . (string)($opportunity['client_name'] ?? '') . "\n"
        . 'Vendedor: ' . (string)($opportunity['seller_name'] ?? '') . "\n"
        . 'Suporte: ' . (string)($opportunity['support_name'] ?? '') . "\n"
        . 'Pendente desde: ' . (string)($opportunity['quote_required_at'] ?? $opportunity['created_at'] ?? '') . "\n"
        . 'Últimas anotações: ' . ($notes ? implode(' | ', $notes) : 'nenhuma anotação registrada');

    return openai_call_json_schema(
        $pdo,
        [['type' => 'input_text', 'text' => $prompt]],
        'quote_reminder_email',
        $schema
    );
}

function resend_settings(PDO $pdo): array
{
    $cfg = integration_get($pdo, 'resend');
    $extra = json_decode((string)($cfg['extra_json'] ?? '{}'), true);
    return [
        'active' => !empty($cfg['is_active']),
        'api_key' => secret_decrypt($cfg['access_token'] ?? ''),
        'from_email' => trim((string)($cfg['client_id'] ?? '')),
        'from_name' => trim((string)($extra['from_name'] ?? 'DNB CRM')),
    ];
}

function resend_send_email(PDO $pdo, array $to, string $subject, string $text): void
{
    $cfg = resend_settings($pdo);
    if (!$cfg['active'] || $cfg['api_key'] === '' || !filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Configure e ative o Resend em Configurações antes de enviar lembretes.');
    }

    $to = array_values(array_unique(array_filter(array_map(
        static fn($email) => filter_var((string)$email, FILTER_VALIDATE_EMAIL) ? strtolower(trim((string)$email)) : null,
        $to
    ))));

    if (!$to) {
        throw new RuntimeException('A oportunidade não possui destinatários de e-mail válidos.');
    }

    $from = $cfg['from_name'] !== ''
        ? $cfg['from_name'] . ' <' . $cfg['from_email'] . '>'
        : $cfg['from_email'];

    $payload = [
        'from' => $from,
        'to' => $to,
        'subject' => trim($subject),
        'text' => trim($text),
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $cfg['api_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Falha ao enviar e-mail pelo Resend: ' . $error);
    }
    if ($status < 200 || $status >= 300) {
        $data = json_decode($raw, true);
        $message = is_array($data) ? (string)($data['message'] ?? 'erro não informado') : 'erro não informado';
        throw new RuntimeException('Resend HTTP ' . $status . ': ' . $message);
    }
}

function process_quote_reminders(PDO $pdo): array
{
    ensure_opportunity_tracking_schema($pdo);

    $openai = integration_get($pdo, 'openai');
    $resend = resend_settings($pdo);

    if (empty($openai['is_active']) || secret_decrypt($openai['access_token'] ?? '') === '') {
        return ['sent' => 0, 'skipped' => 0, 'errors' => ['ChatGPT / OpenAI não está ativo.']];
    }
    if (!$resend['active'] || $resend['api_key'] === '' || !filter_var($resend['from_email'], FILTER_VALIDATE_EMAIL)) {
        return ['sent' => 0, 'skipped' => 0, 'errors' => ['Resend não está configurado/ativo.']];
    }

    $sql = "SELECT o.*,
                   seller.name seller_name,seller.email seller_email,
                   support.name support_name,support.email support_email
            FROM opportunities o
            LEFT JOIN users seller ON seller.id=o.seller_user_id
            LEFT JOIN users support ON support.id=o.support_user_id
            WHERE o.status='Aberta'
              AND o.quote_required=1
              AND o.quote_completed_at IS NULL
              AND COALESCE(o.quote_required_at,o.created_at) <= (NOW() - INTERVAL 1 DAY)
              AND (o.quote_reminder_last_sent_at IS NULL OR o.quote_reminder_last_sent_at <= (NOW() - INTERVAL 1 DAY))
            ORDER BY COALESCE(o.quote_required_at,o.created_at) ASC
            LIMIT 100";
    $items = $pdo->query($sql)->fetchAll();

    $sent = 0;
    $skipped = 0;
    $errors = [];

    foreach ($items as $opportunity) {
        try {
            $recipients = array_values(array_unique(array_filter([
                $opportunity['seller_email'] ?? null,
                $opportunity['support_email'] ?? null,
            ], static fn($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL))));

            if (!$recipients) {
                $skipped++;
                $errors[] = $opportunity['number'] . ': vendedor/suporte sem e-mail válido.';
                continue;
            }

            $notesQ = $pdo->prepare(
                'SELECT n.note,n.created_at,u.name author_name
                 FROM opportunity_notes n
                 JOIN users u ON u.id=n.user_id
                 WHERE n.opportunity_id=?
                 ORDER BY n.id DESC
                 LIMIT 5'
            );
            $notesQ->execute([(int)$opportunity['id']]);
            $notes = $notesQ->fetchAll();

            $email = openai_build_quote_reminder_email($pdo, $opportunity, $notes);
            resend_send_email($pdo, $recipients, (string)$email['subject'], (string)$email['text']);

            $pdo->prepare('UPDATE opportunities SET quote_reminder_last_sent_at=NOW() WHERE id=?')
                ->execute([(int)$opportunity['id']]);

            $pdo->prepare(
                'INSERT INTO audit_logs (user_id,action,entity_type,entity_id,context_json)
                 VALUES (NULL,"quote_reminder_email","opportunity",?,?)'
            )->execute([
                (int)$opportunity['id'],
                json_encode(['to'=>$recipients], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $sent++;
        } catch (Throwable $e) {
            $errors[] = $opportunity['number'] . ': ' . $e->getMessage();
        }
    }

    return ['sent'=>$sent,'skipped'=>$skipped,'errors'=>$errors];
}

function openai_extract_erp_quote(PDO $pdo, string $tmpPath, string $filename): array
{
    $config = integration_get($pdo, 'openai');
    $apiKey = secret_decrypt($config['access_token'] ?? '');
    if (empty($config['is_active']) || $apiKey === '') {
        throw new RuntimeException('Configure e ative ChatGPT / OpenAI antes de importar orçamento do ERP.');
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

    $extra = json_decode((string)($config['extra_json'] ?? '{}'), true);
    $model = trim((string)($extra['model'] ?? '')) ?: 'gpt-5.6-sol';

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'numero' => ['type' => 'string'],
            'data' => ['type' => 'string'],
            'cliente' => ['type' => 'string'],
            'vendedor' => ['type' => 'string'],
            'suporte' => ['type' => 'string'],
            'valor' => ['type' => 'number'],
            'linha' => ['type' => 'string'],
        ],
        'required' => ['numero','data','cliente','vendedor','suporte','valor','linha'],
    ];

    $prompt = <<<'PROMPT'
Este PDF é um ORÇAMENTO emitido pelo ERP da DNB.
Extraia somente os dados existentes no documento.
Responda no schema informado:
- numero: número do orçamento;
- data: data de emissão em YYYY-MM-DD;
- cliente: razão social/nome do cliente;
- vendedor: nome do vendedor;
- suporte: nome do suporte, se houver;
- valor: valor total do orçamento;
- linha: linha de produto predominante, usando quando possível uma destas categorias:
  Compressores IR (agenciamento), Serviço Manutenção IR, Locação, Peças IR, Bombas ARO, Peças ARO, Parker Automação, Parker Filtração de Processos, Parker Filtração GSF, Parker Conexões.
Não invente informações. Se o documento misturar linhas distintas, use a linha predominante e preserve a revisão manual posterior.
PROMPT;

    $payload = [
        'model' => $model,
        'store' => false,
        'input' => [[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'input_file',
                    'filename' => preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'orcamento.pdf',
                    'file_data' => 'data:application/pdf;base64,' . base64_encode($bytes),
                ],
                ['type' => 'input_text', 'text' => $prompt],
            ],
        ]],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'erp_quote',
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
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) throw new RuntimeException('Falha ao consultar a OpenAI: ' . $error);

    $response = json_decode($raw, true);
    if (!is_array($response) || $status < 200 || $status >= 300) {
        $message = is_array($response) ? (string)($response['error']['message'] ?? 'erro não informado') : 'resposta inválida';
        throw new RuntimeException('OpenAI HTTP ' . $status . ': ' . $message);
    }

    $data = json_decode(openai_response_text($response), true);
    if (!is_array($data)) {
        throw new RuntimeException('A IA não retornou os dados do orçamento em formato válido.');
    }

    return [
        'numero' => trim((string)($data['numero'] ?? '')),
        'data' => trim((string)($data['data'] ?? '')),
        'cliente' => trim((string)($data['cliente'] ?? '')),
        'vendedor' => trim((string)($data['vendedor'] ?? '')),
        'suporte' => trim((string)($data['suporte'] ?? '')),
        'valor' => (float)($data['valor'] ?? 0),
        'linha' => trim((string)($data['linha'] ?? '')),
    ];
}

function spreadsheet_rows_from_upload(array $file): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Não foi possível receber o arquivo do relatório.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $name = strtolower((string)($file['name'] ?? ''));
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Upload do relatório inválido.');
    }
    if ($size <= 0 || $size > 15 * 1024 * 1024) {
        throw new RuntimeException('O relatório deve ter no máximo 15 MB.');
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($ext === 'csv') {
        $handle = fopen($tmp, 'rb');
        if (!$handle) throw new RuntimeException('Não foi possível abrir o CSV.');

        $first = fgets($handle);
        if ($first === false) {
            fclose($handle);
            return [];
        }

        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    if ($ext === 'xlsx') {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('O servidor precisa da extensão ZIP do PHP para ler XLSX.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('Não foi possível abrir o XLSX.');
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $xml = simplexml_load_string($sharedXml);
            if ($xml) {
                $sharedNamespaces = $xml->getNamespaces(true);
                $sharedRoot = isset($sharedNamespaces['']) ? $xml->children($sharedNamespaces['']) : $xml;
                foreach ($sharedRoot->si as $si) {
                    $parts = [];
                    if (isset($si->t)) $parts[] = (string)$si->t;
                    foreach ($si->r as $run) $parts[] = (string)$run->t;
                    $sharedStrings[] = implode('', $parts);
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false) {
            throw new RuntimeException('A primeira planilha do XLSX não foi encontrada.');
        }

        $xml = simplexml_load_string($sheetXml);
        if (!$xml) throw new RuntimeException('XLSX inválido.');

        $namespaces = $xml->getNamespaces(true);
        $sheet = isset($namespaces['']) ? $xml->children($namespaces['']) : $xml;
        if (!isset($sheet->sheetData)) {
            throw new RuntimeException('A primeira planilha do XLSX não possui dados legíveis.');
        }

        $rows = [];
        foreach ($sheet->sheetData->row as $rowNode) {
            $row = [];
            foreach ($rowNode->c as $cell) {
                $ref = (string)$cell['r'];
                preg_match('/([A-Z]+)/', $ref, $m);
                $letters = $m[1] ?? 'A';
                $index = 0;
                for ($i=0; $i<strlen($letters); $i++) {
                    $index = $index * 26 + (ord($letters[$i]) - 64);
                }
                $index--;

                $value = (string)$cell->v;
                if ((string)$cell['t'] === 's') {
                    $value = $sharedStrings[(int)$value] ?? '';
                }
                $row[$index] = $value;
            }

            if ($row) {
                ksort($row);
                $max = max(array_keys($row));
                $dense = [];
                for ($i=0; $i<=$max; $i++) $dense[] = $row[$i] ?? '';
                $rows[] = $dense;
            }
        }
        return $rows;
    }

    throw new RuntimeException('Formato não suportado. Use CSV ou XLSX.');
}

function rows_to_assoc(array $rows): array
{
    if (!$rows) return [];
    $headers = array_map(static fn($v) => normalized_name((string)$v), array_shift($rows));
    $out = [];

    foreach ($rows as $row) {
        $assoc = [];
        foreach ($headers as $i => $header) {
            if ($header !== '') $assoc[$header] = trim((string)($row[$i] ?? ''));
        }
        if (array_filter($assoc, static fn($v) => $v !== '')) $out[] = $assoc;
    }

    return $out;
}

function pick_assoc_value(array $row, array $keys): string
{
    foreach ($keys as $key) {
        $normalized = normalized_name($key);
        if (isset($row[$normalized]) && trim((string)$row[$normalized]) !== '') {
            return trim((string)$row[$normalized]);
        }
    }
    return '';
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

function ensure_opportunity_tracking_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($database === '') {
        throw new RuntimeException('Banco de dados não selecionado.');
    }

    $q = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME="opportunities"');
    $q->execute([$database]);
    $existing = array_flip(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)));

    $columns = [
        'loss_reason' => 'TEXT NULL AFTER status',
        'loss_recorded_at' => 'DATETIME NULL AFTER loss_reason',
        'quote_required' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER loss_recorded_at',
        'quote_required_at' => 'DATETIME NULL AFTER quote_required',
        'quote_completed_at' => 'DATETIME NULL AFTER quote_required_at',
        'quote_reference' => 'VARCHAR(120) NULL AFTER quote_completed_at',
        'quote_reminder_last_sent_at' => 'DATETIME NULL AFTER quote_reference',
    ];

    foreach ($columns as $name => $definition) {
        if (!isset($existing[$name])) {
            $pdo->exec('ALTER TABLE opportunities ADD COLUMN ' . $name . ' ' . $definition);
        }
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS opportunity_notes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            opportunity_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            note TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_opportunity_notes_opp_created (opportunity_id, created_at),
            CONSTRAINT fk_opportunity_note_opp FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE,
            CONSTRAINT fk_opportunity_note_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $done = true;
}

function opportunity_reminder_token(): string
{
    return hash_hmac('sha256', 'dnb-crm-opportunity-reminders', app_key_bytes());
}

function ensure_commercial_automation_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($database === '') {
        throw new RuntimeException('Banco de dados não selecionado.');
    }

    $q = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME="agenda"');
    $q->execute([$database]);
    $existing = array_flip(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN)));

    $columns = [
        'event_type' => 'ENUM("compromisso","followup","visita_retorno") NOT NULL DEFAULT "compromisso" AFTER status',
        'template_key' => 'VARCHAR(80) NULL AFTER event_type',
        'reminder_sent_at' => 'DATETIME NULL AFTER template_key',
        'auto_generated' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER reminder_sent_at',
    ];

    foreach ($columns as $name => $definition) {
        if (!isset($existing[$name])) {
            $pdo->exec('ALTER TABLE agenda ADD COLUMN ' . $name . ' ' . $definition);
        }
    }

    try {
        $pdo->exec('CREATE INDEX idx_agenda_type_date ON agenda (event_type,event_date)');
    } catch (Throwable $ignored) {
    }
    try {
        $pdo->exec('CREATE INDEX idx_agenda_reminder ON agenda (reminder_sent_at)');
    } catch (Throwable $ignored) {
    }

    $done = true;
}

function commercial_automation_defaults(): array
{
    return [
        'followup_days' => 3,
        'return_days' => 60,
        'templates' => [
            'followup_relacionamento' => [
                'label' => 'Follow-up · relacionamento',
                'subject' => '[DNB CRM] Follow-up com {cliente} hoje',
                'body' => "Olá {nome},\n\nHoje é o follow-up programado da visita realizada em {visita_data} ao cliente {cliente}. Faça um contato para entender o andamento, registrar o retorno no CRM e definir o próximo passo.\n\nContato: {contato}\nOS: {os}\n\nDNB CRM",
            ],
            'followup_oportunidade' => [
                'label' => 'Follow-up · oportunidade',
                'subject' => '[DNB CRM] Oportunidade identificada · {cliente}',
                'body' => "Olá {nome},\n\nHá um follow-up programado para hoje referente à oportunidade identificada na visita ao cliente {cliente}. Verifique a evolução da demanda e registre o andamento no CRM.\n\nOportunidade: {oportunidade}\nOS: {os}\nContato: {contato}\n\nDNB CRM",
            ],
            'followup_tecnico' => [
                'label' => 'Follow-up · técnico',
                'subject' => '[DNB CRM] Follow-up técnico · {cliente}',
                'body' => "Olá {nome},\n\nHoje é o follow-up técnico programado após a visita ao cliente {cliente}. Confirme a situação dos equipamentos e registre no CRM qualquer necessidade de proposta, serviço ou nova visita.\n\nEquipamentos: {equipamentos}\nOS: {os}\nContato: {contato}\n\nDNB CRM",
            ],
            'visita_relacionamento' => [
                'label' => 'Cronograma · relacionamento',
                'subject' => '[DNB CRM] Próxima visita programada · {cliente}',
                'body' => "Olá {nome},\n\nA próxima visita comercial ao cliente {cliente} está programada para {data}. Use o cronograma do CRM para confirmar o compromisso e preparar o objetivo da visita.\n\nContato: {contato}\nVisita anterior: {visita_data}\n\nDNB CRM",
            ],
            'visita_oportunidade' => [
                'label' => 'Cronograma · oportunidade',
                'subject' => '[DNB CRM] Retorno comercial programado · {cliente}',
                'body' => "Olá {nome},\n\nO retorno presencial ao cliente {cliente} está programado para {data}. A visita anterior registrou uma oportunidade; revise o histórico antes do contato e atualize o CRM após a visita.\n\nOportunidade: {oportunidade}\nOS anterior: {os}\n\nDNB CRM",
            ],
            'visita_tecnica' => [
                'label' => 'Cronograma · técnico',
                'subject' => '[DNB CRM] Visita técnica de retorno · {cliente}',
                'body' => "Olá {nome},\n\nA visita de retorno ao cliente {cliente} está programada para {data}. Revise os equipamentos citados na visita anterior e confirme o compromisso no Cronograma Comercial.\n\nEquipamentos: {equipamentos}\nOS anterior: {os}\n\nDNB CRM",
            ],
        ],
    ];
}

function commercial_automation_settings(PDO $pdo): array
{
    $defaults = commercial_automation_defaults();
    $cfg = integration_get($pdo, 'commercial_automation');
    $extra = json_decode((string)($cfg['extra_json'] ?? '{}'), true);
    $extra = is_array($extra) ? $extra : [];

    $templates = $defaults['templates'];
    if (is_array($extra['templates'] ?? null)) {
        foreach ($templates as $key => $defaultTemplate) {
            if (!is_array($extra['templates'][$key] ?? null)) continue;
            $saved = $extra['templates'][$key];
            $templates[$key]['subject'] = trim((string)($saved['subject'] ?? '')) ?: $defaultTemplate['subject'];
            $templates[$key]['body'] = trim((string)($saved['body'] ?? '')) ?: $defaultTemplate['body'];
        }
    }

    return [
        'followup_days' => 3,
        'return_days' => 60,
        'templates' => $templates,
    ];
}

function commercial_template_key(array $visit, string $eventType): string
{
    $prefix = $eventType === 'visita_retorno' ? 'visita' : 'followup';

    if (trim((string)($visit['opportunity_text'] ?? $visit['oportunidade'] ?? '')) !== '') {
        return $prefix . '_oportunidade';
    }
    if (trim((string)($visit['equipment'] ?? $visit['equipamentos'] ?? '')) !== '') {
        return $prefix . '_tecnico';
    }
    return $prefix . '_relacionamento';
}

function commercial_render_template(string $text, array $vars): string
{
    $replace = [];
    foreach ($vars as $key => $value) {
        $replace['{' . $key . '}'] = (string)$value;
    }
    return strtr($text, $replace);
}

function schedule_visit_commercial_automation(PDO $pdo, int $visitId, array $data, int $createdBy): array
{
    ensure_commercial_automation_schema($pdo);

    $visitDate = valid_date_string($data['data'] ?? '')
        ? new DateTimeImmutable((string)$data['data'])
        : new DateTimeImmutable('today');

    $client = trim((string)($data['cliente'] ?? ''));
    if ($client === '') {
        throw new RuntimeException('Cliente ausente; não foi possível criar o cronograma automático.');
    }

    $sellerId = ($data['seller_user_id'] ?? '') !== '' && $data['seller_user_id'] !== null
        ? (int)$data['seller_user_id']
        : null;

    $contact = trim(implode(' · ', array_filter([
        trim((string)($data['contato'] ?? '')),
        trim((string)($data['fone'] ?? '')),
    ])));

    $settings = commercial_automation_settings($pdo);
    $followupDate = $visitDate->modify('+' . (int)$settings['followup_days'] . ' days')->format('Y-m-d');
    $returnDate = $visitDate->modify('+' . (int)$settings['return_days'] . ' days')->format('Y-m-d');

    $visitShape = [
        'opportunity_text' => trim((string)($data['oportunidade'] ?? '')),
        'equipment' => trim((string)($data['equipamentos'] ?? '')),
    ];

    $plans = [
        [
            'type' => 'followup',
            'date' => $followupDate,
            'template' => commercial_template_key($visitShape, 'followup'),
            'objective' => $visitShape['opportunity_text'] !== ''
                ? 'Follow-up da oportunidade identificada na visita'
                : ($visitShape['equipment'] !== '' ? 'Follow-up técnico pós-visita' : 'Follow-up comercial pós-visita'),
            'source' => 'auto-followup-3d',
        ],
        [
            'type' => 'visita_retorno',
            'date' => $returnDate,
            'template' => commercial_template_key($visitShape, 'visita_retorno'),
            'objective' => $visitShape['opportunity_text'] !== ''
                ? 'Visita de retorno para acompanhamento da oportunidade'
                : ($visitShape['equipment'] !== '' ? 'Visita técnica de retorno' : 'Visita comercial de relacionamento'),
            'source' => 'auto-retorno-60d',
        ],
    ];

    $created = [];
    foreach ($plans as $plan) {
        $check = $pdo->prepare('SELECT id FROM agenda WHERE visit_id=? AND event_type=? AND source=? LIMIT 1');
        $check->execute([$visitId,$plan['type'],$plan['source']]);
        $existingId = (int)($check->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $created[$plan['type']] = ['id'=>$existingId,'date'=>$plan['date'],'created'=>false];
            continue;
        }

        $q = $pdo->prepare(
            'INSERT INTO agenda
             (event_date,event_time,client_name,user_id,contact_name,objective,status,event_type,template_key,reminder_sent_at,auto_generated,source,visit_id,created_by)
             VALUES (?,NULL,?,?,?,?, "agendado",?,?,NULL,1,?,?,?)'
        );
        $q->execute([
            $plan['date'],
            $client,
            $sellerId,
            $contact ?: null,
            $plan['objective'],
            $plan['type'],
            $plan['template'],
            $plan['source'],
            $visitId,
            $createdBy,
        ]);

        $created[$plan['type']] = [
            'id' => (int)$pdo->lastInsertId(),
            'date' => $plan['date'],
            'created' => true,
        ];
    }

    return $created;
}

function process_commercial_agenda_reminders(PDO $pdo): array
{
    ensure_commercial_automation_schema($pdo);
    $settings = commercial_automation_settings($pdo);
    $resend = resend_settings($pdo);

    if (!$resend['active'] || $resend['api_key'] === '' || !filter_var($resend['from_email'], FILTER_VALIDATE_EMAIL)) {
        return ['sent'=>0,'skipped'=>0,'errors'=>['Resend não está configurado/ativo.']];
    }

    $sql = "SELECT a.*,
                   u.name user_name,u.email user_email,
                   v.os_number,v.visit_date,v.opportunity_text,v.equipment
            FROM agenda a
            LEFT JOIN users u ON u.id=a.user_id
            LEFT JOIN visits v ON v.id=a.visit_id
            WHERE a.event_type IN ('followup','visita_retorno')
              AND a.auto_generated=1
              AND a.status IN ('sugerido','agendado','confirmado')
              AND a.reminder_sent_at IS NULL
              AND a.event_date <= CURDATE()
            ORDER BY a.event_date ASC,a.id ASC
            LIMIT 200";
    $items = $pdo->query($sql)->fetchAll();

    $sent = 0;
    $skipped = 0;
    $errors = [];

    foreach ($items as $item) {
        try {
            $email = trim((string)($item['user_email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                $errors[] = $item['client_name'] . ': responsável sem e-mail válido.';
                continue;
            }

            $templateKey = trim((string)($item['template_key'] ?? ''));
            $template = $settings['templates'][$templateKey] ?? null;
            if (!is_array($template)) {
                $fallbackKey = $item['event_type'] === 'visita_retorno'
                    ? commercial_template_key($item, 'visita_retorno')
                    : commercial_template_key($item, 'followup');
                $template = $settings['templates'][$fallbackKey] ?? null;
            }
            if (!is_array($template)) {
                throw new RuntimeException('Modelo de e-mail não encontrado.');
            }

            $vars = [
                'nome' => trim((string)($item['user_name'] ?? 'colaborador')),
                'cliente' => trim((string)($item['client_name'] ?? '')),
                'data' => valid_date_string((string)$item['event_date']) ? date('d/m/Y', strtotime((string)$item['event_date'])) : (string)$item['event_date'],
                'visita_data' => valid_date_string((string)($item['visit_date'] ?? '')) ? date('d/m/Y', strtotime((string)$item['visit_date'])) : '—',
                'contato' => trim((string)($item['contact_name'] ?? '')) ?: '—',
                'os' => trim((string)($item['os_number'] ?? '')) ?: '—',
                'oportunidade' => trim((string)($item['opportunity_text'] ?? '')) ?: '—',
                'equipamentos' => trim((string)($item['equipment'] ?? '')) ?: '—',
                'objetivo' => trim((string)($item['objective'] ?? '')),
            ];

            $subject = commercial_render_template((string)$template['subject'], $vars);
            $body = commercial_render_template((string)$template['body'], $vars);

            resend_send_email($pdo, [$email], $subject, $body);

            $pdo->prepare('UPDATE agenda SET reminder_sent_at=NOW() WHERE id=?')->execute([(int)$item['id']]);
            $pdo->prepare(
                'INSERT INTO audit_logs (user_id,action,entity_type,entity_id,context_json)
                 VALUES (NULL,"commercial_agenda_reminder","agenda",?,?)'
            )->execute([
                (int)$item['id'],
                json_encode([
                    'event_type'=>$item['event_type'],
                    'to'=>$email,
                    'template_key'=>$templateKey,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $sent++;
        } catch (Throwable $e) {
            $errors[] = (string)($item['client_name'] ?? 'Agenda') . ': ' . $e->getMessage();
        }
    }

    return ['sent'=>$sent,'skipped'=>$skipped,'errors'=>$errors];
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
