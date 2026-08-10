<?php
declare(strict_types=1);
/**
 * Integração RD Station — duas fontes independentes de coleta.
 *
 * O fluxo de dados é sempre RD → CRM: o CRM autoriza a aplicação, guarda os
 * tokens e passa a ler. Nada é escrito de volta na RD por este serviço.
 *
 * - rdstation_marketing: OAuth 2.0 (authorization code + refresh token).
 * - rdstation_crm: a RD Station CRM autentica por token estático de instância;
 *   o slot também aceita client_id/secret caso a aplicação criada no portal
 *   ofereça o fluxo OAuth, e o callback trata os dois casos.
 */

require_once __DIR__ . '/settings_service.php';

class RdStationException extends RuntimeException {}

const RDSTATION_FONTES = [
    'rdstation_marketing' => [
        'rotulo' => 'RD Station Marketing',
        'callback' => '/integrations/rdstation/callback',
        'autorizacao' => 'https://api.rd.services/auth/dialog',
        'token' => 'https://api.rd.services/auth/token',
        'oauth' => true,
    ],
    'rdstation_crm' => [
        'rotulo' => 'RD Station CRM',
        'callback' => '/integrations/rdstation/crm/callback',
        'autorizacao' => 'https://crm.rdstation.com/api/v1/auth/dialog',
        'token' => 'https://crm.rdstation.com/api/v1/auth/token',
        'oauth' => true,
    ],
];

function rdFonteValida(string $fonte): bool
{
    return isset(RDSTATION_FONTES[$fonte]);
}

/** @return array<string,mixed> */
function rdFonte(string $fonte): array
{
    if (!rdFonteValida($fonte)) throw new RdStationException('Fonte RD Station desconhecida: ' . $fonte);
    return RDSTATION_FONTES[$fonte];
}

/** URL de callback efetiva: a gravada pelo admin ou, na falta, o host atual. */
function rdCallbackUrl(string $fonte): string
{
    $meta = rdFonte($fonte);
    return integracaoValor($fonte, 'callback_url', '', integracaoBaseUrl() . $meta['callback']);
}

function rdClientId(string $fonte): string
{
    return integracaoValor($fonte, 'client_id');
}

function rdClientSecret(string $fonte): string
{
    return integracaoValor($fonte, 'client_secret');
}

function rdAccessToken(string $fonte): string
{
    return integracaoValor($fonte, 'access_token');
}

/** Token estático de instância (caminho alternativo da RD Station CRM). */
function rdTokenEstatico(string $fonte): string
{
    return integracaoValor($fonte, 'static_token');
}

function rdConectado(string $fonte): bool
{
    return rdAccessToken($fonte) !== '' || rdTokenEstatico($fonte) !== '';
}

function rdExpiraEm(string $fonte): string
{
    return integracaoValor($fonte, 'expires_at');
}

function rdTokenExpirado(string $fonte): bool
{
    $expira = rdExpiraEm($fonte);
    if ($expira === '') return false;
    // Trata como expirado 60s antes, para não perder a corrida com a RD.
    return strtotime($expira) !== false && strtotime($expira) <= time() + 60;
}

function rdPodeConectar(string $fonte): bool
{
    return rdClientId($fonte) !== '' && rdClientSecret($fonte) !== '' && rdCallbackUrl($fonte) !== '';
}

/**
 * State assinado com a chave de sessão: a RD devolve o valor intacto e nós
 * conferimos antes de aceitar o code, impedindo callback forjado.
 */
function rdGerarState(string $fonte): string
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['rd_state'][$fonte] = $nonce;
    return $fonte . ':' . $nonce;
}

function rdValidarState(string $recebido): string
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    $partes = explode(':', $recebido, 2);
    if (count($partes) !== 2) throw new RdStationException('Parâmetro state ausente ou malformado.');
    [$fonte, $nonce] = $partes;
    if (!rdFonteValida($fonte)) throw new RdStationException('Fonte desconhecida no state.');
    $esperado = (string) ($_SESSION['rd_state'][$fonte] ?? '');
    if ($esperado === '' || !hash_equals($esperado, $nonce)) {
        throw new RdStationException('State inválido — reinicie a conexão pela tela de Configurações.');
    }
    unset($_SESSION['rd_state'][$fonte]);
    return $fonte;
}

function rdUrlAutorizacao(string $fonte): string
{
    if (!rdPodeConectar($fonte)) {
        throw new RdStationException('Preencha Client ID, Client Secret e a URL de callback antes de conectar.');
    }
    $meta = rdFonte($fonte);
    return $meta['autorizacao'] . '?' . http_build_query([
        'client_id' => rdClientId($fonte),
        'redirect_uri' => rdCallbackUrl($fonte),
        'state' => rdGerarState($fonte),
    ]);
}

/** @return array<string,mixed> */
function rdHttpPostJson(string $url, array $payload): array
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: DNB-CRM-cPanel/3.5'];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $corpo = curl_exec($ch);
        $erro = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($corpo === false) throw new RdStationException('Falha de comunicação com a RD Station: ' . $erro);
    } else {
        $contexto = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $json,
            'timeout' => 30, 'ignore_errors' => true,
        ]]);
        $corpo = @file_get_contents($url, false, $contexto);
        $status = 0;
        foreach (($http_response_header ?? []) as $linha) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $linha, $m)) $status = (int) $m[1];
        }
        if ($corpo === false) throw new RdStationException('Falha de comunicação com a RD Station.');
    }

    if ($status < 200 || $status >= 300) throw new RdStationException('A RD Station retornou HTTP ' . $status . '.');
    $dados = json_decode((string) $corpo, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($dados)) throw new RdStationException('Resposta inválida recebida da RD Station.');
    return $dados;
}

/** Persiste o par de tokens devolvido pela RD, sempre cifrado. */
function rdGuardarTokens(PDO $pdo, string $fonte, array $dados, ?int $usuarioId = null): void
{
    $access = trim((string) ($dados['access_token'] ?? ''));
    if ($access === '') throw new RdStationException('A RD Station não devolveu access_token.');

    salvarIntegracao($pdo, $fonte, 'access_token', $access, true, $usuarioId);

    $refresh = trim((string) ($dados['refresh_token'] ?? ''));
    if ($refresh !== '') salvarIntegracao($pdo, $fonte, 'refresh_token', $refresh, true, $usuarioId);

    $expiraEm = (int) ($dados['expires_in'] ?? 0);
    salvarIntegracao($pdo, $fonte, 'expires_at', $expiraEm > 0 ? date('Y-m-d H:i:s', time() + $expiraEm) : null, false, $usuarioId);
    salvarIntegracao($pdo, $fonte, 'connected_at', date('Y-m-d H:i:s'), false, $usuarioId);
}

function rdTrocarCodePorToken(PDO $pdo, string $fonte, string $code, ?int $usuarioId = null): void
{
    $meta = rdFonte($fonte);
    $dados = rdHttpPostJson($meta['token'], [
        'client_id' => rdClientId($fonte),
        'client_secret' => rdClientSecret($fonte),
        'code' => $code,
        'redirect_uri' => rdCallbackUrl($fonte),
    ]);
    rdGuardarTokens($pdo, $fonte, $dados, $usuarioId);
}

/** Renova o access_token quando vencido. Devolve false se não houver como renovar. */
function rdRenovarToken(PDO $pdo, string $fonte): bool
{
    $refresh = integracaoValor($fonte, 'refresh_token');
    if ($refresh === '' || !rdPodeConectar($fonte)) return false;
    $meta = rdFonte($fonte);
    try {
        $dados = rdHttpPostJson($meta['token'], [
            'client_id' => rdClientId($fonte),
            'client_secret' => rdClientSecret($fonte),
            'refresh_token' => $refresh,
        ]);
        rdGuardarTokens($pdo, $fonte, $dados);
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** Token válido para chamar a API, renovando antes se necessário. */
function rdTokenValido(PDO $pdo, string $fonte): string
{
    if (rdTokenEstatico($fonte) !== '') return rdTokenEstatico($fonte);
    if (rdTokenExpirado($fonte)) rdRenovarToken($pdo, $fonte);
    return rdAccessToken($fonte);
}
