<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/cargo_service.php';

function confirmarComissaoCargo(bool $condicao,string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR,"FALHA: {$mensagem}\n"); exit(1); }
}

confirmarComissaoCargo(registroComissionavelPorCargo(['cargo_has_commission'=>1,'cargo_title'=>CARGO_VENDEDOR_EXTERNO]),'Cargo comissionável deve alimentar a apuração.');
confirmarComissaoCargo(!registroComissionavelPorCargo(['cargo_has_commission'=>0,'cargo_title'=>CARGO_ADMINISTRADOR]),'Administrador nunca deve gerar comissão.');
confirmarComissaoCargo(!registroComissionavelPorCargo(['cargo_has_commission'=>1,'cargo_title'=>CARGO_ADMINISTRADOR]),'A precedência do Administrador deve bloquear comissão mesmo com dado inconsistente.');
confirmarComissaoCargo(!registroComissionavelPorCargo(['cargo_has_commission'=>1,'cargo_title'=>'']), 'Registro sem contexto de cargo não deve ser comissionado.');

echo "Comissão por cargo: OK\n";
