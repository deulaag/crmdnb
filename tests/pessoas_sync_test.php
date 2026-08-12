<?php
declare(strict_types=1);

function confirmarPessoasSync(bool $condicao,string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR,"FALHA: {$mensagem}\n"); exit(1); }
}

$sql=(string)file_get_contents(__DIR__.'/../sql/migrations/20260810_crm_ia_rbac_cargos.sql');
confirmarPessoasSync(str_contains($sql,"dnb_cargos_add_column('usuarios','pessoa_id'"),'A migração deve criar usuarios.pessoa_id.');
confirmarPessoasSync(str_contains($sql,'INSERT INTO usuarios (nome,email,senha_hash'),'Pessoas sem login devem ser importadas como usuários inativos.');
confirmarPessoasSync(str_contains($sql,'UPDATE usuarios u')&&str_contains($sql,'JOIN vendedores v'),'Usuários existentes devem ser vinculados às pessoas legadas.');
confirmarPessoasSync(str_contains($sql,"'inactive'"),'Credenciais importadas não podem ser ativadas sem revisão administrativa.');
confirmarPessoasSync(str_contains($sql,"dnb_cargos_add_column('orcamentos','cargo_id'")&&str_contains($sql,"dnb_cargos_add_column('pedidos','cargo_id'"),'Propostas e pedidos devem preservar o cargo efetivo.');

echo "Sincronização de pessoas: OK\n";
