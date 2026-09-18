<?php
declare(strict_types=1);

require_once __DIR__ . '/integration_config.php';

const DNB_RD_PRODUCTS = ['marketing', 'crm'];

/** @return array<string,mixed> */
function rdProdutoConfig(string $produto): array
{
    $produto = strtolower(trim($produto));
    $config = [
        'marketing' => [
            'label' => 'RD Station Marketing',
            'prefix' => 'DNB_RD_MARKETING',
            'authorize_url' => 'https://api.rd.services/auth/dialog',
            'token_url' => 'https://api.rd.services/auth/token',
            'token_by_code_url' => 'https://api.rd.services/auth/token?token_by=code',
            'callback_path' => '/api/oauth/rdstation/marketing/callback.php',
            'connect_path' => '/api/oauth/rdstation/marketing/connect.php',
            'test_url' => 'https://api.rd.services/platform/contacts/fields',
            'default_expires_in' => 86400,
            'refresh_rolling' => false,
        ],
        'crm' => [
            'label' => 'RD Station CRM',
            'prefix' => 'DNB_RD_CRM',
            'authorize_url' => 'https://accounts.rdstation.com/oauth/authorize',
            'token_url' => 'https://api.rd.services/oauth2/token',
            'token_by_code_url' => 'https://api.rd.services/oauth2/token',
            'callback_path' => '/api/oauth/rdstation/crm/callback.php',
            'connect_path' => '/api/oauth/rdstation/crm/connect.php',
            'test_url' => 'https://api.rd.services/crm/v2/users?page[size]=1',
            'default_expires_in' => 7200,
            'refresh_rolling' => true,
        ],
    ];
    if (!isset($config[$produto])) {
        throw new InvalidArgumentException('Produto RD Station inválido.');
    }
    return $config[$produto];
}

function rdConfigKey(string $produto, string $sufixo): string
{
    $cfg = rdProdutoConfig($produto);
    return $cfg['prefix'] . '_' . strtoupper($sufixo);
}

function rdPublicBaseUrl(bool $permitirFallback = true): string
{
    $configurada = trim(integracaoConfigValor('DNB_PUBLIC_BASE_URL'));
    if ($configurada !== '') return rtrim($configurada, '/');
    if (!$permitirFallback) return '';

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+(?::\d{1,5})?$/', $host)) return '';
    return 'https://' . $host;
}

function rdValidarPublicBaseUrl(string $url): string
{
    $url = rtrim(trim($url), '/');
    if ($url === '') throw new InvalidArgumentException('Informe a URL pública HTTPS do CRM.');
    $parts = parse_url($url);
    if ($parts === false || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        throw new InvalidArgumentException('A URL pública do CRM deve usar HTTPS.');
    }
    if (isset($parts['user'], $parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new InvalidArgumentException('A URL pública não pode conter credenciais, query string ou fragmento.');
    }
    return $url;
}

function rdCallbackUrl(string $produto): string
{
    $base = rdPublicBaseUrl();
    if ($base === '') throw new RuntimeException('Configure a URL pública do CRM antes de conectar o RD Station.');
    return $base . rdProdutoConfig($produto)['callback_path'];
}

function rdConnectPath(string $produto): string
{
    return (string)rdProdutoConfig($produto)['connect_path'];
}

function rdClientId(string $produto): string
{
    return trim(integracaoConfigValor(rdConfigKey($produto, 'CLIENT_ID')));
}

function rdClientSecret(string $produto): string
{
    return trim(integracaoConfigValor(rdConfigKey($produto, 'CLIENT_SECRET')));
}

function rdAccessTokenSalvo(string $produto): string
{
    return trim(integracaoConfigValor(rdConfigKey($produto, 'ACCESS_TOKEN')));
}

function rdRefreshTokenSalvo(string $produto): string
{
    return trim(integracaoConfigValor(rdConfigKey($produto, 'REFRESH_TOKEN')));
}

function rdAccessExpiresAt(string $produto): int
{
    return max(0, (int)integracaoConfigValor(rdConfigKey($produto, 'ACCESS_EXPIRES_AT'), '0'));
}

function rdCredenciaisConfiguradas(string $produto): bool
{
    return rdClientId($produto) !== '' && rdClientSecret($produto) !== '';
}

/** @return array<string,mixed> */
function rdEstadoProduto(string $produto): array
{
    $cfg = rdProdutoConfig($produto);
    $expiresAt = rdAccessExpiresAt($produto);
    $refresh = rdRefreshTokenSalvo($produto);
    $access = rdAccessTokenSalvo($produto);
    $connected = ($refresh !== '' || $access !== '');
    return [
        'produto' => $produto,
        'label' => $cfg['label'],
        'configured' => rdCredenciaisConfiguradas($produto),
        'connected' => $connected,
        'access_available' => $access !== '',
        'refresh_available' => $refresh !== '',
        'access_expires_at' => $expiresAt,
        'access_expired' => $expiresAt > 0 && $expiresAt <= time(),
        'connected_at' => integracaoConfigValor(rdConfigKey($produto, 'CONNECTED_AT')),
        'last_refresh_at' => integracaoConfigValor(rdConfigKey($produto, 'LAST_REFRESH_AT')),
        'last_test_at' => integracaoConfigValor(rdConfigKey($produto, 'LAST_TEST_AT')),
        'last_test_status' => integracaoConfigValor(rdConfigKey($produto, 'LAST_TEST_STATUS')),
        'last_test_message' => integracaoConfigValor(rdConfigKey($produto, 'LAST_TEST_MESSAGE')),
        'callback_url' => rdCallbackUrl($produto),
        'connect_path' => $cfg['connect_path'],
    ];
}

function rdSalvarPublicBaseUrl(string $url): void
{
    salvarIntegracaoConfig(['DNB_PUBLIC_BASE_URL' => rdValidarPublicBaseUrl($url)]);
}

function rdSalvarCredenciais(string $produto, string $clientId, string $clientSecret = ''): void
{
    rdProdutoConfig($produto);
    $clientId = trim($clientId);
    if ($clientId === '') throw new InvalidArgumentException('Informe o Client ID.');
    if (strlen($clientId) > 255) throw new InvalidArgumentException('Client ID inválido.');

    $keyId = rdConfigKey($produto, 'CLIENT_ID');
    $anterior = rdClientId($produto);
    $novos = [$keyId => $clientId];
    if (trim($clientSecret) !== '') {
        if (strlen(trim($clientSecret)) > 1024) throw new InvalidArgumentException('Client Secret inválido.');
        $novos[rdConfigKey($produto, 'CLIENT_SECRET')] = trim($clientSecret);
    }
    if ($anterior !== '' && !hash_equals($anterior, $clientId)) {
        foreach (['ACCESS_TOKEN','REFRESH_TOKEN','ACCESS_EXPIRES_AT','CONNECTED_AT','LAST_REFRESH_AT','LAST_TEST_AT','LAST_TEST_STATUS','LAST_TEST_MESSAGE'] as $suffix) {
            $novos[rdConfigKey($produto, $suffix)] = '';
        }
    }
    salvarIntegracaoConfig($novos);
}

function rdDesconectar(string $produto): void
{
    $novos = [];
    foreach (['ACCESS_TOKEN','REFRESH_TOKEN','ACCESS_EXPIRES_AT','CONNECTED_AT','LAST_REFRESH_AT','LAST_TEST_AT','LAST_TEST_STATUS','LAST_TEST_MESSAGE'] as $suffix) {
        $novos[rdConfigKey($produto, $suffix)] = '';
    }
    salvarIntegracaoConfig($novos);
}

/** @return array{status:int,json:?array,raw:string} */
function rdHttpRequest(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
{
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, ['api.rd.services'], true)) {
        throw new RuntimeException('Endpoint RD Station não permitido.');
    }

    $timeout = max(5, min(60, $timeout));
    $raw = '';
    $status = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Falha de comunicação com o RD Station: ' . $err);
        }
        $raw = (string)$resp;
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $context);
        if ($resp === false && empty($http_response_header)) {
            throw new RuntimeException('Falha de comunicação com o RD Station.');
        }
        $raw = (string)($resp === false ? '' : $resp);
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
    }

    $json = json_decode($raw, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => $raw];
}

function rdErroResposta(array $resposta, string $padrao): string
{
    $json = $resposta['json'] ?? null;
    if (is_array($json)) {
        foreach (['error_description','error','message','detail'] as $key) {
            if (isset($json[$key]) && is_scalar($json[$key])) {
                $msg = trim((string)$json[$key]);
                if ($msg !== '') return mb_substr($msg, 0, 220);
            }
        }
        if (isset($json['errors'][0]['detail'])) return mb_substr((string)$json['errors'][0]['detail'], 0, 220);
    }
    return $padrao . ' (HTTP ' . (int)($resposta['status'] ?? 0) . ').';
}

/** @return array<string,mixed> */
function rdSolicitarToken(string $produto, string $tipo, string $valor): array
{
    $cfg = rdProdutoConfig($produto);
    $clientId = rdClientId($produto);
    $secret = rdClientSecret($produto);
    if ($clientId === '' || $secret === '') throw new RuntimeException('Client ID e Client Secret precisam estar configurados.');

    if ($produto === 'crm') {
        $params = [
            'client_id' => $clientId,
            'client_secret' => $secret,
            'grant_type' => $tipo === 'code' ? 'authorization_code' : 'refresh_token',
        ];
        if ($tipo === 'code') {
            $params['code'] = $valor;
            $params['redirect_uri'] = rdCallbackUrl($produto);
        } else {
            $params['refresh_token'] = $valor;
        }
        $res = rdHttpRequest('POST', $cfg['token_url'], [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    } else {
        $params = [
            'client_id' => $clientId,
            'client_secret' => $secret,
            $tipo === 'code' ? 'code' : 'refresh_token' => $valor,
        ];
        $url = $tipo === 'code' ? $cfg['token_by_code_url'] : $cfg['token_url'];
        $jsonBody = json_encode($params, JSON_UNESCAPED_SLASHES);
        $res = rdHttpRequest('POST', $url, ['Accept: application/json', 'Content-Type: application/json'], $jsonBody === false ? '{}' : $jsonBody);
        if (in_array((int)$res['status'], [400, 404, 405, 415, 422], true)) {
            $form = $params;
            if ($tipo === 'refresh') $form['grant_type'] = 'refresh_token';
            $res = rdHttpRequest('POST', $url, ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'], http_build_query($form, '', '&', PHP_QUERY_RFC3986));
        }
    }

    if ((int)$res['status'] < 200 || (int)$res['status'] >= 300 || !is_array($res['json'])) {
        throw new RuntimeException(rdErroResposta($res, 'RD Station recusou a autenticação'));
    }
    $payload = $res['json'];
    if (empty($payload['access_token'])) throw new RuntimeException('RD Station não retornou access_token.');
    return $payload;
}

/** @param array<string,mixed> $payload */
function rdPersistirTokens(string $produto, array $payload, bool $novaConexao = false): void
{
    $cfg = rdProdutoConfig($produto);
    $access = trim((string)($payload['access_token'] ?? ''));
    if ($access === '') throw new RuntimeException('Access token ausente.');

    $refreshNovo = trim((string)($payload['refresh_token'] ?? ''));
    $refreshAtual = rdRefreshTokenSalvo($produto);
    if ($refreshNovo === '') $refreshNovo = $refreshAtual;
    if ($refreshNovo === '') throw new RuntimeException('RD Station não retornou refresh_token.');

    $expiresIn = max(60, (int)($payload['expires_in'] ?? $cfg['default_expires_in']));
    $now = time();
    $novos = [
        rdConfigKey($produto, 'ACCESS_TOKEN') => $access,
        rdConfigKey($produto, 'REFRESH_TOKEN') => $refreshNovo,
        rdConfigKey($produto, 'ACCESS_EXPIRES_AT') => (string)($now + $expiresIn),
        rdConfigKey($produto, 'LAST_REFRESH_AT') => gmdate('c', $now),
    ];
    if ($novaConexao || integracaoConfigValor(rdConfigKey($produto, 'CONNECTED_AT')) === '') {
        $novos[rdConfigKey($produto, 'CONNECTED_AT')] = gmdate('c', $now);
    }
    salvarIntegracaoConfig($novos);
}

/** @return array<string,mixed> */
function rdTrocarCodePorTokens(string $produto, string $code): array
{
    $code = trim($code);
    if ($code === '') throw new InvalidArgumentException('Código OAuth ausente.');
    $payload = rdSolicitarToken($produto, 'code', $code);
    rdPersistirTokens($produto, $payload, true);
    return $payload;
}

/** @return array<string,mixed> */
function rdRenovarToken(string $produto): array
{
    $refresh = rdRefreshTokenSalvo($produto);
    if ($refresh === '') throw new RuntimeException('Refresh token ausente. Reconecte o aplicativo.');
    $payload = rdSolicitarToken($produto, 'refresh', $refresh);
    rdPersistirTokens($produto, $payload, false);
    return $payload;
}

function rdObterAccessToken(string $produto, bool $forcarRenovacao = false): string
{
    $access = rdAccessTokenSalvo($produto);
    $expiresAt = rdAccessExpiresAt($produto);
    $deveRenovar = $forcarRenovacao || $access === '' || ($expiresAt > 0 && $expiresAt <= time() + 300);
    if ($deveRenovar) {
        rdRenovarToken($produto);
        $access = rdAccessTokenSalvo($produto);
    }
    if ($access === '') throw new RuntimeException('Access token indisponível. Conecte o RD Station.');
    return $access;
}

/** @return array{status:int,json:?array,raw:string} */
function rdApiRequest(string $produto, string $method, string $url, ?string $body = null): array
{
    $token = rdObterAccessToken($produto);
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $res = rdHttpRequest($method, $url, $headers, $body);
    if ((int)$res['status'] === 401 && rdRefreshTokenSalvo($produto) !== '') {
        $token = rdObterAccessToken($produto, true);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        $res = rdHttpRequest($method, $url, $headers, $body);
    }
    return $res;
}

/** @return array{ok:bool,status:int,message:string} */
function rdTestarConexao(string $produto): array
{
    $cfg = rdProdutoConfig($produto);
    $ok = false;
    $status = 0;
    $message = '';
    try {
        $res = rdApiRequest($produto, 'GET', $cfg['test_url']);
        $status = (int)$res['status'];
        if ($status >= 200 && $status < 300) {
            $ok = true;
            $message = $cfg['label'] . ' autenticado e com leitura básica liberada.';
        } elseif ($status === 429) {
            $ok = true;
            $message = $cfg['label'] . ' autenticado; a API informou limite temporário de requisições.';
        } elseif ($status === 403) {
            $message = 'OAuth válido, mas o aplicativo não possui a permissão de leitura necessária para o teste (HTTP 403).';
        } else {
            $message = rdErroResposta($res, 'Não foi possível validar a conexão');
        }
    } catch (Throwable $e) {
        $message = mb_substr($e->getMessage(), 0, 220);
    }

    salvarIntegracaoConfig([
        rdConfigKey($produto, 'LAST_TEST_AT') => gmdate('c'),
        rdConfigKey($produto, 'LAST_TEST_STATUS') => $ok ? 'ok' : 'error',
        rdConfigKey($produto, 'LAST_TEST_MESSAGE') => mb_substr($message, 0, 220),
    ]);
    return ['ok' => $ok, 'status' => $status, 'message' => $message];
}

function rdAuthorizationUrl(string $produto, string $state): string
{
    $cfg = rdProdutoConfig($produto);
    if (!rdCredenciaisConfiguradas($produto)) {
        throw new RuntimeException('Salve o Client ID e o Client Secret antes de conectar.');
    }
    $params = [
        'client_id' => rdClientId($produto),
        'redirect_uri' => rdCallbackUrl($produto),
        'state' => $state,
    ];
    if ($produto === 'crm') $params = ['response_type' => 'code'] + $params;
    return $cfg['authorize_url'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}
