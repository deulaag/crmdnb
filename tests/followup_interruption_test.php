<?php
declare(strict_types=1);
define('DNB_TESTING',true);
require_once __DIR__ . '/../includes/followup_service.php';

function confirmarInterrupcaoFollowup(bool $condicao,string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR,"FALHA: {$mensagem}\n"); exit(1); }
}

confirmarInterrupcaoFollowup(followupDeveInterromperPorEvento('incoming',42),'Mensagem recebida de lead vinculado deve interromper.');
confirmarInterrupcaoFollowup(!followupDeveInterromperPorEvento('outgoing',42),'Mensagem enviada não deve interromper.');
confirmarInterrupcaoFollowup(!followupDeveInterromperPorEvento('incoming',null),'Conversa sem lead não altera cadência.');

echo "Follow-up interruption: OK\n";
