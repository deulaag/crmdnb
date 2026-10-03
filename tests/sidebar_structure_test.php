<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$header = file_get_contents($root . '/includes/layout_header.php');
$style = file_get_contents($root . '/assets/crm-pilot-ui.css');

function verificar(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        fwrite(STDERR, "FALHA: {$mensagem}\n");
        exit(1);
    }
}

verificar($header !== false, 'layout_header.php deve estar acessível');
verificar($style !== false, 'crm-pilot-ui.css deve estar acessível');

verificar(str_contains($header, 'DNB · CRM Comercial'), 'cabeçalho deve reproduzir o título do HTML piloto');
verificar(str_contains($header, 'Políticas de comissão vigentes · metas derivadas por conversão · dados compartilhados entre a equipe'), 'subtítulo do HTML piloto deve estar presente');
verificar(str_contains($header, 'Mês de referência'), 'filtro mensal do HTML piloto deve estar presente');

foreach (['Painel','Negociações','Pedidos','Visitas','Cadência · Agente','Comissões','Configurações'] as $aba) {
    verificar(str_contains($header, "'{$aba}'"), "aba {$aba} ausente");
}

foreach (['Empresas','Contatos','IA central','WhatsApp','Casos de atendimento','Relatório do Painel','Log de Fluxos','Usuários'] as $extra) {
    verificar(str_contains($header, "'{$extra}'"), "módulo extra {$extra} deve permanecer acessível");
}

verificar(str_contains($header, '$papeisUsuario = papeisAtuais();'), 'RBAC deve continuar centralizado');
verificar(str_contains($header, 'array_intersect($papeisUsuario, $papeis)'), 'abas principais devem respeitar RBAC');
verificar(str_contains($header, 'array_intersect($papeisUsuario, $item[4])'), 'menu Mais deve respeitar RBAC');
verificar(str_contains($header, 'aria-current="page"'), 'aba ativa deve ser anunciada por tecnologia assistiva');
verificar(str_contains($header, "($k === 'neg' && $tabAtual === 'orc')"), 'Orçamentos deve manter Negociações ativa');

verificar(!str_contains($header, '<aside class="dnb-sidebar"'), 'sidebar antiga não deve fazer parte do shell novo');
verificar(str_contains($header, 'class="dnb-pilot-tabs"'), 'navegação deve ser horizontal como no HTML piloto');
verificar(str_contains($header, 'class="dnb-pilot-more'), 'módulos adicionais devem ficar no menu Mais');

foreach (['#003060','#a4c8ff','#5f6e7c','#f0f4f8'] as $cor) {
    verificar(str_contains(strtolower($style), strtolower($cor)), "cor {$cor} deve existir no tema piloto");
}
verificar(str_contains($style, '.dnb-pilot-tab.is-active'), 'aba ativa deve ter estado visual');
verificar(str_contains($style, '.dnb-pilot-content .bg-white.rounded-xl'), 'cards existentes devem seguir o modelo do HTML piloto');
verificar(str_contains($style, 'font-family: "Michroma"'), 'títulos devem respeitar a tipografia principal da marca');
verificar(str_contains($style, 'font-family: "Lato"'), 'textos devem respeitar a tipografia secundária da marca');
verificar(str_contains($style, '@media (max-width: 680px)'), 'layout deve permanecer responsivo');

fwrite(STDOUT, "Navegação horizontal DNB Pilot: OK\n");
