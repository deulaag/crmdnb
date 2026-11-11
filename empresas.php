<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

$user = require_auth();
$pdo = db();
ensure_company_cnpja_schema($pdo);
ensure_rd_crm_schema($pdo);
$isAdmin = ($user['role'] ?? '') === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (!$isAdmin || ($_POST['action'] ?? '') !== 'rd_crm_sync') {
            throw new RuntimeException('Ação não permitida.');
        }
        $stats = rd_crm_sync($pdo, (int)$user['id']);
        audit($pdo, (int)$user['id'], 'sync', 'integration', null, ['provider' => 'rd_crm']);
        flash($stats['status'] === 'success' ? 'success' : 'warning', 'RD Station sincronizada. ' . rd_crm_sync_summary_text($stats));
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }
    redirect('empresas.php');
}

function empresas_source_label(string $source): string
{
    return match ($source) {
        'rd_crm' => 'RD Station',
        'cnpja' => 'CNPJá',
        default => 'Manual',
    };
}

$detailId = max(0, (int)($_GET['id'] ?? 0));
$detail = null;
$detailContacts = [];
if ($detailId > 0) {
    $s = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $s->execute([$detailId]);
    $detail = $s->fetch() ?: null;
    if ($detail) {
        $s = $pdo->prepare('SELECT * FROM contacts WHERE company_id = ? ORDER BY name');
        $s->execute([$detailId]);
        $detailContacts = $s->fetchAll();
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$perPage = 50;
$page = max(1, (int)($_GET['p'] ?? 1));

$whereSql = '';
$params = [];
if ($q !== '') {
    $digits = preg_replace('/\D+/', '', $q);
    $whereSql = 'WHERE (co.name LIKE :q0 OR co.alias LIKE :q1 OR co.city LIKE :q2 OR co.email LIKE :q3' . ($digits !== '' ? ' OR co.cnpj LIKE :cnpj' : '') . ')';
    foreach (range(0, 3) as $i) {
        $params['q' . $i] = '%' . $q . '%';
    }
    if ($digits !== '') {
        $params['cnpj'] = '%' . $digits . '%';
    }
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM companies co $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);

$stmt = $pdo->prepare(
    "SELECT co.*,
        (SELECT COUNT(*) FROM contacts c WHERE c.company_id = co.id) contacts_count,
        (SELECT COUNT(*) FROM opportunities o WHERE o.company_id = co.id) opportunities_count
     FROM companies co
     $whereSql
     ORDER BY co.name
     LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

function empresas_page_url(array $extra): string
{
    $query = array_filter(array_merge(['q' => $_GET['q'] ?? null], $extra), static fn($v) => $v !== null && $v !== '');
    return 'empresas.php' . ($query ? '?' . http_build_query($query) : '');
}

page_header('Empresas', 'empresas');
?>

<div class="page-actions">
    <div>
        <span class="eyebrow">CADASTRO</span>
        <h2>Empresas</h2>
        <p>Organizações importadas da RD Station CRM e cadastradas no CRM.</p>
    </div>
    <?php if ($isAdmin): ?>
        <form method="post" data-no-draft>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rd_crm_sync">
            <button class="btn btn-secondary" type="submit">Sincronizar com RD Station</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($detail): ?>
<div class="card">
    <div class="card-title">
        <div>
            <span class="tag"><?= e(empresas_source_label((string)$detail['source'])) ?></span>
            <h3><?= e($detail['name']) ?></h3>
            <p>
                <?= $detail['cnpj'] ? 'CNPJ ' . e(format_cnpj((string)$detail['cnpj'])) . ' · ' : '' ?>
                <?= e(trim(($detail['city'] ?? '') . (($detail['state'] ?? '') ? ' / ' . $detail['state'] : '')) ?: 'Cidade não informada') ?>
                <?= $detail['email'] ? ' · ' . e($detail['email']) : '' ?>
                <?= $detail['phone'] ? ' · ' . e($detail['phone']) : '' ?>
                <?= $detail['website'] ? ' · ' . e($detail['website']) : '' ?>
            </p>
        </div>
        <a class="link-btn" href="<?= e(empresas_page_url([])) ?>">Fechar</a>
    </div>

    <?php if (!$detailContacts): ?>
        <?php empty_state('Sem clientes vinculados', 'Nenhum contato está associado a esta empresa.'); ?>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Nome</th><th>Cargo</th><th>E-mail</th><th>Telefone</th></tr></thead>
                <tbody>
                <?php foreach ($detailContacts as $c): ?>
                    <tr>
                        <td><strong><?= e($c['name']) ?></strong></td>
                        <td><?= e($c['job_title'] ?: '—') ?></td>
                        <td><?= e($c['email'] ?: '—') ?></td>
                        <td><?= e($c['phone'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-title">
        <div>
            <span class="tag">EMPRESAS</span>
            <h3>Registros (<?= $total ?>)</h3>
        </div>
        <form method="get" class="inline-form">
            <label><span>Buscar</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="nome, CNPJ, cidade, e-mail"></label>
            <button class="btn btn-ghost" type="submit">Filtrar</button>
            <?php if ($q !== ''): ?><a class="link-btn" href="empresas.php">Limpar</a><?php endif; ?>
        </form>
    </div>

    <?php if (!$rows): ?>
        <?php empty_state('Nenhuma empresa', $q !== '' ? 'Nenhuma empresa encontrada para a busca.' : 'Conecte a RD Station CRM em Configurações e sincronize para importar as empresas.'); ?>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Empresa</th>
                        <th>CNPJ</th>
                        <th>Cidade / UF</th>
                        <th>Clientes</th>
                        <th>Oportunidades</th>
                        <th>Origem</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a class="link-btn" href="<?= e(empresas_page_url(['id' => (int)$row['id'], 'p' => $page > 1 ? $page : null])) ?>"><strong><?= e($row['name']) ?></strong></a></td>
                        <td class="mono"><?= $row['cnpj'] ? e(format_cnpj((string)$row['cnpj'])) : '—' ?></td>
                        <td><?= e(trim(($row['city'] ?? '') . (($row['state'] ?? '') ? ' / ' . $row['state'] : '')) ?: '—') ?></td>
                        <td>
                            <?php if ((int)$row['contacts_count'] > 0): ?>
                                <a class="link-btn" href="clientes.php?empresa=<?= (int)$row['id'] ?>"><?= (int)$row['contacts_count'] ?></a>
                            <?php else: ?>0<?php endif; ?>
                        </td>
                        <td><?= (int)$row['opportunities_count'] ?></td>
                        <td><span class="status <?= $row['source'] === 'rd_crm' ? 'status-aberta' : 'status-ganha' ?>"><?= e(empresas_source_label((string)$row['source'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <div class="form-actions">
                <?php if ($page > 1): ?><a class="btn btn-ghost" href="<?= e(empresas_page_url(['p' => $page - 1])) ?>">← Anterior</a><?php endif; ?>
                <span>Página <?= $page ?> de <?= $pages ?></span>
                <?php if ($page < $pages): ?><a class="btn btn-ghost" href="<?= e(empresas_page_url(['p' => $page + 1])) ?>">Próxima →</a><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php page_footer(); ?>
