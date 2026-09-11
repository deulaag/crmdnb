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

function validarUrlHttpsIntegracao(string $url, string $campo): void
{
    if ($url === '') return;
    $p = parse_url($url);
    if ($p === false || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
        throw new InvalidArgumentException($campo . ' deve ser uma URL HTTPS válida.');
    }
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
    $bool = static fn(string $chave, bool $padrao=false): bool => in_array(mb_strtolower($valor($chave, $padrao ? '1' : '0')), ['1','true','yes','sim','on'], true);

    $aiEndpoint = $valor('DNB_AI_VPS_ENDPOINT');
    $aiToken = $valor('DNB_AI_VPS_TOKEN');
    $waEndpoint = $valor('DNB_WHATSAPP_SEND_URL');
    $waToken = $valor('DNB_WHATSAPP_API_TOKEN');
    $osWebhook = $valor('DNB_OS_N8N_WEBHOOK_URL');
    $osToken = $valor('DNB_OS_N8N_TOKEN');

    return [
        'ok' => true,
        'csrf' => csrfToken(),
        'fields' => [
            'DNB_AI_VPS_ENDPOINT' => $aiEndpoint,
            'DNB_AI_TIMEOUT' => $valor('DNB_AI_TIMEOUT', '45'),
            'DNB_WHATSAPP_SEND_URL' => $waEndpoint,
            'DNB_WHATSAPP_TIMEOUT' => $valor('DNB_WHATSAPP_TIMEOUT', '30'),
            'DNB_OS_N8N_ENABLED' => $bool('DNB_OS_N8N_ENABLED', false) ? '1' : '0',
            'DNB_OS_N8N_WEBHOOK_URL' => $osWebhook,
            'DNB_OS_N8N_TIMEOUT' => $valor('DNB_OS_N8N_TIMEOUT', '120'),
            'DNB_OS_N8N_FALLBACK_LOCAL' => $bool('DNB_OS_N8N_FALLBACK_LOCAL', true) ? '1' : '0',
            'DNB_OS_EXTRACTOR_URL' => $valor('DNB_OS_EXTRACTOR_URL'),
            'DNB_OS_AZURE_ENDPOINT' => $valor('DNB_OS_AZURE_ENDPOINT'),
            'DNB_OS_AZURE_MODEL' => $valor('DNB_OS_AZURE_MODEL', 'prebuilt-layout'),
            'DNB_OS_AZURE_API_VERSION' => $valor('DNB_OS_AZURE_API_VERSION', '2024-11-30'),
            'DNB_OS_AI_VISUAL_ENABLED' => $bool('DNB_OS_AI_VISUAL_ENABLED', false) ? '1' : '0',
            'DNB_OS_AI_VISUAL_ENDPOINT' => $valor('DNB_OS_AI_VISUAL_ENDPOINT'),
            'DNB_OS_AI_VISUAL_MODEL' => $valor('DNB_OS_AI_VISUAL_MODEL'),
        ],
        'secrets' => [
            'DNB_AI_VPS_TOKEN' => $aiToken !== '',
            'DNB_WHATSAPP_API_TOKEN' => $waToken !== '',
            'DNB_WHATSAPP_WEBHOOK_TOKEN' => $valor('DNB_WHATSAPP_WEBHOOK_TOKEN') !== '',
            'DNB_WHATSAPP_CRON_TOKEN' => $valor('DNB_WHATSAPP_CRON_TOKEN') !== '',
            'DNB_OS_N8N_TOKEN' => $osToken !== '',
            'DNB_OS_EXTRACTOR_TOKEN' => $valor('DNB_OS_EXTRACTOR_TOKEN') !== '',
            'DNB_OS_AZURE_KEY' => $valor('DNB_OS_AZURE_KEY') !== '',
            'DNB_OS_AI_VISUAL_TOKEN' => $valor('DNB_OS_AI_VISUAL_TOKEN') !== '',
        ],
        'status' => [
            'ai' => $aiEndpoint !== '' && $aiToken !== '',
            'whatsapp' => $waEndpoint !== '' && $waToken !== '',
            'os_analyzer' => $bool('DNB_OS_N8N_ENABLED', false) && $osWebhook !== '' && $osToken !== '',
        ],
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') responderIntegracoes(estadoIntegracoes());
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') responderIntegracoes(['ok' => false, 'erro' => 'Método não permitido.'], 405);
exigirCsrf();

$raw = file_get_contents('php://input');
$dados = json_decode((string) $raw, true);
if (!is_array($dados)) $dados = $_POST;
$estadoAtual = estadoIntegracoes();
$novos = [];

foreach (['DNB_AI_VPS_ENDPOINT'=>'Endpoint IA','DNB_WHATSAPP_SEND_URL'=>'Endpoint WhatsApp','DNB_OS_N8N_WEBHOOK_URL'=>'Webhook n8n','DNB_OS_EXTRACTOR_URL'=>'Endpoint do Extrator OS','DNB_OS_AZURE_ENDPOINT'=>'Endpoint Azure Document Intelligence','DNB_OS_AI_VISUAL_ENDPOINT'=>'Endpoint da IA visual'] as $campo=>$rotulo) {
    if (!array_key_exists($campo, $dados)) continue;
    $url = trim((string)$dados[$campo]);
    if ($campo === 'DNB_AI_VPS_ENDPOINT' && $url !== '') validarEndpointIa($url);
    elseif ($campo === 'DNB_WHATSAPP_SEND_URL' && $url !== '') whatsappValidarUrlHttps($url);
    else validarUrlHttpsIntegracao($url, $rotulo);
    $novos[$campo] = $url;
}

foreach (['DNB_AI_TIMEOUT'=>[5,120,45],'DNB_WHATSAPP_TIMEOUT'=>[5,60,30],'DNB_OS_N8N_TIMEOUT'=>[15,240,120]] as $campo=>$limites) {
    if (!array_key_exists($campo, $dados)) continue;
    $novos[$campo] = (string)max($limites[0], min($limites[1], (int)$dados[$campo]));
}
foreach (['DNB_OS_N8N_ENABLED','DNB_OS_N8N_FALLBACK_LOCAL','DNB_OS_AI_VISUAL_ENABLED'] as $campo) {
    if (array_key_exists($campo, $dados)) $novos[$campo] = in_array((string)$dados[$campo], ['1','true','on','sim'], true) ? '1' : '0';
}
if (array_key_exists('DNB_OS_AZURE_MODEL', $dados)) $novos['DNB_OS_AZURE_MODEL'] = mb_substr(trim((string)$dados['DNB_OS_AZURE_MODEL']), 0, 80) ?: 'prebuilt-layout';
if (array_key_exists('DNB_OS_AZURE_API_VERSION', $dados)) {
    $v = trim((string)$dados['DNB_OS_AZURE_API_VERSION']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) throw new InvalidArgumentException('Versão da API Azure deve usar AAAA-MM-DD.');
    $novos['DNB_OS_AZURE_API_VERSION'] = $v;
}
if (array_key_exists('DNB_OS_AI_VISUAL_MODEL', $dados)) $novos['DNB_OS_AI_VISUAL_MODEL'] = mb_substr(trim((string)$dados['DNB_OS_AI_VISUAL_MODEL']), 0, 120);

foreach (['DNB_AI_VPS_TOKEN','DNB_WHATSAPP_API_TOKEN','DNB_WHATSAPP_WEBHOOK_TOKEN','DNB_WHATSAPP_CRON_TOKEN','DNB_OS_N8N_TOKEN','DNB_OS_EXTRACTOR_TOKEN','DNB_OS_AZURE_KEY','DNB_OS_AI_VISUAL_TOKEN'] as $segredo) {
    if (array_key_exists($segredo, $dados) && trim((string)$dados[$segredo]) !== '') $novos[$segredo] = trim((string)$dados[$segredo]);
}

if (!$novos) responderIntegracoes(array_merge($estadoAtual, ['mensagem' => 'Nenhuma alteração informada.']));
try {
    salvarIntegracaoConfig($novos);
    responderIntegracoes(array_merge(estadoIntegracoes(), ['mensagem' => 'Integrações atualizadas com segurança.']));
} catch (Throwable $e) {
    error_log('integrations.save: ' . $e->getMessage());
    responderIntegracoes(['ok' => false, 'erro' => $e->getMessage()], 422);
}
