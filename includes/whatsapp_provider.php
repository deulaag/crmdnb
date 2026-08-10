<?php
declare(strict_types=1);

/** Adaptador HTTP genérico do provedor de WhatsApp. */
class WhatsappProviderException extends RuntimeException {}

$whatsappLocal = __DIR__ . '/../whatsapp.local.php';
if (is_file($whatsappLocal)) require_once $whatsappLocal;
require_once __DIR__ . '/settings_service.php';

/** Constante local correspondente a cada chave gravável pela tela de Configurações. */
const WHATSAPP_CHAVES_EDITAVEIS = [
    'DNB_WHATSAPP_SEND_URL' => 'send_url',
    'DNB_WHATSAPP_API_TOKEN' => 'api_token',
    'DNB_WHATSAPP_WEBHOOK_TOKEN' => 'webhook_token',
    'DNB_WHATSAPP_CRON_TOKEN' => 'cron_token',
    'DNB_WHATSAPP_TIMEOUT' => 'timeout',
];

/**
 * Valor efetivo de uma configuração do WhatsApp. O que estiver gravado em
 * system_integrations vence; na ausência dele valem a constante local e o ambiente,
 * exatamente como antes. Único ponto de leitura do provedor, do webhook e do cron.
 */
function whatsappEnv(string $nome, string $padrao = ''): string
{
    $chave = WHATSAPP_CHAVES_EDITAVEIS[$nome] ?? '';
    if ($chave !== '') {
        $valor = integracaoValor('whatsapp', $chave, $nome, $padrao);
        if ($valor !== '') return $valor;
        return $padrao;
    }
    if (defined($nome)) return trim((string) constant($nome));
    $valor = getenv($nome);
    return $valor === false ? $padrao : trim((string) $valor);
}

function whatsappProviderConfigurado(): bool
{
    return whatsappEnv('DNB_WHATSAPP_SEND_URL') !== '' && whatsappEnv('DNB_WHATSAPP_API_TOKEN') !== '';
}

function whatsappValidarUrlHttps(string $url): void
{
    $partes = parse_url($url);
    if ($partes === false || ($partes['scheme'] ?? '') !== 'https' || empty($partes['host'])) {
        throw new WhatsappProviderException('A URL do provedor de WhatsApp deve usar HTTPS.');
    }
}

/** @return array<string,mixed> */
function whatsappHttpPostJson(string $url, array $payload, array $headers = []): array
{
    whatsappValidarUrlHttps($url);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $headers = array_merge([
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: DNB-CRM-cPanel/3.1',
    ], $headers);
    $timeout = max(5, min(60, (int) (whatsappEnv('DNB_WHATSAPP_TIMEOUT', '30'))));

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $erro = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false) throw new WhatsappProviderException('Falha de comunicação com o provedor: ' . $erro);
    } else {
        $contexto = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $json,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $contexto);
        $status = 0;
        foreach (($http_response_header ?? []) as $linha) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $linha, $m)) $status = (int) $m[1];
        }
        if ($body === false) throw new WhatsappProviderException('Falha de comunicação com o provedor.');
    }

    if ($status < 200 || $status >= 300) {
        throw new WhatsappProviderException('O provedor de WhatsApp retornou HTTP ' . $status . '.');
    }
    if (trim((string) $body) === '') return [];
    $resposta = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($resposta)) throw new WhatsappProviderException('Resposta inválida do provedor de WhatsApp.');
    return $resposta;
}

/**
 * Contrato genérico de envio. Se o provedor exigir outro formato, altere apenas
 * este adaptador; RBAC, fila, webhook e Live Chat permanecem inalterados.
 *
 * @param array<string,mixed> $instancia
 * @param array<string,mixed> $contato
 * @return array<string,mixed>
 */
function enviarWhatsappProvider(array $instancia, array $contato, string $mensagem, ?string $mediaUrl = null): array
{
    if (!whatsappProviderConfigurado()) {
        throw new WhatsappProviderException('Provedor de WhatsApp ainda não configurado no cPanel.');
    }
    $payload = [
        'instance_id' => (string) $instancia['external_instance_id'],
        'to' => (string) $contato['phone'],
        'message' => $mensagem,
    ];
    if ($mediaUrl) $payload['media_url'] = $mediaUrl;
    return whatsappHttpPostJson(
        whatsappEnv('DNB_WHATSAPP_SEND_URL'),
        $payload,
        ['Authorization: Bearer ' . whatsappEnv('DNB_WHATSAPP_API_TOKEN')]
    );
}

/** @param array<string,mixed> $resposta */
function whatsappProviderMessageId(array $resposta): string
{
    foreach (['message_id', 'id', 'messageId'] as $chave) {
        if (!empty($resposta[$chave])) return mb_substr((string) $resposta[$chave], 0, 190);
    }
    if (!empty($resposta['data']) && is_array($resposta['data'])) {
        foreach (['message_id', 'id', 'messageId'] as $chave) {
            if (!empty($resposta['data'][$chave])) return mb_substr((string) $resposta['data'][$chave], 0, 190);
        }
    }
    return 'dnb-' . bin2hex(random_bytes(12));
}
