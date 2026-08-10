<?php
declare(strict_types=1);
define('DNB_TESTING',true);
require_once __DIR__ . '/../includes/followup_service.php';

function confirmarIdempotenciaFollowup(bool $condicao,string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR,"FALHA: {$mensagem}\n"); exit(1); }
}

$a = followupChaveTentativa(42,7,1);
confirmarIdempotenciaFollowup($a===followupChaveTentativa(42,7,1),'Reexecução deve produzir a mesma chave.');
confirmarIdempotenciaFollowup($a!==followupChaveTentativa(42,7,2),'Nova tentativa deve produzir outra chave.');
confirmarIdempotenciaFollowup(followupStatusBloqueiaNovaTentativa('sent'),'Envio confirmado deve bloquear duplicata.');
confirmarIdempotenciaFollowup(followupStatusBloqueiaNovaTentativa('delivery_unknown'),'Envio sem confirmação não deve ser repetido automaticamente.');
confirmarIdempotenciaFollowup(!followupStatusBloqueiaNovaTentativa('failed'),'Falha de IA pode ser retomada com limite.');

echo "Follow-up idempotency: OK\n";
