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
$header = arquivo('includes/layout_header.php');
$footer = arquivo('includes/layout_footer.php');
$enhancements = arquivo('assets/crm-enhancements.js');
$perfil = arquivo('perfil.php');
$profileService = arquivo('includes/profile_service.php');
$leadService = arquivo('includes/lead_service.php');
$comissoes = arquivo('comissoes.php');
$integracoes = arquivo('api/v1/integrations.php');
$opportunities = arquivo('api/v1/opportunities.php');
$taskService = arquivo('includes/task_service.php');
$taskSettings = arquivo('api/v1/task-settings.php');
$taskCron = arquivo('api/internal/tasks/remind.php');

ok(str_contains($rbac, '$modulosSomenteAdmin'), 'RBAC deve centralizar módulos exclusivos do ADM');
foreach (['chat.php','casos.php','ia.php','cadencia.php','auditoria.php','usuarios.php','configuracoes.php'] as $paginaAdmin) {
    ok(str_contains($rbac, "'{$paginaAdmin}'"), "{$paginaAdmin} deve ser protegido como módulo ADM");
}
ok(str_contains($rbac, "['importar_json', 'importar_rdstation_csv']"), 'importações em massa devem possuir guarda administrativa');
ok(str_contains($rbac, 'function ehOperadorVendedor'), 'RBAC deve distinguir vendedor operacional de gestão');
ok(str_contains($painel, 'if (ehOperadorVendedor())'), 'Farol de metas deve ser exclusivo do operador vendedor');
ok(str_contains($painel, 'Tarefas dos orçamentos'), 'Painel deve exibir tarefas comerciais');
ok(!str_contains($painel, 'KPIs automáticos (gatilhos de bonificação'), 'bloco antigo de KPIs automáticos deve sair do Painel');
ok(str_contains($painel, 'csrf_token'), 'Painel de tarefas deve disponibilizar CSRF para conclusão');
ok(str_contains($header, "['wpp', 'WhatsApp', 'chat.php', 'whatsapp', [PAPEL_ADMINISTRADOR]]"), 'Atendimento deve aparecer apenas ao ADM');
ok(str_contains($header, "['ia', 'IA central', 'ia.php', 'ai', [PAPEL_ADMINISTRADOR]]"), 'Agente de IA deve aparecer apenas ao ADM');
ok(str_contains($header, '$humorAtual'), 'cabeçalho global deve carregar sentimento do usuário');
ok(str_contains($footer, 'crm-enhancements.js?v=3.5.0'), 'shell deve carregar versão atual das melhorias do CRM');
ok(str_contains($enhancements, 'initKanbanAjaxFilters'), 'Kanban deve possuir atualização de filtros sem recarga completa');
ok(str_contains($enhancements, 'reorderOpportunityAboveFilters'), 'Nova oportunidade deve ser posicionada antes dos filtros');
ok(str_contains($enhancements, 'addCrmInfoButtons'), 'módulos CRM devem receber botões de informação');
ok(str_contains($enhancements, 'initDirectIntegrationEditors'), 'cartões de integração devem possuir edição direta');
ok(str_contains($enhancements, 'injectTaskFieldsIntoCreate'), 'novo orçamento deve permitir cadastrar tarefa e prazo');
ok(str_contains($enhancements, "result.stage === 'Fechamento'"), 'UI deve exibir modal ao entrar em Fechamento');
ok(str_contains($opportunities, "if (\$novaEtapa === 'Fechamento')"), 'API deve tratar Fechamento como conversão automática');
ok(str_contains($opportunities, 'SELECT id,numero FROM pedidos WHERE orcamento_id=?'), 'conversão automática deve impedir pedido duplicado');
ok(str_contains($opportunities, "status='Ganha'"), 'Fechamento deve marcar oportunidade como ganha');
ok(str_contains($taskService, 'function criarTarefaOrcamento'), 'serviço de tarefas deve criar tarefa vinculada ao orçamento');
ok(str_contains($taskSettings, 'reminder_template'), 'ADM deve poder editar template de lembrete');
ok(str_contains($taskCron, 'criarNotificacao'), 'cron de tarefa deve gerar notificação interna');
ok(str_contains($perfil, 'Qual seu sentimento hoje?'), 'perfil deve conter reação de sentimento diário');
ok(str_contains($perfil, "['tristeza','alegria','loucura']"), 'perfil deve aceitar as três reações solicitadas');
ok(str_contains($profileService, "'alegria' => '😄'"), 'serviço global deve mapear sentimento para emoji');
ok(str_contains($leadService, 'nomeUsuarioComHumor'), 'ações da timeline devem registrar nome com sentimento');
ok(str_contains($integracoes, 'salvarIntegracaoConfig'), 'API administrativa deve persistir configuração local');
ok(str_contains($comissoes, 'actor_user_id'), 'relatório deve usar usuário executor para rastrear movimentações');
ok(is_file($root . '/sql/migrations/20260908_perfil_humor.sql'), 'migração de perfil/humor deve existir');
ok(is_file($root . '/sql/migrations/20260908_timeline_actor.sql'), 'migração de movimentações deve existir');
ok(is_file($root . '/sql/migrations/20260909_crm_tasks.sql'), 'migração de tarefas deve existir');

echo "CRM enhancements: OK\n";
