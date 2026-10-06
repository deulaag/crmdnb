<?php
$root = dirname(__DIR__);
$required = [
    'install.php','login.php','painel.php','orcamentos.php','oportunidade.php','opportunity_reminders.php','pedidos.php','visitas.php','cronograma.php','cnpja_lookup.php','branding_asset.php',
    'cadencia.php','comissoes.php','configuracoes.php','usuarios.php','includes/bootstrap.php','includes/auth.php',
    'includes/layout.php','includes/security.php','session_refresh.php','assets/css/app.css','assets/js/app.js','assets/js/auth.js','assets/js/ui.js','assets/dnb-logo.png','config/database.example.php'
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

echo "Smoke test OK\n";
