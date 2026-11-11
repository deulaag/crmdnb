<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

$user = require_auth();
$pdo = db();
ensure_rd_crm_schema($pdo);
$isAdmin = ($user['role'] ?? '') === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (!$isAdmin || ($_POST['action'] ?? '') !== 'rd_crm_sync') {
            throw new RuntimeException('Ação não permitida.');
        }
        $result = rd_sync_all($pdo, (int)$user['id']);
        audit($pdo, (int)$user['id'], 'sync', 'integration', null, ['provider' => array_keys($result['results'])]);
        flash($result['status'] === 'success' ? 'success' : 'warning', 'RD Station sincronizada. ' . $result['message']);
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }
    redirect('clientes.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$companyFilter = max(0, (int)($_GET['empresa'] ?? 0));
$perPage = 50;
$page = max(1, (int)($_GET['p'] ?? 1));

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(c.name LIKE :q0 OR c.email LIKE :q1 OR c.phone LIKE :q2 OR c.job_title LIKE :q3 OR co.name LIKE :q4)';
    foreach (range(0, 4) as $i) {
        $params['q' . $i] = '%' . $q . '%';
    }
}
if ($companyFilter > 0) {
    $where[] = 'c.company_id = :company';
    $params['company'] = $companyFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM contacts c LEFT JOIN companies co ON co.id = c.company_id $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);

$stmt = $pdo->prepare(
    "SELECT c.*, co.name company_name
     FROM contacts c
     LEFT JOIN companies co ON co.id = c.company_id
     $whereSql
     ORDER BY c.name
     LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$companyName = '';
if ($companyFilter > 0) {
    $s = $pdo->prepare('SELECT name FROM companies WHERE id = ?');
    $s->execute([$companyFilter]);
    $companyName = (string)($s->fetchColumn() ?: '');
}

function clientes_source_label(string $source): string
{
    return match ($source) {
        'rd_crm' => 'RD Station CRM',
        'rd_marketing' => 'RD Marketing',
        'cnpja' => 'CNPJá',
        default => 'Manual',
    };
}

function clientes_page_url(array $extra): string
{
    $query = array_filter(array_merge([
        'q' => $_GET['q'] ?? null,
        'empresa' => $_GET['empresa'] ?? null,
    ], $extra), static fn($v) => $v !== null && $v !== '' && $v !== 0);
    return 'clientes.php' . ($query ? '?' . http_build_query($query) : '');
}

page_header('Clientes', 'clientes');
?>

<div class="page-actions">
    <div>
        <span class="eyebrow">CADASTRO</span>
        <h2>Clientes</h2>
        <p>Contatos importados da RD Station CRM, leads da RD Station Marketing e cadastros do CRM.</p>
    </div>
    <?php if ($isAdmin): ?>
        <form method="post" data-no-draft>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rd_crm_sync">
            <button class="btn btn-secondary" type="submit">Sincronizar com RD Station</button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-title">
        <div>
            <span class="tag">CLIENTES</span>
            <h3>Registros (<?= $total ?>)<?= $companyName !== '' ? ' · ' . e($companyName) : '' ?></h3>
        </div>
        <form method="get" class="inline-form">
            <?php if ($companyFilter > 0): ?><input type="hidden" name="empresa" value="<?= $companyFilter ?>"><?php endif; ?>
            <label><span>Buscar</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="nome, e-mail, telefone, empresa"></label>
            <button class="btn btn-ghost" type="submit">Filtrar</button>
            <?php if ($q !== '' || $companyFilter > 0): ?><a class="link-btn" href="clientes.php">Limpar</a><?php endif; ?>
        </form>
    </div>

    <?php if (!$rows): ?>
        <?php empty_state('Nenhum cliente', $q !== '' ? 'Nenhum cliente encontrado para a busca.' : 'Conecte a RD Station CRM ou Marketing em Configurações e sincronize para importar os clientes.'); ?>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Empresa</th>
                        <th>Cargo</th>
                        <th>E-mail</th>
                        <th>Telefone</th>
                        <th>Origem</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?= e($row['name']) ?></strong></td>
                        <td>
                            <?php if (!empty($row['company_id'])): ?>
                                <a class="link-btn" href="empresas.php?id=<?= (int)$row['company_id'] ?>"><?= e($row['company_name']) ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= e($row['job_title'] ?: '—') ?></td>
                        <td><?= $row['email'] ? '<a class="link-btn" href="mailto:' . e($row['email']) . '">' . e($row['email']) . '</a>' : '—' ?></td>
                        <td><?= e($row['phone'] ?: '—') ?></td>
                        <td><span class="status <?= str_starts_with((string)($row['source'] ?? ''), 'rd_') ? 'status-aberta' : 'status-ganha' ?>"><?= e(clientes_source_label((string)($row['source'] ?? 'manual'))) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <div class="form-actions">
                <?php if ($page > 1): ?><a class="btn btn-ghost" href="<?= e(clientes_page_url(['p' => $page - 1])) ?>">← Anterior</a><?php endif; ?>
                <span>Página <?= $page ?> de <?= $pages ?></span>
                <?php if ($page < $pages): ?><a class="btn btn-ghost" href="<?= e(clientes_page_url(['p' => $page + 1])) ?>">Próxima →</a><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php page_footer(); ?>
