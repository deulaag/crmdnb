<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/cargo_service.php';

function confirmarCargo(bool $condicao, string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR, "FALHA: {$mensagem}\n"); exit(1); }
}

$papeis = papeisPorTitulosCargos([CARGO_VENDEDOR_EXTERNO,CARGO_SUPORTE_VENDAS_JR]);
confirmarCargo(in_array('VENDEDOR',$papeis,true) && in_array('SUPORTE',$papeis,true), 'Múltiplos cargos devem combinar grupos de acesso.');
confirmarCargo(papelPrincipalDosPapeis(['VENDEDOR','ADMINISTRADOR'])==='ADMINISTRADOR', 'Administrador deve ter precedência.');
confirmarCargo(papelPrincipalDosPapeis(['VENDEDOR','SUPERVISOR'])==='SUPERVISOR', 'Supervisor deve ter precedência no papel legado.');
confirmarCargo(count(TITULOS_CARGOS_SISTEMA)===8, 'O sistema deve declarar os oito cargos obrigatórios.');

echo "Cargos: OK\n";
