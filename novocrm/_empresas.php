<?php
declare(strict_types=1);

/**
 * NOVOCRM v3 — regras de empresas e contatos (chave de nome, duplicadas, mesclagem, origem).
 * Compartilhado pela página Clientes e pela Central de Importação. Não altera nada da raiz.
 */

/** Palavras que não identificam a empresa (sufixos societários e conectivos). */
const NC_COMPANY_STOPWORDS = [
    'ltda', 'ltd', 'me', 'epp', 'eireli', 'sa', 'cia', 'companhia',
    'comercio', 'industria', 'ind', 'com', 'do', 'da', 'de', 'dos', 'das', 'e',
];

/**
 * Tira os acentos do português sem depender da extensão iconv (normalized_name() da raiz apaga a letra
 * acentuada quando o iconv não existe no servidor: "Indústria" viraria "ustria").
 */
function nc_fold(string $value): string
{
    static $map = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C', 'Ñ' => 'N', "\xC2\xA0" => ' ',
    ];
    return strtr($value, $map);
}

/**
 * Chave de comparação do nome (V3, 5.2): normalized_name() sem sufixos societários nem palavras vazias.
 * "ACME Indústria e Comércio Ltda." => "acme". SERVE SÓ PARA COMPARAR; nunca para gravar o nome.
 * Se tudo for removido, usa o nome normalizado inteiro (para não igualar empresas diferentes).
 */
function nc_company_key(string $name): string
{
    $norm = normalized_name(nc_fold($name));
    if ($norm === '') {
        return '';
    }
    $norm = (string)preg_replace('/\bs a\b/', ' ', $norm); // "S.A." / "S/A" viram "s a"
    $kept = [];
    foreach (preg_split('/\s+/', trim($norm)) ?: [] as $word) {
        if ($word !== '' && !in_array($word, NC_COMPANY_STOPWORDS, true)) {
            $kept[] = $word;
        }
    }
    return $kept ? implode(' ', $kept) : normalized_name($name);
}

/** CNPJ só com dígitos, ou '' se não for válido. */
function nc_valid_cnpj_or_empty(?string $value): string
{
    $d = cnpj_digits((string)$value);
    return ($d !== '' && cnpj_is_valid($d)) ? $d : '';
}

/**
 * Grupos de possíveis duplicadas: mesmo CNPJ (dígitos) OU mesma chave de nome (V3, 1.1).
 * Os grupos que se sobrepõem são unidos. Cada grupo traz os ids, os motivos e se é mesclável
 * (não é, se houver CNPJs válidos diferentes: matriz/filial).
 *
 * @return array<int,array{ids:int[],reasons:string[],cnpjs:string[],blocked:bool}>
 */
function nc_company_duplicate_groups(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, name, cnpj FROM companies')->fetchAll();

    $byCnpj = [];
    $byKey = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $digits = cnpj_digits((string)($r['cnpj'] ?? ''));
        if ($digits !== '') {
            $byCnpj[$digits][] = $id;
        }
        $key = nc_company_key((string)$r['name']);
        if ($key !== '') {
            $byKey[$key][] = $id;
        }
    }

    // União de conjuntos (union-find simples).
    $parent = [];
    $find = static function (int $x) use (&$parent, &$find): int {
        while (($parent[$x] ?? $x) !== $x) {
            $parent[$x] = $parent[$parent[$x]] ?? $parent[$x];
            $x = $parent[$x];
        }
        return $x;
    };
    $reasons = [];
    foreach ([['Mesmo CNPJ', $byCnpj], ['Mesmo nome', $byKey]] as [$label, $index]) {
        foreach ($index as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                $parent[$id] = $parent[$id] ?? $id;
            }
            $root = $find($ids[0]);
            foreach (array_slice($ids, 1) as $id) {
                $parent[$find($id)] = $root;
            }
            $reasons[$ids[0]][] = $label;
        }
    }

    $cnpjOf = [];
    foreach ($rows as $r) {
        $cnpjOf[(int)$r['id']] = nc_valid_cnpj_or_empty((string)($r['cnpj'] ?? ''));
    }

    $groups = [];
    foreach (array_keys($parent) as $id) {
        $groups[$find($id)][] = $id;
    }
    // Motivos por grupo.
    $groupReasons = [];
    foreach ($reasons as $firstId => $labels) {
        $groupReasons[$find($firstId)] = array_merge($groupReasons[$find($firstId)] ?? [], $labels);
    }

    $out = [];
    foreach ($groups as $root => $ids) {
        sort($ids);
        $cnpjs = array_values(array_unique(array_filter(array_map(static fn($i) => $cnpjOf[$i] ?? '', $ids))));
        $out[] = [
            'ids' => $ids,
            'reasons' => array_values(array_unique($groupReasons[$root] ?? ['Mesmo nome'])),
            'cnpjs' => $cnpjs,
            'blocked' => count($cnpjs) > 1,
        ];
    }
    usort($out, static fn($a, $b) => count($b['ids']) <=> count($a['ids']) ?: $a['ids'][0] <=> $b['ids'][0]);
    return $out;
}

/** Origem de um registro: manual, RD, planilha ou "importação lote #N". */
function nc_origin_label(array $row): string
{
    $batch = (int)($row['import_batch_id'] ?? 0);
    if ($batch > 0) {
        return 'Importação lote #' . $batch;
    }
    return match ((string)($row['source'] ?? 'manual')) {
        'rd', 'rd_crm', 'rd_marketing' => 'RD Station',
        'xlsx' => 'Planilha',
        'cnpja' => 'CNPJá',
        default => 'Manual',
    };
}

/** Rótulo da origem do contato (manual, rd, xlsx). */
function nc_contact_source_label(string $source): string
{
    return match ($source) {
        'rd_crm', 'rd', 'rd_marketing' => 'RD',
        'xlsx' => 'Planilha',
        default => 'Manual',
    };
}

/** Empresas visíveis para o usuário? comercial só vê contatos das suas empresas e os que criou. */
function nc_contacts_scope_sql(PDO $pdo, array $user, string $contactAlias = 'ct', string $companyAlias = 'co'): array
{
    if (($user['role'] ?? '') !== 'comercial') {
        return ['', []];
    }
    $uid = (int)$user['id'];
    if (nc_column_exists($pdo, 'contacts', 'created_by')) {
        return ["($companyAlias.owner_user_id = ? OR $contactAlias.created_by = ?)", [$uid, $uid]];
    }
    return ["$companyAlias.owner_user_id = ?", [$uid]];
}

/**
 * Valida o contato do formulário. Devolve valores limpos ou lança RuntimeException.
 * A empresa é obrigatória e precisa existir (vem do autocomplete, nunca de texto livre).
 */
function nc_contact_validate(PDO $pdo, array $post): array
{
    $name = trim((string)($post['name'] ?? ''));
    $email = strtolower(trim((string)($post['email'] ?? '')));
    $phone = trim((string)($post['phone'] ?? ''));
    $job = trim((string)($post['job_title'] ?? ''));
    $companyId = (int)($post['company_id'] ?? 0);

    if ($name === '') {
        throw new RuntimeException('Informe o nome do contato.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Informe um e-mail válido ou deixe o campo em branco.');
    }
    if ($companyId < 1) {
        throw new RuntimeException('Escolha a empresa na lista de sugestões (busque por nome ou CNPJ).');
    }
    $q = $pdo->prepare('SELECT id, name FROM companies WHERE id = ? LIMIT 1');
    $q->execute([$companyId]);
    $company = $q->fetch();
    if (!$company) {
        throw new RuntimeException('A empresa escolhida não existe mais. Busque novamente.');
    }
    return [
        'name' => $name, 'email' => $email !== '' ? $email : null, 'phone' => $phone !== '' ? $phone : null,
        'job_title' => $job !== '' ? $job : null, 'company_id' => $companyId, 'company_name' => (string)$company['name'],
    ];
}
