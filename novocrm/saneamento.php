<?php
declare(strict_types=1);

/**
 * Saneamento da base de empresas: completar/validar CNPJ (BrasilAPI + CNPJá), descobrir o CNPJ de quem não tem (IA validada)
 * e unificar duplicadas. Acesso: admin e gestor. Esta página só LÊ; as ações ficam em acoes/saneamento-*.php (POST + CSRF).
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_saneamento.php';
require_once __DIR__ . '/partials/saneamento.php';

$user = nc_boot();
$pdo = db();

if (!nc_is_manager($user)) {
    http_response_code(403);
    nc_header('Saneamento', 'clientes', ['Clientes', 'Saneamento']);
    nc_empty('Acesso restrito', 'Somente administradores e gestores acessam o Saneamento.', '<a class="nc-btn nc-btn-primary" href="' . e(nc_url('clientes.php')) . '">Voltar para Clientes</a>');
    nc_footer();
    exit;
}

nc_header('Saneamento', 'clientes', ['Clientes', 'Saneamento']);
nc_page_header('Clientes', 'Saneamento da base', '<a class="nc-btn nc-btn-secondary" href="' . e(nc_url('clientes.php')) . '">Voltar para Clientes</a>',
    'Complete CNPJs, descubra os que faltam e unifique empresas duplicadas.');

if (!nc_san_ready($pdo)) {
    nc_empty('Falta aplicar a migração 011', 'O Saneamento usa tabelas novas (apelidos, fila de CNPJ e registro de mesclagens).',
        '<a class="nc-btn nc-btn-primary" href="' . e(nc_url('migrations/run.php')) . '">Aplicar migrações</a>');
    nc_footer();
    exit;
}

ensure_company_cnpja_schema($pdo);
nc_saneamento_page($pdo, $user);
nc_footer();
