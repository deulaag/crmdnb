<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$pagina=file_get_contents($root.'/auditoria.php');
$servico=file_get_contents($root.'/includes/followup_flow_service.php');
$migration=file_get_contents($root.'/sql/migrations/20260812_followup_flows_pr3.sql');
function confirmarAuditoriaPr3(bool $condicao,string $mensagem): void
{
    if(!$condicao){fwrite(STDERR,"FALHA: {$mensagem}\n");exit(1);}
}
confirmarAuditoriaPr3($pagina!==false&&$servico!==false&&$migration!==false,'arquivos de auditoria devem estar acessíveis');
confirmarAuditoriaPr3(str_contains($pagina,'exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_SUPORTE)'),'Audit Log deve restringir papéis no servidor');
confirmarAuditoriaPr3(str_contains($servico,'function escopoAuditoriaFluxoPr3'),'auditoria deve aplicar escopo por papel');
confirmarAuditoriaPr3(str_contains($servico,'support_cases sc_scope'),'suporte deve enxergar somente execuções ligadas a casos permitidos');
confirmarAuditoriaPr3(str_contains($servico,"LIMIT '.\$limite.' OFFSET '.\$offset"),'lista deve paginar no servidor');
foreach(['version','node_type','funnel','stage','owner_id','q'] as $filtro) confirmarAuditoriaPr3(str_contains($servico,"\$filtros['{$filtro}']"),'filtro '.$filtro.' deve ser processado no servidor');
confirmarAuditoriaPr3(str_contains($pagina,'dnb-audit-graph'),'detalhe deve exibir o grafo publicado em modo de leitura');
confirmarAuditoriaPr3(str_contains($pagina,'dnb-audit-timeline'),'detalhe deve manter timeline textual acessível');
confirmarAuditoriaPr3(str_contains($servico,'sanitizarAuditoriaFollowupPr3'),'eventos devem sanitizar resumos');
confirmarAuditoriaPr3(str_contains($migration,'followup_flow_audit_events'),'mudanças operacionais do fluxo devem ser append-only');
confirmarAuditoriaPr3(!preg_match('/DROP\s+(TABLE|COLUMN)|TRUNCATE/i',$migration),'migration não pode apagar estrutura ou auditoria');
echo "Follow-up audit PR3: OK\n";
