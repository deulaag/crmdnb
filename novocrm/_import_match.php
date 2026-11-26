<?php
declare(strict_types=1);

/**
 * NOVOCRM v3 — vínculo com a empresa e classificação das linhas (V3, seções 4, 5 e 6).
 *
 * PRINCÍPIO: nenhum orçamento ou proposta pode ficar no cliente errado. O vínculo é sempre determinístico
 * (ID externo, CNPJ, e-mail do contato, nome normalizado exato e ÚNICO). Nunca por semelhança, por "contém" nem pela primeira ocorrência.
 * A semelhança só serve para sugerir candidatas na revisão humana.
 */

require_once __DIR__ . '/_import.php';

/** Menor semelhança de nome (%) aceita quando o CNPJ bate (V3, 5.3). */
const NC_IMPORT_MIN_NAME_SIM = 60.0;
/** Semelhança (%) acima da qual uma empresa "parecida" impede criar empresa nova em Contatos (V3, 5.3). */
const NC_IMPORT_SIMILAR_SIM = 80.0;
/** Orçamento com data além deste prazo no futuro vai para Revisar. */
const NC_IMPORT_MAX_FUTURE = '+1 year';

/* ------------------------------------------------------------------ */
/* Contexto: índices em memória das empresas                           */
/* ------------------------------------------------------------------ */

/** @param int[] $ids @return int[] */
function nc_import_ids(array $ids): array
{
    return array_values(array_unique(array_map('intval', $ids)));
}

/** Semelhança de duas chaves de nome, em %. */
function nc_import_similarity(string $a, string $b): float
{
    if ($a === '' || $b === '') {
        return 0.0;
    }
    similar_text($a, $b, $pct);
    return (float)$pct;
}

/**
 * Carrega as empresas e monta os índices de vínculo. É lido uma vez por bloco de linhas.
 *
 * @return array<string,mixed>
 */
function nc_import_context(PDO $pdo): array
{
    $cols = ['id', 'name', 'alias', 'cnpj', 'city', 'state', 'owner_user_id', 'rd_crm_id'];
    foreach (['external_id', 'person_document', 'merged_ids_json', 'phone', 'interest_lines', 'source_quote_count', 'source_last_quote_at', 'source_origin', 'import_note'] as $c) {
        if (nc_column_exists($pdo, 'companies', $c)) {
            $cols[] = $c;
        }
    }
    $ctx = ['co' => [], 'byExt' => [], 'byCnpj' => [], 'byPerson' => [], 'byKey' => [], 'byRoot' => [], 'tokens' => [],
        'users' => nc_import_users($pdo), 'contactsByCompany' => [], 'oppCount' => []];

    $add = static function (array &$index, string $k, int $id): void {
        if ($k === '') {
            return;
        }
        $index[$k] = $index[$k] ?? [];
        if (!in_array($id, $index[$k], true)) {
            $index[$k][] = $id;
        }
    };

    foreach ($pdo->query('SELECT ' . implode(',', $cols) . ' FROM companies')->fetchAll() as $r) {
        $id = (int)$r['id'];
        $digits = cnpj_digits((string)($r['cnpj'] ?? ''));
        $keys = [];
        foreach ([(string)$r['name'], (string)($r['alias'] ?? '')] as $n) {
            if (trim($n) !== '') {
                $keys[] = nc_company_key($n);
            }
        }
        $extIds = [(string)($r['external_id'] ?? ''), (string)($r['rd_crm_id'] ?? '')];
        $cnpjs = [$digits];
        $meta = json_decode((string)($r['merged_ids_json'] ?? ''), true);
        foreach (is_array($meta['merged'] ?? null) ? $meta['merged'] : [] as $m) {
            foreach (['name', 'alias'] as $k) {
                if (!empty($m[$k])) {
                    $keys[] = nc_company_key((string)$m[$k]);
                }
            }
            $extIds[] = (string)($m['external_id'] ?? '');
            $extIds[] = (string)($m['rd_crm_id'] ?? '');
            $cnpjs[] = cnpj_digits((string)($m['cnpj'] ?? ''));
        }
        $keys = array_values(array_unique(array_filter($keys, static fn($k) => $k !== '')));
        $ctx['co'][$id] = [
            'id' => $id, 'name' => (string)$r['name'], 'alias' => (string)($r['alias'] ?? ''), 'cnpj' => $digits,
            'cnpj_valid' => ($digits !== '' && cnpj_is_valid($digits)) ? $digits : '',
            'city' => (string)($r['city'] ?? ''), 'state' => (string)($r['state'] ?? ''),
            'owner' => (int)($r['owner_user_id'] ?? 0), 'external_id' => (string)($r['external_id'] ?? ''),
            'person_document' => (string)($r['person_document'] ?? ''), 'keys' => $keys,
            'phone' => (string)($r['phone'] ?? ''), 'interest_lines' => (string)($r['interest_lines'] ?? ''),
            'source_quote_count' => (string)($r['source_quote_count'] ?? ''), 'source_last_quote_at' => substr((string)($r['source_last_quote_at'] ?? ''), 0, 10),
            'source_origin' => (string)($r['source_origin'] ?? ''), 'import_note' => (string)($r['import_note'] ?? ''),
        ];
        foreach ($extIds as $x) {
            $add($ctx['byExt'], strtolower(trim($x)), $id);
        }
        foreach ($cnpjs as $c) {
            if (strlen($c) === 14) {
                $add($ctx['byCnpj'], $c, $id);
            }
        }
        if (!empty($r['person_document'])) {
            $add($ctx['byPerson'], cnpj_digits((string)$r['person_document']), $id);
        }
        foreach ($keys as $k) {
            $add($ctx['byKey'], $k, $id);
            foreach (explode(' ', $k) as $t) {
                if (strlen($t) >= 3) {
                    $add($ctx['tokens'], $t, $id);
                }
            }
        }
        if (strlen($digits) === 14) {
            $add($ctx['byRoot'], substr($digits, 0, 8), $id);
        }
    }

    // Apelidos aprendidos nas revisões anteriores (migração 011): o nome da planilha passa a valer como nome da empresa.
    if (nc_table_exists($pdo, 'company_aliases')) {
        foreach ($pdo->query('SELECT company_id, alias_key FROM company_aliases')->fetchAll() as $a) {
            $cid = (int)$a['company_id'];
            $k = (string)$a['alias_key'];
            if ($k === '' || !isset($ctx['co'][$cid])) {
                continue;
            }
            if (!in_array($k, $ctx['co'][$cid]['keys'], true)) {
                $ctx['co'][$cid]['keys'][] = $k;
            }
            $add($ctx['byKey'], $k, $cid);
            foreach (explode(' ', $k) as $t) {
                if (strlen($t) >= 3) {
                    $add($ctx['tokens'], $t, $cid);
                }
            }
        }
    }
    return $ctx;
}

/** Usuários ativos indexados por e-mail e por nome normalizado. */
function nc_import_users(PDO $pdo): array
{
    $byEmail = [];
    $byName = [];
    $names = [];
    $hasEmail = nc_column_exists($pdo, 'users', 'email');
    $rows = $pdo->query('SELECT id, name' . ($hasEmail ? ', email' : '') . ' FROM users WHERE active = 1')->fetchAll();
    foreach ($rows as $u) {
        if ($hasEmail && trim((string)$u['email']) !== '') {
            $byEmail[strtolower(trim((string)$u['email']))][] = (int)$u['id'];
        }
        $byName[nc_norm((string)$u['name'])][] = (int)$u['id'];
        $names[(int)$u['id']] = (string)$u['name'];
    }
    return ['byEmail' => $byEmail, 'byName' => $byName, 'names' => $names];
}

/** Vendedor: e-mail exato, depois nome normalizado exato ÚNICO. Nunca o usuário que importa. */
function nc_import_find_user(array $ctx, string $email, string $name): ?int
{
    $email = strtolower(trim($email));
    if ($email !== '' && count($ctx['users']['byEmail'][$email] ?? []) === 1) {
        return $ctx['users']['byEmail'][$email][0];
    }
    $n = nc_norm($name);
    if ($n !== '' && count($ctx['users']['byName'][$n] ?? []) === 1) {
        return $ctx['users']['byName'][$n][0];
    }
    return null;
}

/** Responsável da planilha: com "@" é e-mail exato; senão, nome normalizado exato e único. Nunca quem importa. */
function nc_import_owner_id(array $ctx, string $raw): ?int
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    return str_contains($raw, '@') ? nc_import_find_user($ctx, $raw, '') : nc_import_find_user($ctx, '', $raw);
}

/** "São Paulo/SP", "Campinas - SP" ou só "SP" => ['city'=>..., 'state'=>...]. */
function nc_import_split_city_state(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['city' => '', 'state' => ''];
    }
    if (preg_match('#^(.*?)\s*[/\-–,]\s*([A-Za-z]{2})$#u', $raw, $m)) {
        return ['city' => trim($m[1]), 'state' => strtoupper($m[2])];
    }
    if (preg_match('/^[A-Za-z]{2}$/', $raw)) {
        return ['city' => '', 'state' => strtoupper($raw)];
    }
    return ['city' => $raw, 'state' => ''];
}

/** Colunas lidas de contacts (first_name só existe depois da migração 010). */
function nc_import_contact_cols(PDO $pdo): string
{
    return 'id, name, email, phone, job_title, external_id, company_id' . (nc_column_exists($pdo, 'contacts', 'first_name') ? ', first_name' : '');
}

/** Maior semelhança entre a chave e os nomes (nome, fantasia, mesclados) da empresa. */
function nc_import_company_similarity(array $company, string $key): float
{
    $best = 0.0;
    foreach ($company['keys'] as $k) {
        $best = max($best, nc_import_similarity($key, $k));
    }
    return $best;
}

/**
 * Candidatas para a revisão humana: até 5, por (mesmo CNPJ raiz) > (semelhança) > (mesma cidade/UF).
 * Só sugere; nunca vincula.
 *
 * @return array<int,array{id:int,name:string,cnpj:string,city:string,state:string,sim:int,root:bool,why:string}>
 */
function nc_import_candidates(array $ctx, string $key, string $cnpj, string $city, string $state, float $minSim = 40.0, int $limit = 5): array
{
    $ids = [];
    if (strlen($cnpj) === 14) {
        foreach ($ctx['byRoot'][substr($cnpj, 0, 8)] ?? [] as $id) {
            $ids[$id] = true;
        }
    }
    foreach ($ctx['byKey'][$key] ?? [] as $id) {
        $ids[$id] = true;
    }
    foreach (explode(' ', $key) as $t) {
        if (strlen($t) >= 3) {
            foreach (array_slice($ctx['tokens'][$t] ?? [], 0, 300) as $id) {
                $ids[$id] = true;
            }
        }
    }
    $out = [];
    foreach (array_keys($ids) as $id) {
        $co = $ctx['co'][$id] ?? null;
        if (!$co) {
            continue;
        }
        $root = strlen($cnpj) === 14 && strlen($co['cnpj']) === 14 && substr($co['cnpj'], 0, 8) === substr($cnpj, 0, 8);
        $sim = nc_import_company_similarity($co, $key);
        if (!$root && $sim < $minSim) {
            continue;
        }
        $sameCity = $city !== '' && nc_norm($city) === nc_norm($co['city']) && ($state === '' || strtoupper($state) === strtoupper($co['state']));
        $out[] = [
            'id' => (int)$id, 'name' => $co['name'], 'cnpj' => $co['cnpj'], 'city' => $co['city'], 'state' => $co['state'],
            'sim' => (int)round($sim), 'root' => $root, 'same_city' => $sameCity,
            'why' => $root ? 'Mesmo CNPJ raiz (filial?)' : ('Nome ' . (int)round($sim) . '% parecido' . ($sameCity ? ' · mesma cidade' : '')),
        ];
    }
    usort($out, static fn($a, $b) => [$b['root'], $b['sim'], $b['same_city']] <=> [$a['root'], $a['sim'], $a['same_city']]);
    return array_slice($out, 0, $limit);
}

/** Candidatas a partir de IDs conhecidos (conflito/ambiguidade). */
function nc_import_candidates_from_ids(array $ctx, array $ids, string $key): array
{
    $out = [];
    foreach (nc_import_ids($ids) as $id) {
        $co = $ctx['co'][$id] ?? null;
        if ($co) {
            $out[] = [
                'id' => $id, 'name' => $co['name'], 'cnpj' => $co['cnpj'], 'city' => $co['city'], 'state' => $co['state'],
                'sim' => (int)round(nc_import_company_similarity($co, $key)), 'root' => false, 'same_city' => false, 'why' => 'Encontrada pelas regras',
            ];
        }
    }
    return array_slice($out, 0, 5);
}

/* ------------------------------------------------------------------ */
/* Vínculo com a empresa (V3, 5.1 e 5.3)                               */
/* ------------------------------------------------------------------ */

/**
 * @param array{ext:string,doc:array,name:string,email:string,city:string,state:string} $in
 * @return array{status:string,company_id:?int,rule:string,reason:string,candidates:array}
 *   status: found | none | review | invalid_doc
 */
function nc_import_match_company(PDO $pdo, array $ctx, array $in, string $type): array
{
    $key = nc_company_key($in['name']);
    $doc = $in['doc'];
    $result = static fn(string $status, ?int $id, string $rule, string $reason = '', array $cand = []): array =>
        ['status' => $status, 'company_id' => $id, 'rule' => $rule, 'reason' => $reason, 'candidates' => $cand];

    $hits = [];
    if ($in['ext'] !== '') {
        $ids = nc_import_ids($ctx['byExt'][strtolower($in['ext'])] ?? []);
        if ($ids) {
            $hits['external_id'] = $ids;
        }
    }
    if ($doc['kind'] === 'cnpj') {
        $ids = nc_import_ids($ctx['byCnpj'][$doc['digits']] ?? []);
        if ($ids) {
            $hits['cnpj'] = $ids;
        }
    } elseif ($doc['kind'] === 'cpf') {
        $ids = nc_import_ids($ctx['byPerson'][$doc['digits']] ?? []);
        if ($ids) {
            $hits['cpf'] = $ids;
        }
    }
    if ($type === 'orcamentos' && $in['email'] !== '') {
        $q = $pdo->prepare('SELECT DISTINCT company_id FROM contacts WHERE email = ? AND company_id IS NOT NULL');
        $q->execute([$in['email']]);
        $ids = nc_import_ids($q->fetchAll(PDO::FETCH_COLUMN));
        if ($ids) {
            $hits['contact_email'] = $ids;
        }
    }

    $ruleLabel = ['external_id' => 'ID externo', 'cnpj' => 'CNPJ', 'cpf' => 'CPF', 'contact_email' => 'e-mail do contato'];

    foreach ($hits as $rule => $ids) {
        if (count($ids) > 1) {
            return $result('review', null, $rule, 'A regra "' . $ruleLabel[$rule] . '" encontrou ' . count($ids) . ' empresas. Escolha a correta.',
                nc_import_candidates_from_ids($ctx, $ids, $key));
        }
    }
    $distinct = nc_import_ids(array_map(static fn($ids) => $ids[0], $hits));
    if (count($distinct) > 1) {
        $parts = [];
        foreach ($hits as $rule => $ids) {
            $parts[] = $ruleLabel[$rule] . ' aponta para "' . ($ctx['co'][$ids[0]]['name'] ?? ('#' . $ids[0])) . '"';
        }
        return $result('review', null, 'conflito', 'Conflito entre regras: ' . implode(' e ', $parts) . '.',
            nc_import_candidates_from_ids($ctx, $distinct, $key));
    }
    if (count($distinct) === 1) {
        $rule = (string)array_key_first($hits);
        $id = $distinct[0];
        $co = $ctx['co'][$id];
        if (in_array($rule, ['cnpj', 'contact_email'], true) && $key !== '') {
            $sim = nc_import_company_similarity($co, $key);
            if ($sim < NC_IMPORT_MIN_NAME_SIM) {
                $why = $rule === 'cnpj' ? 'O CNPJ bate com' : 'O e-mail do contato pertence a';
                return $result('review', null, $rule, $why . ' "' . $co['name'] . '", mas o nome da planilha é muito diferente ('
                    . (int)round($sim) . '% de semelhança). O dado de origem pode estar errado.', nc_import_candidates_from_ids($ctx, [$id], $key));
            }
        }
        return $result('found', $id, $rule);
    }

    // Nenhuma regra por ID/CNPJ/e-mail achou empresa: nome exato e único.
    $nameIds = $key !== '' ? nc_import_ids($ctx['byKey'][$key] ?? []) : [];
    if ($doc['kind'] === 'invalido') {
        if ($nameIds) {
            return $result('review', null, 'name_exact', 'CNPJ inválido na planilha ("' . $doc['raw'] . '"); não confio no vínculo só pelo nome.',
                nc_import_candidates_from_ids($ctx, $nameIds, $key));
        }
        if ($type === 'contatos') {
            return $result('invalid_doc', null, 'none', 'CNPJ inválido: "' . $doc['raw'] . '".');
        }
        return $result('review', null, 'none', 'CNPJ inválido ("' . $doc['raw'] . '") e nenhuma empresa com este nome. Escolha a empresa.',
            nc_import_candidates($ctx, $key, '', $in['city'], $in['state']));
    }
    if (count($nameIds) > 1) {
        return $result('review', null, 'name_exact', 'O nome corresponde a ' . count($nameIds) . ' empresas cadastradas. Escolha a correta.',
            nc_import_candidates_from_ids($ctx, $nameIds, $key));
    }
    if (count($nameIds) === 1) {
        $co = $ctx['co'][$nameIds[0]];
        if ($doc['kind'] === 'cnpj' && $co['cnpj_valid'] !== '' && $co['cnpj_valid'] !== $doc['digits']) {
            return $result('review', null, 'name_exact', 'Existe "' . $co['name'] . '" com o mesmo nome, mas com outro CNPJ (' . format_cnpj($co['cnpj_valid'])
                . '). Pode ser outra filial ou outra empresa.', nc_import_candidates_from_ids($ctx, [$co['id']], $key));
        }
        return $result('found', $co['id'], 'name_exact');
    }
    return $result('none', null, 'none', '', nc_import_candidates($ctx, $key, $doc['kind'] === 'cnpj' ? $doc['digits'] : '', $in['city'], $in['state']));
}

/* ------------------------------------------------------------------ */
/* Contatos (V3, 5.5)                                                  */
/* ------------------------------------------------------------------ */

/** Normaliza os valores da linha de Contatos. Erros de obrigatórios saem em 'errors'. */
function nc_import_normalize_contact(array $v, array $cfg = []): array
{
    $warnings = [];
    $errors = [];
    $doc = nc_normalize_document($v['company_cnpj'] ?? '');
    if ($doc['corrected']) {
        $warnings[] = 'CNPJ corrigido (zeros à esquerda)';
    }
    $emailRaw = (string)($v['contact_email'] ?? '');
    $email = nc_email_clean($emailRaw);
    if (strlen($email) > 190) {
        $email = '';
    }
    if ($emailRaw !== '' && $email === '') {
        $warnings[] = 'E-mail inválido ignorado: ' . $emailRaw;
    }
    $phone = (string)($v['contact_phone'] ?? '');

    // Cidade e UF: colunas separadas valem mais que "Cidade/UF" junto.
    $split = nc_import_split_city_state((string)($v['company_city_state'] ?? ''));
    $city = trim((string)($v['company_city'] ?? '')) !== '' ? (string)$v['company_city'] : $split['city'];
    $state = trim((string)($v['company_state'] ?? '')) !== '' ? trim((string)$v['company_state']) : $split['state'];
    if (strlen($state) === 2) {
        $state = strtoupper($state);
    }

    // Nome do contato: se "Nome" vier vazio, o primeiro nome salva a linha (com aviso).
    $name = (string)($v['contact_name'] ?? '');
    $first = (string)($v['contact_first_name'] ?? '');
    if ($name === '' && $first !== '') {
        $name = $first;
        $warnings[] = 'Nome vazio: usei o primeiro nome.';
    }

    // Dados de origem da planilha (quantidade, última data, origem, linhas de interesse, conferir).
    $qtyRaw = (string)($v['company_quote_count'] ?? '');
    $qty = null;
    if ($qtyRaw !== '') {
        $digits = (string)preg_replace('/[.\s]/', '', $qtyRaw);
        if (preg_match('/^\d{1,9}$/', $digits)) {
            $qty = (int)$digits;
        } else {
            $warnings[] = 'Qtde de orçamentos inválida ignorada: ' . $qtyRaw;
        }
    }
    $lastRaw = (string)($v['company_last_quote'] ?? '');
    $last = '';
    if ($lastRaw !== '') {
        $last = (string)nc_parse_date($lastRaw, !empty($cfg['date1904']));
        if ($last === '') {
            $warnings[] = 'Data "Última em" inválida ignorada: ' . $lastRaw;
        }
    }
    $review = nc_import_cut((string)($v['company_review'] ?? ''), 500, $warnings, 'Conferir');
    if ($review !== '' && !in_array(nc_norm($review), ['nao', 'n', 'ok', 'false', '0', 'sem pendencia'], true)) {
        $warnings[] = 'Conferir (planilha): ' . $review;
    } else {
        $review = '';
    }
    $ownerRaw = trim((string)($v['owner_email'] ?? ''));
    if ($ownerRaw !== '' && str_contains($ownerRaw, '@')) {
        $ownerRaw = strtolower($ownerRaw);
    }

    $n = [
        'kind' => 'contatos',
        'company' => [
            'name' => nc_import_cut((string)($v['company_name'] ?? ''), 190, $warnings, 'Nome da empresa'),
            'alias' => nc_import_cut((string)($v['company_alias'] ?? ''), 190, $warnings, 'Nome fantasia'), 'doc' => $doc,
            'city' => nc_import_cut($city, 120, $warnings, 'Cidade'), 'state' => nc_import_cut($state, 40, $warnings, 'UF'),
            'phone' => nc_import_cut((string)($v['company_phone'] ?? ''), 80, $warnings, 'Telefone da empresa'),
            'ext' => nc_import_cut((string)($v['company_external_id'] ?? ''), 120, $warnings, 'ID da empresa'), 'owner_email' => $ownerRaw,
            'interest' => nc_import_cut((string)($v['company_interest'] ?? ''), 500, $warnings, 'Linhas de interesse'),
            'quote_count' => $qty, 'last_quote' => $last,
            'origin' => nc_import_cut((string)($v['company_origin'] ?? ''), 190, $warnings, 'Origem'), 'review' => $review,
        ],
        'contact' => [
            'name' => nc_import_cut($name, 160, $warnings, 'Nome do contato'), 'email' => $email,
            'first' => nc_import_cut($first, 100, $warnings, 'Primeiro nome'),
            'phone' => nc_import_cut($phone, 60, $warnings, 'Telefone'),
            'job' => nc_import_cut((string)($v['contact_job_title'] ?? ''), 120, $warnings, 'Cargo'),
            'ext' => nc_import_cut((string)($v['contact_external_id'] ?? ''), 120, $warnings, 'ID do contato'),
        ],
        'warnings' => $warnings,
    ];
    if ($n['contact']['name'] === '') {
        $errors[] = 'Nome do contato vazio.';
    }
    if ($n['company']['name'] === '') {
        $errors[] = 'Empresa vazia.';
    }
    if ($email === '' && strlen(nc_phone_digits($phone)) < 8) {
        $errors[] = 'Informe e-mail válido ou telefone do contato.';
    }
    $n['errors'] = $errors;
    return $n;
}

/** Contatos de uma empresa (cache no contexto). @return array<int,array<string,mixed>> */
function nc_import_company_contacts(PDO $pdo, array &$ctx, int $companyId): array
{
    if (!isset($ctx['contactsByCompany'][$companyId])) {
        $q = $pdo->prepare('SELECT ' . nc_import_contact_cols($pdo) . ' FROM contacts WHERE company_id = ?');
        $q->execute([$companyId]);
        $ctx['contactsByCompany'][$companyId] = $q->fetchAll();
    }
    return $ctx['contactsByCompany'][$companyId];
}

/**
 * Identifica o contato (5.5): ID externo, e-mail (outra empresa = Revisar), telefone + empresa, nome + empresa.
 *
 * @return array{status:string,id:?int,rule:string,reason:string,row:?array}  status: found | none | review
 */
function nc_import_find_contact(PDO $pdo, array &$ctx, array $c, ?int $companyId): array
{
    $res = static fn(string $s, ?int $id = null, string $rule = '', string $reason = '', ?array $row = null): array =>
        ['status' => $s, 'id' => $id, 'rule' => $rule, 'reason' => $reason, 'row' => $row];
    $coName = static fn(?int $cid): string => $cid && isset($ctx['co'][$cid]) ? $ctx['co'][$cid]['name'] : ('#' . (int)$cid);

    // 1) ID externo do contato
    if ($c['ext'] !== '') {
        $q = $pdo->prepare('SELECT ' . nc_import_contact_cols($pdo) . ' FROM contacts WHERE external_id = ?');
        $q->execute([$c['ext']]);
        $rows = $q->fetchAll();
        if (count($rows) > 1) {
            return $res('review', null, 'external_id', 'O ID do contato corresponde a ' . count($rows) . ' contatos.');
        }
        if (count($rows) === 1) {
            $r = $rows[0];
            $rc = (int)($r['company_id'] ?? 0);
            if ($rc > 0 && $rc !== (int)$companyId) {
                return $res('review', (int)$r['id'], 'external_id', 'Este contato já está em "' . $coName($rc) . '". O contato mudou de empresa?');
            }
            return $res('found', (int)$r['id'], 'external_id', '', $r);
        }
    }
    // 2) E-mail
    if ($c['email'] !== '') {
        $q = $pdo->prepare('SELECT ' . nc_import_contact_cols($pdo) . ' FROM contacts WHERE email = ?');
        $q->execute([$c['email']]);
        $rows = $q->fetchAll();
        $same = array_values(array_filter($rows, static fn($r) => $companyId && (int)($r['company_id'] ?? 0) === $companyId));
        $other = array_values(array_filter($rows, static fn($r) => (int)($r['company_id'] ?? 0) > 0 && (int)$r['company_id'] !== (int)$companyId));
        $orphan = array_values(array_filter($rows, static fn($r) => (int)($r['company_id'] ?? 0) === 0));
        if (count($same) === 1) {
            return $res('found', (int)$same[0]['id'], 'email', '', $same[0]);
        }
        if (count($same) > 1) {
            return $res('review', null, 'email', 'O e-mail está repetido em ' . count($same) . ' contatos desta empresa.');
        }
        if ($other) {
            return $res('review', (int)$other[0]['id'], 'email', 'O e-mail já existe na empresa "' . $coName((int)$other[0]['company_id']) . '". O contato mudou de empresa?');
        }
        if (count($orphan) === 1) {
            return $res('found', (int)$orphan[0]['id'], 'email', '', $orphan[0]);
        }
        if (count($orphan) > 1) {
            return $res('review', null, 'email', 'O e-mail corresponde a ' . count($orphan) . ' contatos sem empresa.');
        }
    }
    if (!$companyId) {
        return $res('none');
    }
    $mine = nc_import_company_contacts($pdo, $ctx, $companyId);
    // 3) Telefone (dígitos, mínimo 10) na mesma empresa
    $digits = nc_phone_digits($c['phone']);
    if (strlen($digits) >= 10) {
        $m = array_values(array_filter($mine, static fn($r) => nc_phone_digits((string)$r['phone']) === $digits));
        if (count($m) === 1) {
            return $res('found', (int)$m[0]['id'], 'phone', '', $m[0]);
        }
        if (count($m) > 1) {
            return $res('review', null, 'phone', 'O telefone corresponde a ' . count($m) . ' contatos desta empresa.');
        }
    }
    // 4) Nome normalizado exato na mesma empresa
    $n = nc_norm($c['name']);
    if ($n !== '') {
        $m = array_values(array_filter($mine, static fn($r) => nc_norm((string)$r['name']) === $n));
        if (count($m) === 1) {
            return $res('found', (int)$m[0]['id'], 'name', '', $m[0]);
        }
        if (count($m) > 1) {
            return $res('review', null, 'name', 'O nome corresponde a ' . count($m) . ' contatos desta empresa.');
        }
    }
    return $res('none');
}

/**
 * Classifica uma linha de Contatos. $force: null | ['mode'=>'escolhida','company_id'=>N] | ['mode'=>'criada'].
 *
 * @return array<string,mixed> class, reason, rule, company_id, candidates, norm
 */
function nc_import_classify_contact(PDO $pdo, array &$ctx, array $v, ?array $force, array $cfg = []): array
{
    $n = nc_import_normalize_contact($v, $cfg);
    $out = static function (string $class, string $reason, string $rule, ?int $companyId, array $cand, array $n): array {
        return ['class' => $class, 'reason' => $reason, 'rule' => $rule, 'company_id' => $companyId, 'candidates' => $cand, 'norm' => $n];
    };
    if ($n['errors']) {
        return $out('erro', implode(' ', $n['errors']), '', null, [], $n);
    }
    $co = $n['company'];
    $key = nc_company_key($co['name']);
    $doc = $co['doc'];
    $n['ctoken'] = $doc['kind'] === 'cnpj' ? 'c' . $doc['digits'] : ($co['ext'] !== '' ? 'x' . strtolower($co['ext']) : 'n' . $key);

    $companyId = null;
    $newCompany = false;
    $rule = '';
    $cand = [];
    if ($force && ($force['mode'] ?? '') === 'escolhida') {
        $companyId = (int)$force['company_id'];
        if (!isset($ctx['co'][$companyId])) {
            return $out('revisar', 'A empresa escolhida na revisão não existe mais. Escolha novamente.', 'manual', null, [], $n);
        }
        $rule = 'manual';
    } elseif ($force && ($force['mode'] ?? '') === 'criada') {
        $newCompany = true;
        $rule = 'manual';
    } else {
        $m = nc_import_match_company($pdo, $ctx, ['ext' => $co['ext'], 'doc' => $doc, 'name' => $co['name'], 'email' => '', 'city' => $co['city'], 'state' => $co['state']], 'contatos');
        if ($m['status'] === 'invalid_doc') {
            return $out('erro', $m['reason'], '', null, [], $n);
        }
        if ($m['status'] === 'review') {
            return $out('revisar', $m['reason'], $m['rule'], null, $m['candidates'], $n);
        }
        if ($m['status'] === 'found') {
            $companyId = $m['company_id'];
            $rule = $m['rule'];
        } else {
            $similar = array_values(array_filter($m['candidates'], static fn($c) => $c['root'] || $c['sim'] >= NC_IMPORT_SIMILAR_SIM));
            if ($similar) {
                return $out('revisar', 'Não achei a empresa, mas existem empresas parecidas. Escolha uma ou crie a empresa nova.', 'none', null, $m['candidates'], $n);
            }
            $newCompany = true;
            $rule = 'novo';
            $cand = $m['candidates'];
        }
    }

    $find = nc_import_find_contact($pdo, $ctx, $n['contact'], $companyId);
    if ($find['status'] === 'review') {
        $cands = [];
        if (!empty($find['id'])) {
            $q = $pdo->prepare('SELECT company_id FROM contacts WHERE id = ?');
            $q->execute([(int)$find['id']]);
            $cid = (int)$q->fetchColumn();
            $cands = $cid > 0 ? nc_import_candidates_from_ids($ctx, [$cid], $key) : [];
        }
        return $out('revisar', $find['reason'], 'contact_' . $find['rule'], $companyId, $cands, $n);
    }

    // Diferenças
    $companyDiff = [];
    $labels = [];
    if ($companyId) {
        $cur = $ctx['co'][$companyId];
        $fill = static function (string $field, string $before, string $after) use (&$companyDiff): void {
            if ($after !== '' && trim($before) === '') {
                $companyDiff[$field] = [$before, $after];
            }
        };
        $fill('cnpj', (string)$cur['cnpj'], $doc['kind'] === 'cnpj' ? $doc['digits'] : '');
        $fill('person_document', (string)$cur['person_document'], $doc['kind'] === 'cpf' ? $doc['digits'] : '');
        $fill('alias', (string)$cur['alias'], $co['alias']);
        $fill('city', (string)$cur['city'], $co['city']);
        $fill('state', (string)$cur['state'], $co['state']);
        $fill('external_id', (string)$cur['external_id'], $co['ext']);
        $fill('phone', (string)$cur['phone'], $co['phone']);
        $ownerId = nc_import_owner_id($ctx, $co['owner_email']);
        if ($co['owner_email'] !== '' && !$ownerId) {
            $n['warnings'][] = 'Vendedor "' . $co['owner_email'] . '" não encontrado: a empresa fica sem responsável.';
        }
        if ($ownerId && !(int)$cur['owner']) {
            $companyDiff['owner_user_id'] = ['', (string)$ownerId];
            $labels['owner_user_id'] = $ctx['users']['names'][$ownerId] ?? ('#' . $ownerId);
        }
        // Dados de origem: a planilha é a fonte, então atualizam quando mudam (não há edição deles no CRM).
        $upd = static function (string $field, string $before, string $after) use (&$companyDiff): void {
            if ($after !== '' && $after !== $before) {
                $companyDiff[$field] = [$before, $after];
            }
        };
        $upd('interest_lines', (string)$cur['interest_lines'], $co['interest']);
        $upd('source_quote_count', (string)$cur['source_quote_count'], $co['quote_count'] !== null ? (string)$co['quote_count'] : '');
        $upd('source_last_quote_at', (string)$cur['source_last_quote_at'], $co['last_quote']);
        $upd('source_origin', (string)$cur['source_origin'], $co['origin']);
        $upd('import_note', (string)$cur['import_note'], $co['review']);
    } else {
        $ownerId = nc_import_owner_id($ctx, $co['owner_email']);
        if ($co['owner_email'] !== '' && !$ownerId) {
            $n['warnings'][] = 'Vendedor "' . $co['owner_email'] . '" não encontrado: a empresa fica sem responsável.';
        }
    }
    $contactDiff = [];
    $contactId = null;
    if ($find['status'] === 'found') {
        $contactId = (int)$find['id'];
        $row = (array)$find['row'];
        $c = $n['contact'];
        if ($c['name'] !== '' && nc_norm($c['name']) !== nc_norm((string)$row['name'])) {
            $contactDiff['name'] = [(string)$row['name'], $c['name']];
        }
        if ($c['email'] !== '' && $c['email'] !== strtolower(trim((string)$row['email']))) {
            $contactDiff['email'] = [(string)$row['email'], $c['email']];
        }
        if (nc_phone_digits($c['phone']) !== '' && nc_phone_digits($c['phone']) !== nc_phone_digits((string)$row['phone'])) {
            $contactDiff['phone'] = [(string)$row['phone'], $c['phone']];
        }
        if ($c['job'] !== '' && $c['job'] !== (string)$row['job_title']) {
            $contactDiff['job_title'] = [(string)$row['job_title'], $c['job']];
        }
        if ($c['ext'] !== '' && $c['ext'] !== (string)$row['external_id']) {
            $contactDiff['external_id'] = [(string)$row['external_id'], $c['ext']];
        }
        if ($c['first'] !== '' && $c['first'] !== (string)($row['first_name'] ?? '')) {
            $contactDiff['first_name'] = [(string)($row['first_name'] ?? ''), $c['first']];
        }
        if ($companyId && (int)($row['company_id'] ?? 0) !== $companyId) {
            $contactDiff['company_id'] = [(string)($row['company_id'] ?? ''), (string)$companyId];
        }
    }
    $n['target'] = [
        'company_id' => $companyId, 'new_company' => $newCompany, 'contact_id' => $contactId,
        'company_diff' => $companyDiff, 'contact_diff' => $contactDiff, 'labels' => $labels,
    ];
    if ($newCompany || $contactId === null) {
        $class = 'novo';
        $reason = $newCompany ? 'Empresa e contato novos.' : 'Contato novo em empresa existente.';
    } elseif ($companyDiff || $contactDiff) {
        $class = 'atualizar';
        $reason = 'Há campos diferentes no CRM.';
    } else {
        $class = 'sem_alteracao';
        $reason = 'Já existe igual no CRM.';
    }
    return $out($class, $reason, $rule, $companyId, $cand, $n);
}

/* ------------------------------------------------------------------ */
/* Orçamentos e propostas (V3, 4.2 e 5.4)                              */
/* ------------------------------------------------------------------ */

/** Normaliza os valores da linha de Orçamentos. @param array{date1904?:bool} $opts */
function nc_import_normalize_opp(array $v, array $cfg): array
{
    $errors = [];
    $warnings = [];
    $date1904 = !empty($cfg['date1904']);
    $ref = nc_import_cut((string)($v['external_ref'] ?? ''), 120, $warnings, 'Número do orçamento');
    $dateRaw = (string)($v['opportunity_date'] ?? '');
    $date = nc_parse_date($dateRaw, $date1904);
    $amountRaw = (string)($v['amount'] ?? '');
    $amount = nc_parse_money($amountRaw);
    $client = nc_import_cut((string)($v['client_name'] ?? ''), 190, $warnings, 'Nome do cliente');
    $doc = nc_normalize_document($v['company_cnpj'] ?? '');
    if ($doc['corrected']) {
        $warnings[] = 'CNPJ corrigido (zeros à esquerda)';
    }
    $email = nc_email_clean((string)($v['contact_email'] ?? ''));
    if (($v['contact_email'] ?? '') !== '' && $email === '') {
        $warnings[] = 'E-mail do contato inválido ignorado: ' . $v['contact_email'];
    }
    if ($ref === '') {
        $errors[] = 'Número do orçamento/proposta vazio.';
    }
    if ($client === '') {
        $errors[] = 'Cliente vazio.';
    }
    if ($dateRaw === '') {
        $errors[] = 'Data vazia.';
    } elseif ($date === null) {
        $errors[] = 'Data inválida: "' . $dateRaw . '".';
    }
    if ($amountRaw === '') {
        $errors[] = 'Valor vazio.';
    } elseif ($amount === null) {
        $errors[] = 'Valor inválido: "' . $amountRaw . '".';
    }
    return [
        'kind' => 'orcamentos', 'ref' => $ref, 'date' => $date, 'client_name' => $client, 'amount' => $amount,
        'amount_raw' => $amountRaw, 'doc' => $doc, 'company_ext' => (string)($v['company_external_id'] ?? ''), 'contact_email' => $email,
        'status_text' => (string)($v['status'] ?? ''), 'stage_text' => (string)($v['stage'] ?? ''),
        'line_text' => (string)($v['product_line'] ?? ''), 'seller_text' => (string)($v['seller'] ?? ''),
        'seller_email' => strtolower((string)($v['seller_email'] ?? '')), 'loss_reason' => (string)($v['loss_reason'] ?? ''),
        'notes' => (string)($v['notes'] ?? ''), 'quote_reference' => nc_import_cut((string)($v['quote_reference'] ?? ''), 120, $warnings, 'Referência'),
        'version' => (string)($v['version'] ?? ''),
        'counts' => nc_import_counts_in_value((string)($v['counts_in_value'] ?? '')),
        'warnings' => $warnings, 'errors' => $errors,
    ];
}

/** Funis, linhas e etapas para resolver linha/etapa do orçamento. */
function nc_import_funnel_context(PDO $pdo): array
{
    static $cache = [];
    $id = spl_object_id($pdo);
    if (isset($cache[$id])) {
        return $cache[$id];
    }
    $lines = [];
    foreach ($pdo->query('SELECT id, name, funnel_key, item_type FROM product_lines WHERE active = 1')->fetchAll() as $l) {
        $lines[(int)$l['id']] = $l;
    }
    $funnels = [];
    foreach ($pdo->query('SELECT id, funnel_key FROM funnels WHERE active = 1')->fetchAll() as $f) {
        $funnels[(string)$f['funnel_key']] = (int)$f['id'];
    }
    $stages = [];
    foreach ($pdo->query('SELECT id, funnel_id, name FROM funnel_stages ORDER BY funnel_id, sort_order, id')->fetchAll() as $s) {
        $stages[(int)$s['funnel_id']][] = ['id' => (int)$s['id'], 'norm' => nc_norm((string)$s['name'])];
    }
    return $cache[$id] = ['lines' => $lines, 'funnels' => $funnels, 'stages' => $stages];
}

/** Linha de produto: mapa de valores do lote, depois product_line_id_from_text() da raiz. Nunca palpite. */
function nc_import_resolve_line(PDO $pdo, string $text, array $valueMaps): ?int
{
    $n = nc_norm($text);
    if ($n === '') {
        return null;
    }
    $mapped = (int)($valueMaps['line'][$n] ?? 0);
    if ($mapped > 0) {
        return $mapped;
    }
    return product_line_id_from_text($pdo, $text);
}

/**
 * Classifica uma linha de Orçamentos. Orçamento não cria empresa sozinho: sem vínculo certo vai para Revisar.
 * Só a decisão humana ("criada", individual ou em massa) cria a empresa, sem CNPJ, na confirmação.
 * $force: null | ['mode'=>'escolhida','company_id'=>N] | ['mode'=>'criada'].
 *
 * @return array<string,mixed>
 */
function nc_import_classify_opp(PDO $pdo, array &$ctx, array $batch, array $cfg, array $v, ?array $force): array
{
    $n = nc_import_normalize_opp($v, $cfg);
    $out = static function (string $class, string $reason, string $rule, ?int $companyId, array $cand, array $n): array {
        return ['class' => $class, 'reason' => $reason, 'rule' => $rule, 'company_id' => $companyId, 'candidates' => $cand, 'norm' => $n];
    };
    if (!$n['counts']) {
        return $out('ignorar', 'Versão antiga: a coluna "Conta no valor" diz que esta emissão não conta.', '', null, [], $n);
    }
    if ($n['errors']) {
        return $out('erro', implode(' ', $n['errors']), '', null, [], $n);
    }
    $valueMaps = (array)($cfg['value_maps'] ?? []);
    $status = nc_import_status_resolve($n['status_text'], (array)($valueMaps['status'] ?? []));
    if ($status === null) {
        return $out('revisar', 'Status desconhecido: "' . $n['status_text'] . '". Mapeie o valor no passo 2 (Mapear colunas).', 'status', null, [], $n);
    }
    $n['status'] = $status;
    $key = nc_company_key($n['client_name']);
    $doc = $n['doc'];

    // Oportunidade já existente com esta chave (external_source + external_ref)?
    $source = nc_import_external_source('orcamentos');
    $q = $pdo->prepare('SELECT * FROM opportunities WHERE external_source = ? AND external_ref = ?');
    $q->execute([$source, $n['ref']]);
    $existing = $q->fetchAll();
    if (count($existing) > 1) {
        return $out('revisar', 'O número "' . $n['ref'] . '" existe em ' . count($existing) . ' oportunidades do CRM.', 'external_ref', null, [], $n);
    }
    $opp = $existing[0] ?? null;

    // Empresa
    $companyId = null;
    $newCompany = false;
    $rule = '';
    $cand = [];
    $forced = false;
    if ($force && ($force['mode'] ?? '') === 'escolhida') {
        $companyId = (int)$force['company_id'];
        if (!isset($ctx['co'][$companyId])) {
            return $out('revisar', 'A empresa escolhida na revisão não existe mais. Escolha novamente.', 'manual', null, [], $n);
        }
        $rule = 'manual';
        $forced = true;
    } elseif ($force && ($force['mode'] ?? '') === 'criada') {
        // Decisão humana em bloco: cria a empresa (sem CNPJ confirmado) na confirmação. O Saneamento completa o CNPJ depois.
        if ($opp) {
            return $out('revisar', 'Este orçamento já existe no CRM (número "' . $n['ref'] . '"). Escolha a empresa dele em vez de criar uma nova.', 'external_ref', null, [], $n);
        }
        $newCompany = true;
        $rule = 'manual';
        $forced = true;
    } else {
        $m = nc_import_match_company($pdo, $ctx, ['ext' => $n['company_ext'], 'doc' => $doc, 'name' => $n['client_name'],
            'email' => $n['contact_email'], 'city' => '', 'state' => ''], 'orcamentos');
        if ($m['status'] === 'found') {
            $companyId = $m['company_id'];
            $rule = $m['rule'];
        } elseif ($opp && (int)($opp['company_id'] ?? 0) > 0 && isset($ctx['co'][(int)$opp['company_id']])
            && ($m['status'] === 'none' || ($m['status'] === 'review' && in_array((int)$opp['company_id'], array_map(static fn($c) => (int)$c['id'], $m['candidates']), true)))) {
            // Reimportação de um orçamento já vinculado (talvez por decisão humana): as regras não o contradizem.
            $companyId = (int)$opp['company_id'];
            $rule = 'manual';
        } else {
            $reason = $m['status'] === 'none'
                ? 'Não achei empresa com este CNPJ, ID ou nome exato. Escolha a empresa (orçamento nunca cria empresa sozinho).'
                : $m['reason'];
            return $out('revisar', $reason, $m['rule'], null, $m['candidates'], $n);
        }
    }
    $company = $newCompany ? ['name' => $n['client_name']] : $ctx['co'][$companyId];

    // Data muito no futuro (a escolha manual da empresa também confirma esta data)
    if (!$forced && $n['date'] > date('Y-m-d', strtotime(NC_IMPORT_MAX_FUTURE))) {
        return $out('revisar', 'Data ' . nc_date($n['date']) . ' está a mais de 1 ano no futuro. Confira e escolha a empresa para confirmar.', 'date', $companyId,
            nc_import_candidates_from_ids($ctx, [$companyId], $key), $n);
    }

    // Já está em outra empresa?
    if ($opp && (int)($opp['company_id'] ?? 0) > 0 && (int)$opp['company_id'] !== $companyId) {
        $other = $ctx['co'][(int)$opp['company_id']]['name'] ?? ('#' . (int)$opp['company_id']);
        return $out('revisar', 'Este orçamento já está no cliente "' . $other . '"; a planilha diz "' . $company['name'] . '". Nada é movido sozinho.', 'external_ref',
            $companyId, nc_import_candidates_from_ids($ctx, [(int)$opp['company_id'], $companyId], $key), $n);
    }

    // Linha, etapa e vendedor
    $fx = nc_import_funnel_context($pdo);
    $lineId = nc_import_resolve_line($pdo, $n['line_text'], $valueMaps);
    $lineDefault = false;
    if (!$lineId || !isset($fx['lines'][$lineId])) {
        $lineId = (int)($batch['default_product_line_id'] ?? 0) ?: null;
        $lineDefault = true;
    }
    $lineRow = $lineId ? ($fx['lines'][$lineId] ?? null) : null;
    $funnelId = $lineRow ? ($fx['funnels'][(string)$lineRow['funnel_key']] ?? null) : null;
    $stageId = null;
    $stageDefault = false;
    $stageExplicit = null;
    if ($funnelId) {
        $list = $fx['stages'][$funnelId] ?? [];
        $sn = nc_norm($n['stage_text']);
        foreach ($list as $s) {
            if ($sn !== '' && $s['norm'] === $sn) {
                $stageExplicit = $s['id'];
                break;
            }
        }
        if ($stageExplicit) {
            $stageId = $stageExplicit;
        } elseif ($list) {
            $stageId = $status === 'Aberta' ? $list[0]['id'] : $list[count($list) - 1]['id'];
            $stageDefault = true;
        }
    }
    $sellerId = null;
    if ($n['seller_text'] !== '' || $n['seller_email'] !== '') {
        $isNone = in_array(nc_norm($n['seller_text']), ['sem vendedor', 'nao informado', 'sem responsavel'], true);
        $sellerId = nc_import_find_user($ctx, $n['seller_email'], $n['seller_text']);
        if (!$sellerId && !$isNone) {
            $n['warnings'][] = 'Vendedor "' . ($n['seller_text'] !== '' ? $n['seller_text'] : $n['seller_email']) . '" não encontrado: ficará sem vendedor.';
        }
    }
    $lossReason = $n['loss_reason'];
    $lossDefault = false;
    if ($status === 'Perdida' && $lossReason === '') {
        $lossReason = 'Não informado na planilha (importação lote #' . (int)$batch['id'] . ')';
        $lossDefault = true;
    }
    $notes = trim($n['notes']);
    if ($n['version'] !== '') {
        $notes = trim($notes . ($notes !== '' ? ' · ' : '') . 'Versão ' . $n['version']);
    }
    if (nc_norm($n['client_name']) !== nc_norm($company['name'])) {
        $notes = trim($notes . ($notes !== '' ? ' · ' : '') . 'Cliente na planilha: ' . $n['client_name']);
    }
    $n['plan'] = [
        'company_id' => $companyId, 'client_name' => $company['name'], 'line_id' => $lineId, 'line_default' => $lineDefault,
        'funnel_id' => $funnelId, 'stage_id' => $stageId, 'stage_default' => $stageDefault, 'stage_explicit' => $stageExplicit,
        'seller_id' => $sellerId, 'status' => $status, 'loss_reason' => $lossReason, 'loss_default' => $lossDefault,
        'notes' => $notes, 'item_type' => $lineRow ? (string)$lineRow['item_type'] : null,
        'needs_default_line' => $lineDefault && !$lineId, 'new_company' => $newCompany,
    ];

    if ($opp === null) {
        $n['target'] = ['company_id' => $companyId, 'opp_id' => null, 'diff' => [], 'crm_edited' => false, 'new_company' => $newCompany];
        return $out('novo', $newCompany ? 'Orçamento novo, com empresa nova (sem CNPJ confirmado).' : 'Orçamento novo.', $rule, $companyId, [], $n);
    }

    // Já existe: só campos mapeados que mudaram
    $diff = [];
    $cmp = static function (string $field, mixed $before, mixed $after) use (&$diff): void {
        if ((string)$before !== (string)$after) {
            $diff[$field] = [(string)$before, (string)$after];
        }
    };
    $cmp('opportunity_date', substr((string)$opp['opportunity_date'], 0, 10), $n['date']);
    $cmp('amount', number_format((float)$opp['amount'], 2, '.', ''), number_format((float)$n['amount'], 2, '.', ''));
    $cmp('status', (string)$opp['status'], $status);
    if ($status === 'Perdida' && $n['loss_reason'] !== '') {
        $cmp('loss_reason', (string)($opp['loss_reason'] ?? ''), $n['loss_reason']);
    }
    if ($stageExplicit && (int)$opp['stage_id'] !== $stageExplicit) {
        $diff['stage_id'] = [(string)$opp['stage_id'], (string)$stageExplicit];
    }
    if ($sellerId && (int)($opp['seller_user_id'] ?? 0) !== $sellerId) {
        $diff['seller_user_id'] = [(string)($opp['seller_user_id'] ?? ''), (string)$sellerId];
    }
    if (!$lineDefault && $lineId && (int)$opp['product_line_id'] !== $lineId) {
        $diff['product_line_id'] = [(string)$opp['product_line_id'], (string)$lineId];
    }
    if ($n['quote_reference'] !== '' && $n['quote_reference'] !== (string)($opp['quote_reference'] ?? '')) {
        $diff['quote_reference'] = [(string)($opp['quote_reference'] ?? ''), $n['quote_reference']];
    }
    if ((int)($opp['company_id'] ?? 0) !== $companyId) {
        $diff['company_id'] = [(string)($opp['company_id'] ?? ''), (string)$companyId];
    }
    $synced = (string)($opp['import_synced_at'] ?? '');
    $edited = $synced === '' || (string)($opp['updated_at'] ?? '') > $synced;
    $n['target'] = ['company_id' => $companyId, 'opp_id' => (int)$opp['id'], 'diff' => $diff, 'crm_edited' => $edited && $diff !== []];
    if (!$diff) {
        return $out('sem_alteracao', 'Já existe igual no CRM.', $rule, $companyId, [], $n);
    }
    return $out('atualizar', $edited ? 'Editado no CRM depois da última importação: por padrão o CRM é mantido.' : 'Há campos diferentes no CRM.', $rule, $companyId, [], $n);
}
