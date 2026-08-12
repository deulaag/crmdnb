<?php
declare(strict_types=1);
define('DNB_TESTING', true);
require_once __DIR__ . '/../includes/rbac.php';

function confirmar(bool $condicao, string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR, "FALHA: {$mensagem}\n"); exit(1); }
}

$_SESSION['usuario'] = ['id'=>1,'nome'=>'Admin','email'=>'admin@example.test','role'=>PAPEL_ADMINISTRADOR,'status'=>'active'];
confirmar(papelAtual() === PAPEL_ADMINISTRADOR, 'Administrador deve manter o papel.');
confirmar(temPapel(PAPEL_ADMINISTRADOR), 'Administrador deve passar pela checagem de papel.');
confirmar(podeReatribuirLead(), 'Administrador pode reatribuir leads.');
confirmar(podeGerenciarCasos(), 'Administrador pode gerenciar casos.');

$_SESSION['usuario']['role'] = PAPEL_SUPERVISOR;
confirmar(podeReatribuirLead(), 'Supervisor pode reatribuir leads.');
confirmar(!podeGerenciarCasos(), 'Supervisor não executa a triagem de Suporte.');

$_SESSION['usuario']['role'] = PAPEL_VENDEDOR;
confirmar(!podeReatribuirLead(), 'Vendedor não pode reatribuir leads.');
confirmar(paginaInicialPorPapel() === '/painel.php', 'Vendedor inicia no painel.');

$_SESSION['usuario']['role'] = PAPEL_SUPORTE;
confirmar(podeGerenciarCasos(), 'Suporte pode gerenciar casos.');
confirmar(paginaInicialPorPapel() === '/casos.php', 'Suporte inicia em Casos.');

echo "RBAC: OK\n";
