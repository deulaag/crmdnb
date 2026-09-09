<?php
declare(strict_types=1);

/**
 * Configuração local das integrações.
 * O arquivo integrations.local.php é ignorado pelo Git e deve permanecer
 * somente no servidor. Variáveis de ambiente continuam sendo aceitas como
 * fallback para instalações já configuradas no cPanel.
 */
const DNB_INTEGRATION_KEYS = [
    'DNB_AI_VPS_ENDPOINT',
    'DNB_AI_VPS_TOKEN',
    'DNB_AI_TIMEOUT',
    'DNB_WHATSAPP_SEND_URL',
    'DNB_WHATSAPP_API_TOKEN',
    'DNB_WHATSAPP_WEBHOOK_TOKEN',
    'DNB_WHATSAPP_CRON_TOKEN',
    'DNB_WHATSAPP_TIMEOUT',
];

function integracaoConfigPath(): string
{
    return dirname(__DIR__) . '/integrations.local.php';
}

/** @return array<string,string> */
function integracaoConfigLocal(): array
{
    $arquivo = integracaoConfigPath();
    if (!is_file($arquivo)) return [];
    $dados = require $arquivo;
    if (!is_array($dados)) return [];
    $saida = [];
    foreach (DNB_INTEGRATION_KEYS as $chave) {
        if (array_key_exists($chave, $dados)) $saida[$chave] = trim((string) $dados[$chave]);
    }
    return $saida;
}

function integracaoConfigValor(string $chave, string $padrao = ''): string
{
    $local = integracaoConfigLocal();
    if (array_key_exists($chave, $local)) return (string) $local[$chave];
    $ambiente = getenv($chave);
    return $ambiente === false ? $padrao : trim((string) $ambiente);
}

/** @param array<string,string> $novos */
function salvarIntegracaoConfig(array $novos): void
{
    $atual = integracaoConfigLocal();
    foreach ($novos as $chave => $valor) {
        if (!in_array($chave, DNB_INTEGRATION_KEYS, true)) continue;
        $atual[$chave] = trim((string) $valor);
    }

    $conteudo = "<?php\n// Gerado pelo DNB CRM. Não versionar este arquivo.\nreturn "
        . var_export($atual, true) . ";\n";
    $arquivo = integracaoConfigPath();
    $tmp = $arquivo . '.tmp-' . bin2hex(random_bytes(6));
    if (@file_put_contents($tmp, $conteudo, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível gravar a configuração local de integrações. Verifique as permissões do servidor.');
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $arquivo)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível ativar a nova configuração de integrações.');
    }
    @chmod($arquivo, 0600);
}
