<?php
declare(strict_types=1);

/**
 * NOVOCRM v4 — Saneamento da base de empresas: completar e validar CNPJ (BrasilAPI, com CNPJá de reserva),
 * descobrir o CNPJ de quem não tem (IA + validação na BrasilAPI) e unificar duplicadas.
 *
 * PRINCÍPIOS
 *  - A IA só PROPÕE um CNPJ. Quem decide é a validação: o CNPJ precisa existir na BrasilAPI/CNPJá e a razão social
 *    ou o nome fantasia precisam ser parecidos com o nome da empresa (e a UF coerente). Depois, um humano aprova.
 *  - Consultas só PREENCHEM campos vazios; o nome da empresa nunca é trocado.
 *  - Mesclar só quando o CNPJ completo é o mesmo (seguro) ou quando um humano aprova; CNPJs válidos diferentes nunca se mesclam.
 *  - Tudo anda em passos curtos (nc_san_step), chamados pela tela (HTMX) ou pelo n8n (api/saneamento.php).
 */

require_once __DIR__ . '/_empresas_merge.php';

/** Semelhança mínima (%) entre o nome da empresa e a razão social/fantasia do CNPJ consultado. */
const NC_SAN_NAME_MIN_SIM = 60.0;
/** Confiança (0-100) a partir da qual "Aprovar todas de alta confiança" aceita a sugestão. */
const NC_SAN_AUTO_CONFIDENCE = 85;
/** Semelhança (%) de nomes para sugerir mesclagem. */
const NC_SAN_DUP_SIM = 90.0;
/** Tempo máximo de um passo (segundos). */
const NC_SAN_STEP_SECONDS = 25;
/** Tentativas por empresa antes de desistir (erros temporários). */
const NC_SAN_MAX_ATTEMPTS = 3;
/** Quantas empresas "descobrir" (cada uma chama a IA) por passo. */
const NC_SAN_AI_PER_STEP = 3;

/** Campos que a consulta pode preencher (somente quando estão vazios na empresa). */
const NC_SAN_FILL_FIELDS = ['alias', 'registration_status', 'registration_status_date', 'founded', 'company_type', 'company_size', 'legal_nature',
    'capital_social', 'email', 'phone', 'zip', 'street', 'address_number', 'address_details', 'district', 'industry', 'main_activity_code', 'city', 'state'];

/** Falha de consulta de CNPJ. kind: invalid | notfound | limit | error | fatal. */
class NcLookupException extends RuntimeException
{
    public function __construct(string $message, public readonly string $kind = 'error')
    {
        parent::__construct($message);
    }
}

/* ------------------------------------------------------------------ */
/* Configuração                                                        */
/* ------------------------------------------------------------------ */

/** Ajustes da BrasilAPI (não precisa de chave). Sem registro, fica ativa. @return array{active:bool,delay_ms:int,batch:int} */
function nc_san_settings(PDO $pdo): array
{
    $cfg = integration_get($pdo, 'brasilapi');
    $extra = json_decode((string)($cfg['extra_json'] ?? '{}'), true);
    $extra = is_array($extra) ? $extra : [];
    return [
        'active' => !$cfg || !empty($cfg['is_active']),
        'delay_ms' => max(0, min(5000, (int)($extra['delay_ms'] ?? 600))),
        'batch' => max(1, min(50, (int)($extra['batch'] ?? 10))),
    ];
}

function nc_san_settings_save(PDO $pdo, bool $active, int $delayMs, int $batch, int $userId): void
{
    integration_upsert($pdo, 'brasilapi', [
        'extra' => ['delay_ms' => max(0, min(5000, $delayMs)), 'batch' => max(1, min(50, $batch))],
        'is_active' => $active,
    ], $userId);
}

/** Token que o n8n usa no cabeçalho Authorization: Bearer. Vazio = desligado. */
function nc_san_token(PDO $pdo): string
{
    $c = integration_get($pdo, 'n8n');
    return !empty($c['is_active']) ? secret_decrypt($c['access_token'] ?? '') : '';
}

function nc_san_token_rotate(PDO $pdo, int $userId): string
{
    $token = bin2hex(random_bytes(24));
    integration_upsert($pdo, 'n8n', ['access_token' => $token, 'is_active' => true], $userId);
    return $token;
}

function nc_san_token_disable(PDO $pdo, int $userId): void
{
    integration_upsert($pdo, 'n8n', ['is_active' => false], $userId);
}

/* ------------------------------------------------------------------ */
/* Consulta de CNPJ: BrasilAPI e CNPJá                                 */
/* ------------------------------------------------------------------ */

function nc_san_title(string $v): string
{
    $v = trim($v);
    return $v === '' ? '' : mb_convert_case(mb_strtolower($v), MB_CASE_TITLE);
}

/**
 * Consulta um CNPJ na BrasilAPI e devolve o mesmo formato de cnpja_map_office() (campos de companies).
 *
 * @throws NcLookupException
 */
function nc_brasilapi_lookup(string $cnpj): array
{
    $cnpj = cnpj_digits($cnpj);
    if (!cnpj_is_valid($cnpj)) {
        throw new NcLookupException('CNPJ inválido. Confira os 14 dígitos.', 'invalid');
    }
    if (!extension_loaded('curl')) {
        throw new NcLookupException('A extensão PHP cURL precisa estar ativa no servidor.', 'fatal');
    }
    $ch = curl_init('https://brasilapi.com.br/api/cnpj/v1/' . $cnpj);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: novocrm-saneamento'],
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false) {
        throw new NcLookupException('Falha de conexão com a BrasilAPI: ' . $err, 'error');
    }
    if ($status === 404) {
        throw new NcLookupException('CNPJ não encontrado na BrasilAPI.', 'notfound');
    }
    if ($status === 429) {
        throw new NcLookupException('Limite de consultas da BrasilAPI atingido.', 'limit');
    }
    $d = json_decode($raw, true);
    if ($status < 200 || $status >= 300 || !is_array($d)) {
        throw new NcLookupException('BrasilAPI HTTP ' . $status . ': ' . (is_array($d) ? (string)($d['message'] ?? 'erro não informado') : 'resposta inválida'), 'error');
    }
    $phone = (string)preg_replace('/\D+/', '', (string)($d['ddd_telefone_1'] ?? ''));
    $head = strtoupper((string)($d['descricao_identificador_matriz_filial'] ?? ''));
    $situacao = (string)($d['descricao_situacao_cadastral'] ?? '');
    return [
        'cnpj' => $cnpj,
        'name' => trim((string)($d['razao_social'] ?? '')),
        'alias' => nc_san_title((string)($d['nome_fantasia'] ?? '')),
        'registration_status' => nc_san_title($situacao),
        'registration_status_date' => substr((string)($d['data_situacao_cadastral'] ?? ''), 0, 10),
        'founded' => substr((string)($d['data_inicio_atividade'] ?? ''), 0, 10),
        'company_type' => $head === 'MATRIZ' ? 'Matriz' : ($head === 'FILIAL' ? 'Filial' : ''),
        'company_size' => nc_san_title((string)($d['porte'] ?? '')),
        'legal_nature' => trim((string)($d['natureza_juridica'] ?? '')),
        'capital_social' => isset($d['capital_social']) && is_numeric($d['capital_social']) ? (float)$d['capital_social'] : null,
        'email' => strtolower(trim((string)($d['email'] ?? ''))),
        'phone' => strlen($phone) >= 10 ? '(' . substr($phone, 0, 2) . ') ' . substr($phone, 2) : $phone,
        'zip' => (string)preg_replace('/\D+/', '', (string)($d['cep'] ?? '')),
        'street' => nc_san_title((string)($d['logradouro'] ?? '')),
        'address_number' => trim((string)($d['numero'] ?? '')),
        'address_details' => nc_san_title((string)($d['complemento'] ?? '')),
        'district' => nc_san_title((string)($d['bairro'] ?? '')),
        'city' => nc_san_title((string)($d['municipio'] ?? '')),
        'state' => strtoupper(trim((string)($d['uf'] ?? ''))),
        'main_activity_code' => isset($d['cnae_fiscal']) ? (string)$d['cnae_fiscal'] : '',
        'industry' => trim((string)($d['cnae_fiscal_descricao'] ?? '')),
        'cnpja_updated_at' => '',
        'active' => strtoupper($situacao) === 'ATIVA',
        'razao' => trim((string)($d['razao_social'] ?? '')),
        'fantasia' => trim((string)($d['nome_fantasia'] ?? '')),
        'raw' => $d,
    ];
}

/**
 * BrasilAPI primeiro; se falhar (limite, erro, fora do ar), a CNPJá como reserva. Devolve os campos + 'source'.
 *
 * @throws NcLookupException
 */
function nc_enrich_lookup(PDO $pdo, string $cnpj): array
{
    $set = nc_san_settings($pdo);
    $first = null;
    if ($set['active']) {
        try {
            $d = nc_brasilapi_lookup($cnpj);
            $d['source'] = 'brasilapi';
            return $d;
        } catch (NcLookupException $e) {
            if (in_array($e->kind, ['invalid', 'fatal'], true)) {
                throw $e;
            }
            $first = $e;
        }
    }
    $cfg = integration_get($pdo, 'cnpja');
    if (!empty($cfg['is_active']) && secret_decrypt($cfg['access_token'] ?? '') !== '') {
        try {
            $d = cnpja_lookup($pdo, $cnpj);
            $d['source'] = 'cnpja';
            return $d;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $kind = str_contains($msg, 'HTTP 404') ? 'notfound' : (str_contains($msg, 'HTTP 429') ? 'limit' : (str_contains($msg, 'cURL') ? 'fatal' : 'error'));
            if ($kind === 'notfound' && $first && $first->kind !== 'notfound') {
                $kind = 'error';
            }
            throw new NcLookupException($msg, $kind);
        }
    }
    if ($first) {
        throw $first;
    }
    throw new NcLookupException('Ative a BrasilAPI (em Saneamento) ou a CNPJá (em Configurações) para consultar CNPJ.', 'fatal');
}

/** Visão comum dos dados de uma consulta (BrasilAPI ou CNPJá). @return array{razao:string,fantasia:string,active:bool,state:string,city:string} */
function nc_san_data_view(array $d): array
{
    $status = strtolower((string)($d['registration_status'] ?? ''));
    return [
        'razao' => (string)($d['razao'] ?? $d['name'] ?? ''),
        'fantasia' => (string)($d['fantasia'] ?? $d['alias'] ?? ''),
        'active' => array_key_exists('active', $d) ? (bool)$d['active'] : ($status === '' || (str_contains($status, 'ativa') && !str_contains($status, 'inativa'))),
        'state' => strtoupper(trim((string)($d['state'] ?? ''))),
        'city' => (string)($d['city'] ?? ''),
    ];
}

function nc_san_sim(string $a, string $b): float
{
    if ($a === '' || $b === '') {
        return 0.0;
    }
    similar_text($a, $b, $pct);
    return (float)$pct;
}

/** Maior semelhança entre (nome, fantasia) da empresa e (razão, fantasia) do CNPJ consultado. */
function nc_san_name_sim(string $name, string $alias, array $view): float
{
    $mine = array_filter([nc_company_key($name), $alias !== '' ? nc_company_key($alias) : '']);
    $theirs = array_filter([nc_company_key($view['razao']), $view['fantasia'] !== '' ? nc_company_key($view['fantasia']) : '']);
    $best = 0.0;
    foreach ($mine as $m) {
        foreach ($theirs as $t) {
            $best = max($best, nc_san_sim($m, $t));
        }
    }
    return $best;
}

/**
 * Preenche SÓ campos vazios da empresa com os dados da consulta. Nunca troca o nome.
 * $setCnpj grava o CNPJ (aprovação humana). Sem ele, updated_at fica como estava (consulta automática não conta como edição).
 *
 * @return int quantos campos de dados foram preenchidos
 */
function nc_san_fill(PDO $pdo, int $companyId, array $data, ?string $setCnpj = null): int
{
    $q = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $q->execute([$companyId]);
    $cur = $q->fetch();
    if (!$cur) {
        return 0;
    }
    $set = [];
    foreach (NC_SAN_FILL_FIELDS as $f) {
        $new = $data[$f] ?? null;
        if ($new === null || $new === '' || !array_key_exists($f, $cur) || trim((string)$cur[$f]) !== '') {
            continue;
        }
        if (in_array($f, ['registration_status_date', 'founded'], true) && !valid_date_string((string)$new)) {
            continue;
        }
        $set[$f] = $f === 'state' ? strtoupper((string)$new) : $new;
    }
    $filled = count($set);
    if ($setCnpj !== null && trim((string)($cur['cnpj'] ?? '')) === '') {
        $set['cnpj'] = $setCnpj;
    }
    if (($data['source'] ?? '') === 'cnpja' && array_key_exists('cnpja_checked_at', $cur)) {
        $set['cnpja_checked_at'] = date('Y-m-d H:i:s');
        if (!empty($data['cnpja_updated_at']) && array_key_exists('cnpja_updated_at', $cur)) {
            $set['cnpja_updated_at'] = date('Y-m-d H:i:s', strtotime((string)$data['cnpja_updated_at']) ?: time());
        }
        if (array_key_exists('cnpja_json', $cur)) {
            $set['cnpja_json'] = json_encode($data['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    if (!$set) {
        return 0;
    }
    $assign = implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($set)));
    $touch = $setCnpj !== null ? 'updated_at = NOW()' : 'updated_at = updated_at';
    $pdo->prepare("UPDATE companies SET $assign, $touch WHERE id = ?")->execute(array_merge(array_values($set), [$companyId]));
    return $filled;
}

/* ------------------------------------------------------------------ */
/* Fila (company_enrich)                                               */
/* ------------------------------------------------------------------ */

function nc_san_ready(PDO $pdo): bool
{
    return nc_table_exists($pdo, 'company_enrich') && nc_table_exists($pdo, 'company_merge_log');
}

/** Grava (ou atualiza) o resultado de um passo para uma empresa. */
function nc_san_enrich_save(PDO $pdo, int $companyId, string $step, string $status, array $f = []): void
{
    $pdo->prepare(
        'INSERT INTO company_enrich (company_id, step, status, source, suggested_cnpj, confidence, evidence_json, error, attempts, checked_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE status = VALUES(status), source = VALUES(source), suggested_cnpj = VALUES(suggested_cnpj), confidence = VALUES(confidence),
           evidence_json = VALUES(evidence_json), error = VALUES(error), attempts = VALUES(attempts), checked_at = NOW()'
    )->execute([
        $companyId, $step, $status, $f['source'] ?? null, $f['cnpj'] ?? null, $f['confidence'] ?? null,
        isset($f['evidence']) ? json_encode($f['evidence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        isset($f['error']) ? mb_substr((string)$f['error'], 0, 500) : null, (int)($f['attempts'] ?? 0),
    ]);
}

/** Coloca na fila as empresas que ainda não estão: com CNPJ (validar) e sem CNPJ (descobrir). @return array{validar:int,descobrir:int} */
function nc_san_queue_fill(PDO $pdo): array
{
    $checked = nc_column_exists($pdo, 'companies', 'cnpja_checked_at') ? ' AND cnpja_checked_at IS NULL' : '';
    $person = nc_column_exists($pdo, 'companies', 'person_document') ? " AND (person_document IS NULL OR person_document = '')" : '';
    $a = $pdo->exec("INSERT IGNORE INTO company_enrich (company_id, step, status) SELECT id, 'validar', 'pendente' FROM companies WHERE cnpj IS NOT NULL AND cnpj <> ''" . $checked);
    $b = $pdo->exec("INSERT IGNORE INTO company_enrich (company_id, step, status) SELECT id, 'descobrir', 'pendente' FROM companies WHERE (cnpj IS NULL OR cnpj = '')" . $person);
    return ['validar' => (int)$a, 'descobrir' => (int)$b];
}

/** Quantas entradas ainda esperam processamento (pendentes ou com erro e tentativas sobrando). */
function nc_san_pending(PDO $pdo): int
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM company_enrich WHERE status IN ('pendente','erro') AND attempts < ?");
    $q->execute([NC_SAN_MAX_ATTEMPTS]);
    return (int)$q->fetchColumn();
}

/** Números para a tela e para o n8n. @return array<string,mixed> */
function nc_san_stats(PDO $pdo): array
{
    $total = (int)$pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
    $semCnpj = (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE cnpj IS NULL OR cnpj = ''")->fetchColumn();
    $queue = [];
    if (nc_table_exists($pdo, 'company_enrich')) {
        foreach ($pdo->query('SELECT step, status, COUNT(*) c FROM company_enrich GROUP BY step, status')->fetchAll() as $r) {
            $queue[(string)$r['step']][(string)$r['status']] = (int)$r['c'];
        }
    }
    return ['companies' => $total, 'sem_cnpj' => $semCnpj, 'com_cnpj' => $total - $semCnpj, 'queue' => $queue,
        'pendentes' => nc_san_ready($pdo) ? nc_san_pending($pdo) : 0];
}

/** Empresas com CNPJ válido por chave de nome (para achar "mesmo nome, uma com CNPJ"). @return array<string,array<int,array{id:int,cnpj:string}>> */
function nc_san_cnpj_index(PDO $pdo): array
{
    $idx = [];
    foreach ($pdo->query("SELECT id, name, cnpj FROM companies WHERE cnpj IS NOT NULL AND cnpj <> ''")->fetchAll() as $r) {
        $c = nc_valid_cnpj_or_empty((string)$r['cnpj']);
        $k = nc_company_key((string)$r['name']);
        if ($c !== '' && $k !== '') {
            $idx[$k][] = ['id' => (int)$r['id'], 'cnpj' => $c];
        }
    }
    return $idx;
}

/**
 * Um passo da fila: processa até $limit empresas (BrasilAPI/CNPJá/IA) respeitando o tempo máximo e a pausa entre chamadas.
 *
 * @return array{processed:int,ok:int,sugeridas:int,divergentes:int,duplicadas:int,nao_encontradas:int,erros:int,throttled:bool,fatal:string,remaining:int}
 */
function nc_san_step(PDO $pdo, int $userId, int $limit = 0): array
{
    $cfg = nc_san_settings($pdo);
    $limit = $limit > 0 ? min($limit, 50) : $cfg['batch'];
    $out = ['processed' => 0, 'ok' => 0, 'sugeridas' => 0, 'divergentes' => 0, 'duplicadas' => 0, 'nao_encontradas' => 0, 'erros' => 0,
        'throttled' => false, 'fatal' => '', 'remaining' => 0];
    $select = static function () use ($pdo, $limit): array {
        $q = $pdo->prepare(
            "SELECT * FROM company_enrich WHERE status IN ('pendente','erro') AND attempts < ? ORDER BY (step = 'validar') DESC, id LIMIT " . (int)$limit
        );
        $q->execute([NC_SAN_MAX_ATTEMPTS]);
        return $q->fetchAll();
    };
    $rows = $select();
    if (!$rows) {
        nc_san_queue_fill($pdo);
        $rows = $select();
    }
    $t0 = microtime(true);
    $index = null;
    $aiDone = 0;
    foreach ($rows as $e) {
        if (microtime(true) - $t0 > NC_SAN_STEP_SECONDS) {
            break;
        }
        $companyId = (int)$e['company_id'];
        $q = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $q->execute([$companyId]);
        $co = $q->fetch();
        if (!$co) {
            $pdo->prepare('DELETE FROM company_enrich WHERE id = ?')->execute([(int)$e['id']]);
            continue;
        }
        $isAi = $e['step'] === 'descobrir';
        if ($isAi && $aiDone >= NC_SAN_AI_PER_STEP) {
            continue;
        }
        try {
            if ($e['step'] === 'validar') {
                $status = nc_san_do_validar($pdo, $co, (int)$e['attempts']);
            } else {
                $index ??= nc_san_cnpj_index($pdo);
                $status = nc_san_do_descobrir($pdo, $co, (int)$e['attempts'], $index);
                $aiDone++;
            }
            $out['processed']++;
            match ($status) {
                'ok', 'aprovado' => $out['ok']++,
                'sugerido' => $out['sugeridas']++,
                'divergente' => $out['divergentes']++,
                'duplicada' => $out['duplicadas']++,
                'nao_encontrado', 'sem_resultado', 'invalido' => $out['nao_encontradas']++,
                default => null,
            };
        } catch (NcLookupException $x) {
            if ($x->kind === 'fatal') {
                $out['fatal'] = $x->getMessage();
                break;
            }
            if ($x->kind === 'limit') {
                $out['throttled'] = true;
                break;
            }
            nc_san_enrich_save($pdo, $companyId, (string)$e['step'], 'erro', ['error' => $x->getMessage(), 'attempts' => (int)$e['attempts'] + 1]);
            $out['erros']++;
        } catch (Throwable $x) {
            $msg = $x->getMessage();
            if (str_contains($msg, 'Configure e ative') || str_contains($msg, 'cURL')) {
                $out['fatal'] = $msg;
                break;
            }
            nc_san_enrich_save($pdo, $companyId, (string)$e['step'], 'erro', ['error' => $msg, 'attempts' => (int)$e['attempts'] + 1]);
            $out['erros']++;
        }
        if ($cfg['delay_ms'] > 0) {
            usleep($cfg['delay_ms'] * 1000);
        }
    }
    $out['remaining'] = nc_san_pending($pdo);
    return $out;
}

/** Passo "validar": empresa com CNPJ. Confere na BrasilAPI/CNPJá e completa campos vazios. */
function nc_san_do_validar(PDO $pdo, array $co, int $attempts): string
{
    $id = (int)$co['id'];
    $cnpj = cnpj_digits((string)$co['cnpj']);
    if (!cnpj_is_valid($cnpj)) {
        nc_san_enrich_save($pdo, $id, 'validar', 'invalido', ['error' => 'CNPJ com dígitos verificadores inválidos: ' . (string)$co['cnpj'], 'attempts' => $attempts]);
        return 'invalido';
    }
    try {
        $d = nc_enrich_lookup($pdo, $cnpj);
    } catch (NcLookupException $x) {
        if ($x->kind === 'notfound') {
            nc_san_enrich_save($pdo, $id, 'validar', 'nao_encontrado', ['error' => $x->getMessage(), 'attempts' => $attempts]);
            return 'nao_encontrado';
        }
        throw $x;
    }
    $view = nc_san_data_view($d);
    $sim = nc_san_name_sim((string)$co['name'], (string)($co['alias'] ?? ''), $view);
    $ev = ['razao_social' => $view['razao'], 'fantasia' => $view['fantasia'], 'situacao' => (string)($d['registration_status'] ?? ''), 'similaridade' => (int)round($sim)];
    if ($sim < NC_SAN_NAME_MIN_SIM) {
        nc_san_enrich_save($pdo, $id, 'validar', 'divergente', ['source' => $d['source'], 'cnpj' => $cnpj, 'confidence' => (int)round($sim), 'evidence' => $ev, 'attempts' => $attempts]);
        return 'divergente';
    }
    $ev['preenchidos'] = nc_san_fill($pdo, $id, $d);
    nc_san_enrich_save($pdo, $id, 'validar', 'ok', ['source' => $d['source'], 'cnpj' => $cnpj, 'confidence' => (int)round($sim), 'evidence' => $ev, 'attempts' => 0]);
    return 'ok';
}

/** Passo "descobrir": empresa sem CNPJ. Procura no próprio CRM e, se não achar, pergunta à IA e valida na BrasilAPI. */
function nc_san_do_descobrir(PDO $pdo, array $co, int $attempts, array &$index): string
{
    $id = (int)$co['id'];
    $name = (string)$co['name'];
    $key = nc_company_key($name);
    foreach ($index[$key] ?? [] as $o) {
        if ((int)$o['id'] !== $id) {
            nc_san_enrich_save($pdo, $id, 'descobrir', 'duplicada', ['source' => 'interno', 'cnpj' => $o['cnpj'], 'confidence' => 95,
                'evidence' => ['merge_with' => (int)$o['id'], 'motivo' => 'Outra empresa com o mesmo nome já tem CNPJ.'], 'attempts' => $attempts]);
            return 'duplicada';
        }
    }
    $ai = nc_san_ai_find_cnpj($pdo, $name, (string)($co['city'] ?? ''), (string)($co['state'] ?? ''), nc_san_company_domain($pdo, $id));
    $cnpj = cnpj_digits((string)($ai['cnpj'] ?? ''));
    if (!cnpj_is_valid($cnpj)) {
        nc_san_enrich_save($pdo, $id, 'descobrir', 'sem_resultado', ['source' => 'ia', 'evidence' => ['ia' => $ai, 'motivo' => 'A IA não encontrou um CNPJ com segurança.'], 'attempts' => $attempts]);
        return 'sem_resultado';
    }
    try {
        $d = nc_enrich_lookup($pdo, $cnpj);
    } catch (NcLookupException $x) {
        if ($x->kind === 'notfound' || $x->kind === 'invalid') {
            nc_san_enrich_save($pdo, $id, 'descobrir', 'sem_resultado', ['source' => 'ia', 'cnpj' => $cnpj,
                'evidence' => ['ia' => $ai, 'motivo' => 'O CNPJ sugerido pela IA não existe na consulta oficial.'], 'attempts' => $attempts]);
            return 'sem_resultado';
        }
        throw $x;
    }
    $view = nc_san_data_view($d);
    $sim = nc_san_name_sim($name, (string)($co['alias'] ?? ''), $view);
    $state = strtoupper(trim((string)($co['state'] ?? '')));
    $city = nc_company_key((string)($co['city'] ?? ''));
    $ufKnown = $state !== '' && $view['state'] !== '';
    $ufOk = $ufKnown && $state === $view['state'];
    $cityOk = $city !== '' && $view['city'] !== '' && $city === nc_company_key($view['city']);
    $ev = ['razao_social' => $view['razao'], 'fantasia' => $view['fantasia'], 'situacao' => (string)($d['registration_status'] ?? ''), 'cidade' => $view['city'],
        'uf' => $view['state'], 'similaridade' => (int)round($sim), 'ia' => $ai];
    if ($sim < NC_SAN_NAME_MIN_SIM || ($ufKnown && !$ufOk)) {
        $ev['motivo'] = $sim < NC_SAN_NAME_MIN_SIM ? 'O nome do CNPJ sugerido é muito diferente do nome da empresa.' : 'A UF do CNPJ sugerido é diferente da empresa.';
        nc_san_enrich_save($pdo, $id, 'descobrir', 'sem_resultado', ['source' => 'ia', 'cnpj' => $cnpj, 'confidence' => (int)round($sim), 'evidence' => $ev, 'attempts' => $attempts]);
        return 'sem_resultado';
    }
    $confidence = max(0, min(100, (int)round($sim) + ($ufOk ? 10 : 0) + ($cityOk ? 5 : 0) - ($view['active'] ? 0 : 25)));
    $q = $pdo->prepare('SELECT id FROM companies WHERE cnpj = ? AND id <> ?');
    $q->execute([$cnpj, $id]);
    $other = (int)$q->fetchColumn();
    if ($other > 0) {
        $ev['merge_with'] = $other;
    }
    nc_san_enrich_save($pdo, $id, 'descobrir', 'sugerido', ['source' => $d['source'], 'cnpj' => $cnpj, 'confidence' => $confidence, 'evidence' => $ev, 'attempts' => $attempts]);
    return 'sugerido';
}

/** Domínio de e-mail corporativo dos contatos da empresa (pista para a IA; ignora e-mails pessoais). */
function nc_san_company_domain(PDO $pdo, int $companyId): string
{
    $personal = ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com', 'yahoo.com.br', 'uol.com.br', 'bol.com.br', 'terra.com.br', 'icloud.com', 'live.com', 'ig.com.br', 'globo.com'];
    $q = $pdo->prepare("SELECT email FROM contacts WHERE company_id = ? AND email IS NOT NULL AND email <> '' ORDER BY id LIMIT 10");
    $q->execute([$companyId]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $email) {
        $domain = strtolower(trim((string)substr(strrchr((string)$email, '@') ?: '', 1)));
        if ($domain !== '' && !in_array($domain, $personal, true)) {
            return $domain;
        }
    }
    return '';
}

/* ------------------------------------------------------------------ */
/* Aprovação das sugestões de CNPJ                                     */
/* ------------------------------------------------------------------ */

/** Aprova uma sugestão: grava o CNPJ (ou mescla, se o CNPJ já é de outra empresa) e completa os campos vazios. @return array{ok:bool,message:string} */
function nc_san_approve(PDO $pdo, int $enrichId, int $userId): array
{
    $q = $pdo->prepare("SELECT * FROM company_enrich WHERE id = ? AND step = 'descobrir' AND status IN ('sugerido','duplicada')");
    $q->execute([$enrichId]);
    $e = $q->fetch();
    if (!$e) {
        return ['ok' => false, 'message' => 'Sugestão não encontrada ou já decidida.'];
    }
    $companyId = (int)$e['company_id'];
    $cnpj = cnpj_digits((string)$e['suggested_cnpj']);
    $q = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $q->execute([$companyId]);
    $co = $q->fetch();
    if (!$co) {
        return ['ok' => false, 'message' => 'A empresa não existe mais.'];
    }
    if (!cnpj_is_valid($cnpj)) {
        return ['ok' => false, 'message' => 'O CNPJ sugerido é inválido.'];
    }
    $q = $pdo->prepare('SELECT id FROM companies WHERE cnpj = ? AND id <> ?');
    $q->execute([$cnpj, $companyId]);
    $other = (int)$q->fetchColumn();
    if ($other > 0) {
        $r = nc_san_merge_group($pdo, [$companyId, $other], $userId);
        if (!$r['ok']) {
            return $r;
        }
        $pdo->prepare("UPDATE company_enrich SET status = 'aprovado', decided_by = ? WHERE id = ?")->execute([$userId, $enrichId]);
        return ['ok' => true, 'message' => 'Mesclada com a empresa que já tinha este CNPJ.'];
    }
    if (trim((string)($co['cnpj'] ?? '')) !== '') {
        $pdo->prepare("UPDATE company_enrich SET status = 'rejeitado', decided_by = ? WHERE id = ?")->execute([$userId, $enrichId]);
        return ['ok' => false, 'message' => 'A empresa já recebeu um CNPJ por outro caminho.'];
    }
    $filled = 0;
    try {
        $filled = nc_san_fill($pdo, $companyId, nc_enrich_lookup($pdo, $cnpj), $cnpj);
    } catch (Throwable $x) {
        // A consulta pode falhar agora (limite): o CNPJ já foi validado ao sugerir, então grava só ele.
        $pdo->prepare('UPDATE companies SET cnpj = ?, updated_at = NOW() WHERE id = ? AND (cnpj IS NULL OR cnpj = ?)')->execute([$cnpj, $companyId, '']);
    }
    $pdo->prepare("UPDATE company_enrich SET status = 'aprovado', decided_by = ? WHERE id = ?")->execute([$userId, $enrichId]);
    nc_san_enrich_save($pdo, $companyId, 'validar', 'ok', ['source' => (string)$e['source'], 'cnpj' => $cnpj, 'confidence' => (int)$e['confidence'], 'attempts' => 0]);
    audit($pdo, $userId, 'company_cnpj_approved', 'company', $companyId, ['cnpj' => $cnpj, 'source' => (string)$e['source'], 'filled' => $filled, 'via' => 'novocrm']);
    return ['ok' => true, 'message' => 'CNPJ gravado.'];
}

function nc_san_reject(PDO $pdo, int $enrichId, int $userId): void
{
    $pdo->prepare("UPDATE company_enrich SET status = 'rejeitado', decided_by = ? WHERE id = ? AND step = 'descobrir' AND status IN ('sugerido','duplicada','sem_resultado')")
        ->execute([$userId, $enrichId]);
}

/** Aprova todas as sugestões de alta confiança (sem mesclagem). @return array{approved:int,merged:int,left:int} */
function nc_san_approve_all(PDO $pdo, int $userId): array
{
    $q = $pdo->prepare("SELECT id, evidence_json FROM company_enrich WHERE step = 'descobrir' AND status = 'sugerido' AND confidence >= ? ORDER BY confidence DESC, id");
    $q->execute([NC_SAN_AUTO_CONFIDENCE]);
    $t0 = microtime(true);
    $approved = 0;
    $merged = 0;
    $rows = $q->fetchAll();
    $left = count($rows);
    foreach ($rows as $r) {
        if (microtime(true) - $t0 > NC_SAN_STEP_SECONDS) {
            break;
        }
        $ev = json_decode((string)$r['evidence_json'], true);
        $left--;
        if (!empty($ev['merge_with'])) {
            continue; // mesclagem exige olho humano
        }
        $res = nc_san_approve($pdo, (int)$r['id'], $userId);
        if ($res['ok']) {
            $approved++;
        }
    }
    return ['approved' => $approved, 'merged' => $merged, 'left' => max(0, $left)];
}

/* ------------------------------------------------------------------ */
/* Duplicadas                                                          */
/* ------------------------------------------------------------------ */

/**
 * Escolhe a empresa principal de um grupo: CNPJ válido, depois mais vínculos (contatos + oportunidades), depois a mais antiga.
 *
 * @param int[] $ids
 */
function nc_san_pick_principal(PDO $pdo, array $ids): int
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare(
        "SELECT c.id, c.cnpj, (SELECT COUNT(*) FROM contacts x WHERE x.company_id = c.id) + (SELECT COUNT(*) FROM opportunities o WHERE o.company_id = c.id) AS links
         FROM companies c WHERE c.id IN ($in)"
    );
    $q->execute($ids);
    $best = null;
    foreach ($q->fetchAll() as $r) {
        $score = [nc_valid_cnpj_or_empty((string)($r['cnpj'] ?? '')) !== '' ? 1 : 0, (int)$r['links'], -(int)$r['id']];
        if ($best === null || $score > $best[0]) {
            $best = [$score, (int)$r['id']];
        }
    }
    return $best ? $best[1] : ($ids[0] ?? 0);
}

/** Mescla um grupo de empresas na principal escolhida pela regra acima. @param int[] $ids @return array{ok:bool,message:string,principal?:int} */
function nc_san_merge_group(PDO $pdo, array $ids, int $userId): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (count($ids) < 2) {
        return ['ok' => false, 'message' => 'Informe ao menos duas empresas.'];
    }
    try {
        $principal = nc_san_pick_principal($pdo, $ids);
        nc_company_merge($pdo, $principal, array_values(array_diff($ids, [$principal])), [], $userId);
        return ['ok' => true, 'message' => 'Mesclada.', 'principal' => $principal];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Procura duplicadas em camadas:
 *  - seguras: mesmo CNPJ completo e válido (podem ser mescladas em bloco);
 *  - filiais: mesmo CNPJ raiz com CNPJs completos diferentes (só marcadas, nunca mescladas);
 *  - parecidas: mesmo nome ou nomes ≥ 90% parecidos, sem dois CNPJs válidos diferentes (aprovação humana).
 *
 * @return array{seguras:array,filiais:array,parecidas:array}
 */
function nc_saneamento_duplicates(PDO $pdo): array
{
    $rows = [];
    foreach ($pdo->query('SELECT id, name, alias, cnpj FROM companies')->fetchAll() as $r) {
        $rows[(int)$r['id']] = [
            'id' => (int)$r['id'], 'name' => (string)$r['name'], 'cnpj' => nc_valid_cnpj_or_empty((string)($r['cnpj'] ?? '')),
            'key' => nc_company_key((string)$r['name']),
        ];
    }
    $byCnpj = [];
    $byRoot = [];
    foreach ($rows as $r) {
        if ($r['cnpj'] !== '') {
            $byCnpj[$r['cnpj']][] = $r['id'];
            $byRoot[substr($r['cnpj'], 0, 8)][$r['cnpj']][] = $r['id'];
        }
    }
    $seguras = [];
    $sameCnpjPair = [];
    foreach ($byCnpj as $cnpj => $ids) {
        if (count($ids) > 1) {
            $seguras[] = ['ids' => $ids, 'cnpj' => (string)$cnpj, 'names' => array_map(static fn($i) => $rows[$i]['name'], $ids)];
            foreach ($ids as $a) {
                foreach ($ids as $b) {
                    $sameCnpjPair[$a . ':' . $b] = true;
                }
            }
        }
    }
    $filiais = [];
    foreach ($byRoot as $root => $cnpjs) {
        if (count($cnpjs) > 1 && count($filiais) < 100) {
            $filiais[] = ['root' => (string)$root, 'cnpjs' => array_keys($cnpjs), 'ids' => array_merge(...array_values($cnpjs))];
        }
    }

    $parecidas = [];
    $seen = [];
    $conflict = static fn(array $a, array $b): bool => $a['cnpj'] !== '' && $b['cnpj'] !== '' && $a['cnpj'] !== $b['cnpj'];
    $push = static function (array $a, array $b, int $sim, string $reason) use (&$parecidas, &$seen, $sameCnpjPair, $conflict): void {
        $pair = min($a['id'], $b['id']) . ':' . max($a['id'], $b['id']);
        if (isset($seen[$pair]) || isset($sameCnpjPair[$a['id'] . ':' . $b['id']]) || $conflict($a, $b) || count($parecidas) >= 300) {
            return;
        }
        $seen[$pair] = true;
        $parecidas[] = ['ids' => [$a['id'], $b['id']], 'names' => [$a['name'], $b['name']], 'cnpjs' => array_values(array_filter([$a['cnpj'], $b['cnpj']])),
            'sim' => $sim, 'reason' => $reason];
    };
    // Mesmo nome (chave idêntica).
    $byKey = [];
    foreach ($rows as $r) {
        if ($r['key'] !== '') {
            $byKey[$r['key']][] = $r;
        }
    }
    foreach ($byKey as $list) {
        $n = count($list);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n && $j < $i + 12; $j++) {
                $push($list[$i], $list[$j], 100, 'Mesmo nome');
            }
        }
    }
    // Nomes muito parecidos: compara só dentro do mesmo primeiro termo (evita comparar todos contra todos).
    $buckets = [];
    foreach ($rows as $r) {
        $first = explode(' ', $r['key'])[0] ?? '';
        if (strlen($first) >= 3) {
            $buckets[$first][] = $r;
        }
    }
    foreach ($buckets as $list) {
        $n = count($list);
        if ($n < 2 || $n > 150) {
            continue;
        }
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($list[$i]['key'] === $list[$j]['key']) {
                    continue;
                }
                $sim = nc_san_sim($list[$i]['key'], $list[$j]['key']);
                if ($sim >= NC_SAN_DUP_SIM) {
                    $push($list[$i], $list[$j], (int)round($sim), 'Nome ' . (int)round($sim) . '% parecido');
                }
            }
        }
    }
    usort($parecidas, static fn($a, $b) => $b['sim'] <=> $a['sim']);
    return ['seguras' => $seguras, 'filiais' => $filiais, 'parecidas' => $parecidas];
}

/** Mescla em bloco todos os grupos de mesmo CNPJ completo. @return array{merged:int,groups:int,errors:string[],left:int} */
function nc_san_merge_safe(PDO $pdo, int $userId): array
{
    $dups = nc_saneamento_duplicates($pdo)['seguras'];
    $t0 = microtime(true);
    $merged = 0;
    $groups = 0;
    $errors = [];
    $left = count($dups);
    foreach ($dups as $g) {
        if (microtime(true) - $t0 > NC_SAN_STEP_SECONDS) {
            break;
        }
        $left--;
        $r = nc_san_merge_group($pdo, $g['ids'], $userId);
        if ($r['ok']) {
            $merged += count($g['ids']) - 1;
            $groups++;
        } elseif (count($errors) < 5) {
            $errors[] = $r['message'];
        }
    }
    return ['merged' => $merged, 'groups' => $groups, 'errors' => $errors, 'left' => max(0, $left)];
}

/* ------------------------------------------------------------------ */
/* IA (OpenAI): só propõe; a validação decide                          */
/* ------------------------------------------------------------------ */

/**
 * Chamada simples à Responses API (texto), com busca na web quando pedido.
 * Se o modelo recusar a ferramenta de busca, tenta de novo sem ela (a BrasilAPI continua validando).
 */
function nc_san_openai_text(PDO $pdo, string $prompt, bool $webSearch): string
{
    $config = integration_get($pdo, 'openai');
    $apiKey = secret_decrypt($config['access_token'] ?? '');
    if (empty($config['is_active']) || $apiKey === '') {
        throw new RuntimeException('Configure e ative ChatGPT / OpenAI em Configurações.');
    }
    if (!extension_loaded('curl')) {
        throw new RuntimeException('A extensão PHP cURL precisa estar ativa no servidor.');
    }
    $extra = json_decode((string)($config['extra_json'] ?? '{}'), true);
    $model = trim((string)(is_array($extra) ? ($extra['model'] ?? '') : '')) ?: 'gpt-5.6-sol';
    $call = static function (bool $tools) use ($apiKey, $model, $prompt): array {
        $payload = ['model' => $model, 'store' => false, 'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $prompt]]]]];
        if ($tools) {
            $payload['tools'] = [['type' => 'web_search']];
        }
        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Falha ao consultar a OpenAI: ' . $err);
        }
        $resp = json_decode($raw, true);
        return [$status, is_array($resp) ? $resp : []];
    };
    [$status, $resp] = $call($webSearch);
    if ($webSearch && $status === 400) {
        [$status, $resp] = $call(false);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('OpenAI HTTP ' . $status . ': ' . (string)($resp['error']['message'] ?? 'erro não informado'));
    }
    return openai_response_text($resp);
}

/** Extrai o primeiro objeto JSON de um texto (a IA às vezes envolve a resposta em markdown). */
function nc_san_extract_json(string $text): array
{
    $a = strpos($text, '{');
    $b = strrpos($text, '}');
    if ($a === false || $b === false || $b <= $a) {
        return [];
    }
    $d = json_decode(substr($text, $a, $b - $a + 1), true);
    return is_array($d) ? $d : [];
}

/** Pergunta à IA o CNPJ provável de uma empresa. Devolve ['cnpj'=>..., 'razao_social'=>..., 'confianca'=>...] (cnpj vazio se não souber). */
function nc_san_ai_find_cnpj(PDO $pdo, string $name, string $city, string $state, string $domain): array
{
    $hints = array_filter([
        $city !== '' || $state !== '' ? 'Local: ' . trim($city . ($state !== '' ? '/' . $state : '')) : '',
        $domain !== '' ? 'Domínio de e-mail: ' . $domain : '',
    ]);
    $prompt = "Identifique o CNPJ da empresa brasileira abaixo, consultando fontes públicas na web (Receita Federal, site da empresa, cadastros de CNPJ).\n"
        . 'Nome: "' . $name . "\"\n" . implode("\n", $hints) . "\n\n"
        . 'Responda SOMENTE com um objeto JSON, sem texto extra: {"cnpj":"14 dígitos ou vazio","razao_social":"...","confianca":0 a 100}. '
        . 'Se houver dúvida entre empresas diferentes ou você não tiver certeza, devolva "cnpj" vazio. Nunca invente um CNPJ.';
    return nc_san_extract_json(nc_san_openai_text($pdo, $prompt, true));
}

/**
 * Pede à IA que escolha, para cada cliente da planilha, a empresa candidata que é a MESMA (ou nenhuma).
 * Só compara nomes; não envia dados de pessoas. O resultado é uma sugestão que o usuário aprova.
 *
 * @param array<int,array{key:string,name:string,cands:array<int,array{id:int,name:string,city:string,state:string}>}> $items
 * @return array<string,array{cid:int,conf:int}> chave do cliente => sugestão
 */
function nc_san_ai_match_groups(PDO $pdo, array $items): array
{
    $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['items'], 'properties' => ['items' => ['type' => 'array', 'items' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['ref', 'company_id', 'confidence'],
        'properties' => ['ref' => ['type' => 'integer'], 'company_id' => ['type' => 'integer'], 'confidence' => ['type' => 'integer']],
    ]]]];
    $out = [];
    foreach (array_chunk(array_values($items), 25) as $chunk) {
        $list = [];
        foreach ($chunk as $i => $it) {
            $list[] = ['ref' => $i, 'cliente' => $it['name'], 'candidatas' => array_map(
                static fn($c) => ['id' => $c['id'], 'nome' => $c['name'], 'local' => trim($c['city'] . ($c['state'] !== '' ? '/' . $c['state'] : ''))],
                array_slice($it['cands'], 0, 5)
            )];
        }
        $prompt = "Para cada cliente, diga qual empresa candidata é a MESMA empresa (mesma razão social, nome fantasia ou grafia diferente do mesmo nome). "
            . "Não confunda filiais ou empresas de grupos diferentes só por nome parecido. Se nenhuma candidata for claramente a mesma, use company_id 0. "
            . "confidence é de 0 a 100.\n" . json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $res = openai_call_json_schema($pdo, [['type' => 'input_text', 'text' => $prompt]], 'import_group_match', $schema);
        foreach ((array)($res['items'] ?? []) as $r) {
            $ref = (int)($r['ref'] ?? -1);
            $cid = (int)($r['company_id'] ?? 0);
            if (!isset($chunk[$ref]) || $cid <= 0) {
                continue;
            }
            $valid = array_map(static fn($c) => (int)$c['id'], $chunk[$ref]['cands']);
            if (in_array($cid, $valid, true)) {
                $out[$chunk[$ref]['key']] = ['cid' => $cid, 'conf' => max(0, min(100, (int)($r['confidence'] ?? 0)))];
            }
        }
    }
    return $out;
}
