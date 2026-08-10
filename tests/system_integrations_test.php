<?php
declare(strict_types=1);
/** Cifra, precedência e mascaramento das configurações de integração. */
define('DNB_TESTING', true);
define('DNB_CONFIG_KEY', 'chave-de-teste-nao-usar-em-producao-0123456789');

require_once __DIR__ . '/../includes/settings_service.php';

function confirmarIntegracao(bool $condicao, string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR, "FALHA: {$mensagem}\n"); exit(1); }
}

// --- Cifra ------------------------------------------------------------------
confirmarIntegracao(configKeyDisponivel(), 'Chave mestra disponível com DNB_CONFIG_KEY definida.');

$segredo = 'token-super-secreto-da-vps-9f3a';
$cifrado = cifrarSegredo($segredo);
confirmarIntegracao(str_starts_with($cifrado, INTEGRACAO_PREFIXO_CIFRA), 'Valor cifrado carrega o prefixo de versão.');
confirmarIntegracao(!str_contains($cifrado, $segredo), 'O texto original não aparece no valor cifrado.');
confirmarIntegracao(decifrarSegredo($cifrado) === $segredo, 'Round-trip de cifra devolve o valor original.');

// Duas cifras do mesmo texto diferem: o IV é aleatório por gravação.
confirmarIntegracao(cifrarSegredo($segredo) !== $cifrado, 'Cifrar duas vezes produz saídas distintas.');

// --- Autenticação GCM -------------------------------------------------------
$corpo = base64_decode(substr($cifrado, strlen(INTEGRACAO_PREFIXO_CIFRA)), true);
$adulterado = INTEGRACAO_PREFIXO_CIFRA . base64_encode(substr($corpo, 0, -1) . chr(ord(substr($corpo, -1)) ^ 0xFF));
confirmarIntegracao(decifrarSegredo($adulterado) === '', 'Payload adulterado é rejeitado pela tag GCM.');
confirmarIntegracao(decifrarSegredo(INTEGRACAO_PREFIXO_CIFRA . 'nao-e-base64-valido!!') === '', 'Base64 inválido não explode.');
confirmarIntegracao(decifrarSegredo('') === '', 'Valor vazio devolve string vazia.');

// Valor gravado à mão, sem prefixo, continua legível — não quebra linha legada.
confirmarIntegracao(decifrarSegredo('texto-legado-em-claro') === 'texto-legado-em-claro', 'Valor sem prefixo é tratado como texto legado.');

// --- Precedência ------------------------------------------------------------
// Sem tabela no ambiente de teste, integracaoValor cai na constante e no ambiente.
define('TESTE_CONSTANTE_INTEGRACAO', 'valor-da-constante');
confirmarIntegracao(
    integracaoValor('whatsapp', 'inexistente', 'TESTE_CONSTANTE_INTEGRACAO') === 'valor-da-constante',
    'Sem valor no banco, a constante local é usada.'
);

putenv('TESTE_ENV_INTEGRACAO=valor-do-ambiente');
confirmarIntegracao(
    integracaoValor('whatsapp', 'inexistente', 'TESTE_ENV_INTEGRACAO') === 'valor-do-ambiente',
    'Sem banco e sem constante, o ambiente é usado.'
);

confirmarIntegracao(
    integracaoValor('whatsapp', 'inexistente', 'NAO_EXISTE_EM_LUGAR_NENHUM', 'padrao') === 'padrao',
    'Sem nenhuma fonte, o padrão é devolvido.'
);

// --- Serviços aceitos -------------------------------------------------------
foreach (['whatsapp', 'ai_vps', 'rdstation_marketing', 'rdstation_crm'] as $servico) {
    confirmarIntegracao(in_array($servico, INTEGRACAO_SERVICOS, true), "Serviço {$servico} é reconhecido.");
}

// --- Mascaramento -----------------------------------------------------------
confirmarIntegracao(integracaoSegredoMascarado('') === '', 'Segredo ausente não vira máscara.');
confirmarIntegracao(integracaoSegredoMascarado('abc') === '•••', 'Segredo curto é totalmente mascarado.');
$mascara = integracaoSegredoMascarado('abcdefghijklmnop');
confirmarIntegracao(str_ends_with($mascara, 'mnop'), 'Segredo longo revela só os 4 últimos caracteres.');
confirmarIntegracao(!str_contains($mascara, 'abcdefghijkl'), 'Segredo longo não vaza o início.');

confirmarIntegracao(integracaoUrlMascarada('') === '—', 'URL vazia vira travessão.');
confirmarIntegracao(integracaoUrlMascarada('https://vps.exemplo.com/api/v1/segredo') === 'https://vps.exemplo.com/…', 'URL mascarada esconde o caminho.');
confirmarIntegracao(integracaoUrlMascarada('nao-e-url') === 'valor inválido', 'URL inválida é sinalizada.');

echo "Integrações do sistema: OK\n";
