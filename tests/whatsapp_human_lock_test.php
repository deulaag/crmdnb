<?php
declare(strict_types=1);
define('DNB_TESTING', true);
require_once __DIR__ . '/../includes/whatsapp_service.php';

function confirmarWhatsappLock(bool $condicao, string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR, "FALHA: {$mensagem}\n"); exit(1); }
}

$livre = ['assigned_to_user_id'=>null,'ai_paused'=>0];
$travada = ['assigned_to_user_id'=>7,'ai_paused'=>1];
$vendedor = ['id'=>7,'role'=>PAPEL_VENDEDOR];
$outro = ['id'=>8,'role'=>PAPEL_SUPORTE];
$admin = ['id'=>1,'role'=>PAPEL_ADMINISTRADOR];

confirmarWhatsappLock(!whatsappTravaHumanaAtiva($livre), 'Conversa livre mantém a IA ativa.');
confirmarWhatsappLock(whatsappTravaHumanaAtiva($travada), 'Conversa assumida pausa a IA.');
confirmarWhatsappLock(usuarioPodeAlterarTravaWhatsapp($livre, $outro), 'Qualquer usuário autorizado pode assumir conversa livre.');
confirmarWhatsappLock(usuarioPodeAlterarTravaWhatsapp($travada, $vendedor), 'Atendente pode liberar a própria conversa.');
confirmarWhatsappLock(!usuarioPodeAlterarTravaWhatsapp($travada, $outro), 'Outro atendente não pode tomar conversa travada.');
confirmarWhatsappLock(usuarioPodeAlterarTravaWhatsapp($travada, $admin), 'Administrador pode intervir no Human Lock.');

echo "WhatsApp Human Lock: OK\n";
