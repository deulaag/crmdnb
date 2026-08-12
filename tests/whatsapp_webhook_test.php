<?php
declare(strict_types=1);
define('DNB_TESTING', true);
require_once __DIR__ . '/../includes/whatsapp_service.php';

function confirmarWhatsappWebhook(bool $condicao, string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR, "FALHA: {$mensagem}\n"); exit(1); }
}

$evento = normalizarEventoWhatsapp([
    'instance'=>['id'=>'dnb-bahia','name'=>'DNB Bahia'],
    'message'=>['id'=>'msg-42','text'=>'Gostaria de um orçamento','from'=>'5571999999999@s.whatsapp.net','contact_name'=>'Cliente Teste'],
    'from_me'=>false,
    'lead_id'=>42,
]);
confirmarWhatsappWebhook($evento['instance_external_id'] === 'dnb-bahia', 'Instância deve ser normalizada.');
confirmarWhatsappWebhook($evento['provider_message_id'] === 'msg-42', 'ID da mensagem garante idempotência.');
confirmarWhatsappWebhook($evento['phone'] === '5571999999999', 'Telefone deve conter somente dígitos.');
confirmarWhatsappWebhook($evento['direction'] === 'incoming', 'Mensagem do contato deve ser de entrada.');
confirmarWhatsappWebhook($evento['lead_id'] === 42, 'Lead informado deve ser preservado.');
confirmarWhatsappWebhook(whatsappTokenValido('segredo-teste', 'segredo-teste'), 'Token correto deve ser aceito.');
confirmarWhatsappWebhook(!whatsappTokenValido('segredo-teste', 'incorreto'), 'Token incorreto deve ser rejeitado.');

$falhou = false;
try { normalizarEventoWhatsapp(['instance_id'=>'x','phone'=>'5571','text'=>'sem id']); }
catch (InvalidArgumentException $e) { $falhou = true; }
confirmarWhatsappWebhook($falhou, 'Webhook sem ID da mensagem deve ser rejeitado.');

echo "WhatsApp webhook: OK\n";
