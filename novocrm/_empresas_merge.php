<?php
declare(strict_types=1);

/**
 * NOVOCRM v3 — mesclar empresas duplicadas (V3, seção 7). Somente admin (a tela confere o papel).
 * Move contatos e oportunidades (as únicas tabelas com company_id), decide os campos, guarda os IDs
 * externos da duplicada em companies.merged_ids_json e exclui a duplicada, tudo em uma transação.
 */
require_once __DIR__ . '/_empresas.php';

/** Campos que o usuário escolhe lado a lado (coluna => rótulo). */
const NC_MERGE_PICK_FIELDS = [
    'name' => 'Razão social', 'alias' => 'Nome fantasia', 'cnpj' => 'CNPJ', 'email' => 'E-mail', 'phone' => 'Telefone',
    'zip' => 'CEP', 'street' => 'Logradouro', 'address_number' => 'Número', 'address_details' => 'Complemento',
    'district' => 'Bairro', 'city' => 'Cidade', 'state' => 'UF', 'industry' => 'Segmento', 'owner_user_id' => 'Responsável',
];

/** Campos preenchidos automaticamente na principal quando estiverem vazios nela. */
const NC_MERGE_FILL_FIELDS = [
    'registration_status', 'registration_status_date', 'founded', 'company_type', 'company_size', 'legal_nature',
    'capital_social', 'main_activity_code', 'website', 'cnpja_updated_at', 'cnpja_checked_at', 'cnpja_json',
    'rd_crm_id', 'rd_marketing_uuid', 'external_id', 'person_document', 'portfolio_status',
];

function nc_merge_blank(mixed $v): bool
{
    return $v === null || trim((string)$v) === '';
}

/**
 * Escolha padrão por campo: valor da principal se houver; senão o primeiro preenchido entre as demais.
 * No CNPJ, um CNPJ válido sempre vence um inválido ou vazio.
 *
 * @param array<int,array<string,mixed>> $companies id => linha
 * @return array<string,int> campo => id da empresa de onde vem o valor
 */
function nc_merge_default_pick(array $companies, int $principalId): array
{
    $order = array_merge([$principalId], array_values(array_diff(array_keys($companies), [$principalId])));
    $pick = [];
    foreach (array_keys(NC_MERGE_PICK_FIELDS) as $field) {
        $pick[$field] = $principalId;
        foreach ($order as $id) {
            $value = $companies[$id][$field] ?? null;
            $ok = $field === 'cnpj' ? nc_valid_cnpj_or_empty((string)$value) !== '' : !nc_merge_blank($value);
            if ($ok) {
                $pick[$field] = $id;
                break;
            }
        }
    }
    return $pick;
}

/**
 * Mescla $dupIds em $principalId. Bloqueia se houver CNPJs válidos diferentes (empresas diferentes,
 * ex.: matriz e filial).
 *
 * @param array<string,int> $pick campo => id da empresa de onde vem o valor (campos fora da lista são ignorados)
 * @return array{moved_contacts:int,moved_opportunities:int,deleted:int[]}
 */
function nc_company_merge(PDO $pdo, int $principalId, array $dupIds, array $pick, int $userId): array
{
    $dupIds = array_values(array_unique(array_filter(
        array_map('intval', $dupIds),
        static fn($i) => $i > 0 && $i !== $principalId
    )));
    if ($principalId < 1 || !$dupIds) {
        throw new RuntimeException('Escolha a empresa principal e ao menos uma duplicada.');
    }
    if (!nc_column_exists($pdo, 'companies', 'merged_ids_json')) {
        throw new RuntimeException('Aplique as migrações do NOVOCRM (008_import_tracking_columns) antes de mesclar.');
    }

    $all = array_merge([$principalId], $dupIds);
    $in = implode(',', array_fill(0, count($all), '?'));

    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT * FROM companies WHERE id IN ($in) FOR UPDATE");
        $q->execute($all);
        $rows = [];
        foreach ($q->fetchAll() as $r) {
            $rows[(int)$r['id']] = $r;
        }
        if (count($rows) !== count($all)) {
            throw new RuntimeException('Uma das empresas não existe mais. Atualize a lista e tente de novo.');
        }

        $cnpjs = [];
        foreach ($rows as $r) {
            $c = nc_valid_cnpj_or_empty((string)($r['cnpj'] ?? ''));
            if ($c !== '') {
                $cnpjs[$c] = true;
            }
        }
        if (count($cnpjs) > 1) {
            throw new RuntimeException('Estas empresas têm CNPJs válidos diferentes (por exemplo matriz e filial). Não é possível mesclar.');
        }

        // Valores finais da principal.
        $default = nc_merge_default_pick($rows, $principalId);
        $set = [];
        foreach (array_keys(NC_MERGE_PICK_FIELDS) as $field) {
            if (!nc_column_exists($pdo, 'companies', $field)) {
                continue;
            }
            $from = (int)($pick[$field] ?? $default[$field]);
            if (!isset($rows[$from])) {
                $from = $default[$field];
            }
            $value = $rows[$from][$field] ?? null;
            if (nc_merge_blank($value)) { // escolheu um valor vazio: usa o padrão (primeiro preenchido)
                $value = $rows[$default[$field]][$field] ?? null;
            }
            if ($field === 'cnpj') {
                $d = cnpj_digits((string)$value);
                $value = $d !== '' ? $d : null;
            }
            $set[$field] = nc_merge_blank($value) ? null : $value;
        }
        foreach (NC_MERGE_FILL_FIELDS as $field) {
            if (!nc_column_exists($pdo, 'companies', $field) || !nc_merge_blank($rows[$principalId][$field] ?? null)) {
                continue;
            }
            foreach ($dupIds as $id) {
                if (!nc_merge_blank($rows[$id][$field] ?? null)) {
                    $set[$field] = $rows[$id][$field];
                    break;
                }
            }
        }

        // IDs e nomes das duplicadas ficam guardados na principal.
        $meta = json_decode((string)($rows[$principalId]['merged_ids_json'] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $meta['merged'] = is_array($meta['merged'] ?? null) ? $meta['merged'] : [];
        foreach ($dupIds as $id) {
            $d = $rows[$id];
            $meta['merged'][] = [
                'id' => $id, 'name' => (string)$d['name'], 'alias' => (string)($d['alias'] ?? ''),
                'cnpj' => (string)($d['cnpj'] ?? ''), 'rd_crm_id' => (string)($d['rd_crm_id'] ?? ''),
                'rd_marketing_uuid' => (string)($d['rd_marketing_uuid'] ?? ''), 'external_id' => (string)($d['external_id'] ?? ''),
                'merged_at' => date('Y-m-d H:i:s'), 'merged_by' => $userId,
            ];
        }
        $set['merged_ids_json'] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Registro da mesclagem (migração 011): a linha completa de cada empresa apagada e o que foi movido dela.
        if (nc_table_exists($pdo, 'company_merge_log')) {
            $log = $pdo->prepare(
                'INSERT INTO company_merge_log (principal_id, dup_id, dup_json, moved_contacts_json, moved_opps_json, merged_by) VALUES (?,?,?,?,?,?)'
            );
            foreach ($dupIds as $dupId) {
                $ids = [];
                foreach (['contacts', 'opportunities'] as $table) {
                    $s = $pdo->prepare("SELECT id FROM $table WHERE company_id = ?");
                    $s->execute([$dupId]);
                    $ids[$table] = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
                }
                $log->execute([
                    $principalId, $dupId, json_encode($rows[$dupId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                    json_encode($ids['contacts']), json_encode($ids['opportunities']), $userId > 0 ? $userId : null,
                ]);
            }
        }

        // Move contatos e oportunidades.
        $inDup = implode(',', array_fill(0, count($dupIds), '?'));
        $moved = [];
        foreach (['contacts', 'opportunities'] as $table) {
            $q = $pdo->prepare("SELECT id FROM $table WHERE company_id IN ($inDup)");
            $q->execute($dupIds);
            $moved[$table] = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            $pdo->prepare("UPDATE $table SET company_id = ? WHERE company_id IN ($inDup)")
                ->execute(array_merge([$principalId], $dupIds));
        }

        $assign = implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($set)));
        $pdo->prepare("UPDATE companies SET $assign WHERE id = ?")->execute(array_merge(array_values($set), [$principalId]));
        $pdo->prepare("DELETE FROM companies WHERE id IN ($inDup)")->execute($dupIds);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $cap = static fn(array $list): array => ['count' => count($list), 'ids' => array_slice($list, 0, 500)];
    audit($pdo, $userId, 'company_merge', 'company', $principalId, [
        'merged_company_ids' => $dupIds,
        'contacts_moved' => $cap($moved['contacts']),
        'opportunities_moved' => $cap($moved['opportunities']),
        'fields' => array_diff_key($set, ['cnpja_json' => 1, 'merged_ids_json' => 1]),
        'via' => 'novocrm',
    ]);

    return ['moved_contacts' => count($moved['contacts']), 'moved_opportunities' => count($moved['opportunities']), 'deleted' => $dupIds];
}

/** Nomes das empresas já mescladas nesta (a ficha continua achando pedidos e visitas pelo nome antigo). */
function nc_merged_names(array $company): array
{
    $meta = json_decode((string)($company['merged_ids_json'] ?? ''), true);
    $names = [];
    foreach (is_array($meta['merged'] ?? null) ? $meta['merged'] : [] as $m) {
        foreach (['name', 'alias'] as $k) {
            if (!empty($m[$k])) {
                $names[] = (string)$m[$k];
            }
        }
    }
    return $names;
}
