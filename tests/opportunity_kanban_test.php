<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$pagina=file_get_contents($root.'/orcamentos.php');
$javascript=file_get_contents($root.'/assets/ui.js');
$estilo=file_get_contents($root.'/assets/style.css');
function confirmarKanban(bool $condicao,string $mensagem): void
{
    if(!$condicao){fwrite(STDERR,"FALHA: {$mensagem}\n");exit(1);}
}
confirmarKanban($pagina!==false&&$javascript!==false&&$estilo!==false,'arquivos do Kanban devem estar acessíveis');
confirmarKanban(str_contains($pagina,'data-opportunity-kanban'),'página deve expor o Kanban de oportunidades');
confirmarKanban(str_contains($pagina,'data-kanban-stage'),'colunas devem identificar a etapa de destino');
confirmarKanban(str_contains($pagina,'data-kanban-stage-form'),'cartões devem manter formulário de fallback');
confirmarKanban(str_contains($pagina,"if(\$k!==\$filtrosKanban['funil']) continue"),'somente o funil escolhido deve ser renderizado');
confirmarKanban(str_contains($pagina,'exigirCsrf();'),'ações da página devem validar CSRF');
confirmarKanban(substr_count($pagina,'<form method="post"')===substr_count($pagina,'csrfInput()'),'todos os formulários POST devem enviar CSRF');
confirmarKanban(!str_contains($pagina,'min-w-[760px]'),'tabela larga antiga não deve permanecer como tela principal');
confirmarKanban(!str_contains($pagina,'overflow-x-auto'),'oportunidades não devem depender de rolagem lateral');
confirmarKanban(str_contains($estilo,'repeat(auto-fit, minmax(min(100%, 230px), 1fr))'),'colunas devem quebrar responsivamente');
confirmarKanban(str_contains($javascript,'function initOpportunityKanban'),'arrastar e soltar deve possuir inicialização progressiva');
echo "Opportunity Kanban PR3: OK\n";
