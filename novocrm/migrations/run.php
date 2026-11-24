<?php
declare(strict_types=1);

/**
 * NOVOCRM — executor de migrações (somente admin, idempotente).
 *
 * - Lê os arquivos NNN_*.sql desta pasta em ordem.
 * - Só aceita SQL ADITIVO: CREATE TABLE IF NOT EXISTS e ALTER TABLE ... ADD COLUMN (NULL ou com DEFAULT).
 * - Antes de ADD COLUMN, consulta information_schema; se a coluna já existe, pula.
 * - Registra cada arquivo aplicado em nc_migrations(name, applied_at).
 */

require_once __DIR__ . '/../_layout.php';

/** Divide o conteúdo de um .sql em comandos, ignorando comentários "-- ...". */
function nc_migration_statements(string $sql): array
{
    $lines = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $lines[] = $line;
    }
    $statements = [];
    foreach (explode(';', implode("\n", $lines)) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }
    }
    return $statements;
}

/**
 * Valida que o comando é aditivo. Retorna ['type' => 'create'|'add_column', ...] ou lança exceção.
 */
function nc_migration_classify(string $stmt): array
{
    if (preg_match('/\b(DROP|RENAME|MODIFY|CHANGE|TRUNCATE|DELETE|UPDATE|INSERT)\b/i', $stmt)
        && !preg_match('/^\s*CREATE\s+TABLE/i', $stmt)) {
        throw new RuntimeException('Comando não aditivo bloqueado: ' . substr($stmt, 0, 80));
    }

    if (preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?/i', $stmt, $m)) {
        return ['type' => 'create', 'table' => $m[1]];
    }

    if (preg_match('/^\s*ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+ADD\s+COLUMN\s+`?([A-Za-z0-9_]+)`?\s+(.+)$/is', $stmt, $m)) {
        $definition = $m[3];
        if (preg_match('/\bNOT\s+NULL\b/i', $definition) && !preg_match('/\bDEFAULT\b/i', $definition)) {
            throw new RuntimeException("Coluna {$m[2]} em {$m[1]}: NOT NULL exige DEFAULT.");
        }
        if (!preg_match('/\bNULL\b/i', $definition) && !preg_match('/\bDEFAULT\b/i', $definition)) {
            throw new RuntimeException("Coluna {$m[2]} em {$m[1]}: informe NULL ou DEFAULT.");
        }
        return ['type' => 'add_column', 'table' => $m[1], 'column' => $m[2]];
    }

    throw new RuntimeException('Comando não permitido (somente CREATE TABLE IF NOT EXISTS e ALTER TABLE ... ADD COLUMN): ' . substr($stmt, 0, 80));
}

function nc_migration_table_exists(PDO $pdo, string $table): bool
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute([$table]);
    return (int)$q->fetchColumn() > 0;
}

function nc_migration_column_exists(PDO $pdo, string $table, string $column): bool
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $q->execute([$table, $column]);
    return (int)$q->fetchColumn() > 0;
}

function nc_migration_files(): array
{
    $files = glob(__DIR__ . '/[0-9][0-9][0-9]_*.sql') ?: [];
    sort($files, SORT_STRING);
    return $files;
}

function nc_migration_applied(PDO $pdo): array
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nc_migrations (
            name VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $rows = $pdo->query('SELECT name, applied_at FROM nc_migrations')->fetchAll();
    $applied = [];
    foreach ($rows as $row) {
        $applied[(string)$row['name']] = (string)$row['applied_at'];
    }
    return $applied;
}

/** Aplica um arquivo. Retorna lista de ações realizadas (texto). */
function nc_migration_apply(PDO $pdo, string $file): array
{
    $name = basename($file);
    $sql = (string)file_get_contents($file);
    $statements = nc_migration_statements($sql);
    if (!$statements) {
        throw new RuntimeException("{$name}: arquivo vazio.");
    }

    // Valida tudo antes de executar qualquer coisa.
    $plan = [];
    foreach ($statements as $stmt) {
        $plan[] = [$stmt, nc_migration_classify($stmt)];
    }

    $log = [];
    foreach ($plan as [$stmt, $info]) {
        if ($info['type'] === 'add_column') {
            if (!nc_migration_table_exists($pdo, $info['table'])) {
                throw new RuntimeException("{$name}: tabela {$info['table']} não existe.");
            }
            if (nc_migration_column_exists($pdo, $info['table'], $info['column'])) {
                $log[] = "{$info['table']}.{$info['column']} já existe — ignorado";
                continue;
            }
            $pdo->exec($stmt);
            $log[] = "coluna {$info['table']}.{$info['column']} criada";
        } else {
            $existed = nc_migration_table_exists($pdo, $info['table']);
            $pdo->exec($stmt);
            $log[] = $existed ? "tabela {$info['table']} já existe — ignorada" : "tabela {$info['table']} criada";
        }
    }

    $pdo->prepare('INSERT IGNORE INTO nc_migrations (name) VALUES (?)')->execute([$name]);
    return $log;
}

nc_boot();
$user = require_admin();
$pdo = db();

$results = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $applied = nc_migration_applied($pdo);
    $failed = false;

    foreach (nc_migration_files() as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            continue;
        }
        try {
            $log = nc_migration_apply($pdo, $file);
            audit($pdo, (int)$user['id'], 'novocrm_migrate', 'novocrm_migration', null, ['name' => $name, 'log' => $log]);
            $results[] = ['name' => $name, 'ok' => true, 'text' => implode('; ', $log)];
        } catch (Throwable $ex) {
            $failed = true;
            $results[] = ['name' => $name, 'ok' => false, 'text' => $ex->getMessage()];
            break; // preserva a ordem: não aplica as seguintes se uma falhar
        }
    }

    if (!$results) {
        flash('info', 'Nenhuma migração pendente.');
    } elseif ($failed) {
        flash('danger', 'Uma migração falhou. Veja o detalhe abaixo; as anteriores foram mantidas.');
    } else {
        flash('success', count($results) . ' migração(ões) aplicada(s).');
    }
}

$applied = nc_migration_applied($pdo);
$files = nc_migration_files();
$pending = 0;
foreach ($files as $file) {
    if (!isset($applied[basename($file)])) {
        $pending++;
    }
}

nc_header('Migrações', '', ['Configurações', 'Migrações']);
nc_page_header(
    $pending . ' pendente(s) · ' . count($applied) . ' aplicada(s)',
    'Migrações do banco',
    $pending > 0
        ? '<form method="post" action="">' . csrf_field()
            . '<button class="nc-btn nc-btn-primary" type="submit">Aplicar pendentes</button></form>'
        : ''
);

if ($results): ?>
    <div class="nc-card" style="margin-bottom:16px">
        <ul>
            <?php foreach ($results as $r): ?>
                <li>
                    <span class="nc-badge <?= $r['ok'] ? 'nc-badge-success' : 'nc-badge-danger' ?>"><?= $r['ok'] ? 'Aplicada' : 'Falhou' ?></span>
                    <strong><?= e($r['name']) ?></strong> — <?= e($r['text']) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif;

if (!$files): ?>
    <?php nc_empty('Nenhuma migração nesta fase', 'Os arquivos NNN_*.sql chegam junto das fases que precisam deles. O executor já está pronto e a tabela nc_migrations é criada ao abrir esta página.'); ?>
<?php else: ?>
    <div class="nc-table-wrap">
        <table class="nc-table">
            <thead><tr><th>Arquivo</th><th>Status</th><th>Aplicada em</th></tr></thead>
            <tbody>
            <?php foreach ($files as $file): $name = basename($file); ?>
                <tr>
                    <td><?= e($name) ?></td>
                    <td>
                        <?php if (isset($applied[$name])): ?>
                            <span class="nc-badge nc-badge-success">Aplicada</span>
                        <?php else: ?>
                            <span class="nc-badge nc-badge-warning">Pendente</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($applied[$name] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif;

nc_footer();
