<?php
$root = dirname(__DIR__);
$required = [
    'install.php','login.php','painel.php','orcamentos.php','oportunidade.php','opportunity_reminders.php','pedidos.php','visitas.php','cronograma.php','cnpja_lookup.php','branding_asset.php',
    'cadencia.php','comissoes.php','clientes.php','empresas.php','rd_crm_oauth.php','rd_marketing_oauth.php','includes/rd_oauth_flow.php','configuracoes.php','usuarios.php','includes/bootstrap.php','includes/auth.php',
    'includes/layout.php','includes/security.php','session_refresh.php','assets/css/app.css','assets/js/app.js','assets/js/auth.js','assets/js/ui.js','assets/js/ai-cmd.js','assets/dnb-logo.png','config/database.example.php'
];

foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) {
        fwrite(STDERR, "Arquivo obrigatório ausente: {$file}\n");
        exit(1);
    }
}

$install = file_get_contents($root . '/install.php');
foreach (['opportunities','orders','visits','agenda','companies','contacts'] as $table) {
    if (preg_match('/INSERT\s+INTO\s+' . preg_quote($table, '/') . '\b/i', $install)) {
        fwrite(STDERR, "O instalador não pode criar registros comerciais em {$table}.\n");
        exit(1);
    }
}

$all = '';
foreach (glob($root . '/*.php') as $file) {
    $all .= file_get_contents($file);
}

if (str_contains($all, '.env')) {
    $clean = str_replace(['Não usamos <code>.env</code>.','Sem <code>.env</code>:'], '', $all);
    if (str_contains($clean, '.env')) {
        fwrite(STDERR, "Uso indevido de .env encontrado.\n");
        exit(1);
    }
}

$login = file_get_contents($root . '/login.php');
foreach (['sys_dnb_crm_user','sys_dnb_crm_pass','cf-turnstile-response','redirect_to'] as $needle) {
    if (!str_contains($login, $needle)) {
        fwrite(STDERR, "Login sem requisito de segurança: {$needle}\n");
        exit(1);
    }
}

$bootstrap = file_get_contents($root . '/includes/bootstrap.php');
foreach (["session_name('DNBCRMSESSID')", "'secure' => true", "'httponly' => true", "'samesite' => 'Lax'"] as $needle) {
    if (!str_contains($bootstrap, $needle)) {
        fwrite(STDERR, "Cookie de sessão fora do padrão: {$needle}\n");
        exit(1);
    }
}

if (preg_match("/['\"]domain['\"]\s*=>/i", $bootstrap)) {
    fwrite(STDERR, "Cookie de sessão não pode definir Domain amplo.\n");
    exit(1);
}

$confirmSearch = file_get_contents($root . '/visitas.php') . file_get_contents($root . '/orcamentos.php');
if (str_contains($confirmSearch, 'confirm(')) {
    fwrite(STDERR, "Confirmações nativas encontradas; use modal da UI.\n");
    exit(1);
}

$layout = file_get_contents($root . '/includes/layout.php');
foreach (['data-notification-modal','data-confirm-modal','assets/js/ui.js'] as $needle) {
    if (!str_contains($layout, $needle)) {
        fwrite(STDERR, "Layout sem padrão global de modal: {$needle}\n");
        exit(1);
    }
}

$functions = file_get_contents($root . '/includes/functions.php');
foreach (['branding_settings','branding_logo_path','branding_logo_url','branding_save_logo_upload'] as $needle) {
    if (!str_contains($functions, 'function ' . $needle)) {
        fwrite(STDERR, "Função de branding ausente: {$needle}\n");
        exit(1);
    }
}

$configPage = file_get_contents($root . '/configuracoes.php');
foreach (['name="portal_logo"','name="pdf_logo"','enctype="multipart/form-data"'] as $needle) {
    if (!str_contains($configPage, $needle)) {
        fwrite(STDERR, "Configuração de logo ausente: {$needle}\n");
        exit(1);
    }
}

$quotes = file_get_contents($root . '/orcamentos.php');
foreach ([
    'Triagem de leads das visitas',
    'FUNIS DE VENDA · OPORTUNIDADES ABERTAS',
    'IMPORTAR DO COTADOR DNB (JSON)',
    'IMPORTAR ORÇAMENTO DO ERP (PDF)',
    'SINCRONIZAR RELATÓRIO SEMANAL SALESFORCE (AGENCIADOS IR)'
] as $needle) {
    if (!str_contains($quotes, $needle)) {
        fwrite(STDERR, "Modelo de Orçamentos incompleto: {$needle}\n");
        exit(1);
    }
}

if (str_contains($quotes, 'name="dnb_commission_estimated"')) {
    fwrite(STDERR, "Campo manual de comissão estimada não pode existir em Orçamentos.\n");
    exit(1);
}

$orders = file_get_contents($root . '/pedidos.php');
if (str_contains($orders, 'name="dnb_commission"')) {
    fwrite(STDERR, "Campo manual de comissão DNB não pode existir em Pedidos.\n");
    exit(1);
}

$configPage = file_get_contents($root . '/configuracoes.php');
if (!str_contains($configPage, 'Políticas por pessoa (parâmetros editáveis)')) {
    fwrite(STDERR, "Políticas por pessoa ausentes da página de Configurações.\n");
    exit(1);
}

$opportunityPage = file_get_contents($root . '/oportunidade.php');
foreach ([
    'Realizar orçamento',
    'Anotações do andamento do cliente',
    'Motivo da perda',
    'opportunity_notes',
    'quote_completed_at'
] as $needle) {
    if (!str_contains($opportunityPage, $needle)) {
        fwrite(STDERR, "Acompanhamento da oportunidade incompleto: {$needle}\n");
        exit(1);
    }
}

$functions = file_get_contents($root . '/includes/functions.php');
foreach ([
    'openai_read_salesforce_report',
    'openai_build_quote_reminder_email',
    'resend_send_email',
    'process_quote_reminders',
    'commercial_automation_defaults',
    'schedule_visit_commercial_automation',
    'process_commercial_agenda_reminders'
] as $needle) {
    if (!str_contains($functions, 'function ' . $needle)) {
        fwrite(STDERR, "Automação/agente ausente: {$needle}\n");
        exit(1);
    }
}

$configPage = file_get_contents($root . '/configuracoes.php');
foreach ([
    'Resend · E-mail',
    'Lembretes de orçamento originado de visita',
    'Follow-up em 3 dias · próxima visita em 60 dias',
    'followup_relacionamento',
    'followup_oportunidade',
    'followup_tecnico',
    'visita_relacionamento',
    'visita_oportunidade',
    'visita_tecnica'
] as $needle) {
    if (!str_contains($configPage, $needle)) {
        fwrite(STDERR, "Configuração de lembretes ausente: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($quotes, 'ChatGPT / OpenAI') || !str_contains($quotes, 'Sincronizar relatório com IA')) {
    fwrite(STDERR, "Agente OpenAI não está visível nos importadores de Orçamentos.\n");
    exit(1);
}

$visits = file_get_contents($root . '/visitas.php');
foreach (['schedule_visit_commercial_automation','follow-up em 3 dias','próxima visita em 60 dias'] as $needle) {
    if (!str_contains($visits, $needle)) {
        fwrite(STDERR, "Automação pós-visita ausente: {$needle}\n");
        exit(1);
    }
}

$schedule = file_get_contents($root . '/cronograma.php');
foreach (['Follow-ups programados','Próximas visitas','event_type','reminder_sent_at'] as $needle) {
    if (!str_contains($schedule, $needle)) {
        fwrite(STDERR, "Cronograma automático incompleto: {$needle}\n");
        exit(1);
    }
}

$job = file_get_contents($root . '/opportunity_reminders.php');
if (!str_contains($job, 'process_commercial_agenda_reminders')) {
    fwrite(STDERR, "Job diário não processa os lembretes do cronograma.\n");
    exit(1);
}

$visits = file_get_contents($root . '/visitas.php');
foreach ([
    'data-ai-cmd-context="visitas"',
    'Retorno / nova visita sugerida',
    'Data sugerida para retorno',
    'required'
] as $needle) {
    if (!str_contains($visits, $needle)) {
        fwrite(STDERR, "Extração de Visitas sem CMD/campos obrigatórios: {$needle}\n");
        exit(1);
    }
}

$quotes = file_get_contents($root . '/orcamentos.php');
foreach (['data-ai-cmd-context="orcamento-erp"','data-ai-cmd-context="salesforce"'] as $needle) {
    if (!str_contains($quotes, $needle)) {
        fwrite(STDERR, "Extração de Orçamentos sem CMD: {$needle}\n");
        exit(1);
    }
}

$layout = file_get_contents($root . '/includes/layout.php');
foreach (['data-ai-cmd-modal','assets/js/ai-cmd.js'] as $needle) {
    if (!str_contains($layout, $needle)) {
        fwrite(STDERR, "Modal CMD global ausente: {$needle}\n");
        exit(1);
    }
}

$functions = file_get_contents($root . '/includes/functions.php');
foreach (['Retornar ao cliente para acompanhar','+60 days'] as $needle) {
    if (!str_contains($functions, $needle)) {
        fwrite(STDERR, "Fallback obrigatório de retorno da IA ausente: {$needle}\n");
        exit(1);
    }
}

foreach (['rd_crm_oauth.php' => "'rd_crm'", 'rd_marketing_oauth.php' => "'rd_marketing'"] as $callbackFile => $providerNeedle) {
    $callback = file_get_contents($root . '/' . $callbackFile);
    if (!str_contains($callback, $providerNeedle) || !str_contains($callback, 'includes/rd_oauth_flow.php')) {
        fwrite(STDERR, "Callback OAuth inválido: {$callbackFile}
");
        exit(1);
    }
}

$oauth = file_get_contents($root . '/includes/rd_oauth_flow.php');
foreach (['require_admin()','random_bytes','hash_equals','rd_exchange_code'] as $needle) {
    if (!str_contains($oauth, $needle)) {
        fwrite(STDERR, "Callback OAuth da RD Station sem requisito: {$needle}\n");
        exit(1);
    }
}

$functions = file_get_contents($root . '/includes/functions.php');
foreach (['function rd_crm_sync','function rd_marketing_sync','accounts.rdstation.com/oauth/authorize','api.rd.services/oauth2/token','api.rd.services/auth/dialog','api.rd.services/auth/token','api.rd.services/crm/v2/','api.rd.services/platform/',"'grant_type'] = 'refresh_token'"] as $needle) {
    if (!str_contains($functions, $needle)) {
        fwrite(STDERR, "Integração RD Station CRM incompleta: {$needle}\n");
        exit(1);
    }
}

$configPage = file_get_contents($root . '/configuracoes.php');
foreach (["in_array(\$key, ['rd_crm','rd_marketing'], true)",'rd_callback_url($key)','?action=connect','rd_sync'] as $needle) {
    if (!str_contains($configPage, $needle)) {
        fwrite(STDERR, "Configuração RD Station CRM sem: {$needle}\n");
        exit(1);
    }
}

$layout = file_get_contents($root . '/includes/layout.php');
$posVisitas = strpos($layout, "'visitas' =>");
$posClientes = strpos($layout, "'clientes' =>");
$posEmpresas = strpos($layout, "'empresas' =>");
if ($posVisitas === false || $posClientes === false || $posEmpresas === false || !($posVisitas < $posClientes && $posClientes < $posEmpresas)) {
    fwrite(STDERR, "Menu deve ter Clientes e Empresas logo após Visitas.\n");
    exit(1);
}

// --- NOVOCRM (/novocrm): estrutura e travas obrigatórias ---
foreach ([
    'novocrm/_app.php','novocrm/_layout.php','novocrm/login.php','novocrm/index.php','novocrm/painel.php',
    'novocrm/acoes/busca.php','novocrm/migrations/run.php','novocrm/_lib.php','novocrm/clientes.php','novocrm/cliente.php','novocrm/acoes/cliente-salvar.php','novocrm/acoes/cnpj-consulta.php','novocrm/migrations/002_company_portfolio_status.sql','novocrm/migrations/001_opportunity_position.sql','novocrm/migrations/003_order_items.sql','novocrm/migrations/004_user_monthly_target.sql','novocrm/comissoes.php','novocrm/configuracoes.php','novocrm/partials/comissao.php','novocrm/acoes/meta-salvar.php','novocrm/pedidos.php','novocrm/pedido.php','novocrm/visitas.php','novocrm/acoes/pedido-salvar.php','novocrm/acoes/pedido-faturar.php','novocrm/acoes/visita-promover.php','novocrm/acoes/agenda-status.php','novocrm/oportunidades.php','novocrm/oportunidade.php','novocrm/partials/oportunidade.php','novocrm/acoes/oportunidade-etapa.php','novocrm/acoes/oportunidade-acao.php','novocrm/acoes/oportunidade-salvar.php','novocrm/assets/css/novocrm.css',
    'novocrm/assets/js/novocrm.js','novocrm/assets/js/theme-init.js',
    'novocrm/assets/vendor/htmx.min.js','novocrm/assets/vendor/Sortable.min.js',
    // V2: Equipe & Comissões, Cadência, metas, fechamento de comissões, probabilidade de etapa
    'novocrm/equipe.php','novocrm/cadencia.php','novocrm/partials/icons.php','novocrm/partials/kpi.php','novocrm/partials/equipe.php',
    'novocrm/migrations/004_sales_targets.sql','novocrm/migrations/004b_migrate_monthly_target.sql',
    'novocrm/migrations/006_commission_closings.sql','novocrm/migrations/007_stage_probability.sql',
    'novocrm/acoes/equipe-fechar.php','novocrm/acoes/equipe-pagar.php','novocrm/acoes/equipe-csv.php',
    'novocrm/acoes/representante-form.php','novocrm/acoes/representante-salvar.php','novocrm/acoes/agenda-reagendar.php',
    // V3: Clientes (Empresas, Contatos, Importações), mesclagem e migração de rastreio de importação
    'novocrm/_empresas.php','novocrm/_empresas_merge.php','novocrm/partials/clientes-empresas.php','novocrm/partials/clientes-contatos.php',
    'novocrm/partials/clientes-importacoes.php','novocrm/partials/contato.php','novocrm/partials/empresa-picker.php','novocrm/partials/empresa-mesclar.php',
    'novocrm/acoes/empresa-busca.php','novocrm/acoes/contato-form.php','novocrm/acoes/contato-salvar.php',
    'novocrm/acoes/contato-vincular-form.php','novocrm/acoes/contato-vincular.php',
    'novocrm/acoes/empresa-mesclar-form.php','novocrm/acoes/empresa-mesclar.php',
    'novocrm/migrations/008_import_tracking_columns.sql',
    'novocrm/migrations/009_import_tables.sql','novocrm/importar.php','novocrm/_import.php','novocrm/_import_match.php','novocrm/_import_run.php','novocrm/partials/importar.php',
    'novocrm/acoes/importar-enviar.php','novocrm/acoes/importar-mapear.php','novocrm/acoes/importar-processar.php','novocrm/acoes/importar-revisar-form.php',
    'novocrm/acoes/importar-resolver.php','novocrm/acoes/importar-acao.php','novocrm/acoes/importar-csv.php','novocrm/acoes/importar-cnpja.php',
] as $file) {
    if (!is_file($root . '/' . $file)) {
        fwrite(STDERR, "Arquivo do NOVOCRM ausente: {$file}\n");
        exit(1);
    }
}

$ncApp = file_get_contents($root . '/novocrm/_app.php');
foreach (['const NC_PUBLIC = false;', '../includes/auth.php', 'function nc_require_post', 'verify_csrf()'] as $needle) {
    if (!str_contains($ncApp, $needle)) {
        fwrite(STDERR, "NOVOCRM sem requisito de segurança: {$needle}\n");
        exit(1);
    }
}

$ncLogin = file_get_contents($root . '/novocrm/login.php');
if (!str_contains($ncLogin, '/login.php?redirect_to=') || str_contains($ncLogin, 'password_verify')) {
    fwrite(STDERR, "novocrm/login.php deve apenas redirecionar para o login da raiz.\n");
    exit(1);
}

$ncJs = file_get_contents($root . '/novocrm/assets/js/novocrm.js');
foreach (['htmx:configRequest', "'csrf_token'", 'htmx:responseError', '419'] as $needle) {
    if (!str_contains($ncJs, $needle)) {
        fwrite(STDERR, "NOVOCRM JS sem tratamento de CSRF/419: {$needle}\n");
        exit(1);
    }
}

$ncAll = '';
$ncIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/novocrm', FilesystemIterator::SKIP_DOTS));
foreach ($ncIt as $f) {
    if ($f->isFile() && preg_match('/\.(php|js|css)$/', $f->getFilename()) && !str_contains($f->getPathname(), 'vendor')) {
        $ncAll .= file_get_contents($f->getPathname());
    }
}
foreach (['process_quote_reminders(', 'process_commercial_agenda_reminders('] as $forbidden) {
    if (str_contains($ncAll, $forbidden)) {
        fwrite(STDERR, "NOVOCRM não pode chamar automaticamente: {$forbidden}\n");
        exit(1);
    }
}

// V2: menu 100% nativo (sem selo "versão clássica" e sem itens apontando para a raiz) e comissoes.php redirecionando.
$ncMenuSrc = file_get_contents($root . '/novocrm/_app.php') . file_get_contents($root . '/novocrm/_layout.php');
foreach (['versão clássica', 'CRM clássico', "'native'"] as $forbidden) {
    if (str_contains($ncMenuSrc, $forbidden)) {
        fwrite(STDERR, "NOVOCRM: a sidebar não pode exibir/usar: {$forbidden}\n");
        exit(1);
    }
}
if (!preg_match("/function nc_menu\\(\\): array\\s*\\{(.*?)\\n\\}/s", $ncMenuSrc, $ncMenuBody) || preg_match("/'href'\\s*=>\\s*'\\//", $ncMenuBody[1])) {
    fwrite(STDERR, "NOVOCRM: nenhum item da sidebar pode apontar para a raiz.\n");
    exit(1);
}
$ncComissoes = file_get_contents($root . '/novocrm/comissoes.php');
if (!str_contains($ncComissoes, 'equipe.php') || !str_contains($ncComissoes, '301')) {
    fwrite(STDERR, "novocrm/comissoes.php deve redirecionar (301) para equipe.php.\n");
    exit(1);
}
$ncCadencia = file_get_contents($root . '/novocrm/cadencia.php');
if (str_contains($ncCadencia, "/cadencia.php'") && str_contains($ncCadencia, 'Location:')) {
    fwrite(STDERR, "novocrm/cadencia.php é nativa e não pode redirecionar para a raiz.\n");
    exit(1);
}

// V3: endpoints que gravam exigem POST + CSRF; mesclar é só admin; vincular em lote é admin/gestor.
foreach (['contato-salvar','contato-vincular','empresa-mesclar'] as $ncAction) {
    $src = file_get_contents($root . '/novocrm/acoes/' . $ncAction . '.php');
    if (!str_contains($src, 'nc_require_post()')) {
        fwrite(STDERR, "novocrm/acoes/{$ncAction}.php deve chamar nc_require_post() (POST + CSRF).\n");
        exit(1);
    }
}
foreach (['empresa-mesclar' => 'nc_is_admin(', 'empresa-mesclar-form' => 'nc_is_admin(', 'contato-vincular' => 'nc_is_manager(', 'contato-vincular-form' => 'nc_is_manager('] as $ncAction => $guard) {
    if (!str_contains(file_get_contents($root . '/novocrm/acoes/' . $ncAction . '.php'), $guard)) {
        fwrite(STDERR, "novocrm/acoes/{$ncAction}.php sem checagem de permissão ({$guard}).\n");
        exit(1);
    }
}
$ncRun = file_get_contents($root . '/novocrm/migrations/run.php');
if (!str_contains($ncRun, 'create_index') || preg_match('/UNIQUE\s+INDEX/i', file_get_contents($root . '/novocrm/migrations/008_import_tracking_columns.sql'))) {
    fwrite(STDERR, "NOVOCRM: a 008 só pode criar índices comuns (não únicos) pelo run.php.\n");
    exit(1);
}

// V3 (Central de Importação): só admin/gestor, POST + CSRF nas ações que gravam e nenhuma chamada externa durante a importação.
foreach (['importar-enviar','importar-mapear','importar-processar','importar-resolver','importar-acao','importar-cnpja'] as $ncAction) {
    $src = file_get_contents($root . '/novocrm/acoes/' . $ncAction . '.php');
    if (!str_contains($src, 'nc_require_post()') || !str_contains($src, 'nc_is_manager(')) {
        fwrite(STDERR, "novocrm/acoes/{$ncAction}.php deve exigir nc_require_post() e nc_is_manager().\n");
        exit(1);
    }
}
foreach (['importar-csv','importar-revisar-form'] as $ncAction) {
    if (!str_contains(file_get_contents($root . '/novocrm/acoes/' . $ncAction . '.php'), 'nc_is_manager(')) {
        fwrite(STDERR, "novocrm/acoes/{$ncAction}.php deve exigir nc_is_manager().\n");
        exit(1);
    }
}
if (!str_contains(file_get_contents($root . '/novocrm/importar.php'), 'nc_is_manager(')) {
    fwrite(STDERR, "novocrm/importar.php deve exigir nc_is_manager().\n");
    exit(1);
}
$ncImport = '';
foreach (['_import.php', '_import_match.php', 'importar.php', 'partials/importar.php', 'acoes/importar-enviar.php', 'acoes/importar-mapear.php', 'acoes/importar-processar.php', 'acoes/importar-resolver.php', 'acoes/importar-acao.php'] as $ncFile) {
    $ncImport .= file_get_contents($root . '/novocrm/' . $ncFile);
}
foreach (['cnpja_lookup(', 'rd_sync_all(', 'rd_api_get(', 'rd_access_token(', 'rd_crm_access_token(', 'send_mail(', 'mail('] as $forbidden) {
    if (str_contains($ncImport, $forbidden)) {
        fwrite(STDERR, "A importação não pode chamar serviços externos ({$forbidden}); o CNPJá só roda na fila pós-confirmação (acoes/importar-cnpja.php).\n");
        exit(1);
    }
}
$ncRunSrc = file_get_contents($root . '/novocrm/_import_run.php');
if (substr_count($ncRunSrc, 'cnpja_lookup(') !== 1 || !str_contains($ncRunSrc, 'function nc_import_cnpja_step')) {
    fwrite(STDERR, "cnpja_lookup() só pode ser chamado em nc_import_cnpja_step().\n");
    exit(1);
}
foreach (['LIMIT 1', ' LIKE '] as $forbidden) {
    if (str_contains(file_get_contents($root . '/novocrm/_import_match.php'), $forbidden)) {
        fwrite(STDERR, "O vínculo com a empresa não pode usar {$forbidden} (só regras determinísticas e únicas).\n");
        exit(1);
    }
}
if (!is_file($root . '/tests/novocrm_import_test.php') || !is_file($root . '/tests/fixtures/orcamentos.xlsx')) {
    fwrite(STDERR, "Faltam tests/novocrm_import_test.php e as planilhas de exemplo em tests/fixtures.\n");
    exit(1);
}

echo "Smoke test OK\n";
