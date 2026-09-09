<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/integration_config.php';
require_once __DIR__ . '/../../includes/whatsapp_provider.php';
require_once __DIR__ . '/../../includes/ai_client.php';

header('Content-Type: application/json; charset=utf-8');
exigirPapel(PAPEL_ADMINISTRADOR);

function responderIntegracoes(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return array<string,mixed> */
function estadoIntegracoes(): array
{
    $config = integracaoConfigLocal();
    $valor = static function (string $chave, string $padrao = '') use ($config): string {
        if (array_key_exists($chave, $config)) return (string) $config[$chave];
        if (defined($chave)) return trim((string) constant($chave));
        $env = getenv($chave);
        return $env === false ? $padrao : trim((string) $env);
    };
    $aiEndpoint = $valor('DNB_AI_VPS_ENDPOINT');
    $aiToken = $valor('DNB_AI_VPS_TOKEN');
    $waEndpoint = $valor('DNB_WHATSAPP_SEND_URL');
    $waToken = $valor('DNB_WHATSAPP_API_TOKEN');
    return [
        'ok' => true,
        'csrf' => csrfToken(),
        'fields' => [
            'DNB_AI_VPS_ENDPOINT' => $aiEndpoint,
            'DNB_AI_TIMEOUT' => $valor('DNB_AI_TIMEOUT', '45'),
            'DNB_WHATSAPP_SEND_URL' => $waEndpoint,
            'DNB_WHATSAPP_TIMEOUT' => $valor('DNB_WHATSAPP_TIMEOUT', '30'),
        ],
        'secrets' => [
            'DNB_AI_VPS_TOKEN' => $aiToken !== '',
            'DNB_WHATSAPP_API_TOKEN' => $waToken !== '',
            'DNB_WHATSAPP_WEBHOOK_TOKEN' => $valor('DNB_WHATSAPP_WEBHOOK_TOKEN') !== '',
            'DNB_WHATSAPP_CRON_TOKEN' => $valor('DNB_WHATSAPP_CRON_TOKEN') !== '',
        ],
        'status' => [
            'ai' => $aiEndpoint !== '' && $aiToken !== '',
            'whatsapp' => $waEndpoint !== '' && $waToken !== '',
        ],
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    responderIntegracoes(estadoIntegracoes());
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') responderIntegracoes(['ok' => false, 'erro' => 'Método não permitido.'], 405);
exigirCsrf();

$raw = file_get_contents('php://input');
$dados = json_decode((string) $raw, true);
if (!is_array($dados)) $dados = $_POST;

$estadoAtual = estadoIntegracoes();
$camposAtuais = $estadoAtual['fields'];
$novos = [];

if (array_key_exists('DNB_AI_VPS_ENDPOINT', $dados)) {
    $aiEndpoint = trim((string) $dados['DNB_AI_VPS_ENDPOINT']);
    if ($aiEndpoint !== '') validarEndpointIa($aiEndpoint);
    $novos['DNB_AI_VPS_ENDPOINT'] = $aiEndpoint;
}
if (array_key_exists('DNB_WHATSAPP_SEND_URL', $dados)) {
    $waEndpoint = trim((string) $dados['DNB_WHATSAPP_SEND_URL']);
    if ($waEndpoint !== '') whatsappValidarUrlHttps($waEndpoint);
    $novos['DNB_WHATSAPP_SEND_URL'] = $waEndpoint;
}
if (array_key_exists('DNB_AI_TIMEOUT', $dados)) {
    $novos['DNB_AI_TIMEOUT'] = (string) max(5, min(120, (int) $dados['DNB_AI_TIMEOUT']));
}
if (array_key_exists('DNB_WHATSAPP_TIMEOUT', $dados)) {
    $novos['DNB_WHATSAPP_TIMEOUT'] = (string) max(5, min(60, (int) $dados['DNB_WHATSAPP_TIMEOUT']));
}

foreach (['DNB_AI_VPS_TOKEN','DNB_WHATSAPP_API_TOKEN','DNB_WHATSAPP_WEBHOOK_TOKEN','DNB_WHATSAPP_CRON_TOKEN'] as $segredo) {
    if (array_key_exists($segredo, $dados) && trim((string) $dados[$segredo]) !== '') {
        $novos[$segredo] = trim((string) $dados[$segredo]);
    }
}

if (!$novos) responderIntegracoes(array_merge($estadoAtual, ['mensagem' => 'Nenhuma alteração informada.']));

try {
    salvarIntegracaoConfig($novos);
    responderIntegracoes(array_merge(estadoIntegracoes(), ['mensagem' => 'Integrações atualizadas com segurança.']));
} catch (Throwable $e) {
    error_log('integrations.save: ' . $e->getMessage());
    responderIntegracoes(['ok' => false, 'erro' => $e->getMessage()], 422);
}
