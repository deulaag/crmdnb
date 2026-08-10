<?php
declare(strict_types=1);
define('DNB_TESTING',true);
require_once __DIR__ . '/../includes/rbac.php';

function confirmarRbacMulticargo(bool $condicao,string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR,"FALHA: {$mensagem}\n"); exit(1); }
}

$_SESSION['usuario']=['id'=>7,'nome'=>'Multi','email'=>'multi@example.test','status'=>'active','role'=>'VENDEDOR','papeis'=>['VENDEDOR','SUPORTE']];
confirmarRbacMulticargo(temPapel(PAPEL_VENDEDOR),'O cargo comercial deve liberar o escopo de Vendedor.');
confirmarRbacMulticargo(temPapel(PAPEL_SUPORTE),'O segundo cargo deve liberar a fila de Suporte.');
confirmarRbacMulticargo(podeGerenciarCasos(),'Vendedor com cargo de Suporte deve poder gerenciar casos.');
confirmarRbacMulticargo(papeisPodemVisualizarConversaWhatsapp(['VENDEDOR','SUPORTE'],7,9,[],true),'Qualquer cargo autorizado deve liberar a conversa.');
confirmarRbacMulticargo(paginaInicialPorPapel()==='/painel.php','Usuário comercial e Suporte deve iniciar no painel.');

$_SESSION['usuario']['papeis']=['ADMINISTRADOR','VENDEDOR'];
confirmarRbacMulticargo(papelAtual()===PAPEL_ADMINISTRADOR,'Administrador deve prevalecer em contexto multi-cargo.');
confirmarRbacMulticargo(podeReatribuirLead(),'Administrador multi-cargo mantém acesso total.');

echo "RBAC multi-cargo: OK\n";
