<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pagina = file_get_contents($root . '/cadencia.php');
$servico = file_get_contents($root . '/includes/followup_service.php');
$javascript = file_get_contents($root . '/assets/ui.js');
$estilo = file_get_contents($root . '/assets/style.css');
$cabecalho = file_get_contents($root . '/includes/layout_header.php');
$rodape = file_get_contents($root . '/includes/layout_footer.php');

function confirmarCanvas(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        fwrite(STDERR, "FALHA: {$mensagem}\n");
        exit(1);
    }
}

foreach ([
    'cadencia.php' => $pagina,
    'followup_service.php' => $servico,
    'ui.js' => $javascript,
    'style.css' => $estilo,
    'layout_header.php' => $cabecalho,
    'layout_footer.php' => $rodape,
] as $arquivo => $conteudo) {
    confirmarCanvas($conteudo !== false, "{$arquivo} deve estar acessível");
}

confirmarCanvas(str_contains($pagina, 'data-followup-builder'), 'página deve expor o editor visual');
confirmarCanvas(str_contains($pagina, 'data-flow-viewport'), 'editor deve possuir viewport navegável');
foreach (['trigger', 'eligibility', 'ai', 'whatsapp', 'control'] as $no) {
    confirmarCanvas(str_contains($pagina, 'data-flow-node="' . $no . '"'), "nó {$no} ausente");
}
confirmarCanvas(substr_count($pagina, 'data-flow-next=') === 4, 'fluxo deve manter quatro conexões entre os cinco nós');
confirmarCanvas(str_contains($pagina, 'data-flow-fallback'), 'edição em tabela deve permanecer como fallback progressivo');
confirmarCanvas(substr_count($pagina, '<form') === substr_count($pagina, '</form>'), 'formulários da página devem permanecer balanceados');

foreach (['regra_id', 'inactivity_hours', 'min_score', 'max_attempts', 'is_active'] as $campo) {
    confirmarCanvas(substr_count($pagina, 'name="' . $campo . '"') >= 2, "campo legado {$campo} deve existir no canvas e no fallback");
}
confirmarCanvas(str_contains($pagina, "if (\$acao === 'salvar_regra')"), 'handler legado de salvamento deve permanecer');
confirmarCanvas(str_contains($pagina, 'salvarRegraFollowup('), 'canvas deve continuar salvando pelo serviço atual');
confirmarCanvas(
    str_contains($servico, 'UPDATE followup_rules SET inactivity_hours=?,min_score=?,max_attempts=?,is_active=?,updated_at=NOW() WHERE id=?'),
    'persistência do motor atual deve permanecer compatível'
);

confirmarCanvas(str_contains($javascript, 'function initFollowupBuilder'), 'JavaScript deve inicializar o editor');
confirmarCanvas(str_contains($javascript, 'function drawEdges'), 'JavaScript deve redesenhar conexões');
confirmarCanvas(str_contains($javascript, "storagePrefix = 'dnb-followup-layout-v1:'"), 'posições visuais devem usar armazenamento local versionado');
confirmarCanvas(str_contains($estilo, '.dnb-flow-canvas'), 'canvas deve possuir estilos próprios');
confirmarCanvas(str_contains($estilo, '.dnb-flow-port'), 'pontos de conexão devem estar visíveis');
confirmarCanvas(str_contains($estilo, '.dnb-flow-edge--pulse'), 'conexões devem ter tratamento visual');
confirmarCanvas(str_contains($cabecalho, '/assets/style.css?v=3.6.0'), 'CSS deve usar a nova versão de cache');
confirmarCanvas(str_contains($rodape, '/assets/ui.js?v=3.6.0'), 'JavaScript deve usar a nova versão de cache');

fwrite(STDOUT, "Follow-up visual canvas: OK\n");
