<?php
/** Cliente HTTP autenticado para a VPS de IA. */
require_once __DIR__ . '/../config.php';

// Compatibilidade com instalações cujo config.php histórico contém somente o
// banco: a IA continua configurável por variáveis do ambiente do cPanel.
if (!defined('AI_VPS_ENDPOINT')) define('AI_VPS_ENDPOINT', getenv('DNB_AI_VPS_ENDPOINT') ?: '');
if (!defined('AI_VPS_TOKEN')) define('AI_VPS_TOKEN', getenv('DNB_AI_VPS_TOKEN') ?: '');
if (!defined('AI_REQUEST_TIMEOUT')) define('AI_REQUEST_TIMEOUT', max(5, (int) (getenv('DNB_AI_TIMEOUT') ?: 45)));
if (!defined('AI_MAX_PROMPT_LENGTH')) define('AI_MAX_PROMPT_LENGTH', 8000);

class AiClientException extends RuntimeException {}

function iaConfigurada(): bool
{
    return AI_VPS_ENDPOINT !== '' && AI_VPS_TOKEN !== '';
}

function validarEndpointIa(string $endpoint): void
{
    $partes = parse_url($endpoint);
    if ($partes === false || ($partes['scheme'] ?? '') !== 'https' || empty($partes['host'])) {
        throw new AiClientException('O endpoint da IA deve usar HTTPS e possuir um host válido.');
    }
}

function normalizarRespostaIa(array $dados): array
{
    $score = $dados['lead_score'] ?? [];
    if (is_numeric($score)) $score = ['score' => (int) $score, 'justification' => 'Pontuação retornada pela IA.'];
    if (!is_array($score)) $score = [];
    $valorScore = isset($score['score']) ? max(0, min(100, (int) $score['score'])) : null;
    $guardrail = $dados['guardrail'] ?? [];
    if (!is_array($guardrail)) $guardrail = ['status' => (string) $guardrail];

    return [
        'route' => mb_substr((string) ($dados['route'] ?? 'assistente_comercial'), 0, 80),
        'response' => trim((string) ($dados['response'] ?? $dados['answer'] ?? '')),
        'score' => $valorScore,
        'score_justification' => mb_substr((string) ($score['justification'] ?? ''), 0, 2000),
        'guardrail_status' => mb_substr((string) ($guardrail['status'] ?? $dados['guardrail_status'] ?? 'approved'), 0, 30),
        'guardrail_reason' => mb_substr((string) ($guardrail['reason'] ?? $dados['guardrail_reason'] ?? ''), 0, 1000),
        'escalated' => filter_var($dados['escalated'] ?? false, FILTER_VALIDATE_BOOL),
        'reason_blocked' => mb_substr((string) ($dados['reason_blocked'] ?? $guardrail['reason'] ?? ''), 0, 1000),
        'raw' => $dados,
    ];
}

function validarPayloadIa(array $payload): void
{
    if (($payload['contract_version'] ?? '') !== '1.0') throw new InvalidArgumentException('Versão do contrato de IA inválida.');
    if ((int) ($payload['lead']['id'] ?? 0) <= 0) throw new InvalidArgumentException('Lead ausente no payload de IA.');
    $mensagem = trim((string) ($payload['request']['message'] ?? ''));
    if ($mensagem === '' || mb_strlen($mensagem) > AI_MAX_PROMPT_LENGTH) throw new InvalidArgumentException('Mensagem inválida no payload de IA.');
    if ((int) ($payload['requested_by']['id'] ?? 0) <= 0) throw new InvalidArgumentException('Solicitante ausente no payload de IA.');
}

function enviarParaIaVps(array $payload): array
{
    validarPayloadIa($payload);
    if (!iaConfigurada()) {
        throw new AiClientException('Integração com a VPS ainda não configurada no cPanel.');
    }
    validarEndpointIa(AI_VPS_ENDPOINT);
    $url = rtrim(AI_VPS_ENDPOINT, '/') . '/api/v1/ai/route-and-process';
    $correlationId = (string) ($payload['correlation_id'] ?? bin2hex(random_bytes(16)));
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $headers = [
        'Authorization: Bearer ' . AI_VPS_TOKEN,
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Correlation-ID: ' . $correlationId,
        'User-Agent: DNB-CRM-cPanel/3.0',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => AI_REQUEST_TIMEOUT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $erro = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false) throw new AiClientException('Falha de comunicação com a VPS: ' . $erro);
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $json,
            'timeout' => AI_REQUEST_TIMEOUT, 'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $linha) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $linha, $m)) $status = (int) $m[1];
        }
        if ($body === false) throw new AiClientException('Falha de comunicação com a VPS.');
    }

    if ($status < 200 || $status >= 300) {
        throw new AiClientException('A VPS retornou HTTP ' . $status . '.');
    }
    $dados = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($dados)) throw new AiClientException('Resposta inválida recebida da VPS.');
    return normalizarRespostaIa($dados);
}
