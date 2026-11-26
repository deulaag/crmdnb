<?php
declare(strict_types=1);

/**
 * Clientes — duas visões do mesmo cadastro, sem ambiguidade (V3, seção 1):
 *   Empresas (tabela companies) | Contatos (tabela contacts) | Importações (lotes, admin/gestor).
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_empresas.php';
require_once __DIR__ . '/partials/clientes-empresas.php';
require_once __DIR__ . '/partials/clientes-contatos.php';
require_once __DIR__ . '/partials/clientes-importacoes.php';

$user = nc_boot();
$pdo = db();
ensure_company_cnpja_schema($pdo);
ensure_rd_crm_schema($pdo);
$isManager = nc_is_manager($user);

$aba = (string)($_GET['aba'] ?? 'empresas');
if (!in_array($aba, ['empresas', 'contatos', 'importacoes'], true)) {
    $aba = 'empresas';
}

// Totais para o cabeçalho e para o contador das abas (sem filtros de busca).
$totals = $pdo->query(
    "SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN NULLIF(TRIM(cnpj), '') IS NOT NULL THEN 1 ELSE 0 END), 0) com_cnpj FROM companies"
)->fetch();
[$scopeSql, $scopeParams] = nc_contacts_scope_sql($pdo, $user);
$q = $pdo->prepare(
    'SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN ct.company_id IS NULL THEN 1 ELSE 0 END), 0) sem_empresa
     FROM contacts ct LEFT JOIN companies co ON co.id = ct.company_id' . ($scopeSql !== '' ? " WHERE $scopeSql" : '')
);
$q->execute($scopeParams);
$ct = $q->fetch();
$totals = [
    'empresas' => (int)$totals['total'],
    'com_cnpj' => (int)$totals['com_cnpj'],
    'contatos' => (int)$ct['total'],
    'sem_empresa' => (int)$ct['sem_empresa'],
];

if ($aba === 'importacoes' && !$isManager) {
    http_response_code(403);
    nc_header('Importações', 'clientes', ['Clientes', 'Importações']);
    nc_empty('Acesso restrito', 'Somente administradores e gestores acessam as importações.', '<a class="nc-btn nc-btn-primary" href="' . e(nc_url('clientes.php')) . '">Voltar para Clientes</a>');
    nc_footer();
    exit;
}

nc_header('Clientes', 'clientes', ['Clientes', ['empresas' => 'Empresas', 'contatos' => 'Contatos', 'importacoes' => 'Importações'][$aba]]);

if ($aba === 'empresas') {
    nc_clientes_empresas_header($totals, $user);
} elseif ($aba === 'contatos') {
    nc_clientes_contatos_header($totals, $user);
} else {
    nc_page_header('Histórico dos lotes enviados', 'Importações', nc_clientes_import_button($user));
}

nc_clientes_tabs($aba, $totals, $isManager);

if ($aba === 'empresas') {
    nc_clientes_empresas($pdo, $user);
} elseif ($aba === 'contatos') {
    nc_clientes_contatos($pdo, $user);
} else {
    nc_clientes_importacoes($pdo, $user);
}

nc_footer();

/** Abas do topo: Empresas | Contatos | Importações (só admin/gestor). */
function nc_clientes_tabs(string $aba, array $totals, bool $isManager): void
{
    $tabs = [
        'empresas' => ['Empresas', $totals['empresas']],
        'contatos' => ['Contatos', $totals['contatos']],
    ];
    if ($isManager) {
        $tabs['importacoes'] = ['Importações', null];
        if (is_file(__DIR__ . '/saneamento.php')) {
            $tabs['saneamento'] = ['Saneamento', null];
        }
    }
    ?>
    <div class="nc-tabs" role="tablist" aria-label="Clientes">
        <?php foreach ($tabs as $key => [$label, $count]): ?>
            <a class="nc-tab<?= $aba === $key ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $aba === $key ? 'true' : 'false' ?>"
               href="<?= e(nc_url($key === 'saneamento' ? 'saneamento.php' : 'clientes.php' . ($key === 'empresas' ? '' : '?aba=' . $key))) ?>">
                <?= e($label) ?><?php if ($count !== null): ?> <span class="nc-tab-count"><?= (int)$count ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
}

/** Botão secundário "Importar planilha" (só admin/gestor, e só quando a Central de Importação existir). */
function nc_clientes_import_button(array $user, string $tipo = 'contatos'): string
{
    if (!nc_is_manager($user) || !is_file(__DIR__ . '/importar.php')) {
        return '';
    }
    return '<a class="nc-btn nc-btn-secondary" href="' . e(nc_url('importar.php?tipo=' . $tipo)) . '">Importar planilha</a>';
}
