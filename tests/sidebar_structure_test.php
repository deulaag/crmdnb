<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$header = file_get_contents($root . '/includes/layout_header.php');
$style = file_get_contents($root . '/assets/style.css');

function verificar(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        fwrite(STDERR, "FALHA: {$mensagem}\n");
        exit(1);
    }
}

verificar($header !== false, 'layout_header.php deve estar acessível');
verificar($style !== false, 'style.css deve estar acessível');

foreach (['Atendimento', 'CRM', 'Agente de IA', 'Análise', 'Gestão'] as $grupo) {
    verificar(str_contains($header, "'rotulo' => '{$grupo}'"), "grupo {$grupo} ausente");
}

$rotas = [
    'painel.php',
    'empresas.php',
    'contatos.php',
    'negociacoes.php',
    'pedidos.php',
    'visitas.php',
    'cadencia.php',
    'comissoes.php',
    'ia.php',
    'chat.php',
    'casos.php',
    'usuarios.php',
    'configuracoes.php',
];
foreach ($rotas as $rota) {
    verificar(substr_count($header, "'{$rota}'") === 1, "rota {$rota} deve existir uma única vez na configuração");
}

verificar(!str_contains($header, "['orc', 'Orçamentos', 'orcamentos.php'"), 'Orçamentos não deve permanecer como item isolado da lateral');
verificar(str_contains($header, "['neg', 'Negociações', 'negociacoes.php'"), 'Negociações deve ser o módulo comercial principal');
verificar(str_contains($header, "($k === 'neg' && $tabAtual === 'orc')"), 'Orçamentos deve manter Negociações ativo na lateral');

verificar(str_contains($header, "['cad', 'Follow-ups', 'cadencia.php'"), 'Cadência deve aparecer como Follow-ups sem alterar sua rota');
verificar(str_contains($header, '$papeisUsuario = papeisAtuais();'), 'perfis do usuário devem ser calculados uma única vez');
verificar(str_contains($header, 'array_intersect($papeisUsuario, $item[4])'), 'itens dos grupos devem respeitar RBAC');
verificar(str_contains($header, 'array_intersect($papeisUsuario, $papeis)'), 'Configurações deve respeitar RBAC');
verificar(str_contains($header, 'aria-current="page"'), 'item ativo deve ser anunciado por tecnologia assistiva');
verificar(str_contains($header, 'dnb-nav__link--footer'), 'Configurações deve permanecer no rodapé');
verificar(str_contains($style, '--dnb-sidebar-active: #477247;'), 'cor verde da seleção deve estar centralizada em um token');
verificar(str_contains($style, '.dnb-nav__group'), 'sidebar deve possuir estilo para grupos');
verificar(str_contains($style, '.dnb-nav__link:focus-visible'), 'links da sidebar devem possuir foco visível');

fwrite(STDOUT, "Sidebar agrupada: OK\n");
