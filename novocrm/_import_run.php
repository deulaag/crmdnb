<?php
declare(strict_types=1);

/**
 * NOVOCRM v3 — ciclo de vida do lote de importação: arquivo, staging, revisão, confirmação e desfazer (V3, seções 2 e 6).
 *
 * NADA é gravado em companies/contacts/opportunities antes de nc_import_confirm(). Tudo que a prévia mostra
 * fica em import_rows. A importação NUNCA chama RD Station, CNPJá nem lembretes por e-mail.
 */

require_once __DIR__ . '/_import_match.php';

const NC_IMPORT_CHUNK = 500;
const NC_IMPORT_UNDO_DAYS = 7;
const NC_IMPORT_CLASSES = ['novo', 'atualizar', 'sem_alteracao', 'revisar', 'erro', 'ignorar'];

function nc_import_json(mixed $v): string
{
    return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

function nc_import_decode(?string $json): array
{
    $d = json_decode((string)$json, true);
    return is_array($d) ? $d : [];
}

/** O que falta aplicar para a Central funcionar (migrações 008 e 009). @return string[] */
function nc_import_missing_setup(PDO $pdo): array
{
    $missing = [];
    foreach (['import_batches', 'import_rows', 'import_templates'] as $t) {
        if (!nc_table_exists($pdo, $t)) {
            $missing[] = 'tabela ' . $t . ' (migração 009)';
        }
    }
    foreach ([['companies', 'external_id'], ['companies', 'import_batch_id'], ['contacts', 'external_id'], ['contacts', 'import_batch_id'],
        ['opportunities', 'external_ref'], ['opportunities', 'import_batch_id']] as [$t, $c]) {
        if (!nc_column_exists($pdo, $t, $c)) {
            $missing[] = 'coluna ' . $t . '.' . $c . ' (migração 008)';
        }
    }
    return $missing;
}

/* ------------------------------------------------------------------ */
/* Lote e arquivo                                                      */
/* ------------------------------------------------------------------ */

function nc_import_batch(PDO $pdo, int $id): ?array
{
    $q = $pdo->prepare('SELECT * FROM import_batches WHERE id = ?');
    $q->execute([$id]);
    $b = $q->fetch();
    return $b ?: null;
}

/** Configuração do lote (mapeamento, mapas de valores), guardada em counters_json['config']. */
function nc_import_config(array $batch): array
{
    $c = nc_import_decode($batch['counters_json'] ?? null);
    $cfg = is_array($c['config'] ?? null) ? $c['config'] : [];
    return $cfg + ['mapping' => [], 'value_maps' => [], 'headers' => [], 'date1904' => false, 'header_row' => 1];
}

/** Atualiza campos do lote; $counters mescla em counters_json sem perder a configuração. */
function nc_import_batch_update(PDO $pdo, int $id, array $set, ?array $counters = null): void
{
    if ($counters !== null) {
        $q = $pdo->prepare('SELECT counters_json FROM import_batches WHERE id = ?');
        $q->execute([$id]);
        $cur = nc_import_decode((string)$q->fetchColumn());
        $set['counters_json'] = nc_import_json(array_merge($cur, $counters));
    }
    if (!$set) {
        return;
    }
    $assign = implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($set)));
    $pdo->prepare("UPDATE import_batches SET $assign WHERE id = ?")->execute(array_merge(array_values($set), [$id]));
}

/** Pasta de armazenamento (protegida pelo .htaccess de /storage). */
function nc_import_storage_root(): string
{
    return dirname(__DIR__) . '/storage/imports';
}

/**
 * Valida e guarda o arquivo em storage/imports/AAAA/MM/<hash>.<ext>. Devolve nome, hash, caminho relativo e extensão.
 *
 * @return array{name:string,hash:string,path:string,ext:string}
 */
function nc_import_store_file(string $tmpPath, string $originalName, ?string $root = null): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv'], true)) {
        throw new RuntimeException('Envie um arquivo .xlsx ou .csv.');
    }
    $size = (int)@filesize($tmpPath);
    if ($size <= 0) {
        throw new RuntimeException('O arquivo está vazio.');
    }
    if ($size > NC_IMPORT_MAX_BYTES) {
        throw new RuntimeException('O arquivo deve ter no máximo 15 MB.');
    }
    $head = (string)file_get_contents($tmpPath, false, null, 0, 4);
    if ($ext === 'xlsx' && !str_starts_with($head, 'PK')) {
        throw new RuntimeException('Este arquivo não é um XLSX válido (use "Salvar como" .xlsx no Excel).');
    }
    $hash = hash_file('sha256', $tmpPath);
    $rel = 'storage/imports/' . date('Y') . '/' . date('m') . '/' . $hash . '.' . $ext;
    $base = $root ?? dirname(__DIR__);
    $abs = $base . '/' . $rel;
    if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0750, true) && !is_dir(dirname($abs))) {
        throw new RuntimeException('Não consegui criar a pasta de importações no servidor.');
    }
    if (!is_file($abs)) {
        $ok = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $abs) : copy($tmpPath, $abs);
        if (!$ok) {
            throw new RuntimeException('Não consegui guardar o arquivo no servidor.');
        }
    }
    return ['name' => mb_substr(basename($originalName), 0, 255), 'hash' => $hash, 'path' => $rel, 'ext' => $ext];
}

/** Caminho absoluto do arquivo do lote. */
function nc_import_file_path(array $batch, ?string $root = null): string
{
    return ($root ?? dirname(__DIR__)) . '/' . ltrim((string)$batch['file_path'], '/');
}

/** Cria o lote (status enviado). Devolve o ID. */
function nc_import_create_batch(PDO $pdo, string $type, array $file, int $userId, ?string $sheet): int
{
    $q = $pdo->prepare(
        'INSERT INTO import_batches (import_type, file_name, file_hash, file_path, sheet_name, status, created_by)
         VALUES (?,?,?,?,?,?,?)'
    );
    $q->execute([$type, $file['name'], $file['hash'], $file['path'], $sheet, 'enviado', $userId]);
    return (int)$pdo->lastInsertId();
}

/** Lote confirmado anteriormente com o mesmo arquivo (aviso da etapa 1). */
function nc_import_previous_with_hash(PDO $pdo, string $hash, int $exceptId = 0): ?array
{
    $q = $pdo->prepare(
        "SELECT b.id, b.confirmed_at, u.name user_name FROM import_batches b LEFT JOIN users u ON u.id = b.confirmed_by
         WHERE b.file_hash = ? AND b.status = 'confirmado' AND b.id <> ? ORDER BY b.id DESC"
    );
    $q->execute([$hash, $exceptId]);
    $r = $q->fetch();
    return $r ?: null;
}

/* ------------------------------------------------------------------ */
/* Modelos de mapeamento                                               */
/* ------------------------------------------------------------------ */

function nc_import_find_template(PDO $pdo, string $type, string $signature): ?array
{
    $q = $pdo->prepare('SELECT * FROM import_templates WHERE import_type = ? AND header_signature = ?');
    $q->execute([$type, $signature]);
    $t = $q->fetch();
    return $t ?: null;
}

function nc_import_save_template(PDO $pdo, string $type, string $signature, array $mapping, array $valueMaps, int $userId, string $fileName): int
{
    $cur = nc_import_find_template($pdo, $type, $signature);
    $mappingJson = nc_import_json($mapping);
    $mapsJson = nc_import_json($valueMaps);
    if ($cur) {
        $pdo->prepare('UPDATE import_templates SET mapping_json = ?, value_maps_json = ?, updated_by = ? WHERE id = ?')
            ->execute([$mappingJson, $mapsJson, $userId, (int)$cur['id']]);
        return (int)$cur['id'];
    }
    $pdo->prepare(
        'INSERT INTO import_templates (import_type, name, header_signature, mapping_json, value_maps_json, external_source, updated_by)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([$type, mb_substr($fileName, 0, 120), $signature, $mappingJson, $mapsJson, nc_import_external_source($type), $userId]);
    return (int)$pdo->lastInsertId();
}

/** Valores distintos (e quantas vezes aparecem) de uma coluna, para o mapeamento de valores. @return array<string,int> */
function nc_import_distinct_values(array $dataRows, int $col, int $limit = 60): array
{
    $count = [];
    foreach ($dataRows as $r) {
        $v = nc_clean_cell((string)($r['cells'][$col] ?? ''));
        if ($v !== '') {
            $count[$v] = ($count[$v] ?? 0) + 1;
        }
    }
    arsort($count);
    return array_slice($count, 0, $limit, true);
}

/* ------------------------------------------------------------------ */
/* Staging (Etapas 2 → 3)                                              */
/* ------------------------------------------------------------------ */

/**
 * Lê a planilha e grava as linhas brutas em import_rows (pendentes de processamento).
 * Reprocessar o lote apaga o staging anterior (as decisões de revisão se perdem).
 *
 * @return int quantidade de linhas de dados
 */
function nc_import_stage(PDO $pdo, array $batch, array $config, ?string $root = null): int
{
    $sheet = nc_sheet_read(nc_import_file_path($batch, $root), ($batch['sheet_name'] ?? '') !== '' ? (string)$batch['sheet_name'] : null);
    $rows = $sheet['rows'];
    if (count($rows) < 2) {
        throw new RuntimeException('A planilha não tem linhas de dados abaixo do cabeçalho.');
    }
    $config['date1904'] = $sheet['date1904'];
    $config['header_row'] = $rows[0]['n'];
    $config['headers'] = $rows[0]['cells'];
    $data = array_slice($rows, 1);
    $config['total_rows'] = count($data);

    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('DELETE FROM import_rows WHERE batch_id = ?')->execute([(int)$batch['id']]);
        foreach (array_chunk($data, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?)'));
            $params = [];
            foreach ($chunk as $r) {
                array_push($params, (int)$batch['id'], (int)$r['n'], nc_import_json($r['cells']), 'ignorar', 'Aguardando processamento');
            }
            $pdo->prepare("INSERT INTO import_rows (batch_id, sheet_row, raw_json, classification, reason) VALUES $ph")->execute($params);
        }
        nc_import_batch_update($pdo, (int)$batch['id'], ['status' => 'mapeado', 'error_message' => null], [
            'config' => $config, 'rows' => count($data), 'processed' => 0,
        ]);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return count($data);
}

/** Quantas linhas ainda esperam processamento. */
function nc_import_pending(PDO $pdo, int $batchId): int
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM import_rows WHERE batch_id = ? AND normalized_json IS NULL');
    $q->execute([$batchId]);
    return (int)$q->fetchColumn();
}

/** Chave de duplicidade interna de uma linha de Contatos. */
function nc_import_contact_dedupe_key(array $n): string
{
    $c = $n['contact'];
    $t = (string)($n['ctoken'] ?? '');
    if ($c['ext'] !== '') {
        return 'x:' . strtolower($c['ext']);
    }
    if ($c['email'] !== '') {
        return 'e:' . $c['email'] . '|' . $t;
    }
    $digits = nc_phone_digits($c['phone']);
    if (strlen($digits) >= 8) {
        return 'p:' . $digits . '|' . $t;
    }
    return 'n:' . nc_norm($c['name']) . '|' . $t;
}

/** Assinatura dos dados de um orçamento (para distinguir duplicata idêntica de conflito). */
function nc_import_opp_signature(array $n): string
{
    return sha1(nc_import_json([$n['ref'], $n['date'], $n['client_name'], $n['amount'], $n['status_text'], $n['stage_text'], $n['line_text'],
        $n['seller_text'], $n['seller_email'], $n['doc']['digits'] ?? '', $n['loss_reason'], $n['notes'], $n['quote_reference'], $n['version']]));
}

/**
 * Processa o próximo bloco (500 linhas) da prévia. Devolve ['processed'=>n,'pending'=>m,'done'=>bool].
 * Ao terminar, o lote passa a em_revisao e os contadores são recalculados.
 */
function nc_import_process_chunk(PDO $pdo, array $batch, int $limit = NC_IMPORT_CHUNK): array
{
    $id = (int)$batch['id'];
    $type = (string)$batch['import_type'];
    $cfg = nc_import_config($batch);

    $q = $pdo->prepare('SELECT id, sheet_row, raw_json FROM import_rows WHERE batch_id = ? AND normalized_json IS NULL ORDER BY sheet_row LIMIT ' . (int)$limit);
    $q->execute([$id]);
    $pending = $q->fetchAll();
    if (!$pending) {
        return nc_import_finish_processing($pdo, $batch, 0);
    }

    // Já processadas (para detectar duplicidade dentro do arquivo, mantendo a primeira).
    $seen = ['keys' => [], 'emails' => [], 'refs' => []];
    $q = $pdo->prepare("SELECT id, sheet_row, classification, normalized_json FROM import_rows WHERE batch_id = ? AND normalized_json IS NOT NULL");
    $q->execute([$id]);
    foreach ($q->fetchAll() as $r) {
        $n = nc_import_decode($r['normalized_json']);
        if (!empty($n['_key']) && !in_array($r['classification'], ['erro', 'ignorar'], true)) {
            $seen['keys'][$n['_key']] = (int)$r['sheet_row'];
            if ($type === 'contatos' && !empty($n['contact']['email'])) {
                $seen['emails'][$n['contact']['email']] = ['ctoken' => (string)($n['ctoken'] ?? ''), 'row' => (int)$r['sheet_row']];
            }
        }
        if ($type === 'orcamentos' && !empty($n['ref']) && !empty($n['_sig'])) {
            $seen['refs'][$n['ref']] = ['id' => (int)$r['id'], 'row' => (int)$r['sheet_row'], 'sig' => $n['_sig'], 'conflict' => $r['classification'] === 'erro' && !empty($n['_conflict'])];
        }
    }

    $ctx = nc_import_context($pdo);
    $upd = $pdo->prepare(
        'UPDATE import_rows SET normalized_json = ?, classification = ?, reason = ?, match_rule = ?, company_id = ?, candidates_json = ? WHERE id = ?'
    );
    foreach ($pending as $row) {
        $cells = nc_import_decode($row['raw_json']);
        $values = nc_import_row_values($cells, $cfg['mapping']);
        $empty = !array_filter($values, static fn($v) => $v !== '');
        if ($empty || nc_import_row_is_total($cells)) {
            $upd->execute([nc_import_json(['kind' => $type, 'skipped' => true]),
                'ignorar', $empty ? 'Linha vazia.' : 'Linha de total/subtotal.', null, null, null, (int)$row['id']]);
            continue;
        }
        $res = $type === 'contatos'
            ? nc_import_classify_contact($pdo, $ctx, $values, null)
            : nc_import_classify_opp($pdo, $ctx, $batch, $cfg, $values, null);
        $n = $res['norm'];
        $class = $res['class'];
        $reason = $res['reason'];

        if (!in_array($class, ['erro', 'ignorar'], true)) {
            if ($type === 'contatos') {
                $n['_key'] = nc_import_contact_dedupe_key($n);
                if (isset($seen['keys'][$n['_key']])) {
                    $class = 'ignorar';
                    $reason = 'Repetida na planilha: igual à linha ' . $seen['keys'][$n['_key']] . ' (mantida a primeira).';
                } else {
                    $email = $n['contact']['email'] ?? '';
                    $prev = $email !== '' ? ($seen['emails'][$email] ?? null) : null;
                    if ($prev && $prev['ctoken'] !== ($n['ctoken'] ?? '') && !in_array($class, ['revisar'], true)) {
                        $class = 'revisar';
                        $reason = 'O mesmo e-mail aparece na linha ' . $prev['row'] . ' com outra empresa. O contato mudou de empresa?';
                    }
                    $seen['keys'][$n['_key']] = (int)$row['sheet_row'];
                    if ($email !== '') {
                        $seen['emails'][$email] = ['ctoken' => (string)($n['ctoken'] ?? ''), 'row' => (int)$row['sheet_row']];
                    }
                }
            } else {
                $n['_key'] = 'ref:' . $n['ref'];
                $n['_sig'] = nc_import_opp_signature($n);
                $prev = $seen['refs'][$n['ref']] ?? null;
                if ($prev) {
                    if ($prev['sig'] === $n['_sig'] && !$prev['conflict']) {
                        $class = 'ignorar';
                        $reason = 'Repetida na planilha: idêntica à linha ' . $prev['row'] . ' (mantida a primeira).';
                    } else {
                        $class = 'erro';
                        $reason = 'Número "' . $n['ref'] . '" repetido com dados diferentes na planilha (linha ' . $prev['row'] . ').';
                        $n['_conflict'] = true;
                        if (!$prev['conflict']) {
                            $pdo->prepare("UPDATE import_rows SET classification = 'erro', reason = ? WHERE id = ?")
                                ->execute(['Número "' . $n['ref'] . '" repetido com dados diferentes na planilha (linha ' . $row['sheet_row'] . ').', $prev['id']]);
                            $seen['refs'][$n['ref']]['conflict'] = true;
                        }
                    }
                } else {
                    $seen['refs'][$n['ref']] = ['id' => (int)$row['id'], 'row' => (int)$row['sheet_row'], 'sig' => $n['_sig'], 'conflict' => false];
                }
            }
        }
        if ($type === 'orcamentos' && !isset($n['_sig']) && !empty($n['ref'])) {
            $n['_sig'] = nc_import_opp_signature($n);
        }
        $n['warnings'] = array_values(array_unique((array)($n['warnings'] ?? [])));
        $upd->execute([
            nc_import_json($n), $class, $reason, $res['rule'] !== '' ? $res['rule'] : null, $res['company_id'],
            $res['candidates'] ? nc_import_json($res['candidates']) : null, (int)$row['id'],
        ]);
    }
    return nc_import_finish_processing($pdo, $batch, count($pending));
}

/** Fecha o processamento quando não há mais linhas pendentes. */
function nc_import_finish_processing(PDO $pdo, array $batch, int $processed): array
{
    $id = (int)$batch['id'];
    $left = nc_import_pending($pdo, $id);
    $done = $left === 0;
    if ($done) {
        nc_import_batch_update($pdo, $id, ['status' => 'em_revisao']);
        nc_import_recount($pdo, $id);
    }
    return ['processed' => $processed, 'pending' => $left, 'done' => $done];
}

/** Recalcula contadores e somas (R$ por classificação) a partir das linhas. */
function nc_import_recount(PDO $pdo, int $batchId): array
{
    $batch = nc_import_batch($pdo, $batchId);
    $type = (string)($batch['import_type'] ?? 'contatos');
    $counts = array_fill_keys(NC_IMPORT_CLASSES, 0);
    $sums = array_fill_keys(NC_IMPORT_CLASSES, 0.0);
    $total = 0.0;
    $resolved = 0;
    $q = $pdo->prepare('SELECT classification, normalized_json, resolution FROM import_rows WHERE batch_id = ?');
    $q->execute([$batchId]);
    while ($r = $q->fetch()) {
        $c = (string)$r['classification'];
        $counts[$c] = ($counts[$c] ?? 0) + 1;
        if (!empty($r['resolution'])) {
            $resolved++;
        }
        if ($type === 'orcamentos') {
            $n = nc_import_decode($r['normalized_json']);
            $amount = (float)($n['amount'] ?? 0);
            $sums[$c] = ($sums[$c] ?? 0) + $amount;
            $total += $amount;
        }
    }
    $data = $counts + ['revisados' => $resolved, 'sum' => array_map(static fn($v) => round($v, 2), $sums), 'sheet_total' => round($total, 2)];
    nc_import_batch_update($pdo, $batchId, [], $data);
    return $data;
}

/* ------------------------------------------------------------------ */
/* Revisão (Etapa 3)                                                   */
/* ------------------------------------------------------------------ */

function nc_import_row(PDO $pdo, int $rowId): ?array
{
    $q = $pdo->prepare('SELECT * FROM import_rows WHERE id = ?');
    $q->execute([$rowId]);
    $r = $q->fetch();
    return $r ?: null;
}

/** Reclassifica uma linha com a decisão humana. Atualiza import_rows. */
function nc_import_reclassify(PDO $pdo, array &$ctx, array $batch, array $row, ?array $force, int $userId): array
{
    $cfg = nc_import_config($batch);
    $type = (string)$batch['import_type'];
    $values = nc_import_row_values(nc_import_decode($row['raw_json']), $cfg['mapping']);
    if ($force && ($force['mode'] ?? '') === 'ignorada') {
        $old = nc_import_decode($row['normalized_json']);
        $res = ['class' => 'ignorar', 'reason' => 'Ignorada na revisão.', 'rule' => 'manual', 'company_id' => null, 'candidates' => [], 'norm' => $old];
    } else {
        $res = $type === 'contatos'
            ? nc_import_classify_contact($pdo, $ctx, $values, $force)
            : nc_import_classify_opp($pdo, $ctx, $batch, $cfg, $values, $force);
    }
    $old = nc_import_decode($row['normalized_json']);
    $n = $res['norm'];
    foreach (['_key', '_sig', '_conflict'] as $k) {
        if (isset($old[$k])) {
            $n[$k] = $old[$k];
        }
    }
    $resolution = null;
    if ($force) {
        $resolution = match ($force['mode'] ?? '') {
            'escolhida' => 'escolhida', 'criada' => 'criada', 'ignorada' => 'ignorada', default => null,
        };
    }
    $pdo->prepare(
        'UPDATE import_rows SET normalized_json = ?, classification = ?, reason = ?, match_rule = ?, company_id = ?, candidates_json = ?,
         resolved_company_id = ?, resolution = ?, resolved_by = ?, resolved_at = ' . ($resolution ? 'NOW()' : 'NULL') . ' WHERE id = ?'
    )->execute([
        nc_import_json($n), $res['class'], $res['reason'], $res['rule'] !== '' ? $res['rule'] : null, $res['company_id'],
        $res['candidates'] ? nc_import_json($res['candidates']) : null,
        ($force['mode'] ?? '') === 'escolhida' ? (int)$force['company_id'] : null, $resolution, $resolution ? $userId : null, (int)$row['id'],
    ]);
    return $res;
}

/** A decisão de uma linha (para reaplicar na revalidação). */
function nc_import_row_force(array $row): ?array
{
    return match ((string)($row['resolution'] ?? '')) {
        'escolhida' => ['mode' => 'escolhida', 'company_id' => (int)$row['resolved_company_id']],
        'criada' => ['mode' => 'criada'],
        'ignorada' => ['mode' => 'ignorada'],
        default => null,
    };
}

/** Nome do cliente na planilha (para "aplicar às outras linhas com o mesmo nome"). */
function nc_import_row_client_name(array $row): string
{
    $n = nc_import_decode($row['normalized_json'] ?? null);
    return nc_norm((string)($n['client_name'] ?? ($n['company']['name'] ?? '')));
}

/**
 * Decide uma linha em Revisar. $op: escolher | criar | ignorar | limpar. $applySame: aplica às outras linhas
 * em Revisar com o mesmo nome de cliente. Devolve quantas linhas foram decididas.
 */
function nc_import_resolve(PDO $pdo, array $batch, int $rowId, string $op, ?int $companyId, int $userId, bool $applySame = false): int
{
    if (!in_array($batch['status'], ['em_revisao', 'mapeado'], true)) {
        throw new RuntimeException('Este lote não aceita mais revisão.');
    }
    $row = nc_import_row($pdo, $rowId);
    if (!$row || (int)$row['batch_id'] !== (int)$batch['id']) {
        throw new RuntimeException('Linha não encontrada neste lote.');
    }
    $force = match ($op) {
        'escolher' => ['mode' => 'escolhida', 'company_id' => (int)$companyId],
        'criar' => ['mode' => 'criada'],
        'ignorar' => ['mode' => 'ignorada'],
        'limpar' => null,
        default => throw new RuntimeException('Ação de revisão inválida.'),
    };
    if ($op === 'criar' && $batch['import_type'] !== 'contatos') {
        throw new RuntimeException('Orçamento nunca cria empresa sozinho: escolha uma empresa existente (cadastre-a antes em Clientes).');
    }
    if ($op === 'escolher') {
        $q = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE id = ?');
        $q->execute([(int)$companyId]);
        if (!(int)$q->fetchColumn()) {
            throw new RuntimeException('Escolha a empresa na lista de sugestões.');
        }
    }
    $targets = [$row];
    if ($applySame && $op !== 'limpar') {
        $name = nc_import_row_client_name($row);
        if ($name !== '') {
            $q = $pdo->prepare("SELECT * FROM import_rows WHERE batch_id = ? AND classification = 'revisar' AND id <> ?");
            $q->execute([(int)$batch['id'], $rowId]);
            foreach ($q->fetchAll() as $other) {
                if (nc_import_row_client_name($other) === $name) {
                    $targets[] = $other;
                }
            }
        }
    }
    $ctx = nc_import_context($pdo);
    $pdo->beginTransaction();
    try {
        foreach ($targets as $t) {
            nc_import_reclassify($pdo, $ctx, $batch, $t, $force, $userId);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    nc_import_recount($pdo, (int)$batch['id']);
    return count($targets);
}

/** "Manter CRM" ou "Usar planilha" numa linha de Atualizar editada no CRM. */
function nc_import_set_mode(PDO $pdo, array $batch, int $rowId, string $mode): void
{
    if (!in_array($mode, ['crm', 'planilha'], true)) {
        throw new RuntimeException('Escolha "Manter CRM" ou "Usar planilha".');
    }
    $row = nc_import_row($pdo, $rowId);
    if (!$row || (int)$row['batch_id'] !== (int)$batch['id']) {
        throw new RuntimeException('Linha não encontrada neste lote.');
    }
    $pdo->prepare('UPDATE import_rows SET keep_crm_fields_json = ? WHERE id = ?')->execute([nc_import_json(['mode' => $mode]), $rowId]);
}

function nc_import_row_mode(array $row): string
{
    $k = nc_import_decode($row['keep_crm_fields_json'] ?? null);
    return ($k['mode'] ?? 'crm') === 'planilha' ? 'planilha' : 'crm';
}

/** Linha padrão do lote (obrigatória antes de confirmar se houver orçamento novo sem linha identificada). */
function nc_import_set_default_line(PDO $pdo, array $batch, int $lineId): void
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM product_lines WHERE id = ? AND active = 1');
    $q->execute([$lineId]);
    if (!(int)$q->fetchColumn()) {
        throw new RuntimeException('Linha de produto inválida.');
    }
    nc_import_batch_update($pdo, (int)$batch['id'], ['default_product_line_id' => $lineId]);
}

/* ------------------------------------------------------------------ */
/* Confirmação (Etapa 4)                                               */
/* ------------------------------------------------------------------ */

/** O que impede de confirmar agora? Vazio = pode confirmar. @return string[] */
function nc_import_confirm_blockers(PDO $pdo, array $batch): array
{
    $id = (int)$batch['id'];
    $out = [];
    if ($batch['status'] !== 'em_revisao') {
        $out[] = 'O lote não está em revisão.';
        return $out;
    }
    if (nc_import_pending($pdo, $id) > 0) {
        $out[] = 'A prévia ainda está sendo processada.';
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM import_rows WHERE batch_id = ? AND classification = 'revisar'");
    $q->execute([$id]);
    $revisar = (int)$q->fetchColumn();
    if ($revisar > 0) {
        $out[] = $revisar . ' linha(s) em Revisar precisam de decisão.';
    }
    if ($batch['import_type'] === 'orcamentos' && empty($batch['default_product_line_id'])) {
        $q = $pdo->prepare("SELECT normalized_json FROM import_rows WHERE batch_id = ? AND classification IN ('novo')");
        $q->execute([$id]);
        while ($r = $q->fetch()) {
            $n = nc_import_decode($r['normalized_json']);
            if (!empty($n['plan']['needs_default_line'])) {
                $out[] = 'Escolha a "Linha padrão para este lote": há orçamentos sem linha de produto identificada.';
                break;
            }
        }
    }
    return $out;
}

/** Resumo para o modal de confirmação. @return array<string,mixed> */
function nc_import_confirm_summary(PDO $pdo, array $batch): array
{
    $id = (int)$batch['id'];
    $type = (string)$batch['import_type'];
    $s = ['companies_new' => 0, 'contacts_new' => 0, 'opps_new' => 0, 'amount_new' => 0.0, 'updates' => 0, 'ignored' => 0, 'errors' => 0, 'same' => 0, 'kept_crm' => 0];
    $tokens = [];
    $q = $pdo->prepare('SELECT classification, normalized_json, keep_crm_fields_json FROM import_rows WHERE batch_id = ?');
    $q->execute([$id]);
    while ($r = $q->fetch()) {
        $c = $r['classification'];
        $n = nc_import_decode($r['normalized_json']);
        if ($c === 'novo') {
            if ($type === 'contatos') {
                $s['contacts_new']++;
                if (!empty($n['target']['new_company'])) {
                    $tokens[(string)($n['ctoken'] ?? ('r' . $r['normalized_json']))] = true;
                }
            } else {
                $s['opps_new']++;
                $s['amount_new'] += (float)($n['amount'] ?? 0);
            }
        } elseif ($c === 'atualizar') {
            if ($type === 'orcamentos' && !empty($n['target']['crm_edited']) && nc_import_row_mode($r) === 'crm') {
                $s['kept_crm']++;
            } else {
                $s['updates']++;
            }
        } elseif ($c === 'sem_alteracao') {
            $s['same']++;
        } elseif ($c === 'ignorar') {
            $s['ignored']++;
        } elseif ($c === 'erro') {
            $s['errors']++;
        }
    }
    $s['companies_new'] = count($tokens);
    return $s;
}

function nc_import_sql_set(array $cols): string
{
    return implode(', ', array_map(static fn($c) => "`$c` = ?", $cols));
}

/** Instantâneo (valores antigos) de colunas de um registro, para o desfazer. */
function nc_import_snapshot(PDO $pdo, string $table, int $id, array $cols): array
{
    $cols = array_values(array_unique(array_merge($cols, ['import_batch_id', 'import_synced_at', 'updated_at'])));
    $q = $pdo->prepare('SELECT ' . implode(',', array_map(static fn($c) => "`$c`", $cols)) . " FROM `$table` WHERE id = ?");
    $q->execute([$id]);
    return ['id' => $id, 'before' => (array)($q->fetch() ?: [])];
}

/** Encontra, entre as empresas criadas neste lote, a mesma empresa nova (ID externo, CNPJ ou nome exato único). */
function nc_import_registry_find(array $reg, array $co): ?int
{
    $doc = $co['doc'];
    if ($co['ext'] !== '' && isset($reg['ext'][strtolower($co['ext'])])) {
        return $reg['ext'][strtolower($co['ext'])];
    }
    if ($doc['kind'] === 'cnpj' && isset($reg['cnpj'][$doc['digits']])) {
        return $reg['cnpj'][$doc['digits']];
    }
    $key = nc_company_key($co['name']);
    $ids = $reg['key'][$key] ?? [];
    if (count($ids) === 1) {
        $other = $reg['data'][$ids[0]] ?? [];
        $cnpjOther = (string)($other['cnpj'] ?? '');
        if ($doc['kind'] !== 'cnpj' || $cnpjOther === '' || $cnpjOther === $doc['digits']) {
            return $ids[0];
        }
    }
    return null;
}

/**
 * Grava uma linha de Contatos. Devolve [result, previous].
 *
 * @return array{0:array,1:array}
 */
function nc_import_write_contact(PDO $pdo, array &$ctx, array &$reg, array $res, int $batchId, int $userId): array
{
    $n = $res['norm'];
    $t = $n['target'];
    $co = $n['company'];
    $c = $n['contact'];
    $doc = $co['doc'];
    $result = [];
    $previous = [];

    $companyId = $t['company_id'] ? (int)$t['company_id'] : 0;
    if ($companyId > 0) {
        if ($t['company_diff']) {
            $cols = array_keys($t['company_diff']);
            $previous['company'] = nc_import_snapshot($pdo, 'companies', $companyId, $cols);
            $set = [];
            foreach ($t['company_diff'] as $field => [$before, $after]) {
                $set[$field] = $after;
            }
            $assign = nc_import_sql_set(array_keys($set));
            $pdo->prepare("UPDATE companies SET $assign, import_batch_id = ?, import_synced_at = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute(array_merge(array_values($set), [$batchId, $companyId]));
            $result['company_updated'] = $companyId;
        }
    } else {
        $found = nc_import_registry_find($reg, $co);
        if ($found) {
            $companyId = $found;
            $fill = [];
            $have = $reg['data'][$found];
            $want = ['cnpj' => $doc['kind'] === 'cnpj' ? $doc['digits'] : '', 'alias' => $co['alias'], 'city' => $co['city'], 'state' => $co['state'], 'external_id' => $co['ext']];
            foreach ($want as $f => $v) {
                if ($v !== '' && (string)($have[$f] ?? '') === '') {
                    $fill[$f] = $v;
                    $reg['data'][$found][$f] = $v;
                }
            }
            if ($fill) {
                $pdo->prepare('UPDATE companies SET ' . nc_import_sql_set(array_keys($fill)) . ', import_synced_at = NOW(), updated_at = NOW() WHERE id = ?')
                    ->execute(array_merge(array_values($fill), [$found]));
            }
            $result['company_shared'] = $found;
        } else {
            if ($doc['kind'] === 'cnpj') {
                $q = $pdo->prepare('SELECT id FROM companies WHERE cnpj = ?');
                $q->execute([$doc['digits']]);
                if ($q->fetchColumn()) {
                    throw new RuntimeException('O CNPJ ' . format_cnpj($doc['digits']) . ' passou a existir durante a importação. Reabra a revisão.');
                }
            }
            $ownerId = $co['owner_email'] !== '' ? nc_import_find_user($ctx, $co['owner_email'], '') : null;
            $pdo->prepare(
                "INSERT INTO companies (name, alias, cnpj, person_document, city, state, external_id, owner_user_id, source, import_batch_id, import_synced_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,'xlsx',?,NOW(),NOW())"
            )->execute([
                $co['name'], $co['alias'] !== '' ? $co['alias'] : null, $doc['kind'] === 'cnpj' ? $doc['digits'] : null,
                $doc['kind'] === 'cpf' ? $doc['digits'] : null, $co['city'] !== '' ? $co['city'] : null, $co['state'] !== '' ? $co['state'] : null,
                $co['ext'] !== '' ? $co['ext'] : null, $ownerId, $batchId,
            ]);
            $companyId = (int)$pdo->lastInsertId();
            $result['company_created'] = $companyId;
            $reg['data'][$companyId] = ['cnpj' => $doc['kind'] === 'cnpj' ? $doc['digits'] : '', 'alias' => $co['alias'], 'city' => $co['city'], 'state' => $co['state'], 'external_id' => $co['ext']];
            if ($co['ext'] !== '') {
                $reg['ext'][strtolower($co['ext'])] = $companyId;
            }
            if ($doc['kind'] === 'cnpj') {
                $reg['cnpj'][$doc['digits']] = $companyId;
            }
            $reg['key'][nc_company_key($co['name'])][] = $companyId;
        }
    }

    $contactId = $t['contact_id'] ? (int)$t['contact_id'] : 0;
    if ($contactId > 0) {
        if ($t['contact_diff']) {
            $map = ['name' => 'name', 'email' => 'email', 'phone' => 'phone', 'job_title' => 'job_title', 'external_id' => 'external_id', 'company_id' => 'company_id'];
            $set = [];
            foreach ($t['contact_diff'] as $field => [$before, $after]) {
                $set[$map[$field]] = $field === 'company_id' ? $companyId : $after;
            }
            $previous['contact'] = nc_import_snapshot($pdo, 'contacts', $contactId, array_keys($set));
            $pdo->prepare('UPDATE contacts SET ' . nc_import_sql_set(array_keys($set)) . ', import_batch_id = ?, import_synced_at = NOW(), updated_at = NOW() WHERE id = ?')
                ->execute(array_merge(array_values($set), [$batchId, $contactId]));
            $result['contact_updated'] = $contactId;
        }
    } else {
        $pdo->prepare(
            "INSERT INTO contacts (company_id, name, email, phone, job_title, source, external_id, import_batch_id, import_synced_at, created_by, updated_at)
             VALUES (?,?,?,?,?,'xlsx',?,?,NOW(),?,NOW())"
        )->execute([
            $companyId ?: null, $c['name'], $c['email'] !== '' ? $c['email'] : null, $c['phone'] !== '' ? $c['phone'] : null,
            $c['job'] !== '' ? $c['job'] : null, $c['ext'] !== '' ? $c['ext'] : null, $batchId, $userId,
        ]);
        $result['contact_created'] = (int)$pdo->lastInsertId();
    }
    $result['company_id'] = $companyId;
    return [$result, $previous];
}

/**
 * Grava uma linha de Orçamentos. Devolve [result, previous].
 *
 * @return array{0:array,1:array}
 */
function nc_import_write_opp(PDO $pdo, array $row, array $res, array $batch, int $userId): array
{
    $n = $res['norm'];
    $t = $n['target'];
    $p = $n['plan'];
    $batchId = (int)$batch['id'];
    $source = nc_import_external_source('orcamentos');

    if (empty($t['opp_id'])) {
        if (!$p['line_id'] || !$p['funnel_id'] || !$p['stage_id']) {
            throw new RuntimeException('Linha ' . $row['sheet_row'] . ': sem linha de produto/funil/etapa para criar o orçamento.');
        }
        $number = generate_opportunity_number($pdo, $p['seller_id'] ?: null, (string)$n['date']);
        $pdo->prepare(
            "INSERT INTO opportunities
             (number, opportunity_date, client_name, company_id, seller_user_id, product_line_id, funnel_id, stage_id, item_type, amount, status,
              loss_reason, loss_recorded_at, quote_required, quote_reference, source, notes, created_by,
              external_source, external_ref, import_batch_id, import_synced_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?," . ($p['status'] === 'Perdida' ? 'NOW()' : 'NULL') . ",0,?,'xlsx',?,?,?,?,?,NOW(),NOW())"
        )->execute([
            $number, $n['date'], $p['client_name'], $p['company_id'], $p['seller_id'], $p['line_id'], $p['funnel_id'], $p['stage_id'],
            $p['item_type'] ?? '', $n['amount'], $p['status'], $p['status'] === 'Perdida' ? $p['loss_reason'] : null,
            $n['quote_reference'] !== '' ? $n['quote_reference'] : null, $p['notes'] !== '' ? $p['notes'] : null, $userId,
            $source, $n['ref'], $batchId,
        ]);
        return [['opp_created' => (int)$pdo->lastInsertId(), 'number' => $number, 'company_id' => $p['company_id']], []];
    }

    $oppId = (int)$t['opp_id'];
    $diff = $t['diff'];
    $set = [];
    foreach ($diff as $field => [$before, $after]) {
        switch ($field) {
            case 'opportunity_date':
                $set['opportunity_date'] = $n['date'];
                break;
            case 'amount':
                $set['amount'] = $n['amount'];
                break;
            case 'status':
                $set['status'] = $p['status'];
                $set['loss_reason'] = $p['status'] === 'Perdida' ? $p['loss_reason'] : null;
                break;
            case 'loss_reason':
                $set['loss_reason'] = $n['loss_reason'];
                break;
            case 'stage_id':
                $set['stage_id'] = (int)$after;
                break;
            case 'seller_user_id':
                $set['seller_user_id'] = (int)$after;
                break;
            case 'product_line_id':
                $set['product_line_id'] = (int)$after;
                $set['funnel_id'] = (int)$p['funnel_id'];
                $set['item_type'] = (string)$p['item_type'];
                if (!isset($diff['stage_id']) && $p['stage_id']) {
                    $set['stage_id'] = (int)$p['stage_id'];
                }
                break;
            case 'quote_reference':
                $set['quote_reference'] = $n['quote_reference'];
                break;
            case 'company_id':
                $set['company_id'] = $p['company_id'];
                $set['client_name'] = $p['client_name'];
                break;
        }
    }
    if (isset($set['status'])) {
        $set['loss_recorded_at'] = $p['status'] === 'Perdida' ? date('Y-m-d H:i:s') : null;
    }
    $previous = ['opp' => nc_import_snapshot($pdo, 'opportunities', $oppId, array_keys($set))];
    $pdo->prepare('UPDATE opportunities SET ' . nc_import_sql_set(array_keys($set)) . ', import_batch_id = ?, import_synced_at = NOW(), updated_at = NOW() WHERE id = ?')
        ->execute(array_merge(array_values($set), [$batchId, $oppId]));
    return [['opp_updated' => $oppId, 'company_id' => $p['company_id']], $previous];
}

/**
 * Confirma o lote: revalida, grava tudo em UMA transação e registra auditoria (V3, Etapa 4).
 *
 * @return array{ok:bool,message:string,counts?:array}
 */
function nc_import_confirm(PDO $pdo, array $batch, int $userId): array
{
    $id = (int)$batch['id'];
    $type = (string)$batch['import_type'];
    $blockers = nc_import_confirm_blockers($pdo, $batch);
    if ($blockers) {
        return ['ok' => false, 'message' => implode(' ', $blockers)];
    }

    // 1) Revalidação contra o banco ATUAL (nada é gravado aqui, exceto voltar linhas para Revisar).
    $ctx = nc_import_context($pdo);
    $cfg = nc_import_config($batch);
    $q = $pdo->prepare("SELECT * FROM import_rows WHERE batch_id = ? AND classification IN ('novo','atualizar','sem_alteracao') ORDER BY sheet_row");
    $q->execute([$id]);
    $rows = $q->fetchAll();
    $fresh = [];
    $changed = 0;
    foreach ($rows as $row) {
        $force = nc_import_row_force($row);
        $values = nc_import_row_values(nc_import_decode($row['raw_json']), $cfg['mapping']);
        $res = $type === 'contatos'
            ? nc_import_classify_contact($pdo, $ctx, $values, $force)
            : nc_import_classify_opp($pdo, $ctx, $batch, $cfg, $values, $force);
        $old = nc_import_decode($row['normalized_json']);
        $sameTarget = ($res['norm']['target']['contact_id'] ?? null) === ($old['target']['contact_id'] ?? null)
            && ($res['norm']['target']['opp_id'] ?? null) === ($old['target']['opp_id'] ?? null)
            && !empty($res['norm']['target']['new_company']) === !empty($old['target']['new_company']);
        if ($res['class'] !== $row['classification'] || (int)$res['company_id'] !== (int)$row['company_id'] || !$sameTarget) {
            $changed++;
            $pdo->prepare("UPDATE import_rows SET classification = 'revisar', reason = ?, candidates_json = ? WHERE id = ?")->execute([
                'Mudou desde a prévia (antes: ' . $row['classification'] . '; agora: ' . $res['class'] . '). Confira e decida.',
                $res['candidates'] ? nc_import_json($res['candidates']) : null, (int)$row['id'],
            ]);
            continue;
        }
        foreach (['_key', '_sig', '_conflict'] as $k) {
            if (isset($old[$k])) {
                $res['norm'][$k] = $old[$k];
            }
        }
        $fresh[(int)$row['id']] = $res;
    }
    if ($changed > 0) {
        nc_import_recount($pdo, $id);
        return ['ok' => false, 'message' => $changed . ' linha(s) mudaram no CRM desde a prévia e voltaram para Revisar. Nada foi gravado.'];
    }

    // 2) Gravação em uma transação.
    $counts = ['empresas_criadas' => 0, 'empresas_atualizadas' => 0, 'contatos_criados' => 0, 'contatos_atualizados' => 0,
        'orcamentos_criados' => 0, 'orcamentos_atualizados' => 0, 'mantidos_crm' => 0, 'sem_alteracao' => 0];
    $reg = ['ext' => [], 'cnpj' => [], 'key' => [], 'data' => []];
    $pdo->beginTransaction();
    try {
        $save = $pdo->prepare('UPDATE import_rows SET result_json = ?, previous_json = ? WHERE id = ?');
        foreach ($rows as $row) {
            $res = $fresh[(int)$row['id']];
            if ($row['classification'] === 'sem_alteracao') {
                $counts['sem_alteracao']++;
                continue;
            }
            if ($type === 'contatos') {
                [$result, $previous] = nc_import_write_contact($pdo, $ctx, $reg, $res, $id, $userId);
                $counts['empresas_criadas'] += isset($result['company_created']) ? 1 : 0;
                $counts['empresas_atualizadas'] += isset($result['company_updated']) ? 1 : 0;
                $counts['contatos_criados'] += isset($result['contact_created']) ? 1 : 0;
                $counts['contatos_atualizados'] += isset($result['contact_updated']) ? 1 : 0;
            } else {
                $edited = !empty($res['norm']['target']['crm_edited']);
                if ($row['classification'] === 'atualizar' && $edited && nc_import_row_mode($row) === 'crm') {
                    $counts['mantidos_crm']++;
                    $save->execute([nc_import_json(['mantido_crm' => true]), null, (int)$row['id']]);
                    continue;
                }
                [$result, $previous] = nc_import_write_opp($pdo, $row, $res, $batch, $userId);
                $counts['orcamentos_criados'] += isset($result['opp_created']) ? 1 : 0;
                $counts['orcamentos_atualizados'] += isset($result['opp_updated']) ? 1 : 0;
            }
            $save->execute([nc_import_json($result), $previous ? nc_import_json($previous) : null, (int)$row['id']]);
        }
        $pdo->prepare("UPDATE import_batches SET status = 'confirmado', confirmed_by = ?, confirmed_at = NOW(), error_message = NULL WHERE id = ?")
            ->execute([$userId, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        nc_import_batch_update($pdo, $id, ['status' => 'falhou', 'error_message' => mb_substr($e->getMessage(), 0, 1000)]);
        return ['ok' => false, 'message' => 'A importação falhou e nada foi gravado: ' . $e->getMessage()];
    }
    nc_import_batch_update($pdo, $id, [], ['result' => $counts]);
    nc_import_recount($pdo, $id);
    audit($pdo, $userId, 'import_confirm', 'import_batch', $id, ['type' => $type, 'result' => $counts, 'file' => $batch['file_name']]);
    return ['ok' => true, 'message' => 'Importação concluída.', 'counts' => $counts];
}

/* ------------------------------------------------------------------ */
/* Desfazer (V3, 6.3)                                                  */
/* ------------------------------------------------------------------ */

/** Pode desfazer? (admin, lote confirmado há no máximo 7 dias). Devolve '' ou o motivo. */
function nc_import_undo_blocker(array $batch, array $user): string
{
    if (($user['role'] ?? '') !== 'admin') {
        return 'Somente administradores desfazem importações.';
    }
    if ($batch['status'] !== 'confirmado') {
        return 'Só é possível desfazer lotes confirmados.';
    }
    $at = strtotime((string)$batch['confirmed_at']);
    if (!$at || $at < strtotime('-' . NC_IMPORT_UNDO_DAYS . ' days')) {
        return 'O prazo de ' . NC_IMPORT_UNDO_DAYS . ' dias para desfazer já passou.';
    }
    return '';
}

/** @return array{string,string} [updated_at, import_batch_id] atuais do registro, ou ['',''] se não existir */
function nc_import_current_state(PDO $pdo, string $table, int $id): array
{
    $q = $pdo->prepare("SELECT updated_at, import_batch_id FROM `$table` WHERE id = ?");
    $q->execute([$id]);
    $r = $q->fetch();
    return $r ? [(string)$r['updated_at'], (string)$r['import_batch_id']] : ['', ''];
}

/**
 * Desfaz o lote. Registros criados só são excluídos se não foram alterados depois nem têm filhos novos;
 * registros atualizados só voltam ao valor anterior se não foram alterados depois. Nada é apagado à força.
 *
 * @return array{done:int,skipped:array<int,array{what:string,id:int,why:string}>}
 */
function nc_import_undo(PDO $pdo, array $batch, int $userId): array
{
    $id = (int)$batch['id'];
    $confirmedAt = (string)$batch['confirmed_at'];
    $q = $pdo->prepare('SELECT id, sheet_row, result_json, previous_json FROM import_rows WHERE batch_id = ? ORDER BY sheet_row DESC');
    $q->execute([$id]);
    $rows = $q->fetchAll();
    $done = 0;
    $skipped = [];
    $edited = static fn(string $updated): bool => $updated !== '' && $updated > $confirmedAt;
    $hasOppId = nc_column_exists($pdo, 'orders', 'opportunity_id');

    $pdo->beginTransaction();
    try {
        // 1º filhos (orçamentos e contatos), 2º empresas.
        $companyRows = [];
        foreach ($rows as $r) {
            $res = nc_import_decode($r['result_json']);
            $prev = nc_import_decode($r['previous_json']);
            if (!empty($res['opp_created'])) {
                $oid = (int)$res['opp_created'];
                [$upd, $bat] = nc_import_current_state($pdo, 'opportunities', $oid);
                if ($upd === '') {
                    continue;
                }
                $notes = $pdo->prepare('SELECT COUNT(*) FROM opportunity_notes WHERE opportunity_id = ?');
                $notes->execute([$oid]);
                $orders = 0;
                if ($hasOppId) {
                    $o = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE opportunity_id = ?');
                    $o->execute([$oid]);
                    $orders = (int)$o->fetchColumn();
                }
                if ($edited($upd) || (int)$bat !== $id) {
                    $skipped[] = ['what' => 'Orçamento', 'id' => $oid, 'why' => 'alterado depois da importação (ou por outro lote)'];
                } elseif ((int)$notes->fetchColumn() > 0 || $orders > 0) {
                    $skipped[] = ['what' => 'Orçamento', 'id' => $oid, 'why' => 'tem anotações ou pedidos'];
                } else {
                    $pdo->prepare('DELETE FROM opportunities WHERE id = ?')->execute([$oid]);
                    $done++;
                }
            }
            if (!empty($prev['opp'])) {
                $done += nc_import_restore($pdo, 'opportunities', 'Orçamento', $prev['opp'], $id, $confirmedAt, $skipped);
            }
            if (!empty($res['contact_created'])) {
                $cid = (int)$res['contact_created'];
                [$upd, $bat] = nc_import_current_state($pdo, 'contacts', $cid);
                if ($upd === '') {
                    continue;
                }
                if ($edited($upd) || (int)$bat !== $id) {
                    $skipped[] = ['what' => 'Contato', 'id' => $cid, 'why' => 'alterado depois da importação'];
                } else {
                    $pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$cid]);
                    $done++;
                }
            }
            if (!empty($prev['contact'])) {
                $done += nc_import_restore($pdo, 'contacts', 'Contato', $prev['contact'], $id, $confirmedAt, $skipped);
            }
            if (!empty($res['company_created']) || !empty($prev['company'])) {
                $companyRows[] = [$res, $prev];
            }
        }
        foreach ($companyRows as [$res, $prev]) {
            if (!empty($res['company_created'])) {
                $coid = (int)$res['company_created'];
                [$upd, $bat] = nc_import_current_state($pdo, 'companies', $coid);
                if ($upd === '') {
                    continue;
                }
                $name = $pdo->prepare('SELECT name FROM companies WHERE id = ?');
                $name->execute([$coid]);
                $coName = (string)$name->fetchColumn();
                $kids = 0;
                foreach (['contacts', 'opportunities'] as $tbl) {
                    $k = $pdo->prepare("SELECT COUNT(*) FROM $tbl WHERE company_id = ?");
                    $k->execute([$coid]);
                    $kids += (int)$k->fetchColumn();
                }
                foreach (['orders', 'visits'] as $tbl) {
                    if (nc_table_exists($pdo, $tbl)) {
                        $k = $pdo->prepare("SELECT COUNT(*) FROM $tbl WHERE client_name = ?");
                        $k->execute([$coName]);
                        $kids += (int)$k->fetchColumn();
                    }
                }
                if ($edited($upd) || (int)$bat !== $id) {
                    $skipped[] = ['what' => 'Empresa', 'id' => $coid, 'why' => 'alterada depois da importação'];
                } elseif ($kids > 0) {
                    $skipped[] = ['what' => 'Empresa', 'id' => $coid, 'why' => 'tem contatos, oportunidades, pedidos ou visitas de outras origens'];
                } else {
                    $pdo->prepare('DELETE FROM companies WHERE id = ?')->execute([$coid]);
                    $done++;
                }
            }
            if (!empty($prev['company'])) {
                $done += nc_import_restore($pdo, 'companies', 'Empresa', $prev['company'], $id, $confirmedAt, $skipped);
            }
        }
        $pdo->prepare("UPDATE import_batches SET status = 'desfeito', undone_by = ?, undone_at = NOW() WHERE id = ?")->execute([$userId, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    nc_import_batch_update($pdo, $id, [], ['undo' => ['done' => $done, 'skipped' => $skipped]]);
    audit($pdo, $userId, 'import_undo', 'import_batch', $id, ['done' => $done, 'skipped' => count($skipped)]);
    return ['done' => $done, 'skipped' => $skipped];
}

/** Restaura um registro atualizado, se ninguém mexeu depois. Devolve 1 se restaurou. */
function nc_import_restore(PDO $pdo, string $table, string $label, array $snap, int $batchId, string $confirmedAt, array &$skipped): int
{
    $rid = (int)$snap['id'];
    [$upd, $batch] = nc_import_current_state($pdo, $table, $rid);
    if ($upd === '') {
        return 0;
    }
    if ($upd > $confirmedAt || (int)$batch !== $batchId) {
        $skipped[] = ['what' => $label, 'id' => $rid, 'why' => 'alterado depois da importação'];
        return 0;
    }
    $before = (array)$snap['before'];
    if (!$before) {
        return 0;
    }
    // updated_at entra explicitamente no SET, para o MySQL não trocar pela hora atual (ON UPDATE).
    $pdo->prepare("UPDATE `$table` SET " . nc_import_sql_set(array_keys($before)) . ' WHERE id = ?')
        ->execute(array_merge(array_values($before), [$rid]));
    return 1;
}

/* ------------------------------------------------------------------ */
/* CSV (prévia, erros e relatório)                                     */
/* ------------------------------------------------------------------ */

/** Neutraliza fórmulas ao abrir no Excel (= + - @). */
function nc_import_csv_cell(mixed $v): string
{
    $s = (string)$v;
    return ($s !== '' && in_array($s[0], ['=', '+', '-', '@'], true)) ? "'" . $s : $s;
}

/** Linhas do CSV (cabeçalho + dados). @param string $kind previa | erros | relatorio @return iterable<int,array<int,string>> */
function nc_import_csv_rows(PDO $pdo, array $batch, string $kind): iterable
{
    $type = (string)$batch['import_type'];
    $labels = ['novo' => 'Novo', 'atualizar' => 'Atualizar', 'sem_alteracao' => 'Sem alteração', 'revisar' => 'Revisar', 'erro' => 'Erro', 'ignorar' => 'Ignorar'];
    yield ['Linha da planilha', 'Classificação', 'Motivo', 'Regra do vínculo', 'Empresa vinculada', 'CNPJ da empresa',
        $type === 'orcamentos' ? 'Nº origem' : 'Contato', $type === 'orcamentos' ? 'Valor' : 'E-mail', $type === 'orcamentos' ? 'Status' : 'Telefone',
        'Avisos', 'Resultado'];
    $q = $pdo->prepare('SELECT * FROM import_rows WHERE batch_id = ? ORDER BY sheet_row');
    $q->execute([(int)$batch['id']]);
    $names = [];
    $nameOf = static function (int $cid) use ($pdo, &$names): array {
        if (!isset($names[$cid])) {
            $s = $pdo->prepare('SELECT name, cnpj FROM companies WHERE id = ?');
            $s->execute([$cid]);
            $names[$cid] = $s->fetch() ?: ['name' => '', 'cnpj' => ''];
        }
        return $names[$cid];
    };
    while ($r = $q->fetch()) {
        if ($kind === 'erros' && !in_array($r['classification'], ['erro', 'revisar'], true)) {
            continue;
        }
        $n = nc_import_decode($r['normalized_json']);
        $res = nc_import_decode($r['result_json']);
        $co = (int)$r['company_id'] > 0 ? $nameOf((int)$r['company_id']) : ['name' => '', 'cnpj' => ''];
        $resultText = '';
        foreach (['company_created' => 'empresa criada', 'contact_created' => 'contato criado', 'opp_created' => 'orçamento criado',
            'company_updated' => 'empresa atualizada', 'contact_updated' => 'contato atualizado', 'opp_updated' => 'orçamento atualizado', 'mantido_crm' => 'mantido o CRM'] as $k => $txt) {
            if (!empty($res[$k])) {
                $resultText .= ($resultText ? '; ' : '') . $txt;
            }
        }
        yield array_map('nc_import_csv_cell', [
            $r['sheet_row'], $labels[$r['classification']] ?? $r['classification'], $r['reason'], $r['match_rule'],
            $co['name'], $co['cnpj'] ? format_cnpj((string)$co['cnpj']) : '',
            $type === 'orcamentos' ? ($n['ref'] ?? '') : ($n['contact']['name'] ?? ''),
            $type === 'orcamentos' ? (isset($n['amount']) ? number_format((float)$n['amount'], 2, ',', '.') : '') : ($n['contact']['email'] ?? ''),
            $type === 'orcamentos' ? ($n['plan']['status'] ?? ($n['status_text'] ?? '')) : ($n['contact']['phone'] ?? ''),
            implode(' | ', (array)($n['warnings'] ?? [])), $resultText,
        ]);
    }
}

/* ------------------------------------------------------------------ */
/* Completar empresas pelo CNPJá, depois da confirmação (V3, 5.6)      */
/* ------------------------------------------------------------------ */

/** Campos que o CNPJá pode preencher (somente quando estão vazios na empresa). */
const NC_IMPORT_CNPJA_FIELDS = ['alias', 'registration_status', 'registration_status_date', 'founded', 'company_type', 'company_size', 'legal_nature',
    'capital_social', 'email', 'phone', 'zip', 'street', 'address_number', 'address_details', 'district', 'industry', 'main_activity_code', 'city', 'state'];

/** Empresas criadas pelo lote, com CNPJ válido, ainda não consultadas. @return array<int,array{id:int,cnpj:string}> */
function nc_import_cnpja_pending(PDO $pdo, int $batchId): array
{
    if (!nc_column_exists($pdo, 'companies', 'cnpja_checked_at')) {
        return [];
    }
    $q = $pdo->prepare(
        "SELECT id, cnpj FROM companies WHERE import_batch_id = ? AND source = 'xlsx' AND cnpj IS NOT NULL AND cnpj <> '' AND cnpja_checked_at IS NULL ORDER BY id"
    );
    $q->execute([$batchId]);
    return array_values(array_filter($q->fetchAll(), static fn($r) => cnpj_is_valid((string)$r['cnpj'])));
}

/**
 * Consulta UMA empresa da fila (o chamador espaça as chamadas) e preenche só campos vazios. Nunca troca o nome.
 * updated_at fica como estava, para a consulta automática não contar como "alteração depois" no desfazer.
 *
 * @return array{done:bool,remaining:int,filled:int,error:string,fatal:bool}
 */
function nc_import_cnpja_step(PDO $pdo, int $batchId): array
{
    $pending = nc_import_cnpja_pending($pdo, $batchId);
    if (!$pending) {
        return ['done' => true, 'remaining' => 0, 'filled' => 0, 'error' => '', 'fatal' => false];
    }
    $row = $pending[0];
    $id = (int)$row['id'];
    try {
        $data = cnpja_lookup($pdo, (string)$row['cnpj']);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $fatal = str_contains($msg, 'Configure e ative') || str_contains($msg, 'cURL') || str_contains($msg, 'Falha de conexão');
        if (!$fatal) {
            // CNPJ que a CNPJá não conhece: marca como consultado para a fila andar.
            $pdo->prepare('UPDATE companies SET cnpja_checked_at = NOW(), updated_at = updated_at WHERE id = ?')->execute([$id]);
        }
        return ['done' => $fatal, 'remaining' => count($pending) - ($fatal ? 0 : 1), 'filled' => 0, 'error' => $msg, 'fatal' => $fatal];
    }
    $q = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $q->execute([$id]);
    $cur = (array)$q->fetch();
    $set = [];
    foreach (NC_IMPORT_CNPJA_FIELDS as $f) {
        $new = $data[$f] ?? null;
        if (($new === null || $new === '') || !array_key_exists($f, $cur) || trim((string)$cur[$f]) !== '') {
            continue;
        }
        if (in_array($f, ['registration_status_date', 'founded'], true) && !valid_date_string((string)$new)) {
            continue;
        }
        $set[$f] = $f === 'state' ? strtoupper((string)$new) : $new;
    }
    $set['cnpja_checked_at'] = date('Y-m-d H:i:s');
    if (!empty($data['cnpja_updated_at'])) {
        $set['cnpja_updated_at'] = date('Y-m-d H:i:s', strtotime((string)$data['cnpja_updated_at']) ?: time());
    }
    $set['cnpja_json'] = nc_import_json($data['raw'] ?? []);
    $pdo->prepare('UPDATE companies SET ' . nc_import_sql_set(array_keys($set)) . ', updated_at = updated_at WHERE id = ?')
        ->execute(array_merge(array_values($set), [$id]));
    return ['done' => count($pending) <= 1, 'remaining' => count($pending) - 1, 'filled' => count($set) - 3, 'error' => '', 'fatal' => false];
}
