<?php
declare(strict_types=1);
define('DNB_TESTING', true);
require_once __DIR__ . '/../includes/rbac.php';

function confirmarWhatsappRbac(bool $condicao, string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR, "FALHA: {$mensagem}\n"); exit(1); }
}

confirmarWhatsappRbac(papelPodeVisualizarConversaWhatsapp(PAPEL_ADMINISTRADOR, 1, null), 'Administrador visualiza conversas sem lead.');
confirmarWhatsappRbac(papelPodeVisualizarConversaWhatsapp(PAPEL_SUPERVISOR, 2, 7, [2,7,8]), 'Supervisor visualiza vendedor da equipe.');
confirmarWhatsappRbac(!papelPodeVisualizarConversaWhatsapp(PAPEL_SUPERVISOR, 2, 9, [2,7,8]), 'Supervisor não visualiza outra equipe.');
confirmarWhatsappRbac(papelPodeVisualizarConversaWhatsapp(PAPEL_VENDEDOR, 7, 7), 'Vendedor visualiza o próprio lead.');
confirmarWhatsappRbac(!papelPodeVisualizarConversaWhatsapp(PAPEL_VENDEDOR, 7, 8), 'Vendedor não visualiza lead de outro usuário.');
confirmarWhatsappRbac(!papelPodeVisualizarConversaWhatsapp(PAPEL_VENDEDOR, 7, 7, [], false, false), 'Vendedor não visualiza conversa de lead encerrado.');
confirmarWhatsappRbac(papelPodeVisualizarConversaWhatsapp(PAPEL_SUPORTE, 10, 7, [], true), 'Suporte visualiza conversa com caso aberto.');
confirmarWhatsappRbac(!papelPodeVisualizarConversaWhatsapp(PAPEL_SUPORTE, 10, 7, [], false), 'Suporte não visualiza conversa sem caso aberto.');

echo "WhatsApp RBAC: OK\n";
