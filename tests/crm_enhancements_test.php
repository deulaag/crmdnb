<?php
declare(strict_types=1);

$root = dirname(__DIR__);
function arquivo(string $path): string
{
    global $root;
    $conteudo = file_get_contents($root . '/' . $path);
    if ($conteudo === false) { fwrite(STDERR, "FALHA: arquivo {$path} não encontrado\n"); exit(1); }
    return $conteudo;
}
function ok(bool $condicao, string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR, "FALHA: {$mensagem}\n"); exit(1); }
}

$rbac = arquivo('includes/rbac.php');
$painel = arquivo('painel.php');
$footer = arquivo('includes/layout_footer.php');
$enhancements = arquivo('assets/crm-enhancements.js');
$perfil = arquivo('perfil.php');
$comissoes = arquivo('comissoes.php');
$integracoes = arquivo('api/v1/integrations.php');

ok(str_contains($rbac, "script === 'auditoria.php'"), 'auditoria deve possuir guarda administrativa centralizada');
ok(str_contains($rbac, "['importar_json', 'importar_rdstation_csv']"), 'importações em massa devem possuir guarda administrativa');
ok(str_contains($rbac, 'function ehOperadorVendedor'), 'RBAC deve distinguir vendedor operacional de gestão');
ok(str_contains($painel, 'if (ehOperadorVendedor())'), 'Farol de metas deve ser exclusivo do operador vendedor');
ok(str_contains($painel, 'if (temPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR))'), 'KPIs automáticos devem ser exclusivos de ADM/Supervisor');
ok(str_contains($footer, 'crm-enhancements.js'), 'shell deve carregar melhorias globais do CRM');
ok(str_contains($enhancements, 'initKanbanAjaxFilters'), 'Kanban deve possuir atualização de filtros sem recarga completa');
ok(str_contains($enhancements, 'reorderOpportunityAboveFilters'), 'Nova oportunidade deve ser posicionada antes dos filtros');
ok(str_contains($enhancements, 'addCrmInfoButtons'), 'módulos CRM devem receber botões de informação');
ok(str_contains($enhancements, 'initIntegrationEditor'), 'Integrações devem possuir editor funcional');
ok(str_contains($perfil, 'Qual seu sentimento hoje?'), 'perfil deve conter reação de sentimento diário');
ok(str_contains($perfil, "['tristeza','alegria','loucura']"), 'perfil deve aceitar as três reações solicitadas');
ok(str_contains($integracoes, 'salvarIntegracaoConfig'), 'API administrativa deve persistir configuração local');
ok(str_contains($comissoes, 'actor_user_id'), 'relatório deve usar usuário executor para rastrear movimentações');
ok(is_file($root . '/sql/migrations/20260908_perfil_humor.sql'), 'migração de perfil/humor deve existir');
ok(is_file($root . '/sql/migrations/20260908_timeline_actor.sql'), 'migração de movimentações deve existir');

echo "CRM enhancements: OK\n";
