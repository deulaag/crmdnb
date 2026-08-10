<?php
declare(strict_types=1);
define('DNB_TESTING', true);
require_once __DIR__ . '/../includes/whatsapp_service.php';

function confirmarWhatsappBuffer(bool $condicao, string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR, "FALHA: {$mensagem}\n"); exit(1); }
}

$ultima = new DateTimeImmutable('2026-08-09 12:00:02');
confirmarWhatsappBuffer(whatsappBufferDisponivelEm($ultima)->format('H:i:s') === '12:00:07', 'Buffer deve aguardar cinco segundos após a última mensagem.');
$agrupada = agruparMensagensWhatsapp([
    ['message_body'=>'Olá','media_url'=>null],
    ['message_body'=>'Preciso de uma proposta','media_url'=>null],
    ['message_body'=>'','media_url'=>'https://example.test/anexo.pdf'],
]);
confirmarWhatsappBuffer($agrupada === "Olá\nPreciso de uma proposta\n[Mídia recebida: https://example.test/anexo.pdf]", 'Mensagens sequenciais devem ser agrupadas em ordem.');

echo "WhatsApp buffer: OK\n";
