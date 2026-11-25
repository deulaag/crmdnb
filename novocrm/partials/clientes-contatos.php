<?php
declare(strict_types=1);

/** Cabeçalho da aba Contatos. */
function nc_clientes_contatos_header(array $totals, array $user): void
{
    $actions = nc_clientes_import_button($user, 'contatos')
        . '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e(nc_url('acoes/contato-form.php')) . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">+ Novo contato</button>';
    nc_page_header(
        $totals['contatos'] . ' contatos · ' . $totals['sem_empresa'] . ' sem empresa',
        'Contatos',
        $actions
    );
}

/** Lista de contatos (tabela contacts + LEFT JOIN companies). comercial vê só os das suas empresas e os que criou. */
function nc_clientes_contatos(PDO $pdo, array $user): void
{
    $isManager = nc_is_manager($user);
    $q = trim((string)($_GET['q'] ?? ''));
    $filtro = (string)($_GET['filtro'] ?? 'todos');
    if (!in_array($filtro, ['todos', 'sem_empresa', 'sem_email'], true)) {
        $filtro = 'todos';
    }
    $perPage = 50;
    $page = max(1, (int)($_GET['p'] ?? 1));
    $hasImport = nc_column_exists($pdo, 'contacts', 'import_batch_id');

    $where = [];
    $params = [];
    [$scopeSql, $scopeParams] = nc_contacts_scope_sql($pdo, $user);
    if ($scopeSql !== '') {
        $where[] = $scopeSql;
        $params = array_merge($params, $scopeParams);
    }
    if ($q !== '') {
        $like = nc_like($q);
        $parts = ['ct.name LIKE ?', 'ct.email LIKE ?', 'co.name LIKE ?', 'co.alias LIKE ?'];
        $qp = [$like, $like, $like, $like];
        $digits = preg_replace('/\D+/', '', $q) ?: '';
        if (strlen($digits) >= 3) {
            $parts[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(ct.phone,' ',''),'-',''),'(',''),')',''),'+',''),'.','') LIKE ?";
            $qp[] = nc_like($digits);
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
        $params = array_merge($params, $qp);
    }
    $baseWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $from = 'FROM contacts ct LEFT JOIN companies co ON co.id = ct.company_id';

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) todos,
                COALESCE(SUM(CASE WHEN ct.company_id IS NULL THEN 1 ELSE 0 END), 0) sem_empresa,
                COALESCE(SUM(CASE WHEN ct.email IS NULL OR TRIM(ct.email) = '' THEN 1 ELSE 0 END), 0) sem_email
         $from $baseWhere"
    );
    $stmt->execute($params);
    $counts = array_map('intval', $stmt->fetch() ?: ['todos' => 0, 'sem_empresa' => 0, 'sem_email' => 0]);

    $listWhere = $where;
    if ($filtro === 'sem_empresa') {
        $listWhere[] = 'ct.company_id IS NULL';
    } elseif ($filtro === 'sem_email') {
        $listWhere[] = "(ct.email IS NULL OR TRIM(ct.email) = '')";
    }
    $listSql = $listWhere ? 'WHERE ' . implode(' AND ', $listWhere) : '';

    $total = $counts[$filtro === 'todos' ? 'todos' : $filtro];
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT ct.id, ct.name, ct.job_title, ct.email, ct.phone, ct.source, ct.updated_at, ct.company_id,
                co.name company_name" . ($hasImport ? ', ct.import_batch_id' : '') . "
         $from $listSql ORDER BY ct.name LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $tabs = ['todos' => 'Todos', 'sem_empresa' => 'Sem empresa', 'sem_email' => 'Sem e-mail'];
    ?>
    <form method="get" class="nc-toolbar" role="search">
        <input type="hidden" name="aba" value="contatos">
        <input type="hidden" name="filtro" value="<?= e($filtro) ?>">
        <label class="nc-sr-only" for="nc-q">Buscar contatos</label>
        <input class="nc-input nc-grow" id="nc-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por nome, e-mail, telefone ou empresa">
        <button class="nc-btn nc-btn-secondary" type="submit">Buscar</button>
        <?php if ($q !== ''): ?><a class="nc-btn nc-btn-secondary" href="<?= e(nc_query_url(['q' => null, 'p' => null])) ?>">Limpar</a><?php endif; ?>
    </form>

    <div class="nc-tabbar">
        <div class="nc-tabs" role="tablist" aria-label="Filtro de contatos">
            <?php foreach ($tabs as $key => $label): $n = $counts[$key === 'todos' ? 'todos' : $key]; ?>
                <a class="nc-tab<?= $filtro === $key ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $filtro === $key ? 'true' : 'false' ?>"
                   href="<?= e(nc_query_url(['filtro' => $key === 'todos' ? null : $key, 'p' => null])) ?>">
                    <?= e($label) ?> <span class="nc-tab-count"><?= $n ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($isManager && $counts['sem_empresa'] > 0): ?>
            <div class="nc-toolbar-actions">
                <button type="button" class="nc-btn nc-btn-secondary" hx-get="<?= e(nc_url('acoes/contato-vincular-form.php')) ?>"
                        hx-include="#nc-contatos-lista [name='ids[]']:checked" hx-target="#nc-drawer-body" hx-swap="innerHTML">Vincular à empresa…</button>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($isManager && $counts['sem_empresa'] > 0): ?>
        <p class="nc-help">Marque contatos “Sem empresa” na lista e use <strong>Vincular à empresa…</strong> para ligá-los a um cadastro.</p>
    <?php endif;

    if (!$rows) {
        nc_empty(
            'Nenhum contato encontrado',
            $q !== '' ? 'Nenhum contato corresponde à busca. Tente outro nome, e-mail, telefone ou empresa.' : 'Cadastre o primeiro contato ou sincronize a RD Station para importar sua base.',
            '<button type="button" class="nc-btn nc-btn-primary" hx-get="' . e(nc_url('acoes/contato-form.php')) . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">+ Novo contato</button>'
        );
        return;
    }
    ?>
    <div class="nc-table-wrap" id="nc-contatos-lista">
        <table class="nc-table">
            <thead>
                <tr>
                    <?php if ($isManager): ?><th class="nc-col-check"><input type="checkbox" data-nc-check-all="#nc-contatos-lista" aria-label="Selecionar todos os contatos sem empresa desta página"></th><?php endif; ?>
                    <th>Nome</th><th>Cargo</th><th>Empresa</th><th>E-mail</th><th>Telefone</th><th>Origem</th><th>Atualizado em</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
                $noCompany = $row['company_id'] === null;
                $url = $noCompany ? '' : nc_url('cliente.php?id=' . (int)$row['company_id']); ?>
                <tr>
                    <?php if ($isManager): ?>
                        <td class="nc-col-check">
                            <?php if ($noCompany): ?><input type="checkbox" name="ids[]" value="<?= (int)$row['id'] ?>" aria-label="Selecionar <?= e((string)$row['name']) ?>"><?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td><strong><?= e((string)$row['name']) ?></strong></td>
                    <td><?= e((string)($row['job_title'] ?: '—')) ?></td>
                    <td>
                        <?php if ($noCompany): ?>
                            <?= nc_badge('Sem empresa', 'danger') ?>
                        <?php else: ?>
                            <a href="<?= e($url) ?>" hx-get="<?= e($url) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-push-url="true"><?= e((string)$row['company_name']) ?></a>
                        <?php endif; ?>
                    </td>
                    <td><?= trim((string)$row['email']) !== '' ? e((string)$row['email']) : '—' ?></td>
                    <td><?= trim((string)$row['phone']) !== '' ? e((string)$row['phone']) : '—' ?></td>
                    <td><?= e(isset($row['import_batch_id']) && (int)$row['import_batch_id'] > 0 ? 'Planilha · lote #' . (int)$row['import_batch_id'] : nc_contact_source_label((string)$row['source'])) ?></td>
                    <td><?= e(nc_date((string)$row['updated_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php nc_pagination($page, $pages);
}
