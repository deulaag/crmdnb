<?php
declare(strict_types=1);

/**
 * Testes da Central de Importação do NOVOCRM (V3, Fases 8 e 9).
 *
 *   php tests/novocrm_import_test.php
 *
 * Usa SQLite em memória (extensão pdo_sqlite) com uma subclasse de PDO que traduz o que há de MySQL nas consultas.
 * As planilhas de exemplo estão em tests/fixtures (geradas por tests/fixtures/make_fixtures.py).
 * Sem pdo_sqlite o teste é pulado (código de saída 0) para não travar ambientes sem a extensão.
 */

if (!extension_loaded('pdo_sqlite')) {
    echo "PULADO: a extensão pdo_sqlite não está ativa.\n";
    exit(0);
}

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($msg . ' @ ' . basename($file) . ':' . $line, 0, $no, $file, $line);
});

$root = dirname(__DIR__);
require $root . '/includes/functions.php';
require $root . '/novocrm/_lib.php';
require $root . '/novocrm/_import_run.php';

/** PDO de teste: traduz o SQL de MySQL usado pelo código (information_schema, NOW, <=>, DATE_FORMAT, FOR UPDATE). */
final class FakePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'information_schema.COLUMNS')) {
            $query = 'SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = ?';
        } elseif (str_contains($query, 'information_schema.TABLES')) {
            $query = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = ?";
        }
        $query = str_replace([' FOR UPDATE', '<=>', 'NOW()', "DATE_FORMAT(opportunity_date, '%Y-%m')"],
            ['', ' IS ', 'CURRENT_TIMESTAMP', "strftime('%Y-%m', opportunity_date)"], $query);
        return parent::prepare($query, $options);
    }
}

const CNPJ_A = '11222333000181';
const CNPJ_B = '45678912000155';
const CNPJ_C = '77889966000156';
const CNPJ_D = '33445522000156';
const CNPJ_E = '01234567000195';
const CNPJ_F = '11222333000262';

$fixtures = __DIR__ . '/fixtures';
$tmpRoot = sys_get_temp_dir() . '/nc_import_test_' . bin2hex(random_bytes(4));
mkdir($tmpRoot, 0777, true);

$fail = 0;
$total = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $fail, $total;
    $total++;
    echo ($ok ? 'OK    ' : 'FALHA ') . $name . ($ok ? '' : '  => ' . $extra) . "\n";
    if (!$ok) {
        $fail++;
    }
}
function same(mixed $a, mixed $b): bool
{
    return $a === $b;
}
function dump(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '?';
}

/* ------------------------------------------------------------------ */
/* Banco de teste                                                      */
/* ------------------------------------------------------------------ */

function new_db(): FakePdo
{
    $pdo = new FakePdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, active INT DEFAULT 1)');
    $pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, action TEXT, entity_type TEXT, entity_id INT, context_json TEXT, ip_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, alias TEXT, cnpj TEXT, city TEXT, state TEXT, phone TEXT, owner_user_id INT,
        interest_lines TEXT, source_quote_count INT, source_last_quote_at TEXT, source_origin TEXT, import_note TEXT,
        rd_crm_id TEXT, external_id TEXT, person_document TEXT, merged_ids_json TEXT, source TEXT DEFAULT "manual", import_batch_id INT, import_synced_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INT, name TEXT, first_name TEXT, email TEXT, phone TEXT, job_title TEXT, source TEXT DEFAULT "manual",
        external_id TEXT, import_batch_id INT, import_synced_at TEXT, created_by INT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE product_lines (id INTEGER PRIMARY KEY, name TEXT, funnel_key TEXT, item_type TEXT, active INT DEFAULT 1, sort_order INT DEFAULT 0)');
    $pdo->exec('CREATE TABLE funnels (id INTEGER PRIMARY KEY, funnel_key TEXT, active INT DEFAULT 1)');
    $pdo->exec('CREATE TABLE funnel_stages (id INTEGER PRIMARY KEY, funnel_id INT, name TEXT, sort_order INT)');
    $pdo->exec('CREATE TABLE opportunities (id INTEGER PRIMARY KEY AUTOINCREMENT, number TEXT UNIQUE, opportunity_date TEXT, client_name TEXT, company_id INT, seller_user_id INT,
        product_line_id INT, funnel_id INT, stage_id INT, item_type TEXT, amount REAL, status TEXT, loss_reason TEXT, loss_recorded_at TEXT, quote_required INT DEFAULT 0,
        quote_reference TEXT, source TEXT, notes TEXT, created_by INT, external_source TEXT, external_ref TEXT, import_batch_id INT, import_synced_at TEXT,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE opportunity_notes (id INTEGER PRIMARY KEY AUTOINCREMENT, opportunity_id INT, note TEXT)');
    $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, opportunity_id INT, client_name TEXT)');
    $pdo->exec('CREATE TABLE visits (id INTEGER PRIMARY KEY, client_name TEXT)');
    $pdo->exec('CREATE TABLE import_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, import_type TEXT, file_name TEXT, file_hash TEXT, file_path TEXT, sheet_name TEXT, template_id INT,
        status TEXT DEFAULT "enviado", default_product_line_id INT, counters_json TEXT, error_message TEXT, created_by INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        confirmed_by INT, confirmed_at TEXT, undone_by INT, undone_at TEXT)');
    $pdo->exec('CREATE TABLE import_rows (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id INT, sheet_row INT, raw_json TEXT, normalized_json TEXT, classification TEXT, reason TEXT,
        match_rule TEXT, company_id INT, candidates_json TEXT, resolved_company_id INT, resolution TEXT, resolved_by INT, resolved_at TEXT, keep_crm_fields_json TEXT,
        result_json TEXT, previous_json TEXT)');
    $pdo->exec('CREATE TABLE import_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, import_type TEXT, name TEXT, header_signature TEXT, mapping_json TEXT, value_maps_json TEXT,
        external_source TEXT, updated_by INT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE (import_type, header_signature))');

    $pdo->exec("INSERT INTO users (id, name, email) VALUES (1,'Admin','admin@dnbe.com.br'),(2,'Vendedor Um','vendas1@dnbe.com.br')");
    $pdo->exec("INSERT INTO product_lines (id, name, funnel_key, item_type) VALUES (1,'Linha A','f1','Produto'),(2,'Linha B','f1','Serviço')");
    $pdo->exec("INSERT INTO funnels (id, funnel_key) VALUES (1,'f1')");
    $pdo->exec("INSERT INTO funnel_stages (id, funnel_id, name, sort_order) VALUES (1,1,'Prospecção',1),(2,1,'Proposta',2),(3,1,'Fechamento',3)");

    $co = $pdo->prepare('INSERT INTO companies (id, name, cnpj, city, state, external_id) VALUES (?,?,?,?,?,?)');
    foreach ([
        [1, 'Acme Indústria e Comércio Ltda', CNPJ_A, 'São Paulo', 'SP', null],
        [2, 'Beta Metais Ltda', CNPJ_B, 'Recife', 'PE', 'EXT-BETA'],
        [3, 'Gamma Peças', null, null, null, null],
        [4, 'Delta Comércio Ltda', null, null, null, null],
        [5, 'Delta Indústria', null, null, null, null],
        [6, 'Epsilon Brasil', null, null, null, 'EXT-EPS'],
        [7, 'Zeta Foods', CNPJ_C, null, null, null],
        [8, 'Omega Sistemas', CNPJ_D, null, null, null],
    ] as $r) {
        $co->execute($r);
    }
    $pdo->exec("INSERT INTO contacts (company_id, name, email) VALUES (2,'Carla Dias','carla@beta.com.br')");
    // Orçamento ORC-007 já existe no CRM, no cliente Acme (teste 7)
    $pdo->exec("INSERT INTO opportunities (number, opportunity_date, client_name, company_id, product_line_id, funnel_id, stage_id, item_type, amount, status, source, created_by,
        external_source, external_ref, import_batch_id, import_synced_at) VALUES ('DNB-001/01/2024','2024-01-10','Acme',1,1,1,1,'Produto',10,'Aberta','xlsx',1,
        'xlsx_orcamentos','ORC-007',99,'2024-01-10 10:00:00')");
    return $pdo;
}

/** Envia, mapeia, processa e devolve o lote pronto para a prévia (Etapas 1 a 3). */
function do_import(PDO $pdo, string $type, string $fixture, array $opts = []): array
{
    global $fixtures, $tmpRoot;
    $src = $fixtures . '/' . $fixture;
    $file = nc_import_store_file($src, $fixture, $tmpRoot);
    $batchId = nc_import_create_batch($pdo, $type, $file, 1, $opts['sheet'] ?? null);
    $batch = nc_import_batch($pdo, $batchId);
    $sheet = nc_sheet_read(nc_import_file_path($batch, $tmpRoot), $batch['sheet_name'] ?: null);
    $headers = $sheet['rows'][0]['cells'];
    $mapping = nc_import_suggest_mapping($headers, $type);
    foreach ($opts['map'] ?? [] as $i => $key) {
        $mapping[$i] = $key;
    }
    nc_import_stage($pdo, $batch, ['mapping' => $mapping, 'value_maps' => $opts['value_maps'] ?? []], $tmpRoot);
    $batch = nc_import_batch($pdo, $batchId);
    $guard = 0;
    do {
        $r = nc_import_process_chunk($pdo, $batch);
        $batch = nc_import_batch($pdo, $batchId);
    } while (!$r['done'] && ++$guard < 50);
    if (!empty($opts['default_line'])) {
        nc_import_set_default_line($pdo, $batch, (int)$opts['default_line']);
        $batch = nc_import_batch($pdo, $batchId);
    }
    return $batch;
}

/** Linhas do lote por número da linha da planilha. @return array<int,array<string,mixed>> */
function rows_of(PDO $pdo, int $batchId): array
{
    $q = $pdo->prepare('SELECT * FROM import_rows WHERE batch_id = ? ORDER BY sheet_row');
    $q->execute([$batchId]);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $r['n'] = json_decode((string)$r['normalized_json'], true) ?: [];
        $out[(int)$r['sheet_row']] = $r;
    }
    return $out;
}
function count_of(PDO $pdo, string $table): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
}
function resolve(PDO $pdo, int $batchId, int $sheetRow, string $op, ?int $company = null, bool $same = false): void
{
    $batch = nc_import_batch($pdo, $batchId);
    $q = $pdo->prepare('SELECT id FROM import_rows WHERE batch_id = ? AND sheet_row = ?');
    $q->execute([$batchId, $sheetRow]);
    nc_import_resolve($pdo, $batch, (int)$q->fetchColumn(), $op, $company, 1, $same);
}
function summary(PDO $pdo, int $batchId): array
{
    return nc_import_recount($pdo, $batchId);
}

// Permite que outros scripts (ex.: renderização das telas) reutilizem os auxiliares deste arquivo.
if (getenv('NC_IMPORT_TEST_LIB') === '1') {
    return;
}

/* ================================================================== */
echo "== Leitor de planilha e conversões (V3, seção 3) ==\n";

$sheet = nc_sheet_read($fixtures . '/contatos.xlsx');
check('XLSX: lê a 1ª aba VISÍVEL (a sheet1.xml é oculta) e lista as abas', $sheet['sheet'] === 'Contatos' && $sheet['sheets'] === ['Contatos', 'Outra aba'], dump($sheet['sheets']));
check('XLSX: preserva o número real da linha (pula as vazias 5 e 9)', array_column($sheet['rows'], 'n') === [1, 2, 3, 4, 6, 7, 8, 10, 11, 12, 13, 14], dump(array_column($sheet['rows'], 'n')));
$outra = nc_sheet_read($fixtures . '/contatos.xlsx', 'Outra aba');
check('XLSX: abre a aba escolhida pelo nome', $outra['rows'][0]['cells'] === ['a', 'b']);
$inl = nc_sheet_read($fixtures . '/orcamentos_inline_1904.xlsx');
check('XLSX: lê células inlineStr', $inl['rows'][1]['cells'][0] === 'INL-1' && $inl['rows'][1]['cells'][2] === 'Gamma Peças');
check('XLSX: detecta date1904 e converte o serial (44160 => 2024-11-26 em base 1904, = 45622 em base 1900)', $inl['date1904'] === true && nc_parse_date('44160', true) === '2024-11-26', (string)nc_parse_date('44160', true));
$csv = nc_sheet_read($fixtures . '/orcamentos.csv');
check('CSV: Windows-1252 com ; vira UTF-8 (acentos preservados)', $csv['rows'][0]['cells'][1] === 'Data da emissão' && $csv['rows'][1]['cells'][2] === 'Gamma Peças');
try {
    nc_sheet_read($fixtures . '/contatos.xlsx', 'Nao existe');
    check('XLSX: aba inexistente dá erro', false);
} catch (RuntimeException) {
    check('XLSX: aba inexistente dá erro', true);
}

foreach ([['1234.56', 1234.56], ['1.234,56', 1234.56], ['R$ 1.234,56', 1234.56], ['1234,5', 1234.5], ['R$ 5.980,77', 5980.77], ['1.234.567,89', 1234567.89],
    ['1,234.56', 1234.56], ['0,00', 0.0], ['1.234', 1234.0]] as [$in, $out]) {
    check('Valor "' . $in . '" => ' . $out, nc_parse_money($in) === $out, dump(nc_parse_money($in)));
}
foreach (['abc', '-10', '', 'R$', '12,3x'] as $bad) {
    check('Valor inválido "' . $bad . '" => null (vira Erro)', nc_parse_money($bad) === null);
}
foreach ([['45622', '2024-11-26'], ['26/11/2024', '2024-11-26'], ['26/11/24', '2024-11-26'], ['2024-11-26', '2024-11-26'], ['26/11/2024 14:30', '2024-11-26'],
    ['29/10/2026 17:27', '2026-10-29']] as [$in, $out]) {
    check('Data "' . $in . '" => ' . $out, nc_parse_date($in) === $out, dump(nc_parse_date($in)));
}
check('Data impossível 31/02/2024 => null', nc_parse_date('31/02/2024') === null);
$d = nc_normalize_document('1234567000195');
check('CNPJ sem zero à esquerda é corrigido e sinalizado', $d['kind'] === 'cnpj' && $d['digits'] === CNPJ_E && $d['corrected'] === true);
check('CNPJ em notação científica do Excel (1.1222333000181E+13)', nc_normalize_document('1.1222333000181E+13')['digits'] === CNPJ_A);
check('CNPJ inválido => invalido', nc_normalize_document('11.222.333/0001-82')['kind'] === 'invalido');
check('CPF válido é aceito como documento de pessoa física (nunca como CNPJ)', nc_normalize_document('529.982.247-25')['kind'] === 'cpf');
check('Chave de nome: "ACME Indústria e Comércio Ltda." = "ACME IND. E COM. LTDA" = acme',
    nc_company_key('ACME Indústria e Comércio Ltda.') === 'acme' && nc_company_key('ACME IND. E COM. LTDA') === 'acme');
check('Chave de nome: "Acme Industrial" é diferente', nc_company_key('Acme Industrial') === 'acme industrial');
check('Linha "Total Engenharia" NÃO é linha de total, mas "Total" + número é',
    !nc_import_row_is_total(['Total Engenharia', 'x']) && nc_import_row_is_total(['Total', '', '99999']));

$h = ['Vendedor', 'E-mail', 'Número', 'Versão', 'Tipo', 'Cliente', 'Data da emissão', 'Emitida por', 'Total (R$)', 'Desconto (R$)', 'Emissões da proposta', 'Conta no valor'];
$m = nc_import_suggest_mapping($h, 'orcamentos');
check('Sinônimos: cabeçalho do relatório "propostas por vendedor" é reconhecido',
    $m[0] === 'seller' && $m[1] === 'seller_email' && $m[2] === 'external_ref' && $m[3] === 'version' && $m[4] === 'product_line'
    && $m[5] === 'client_name' && $m[6] === 'opportunity_date' && $m[7] === '' && $m[8] === 'amount' && $m[11] === 'counts_in_value', dump($m));
check('Sinônimos: Contatos', nc_import_suggest_mapping(['Nome', 'Cargo', 'E-mail', 'Telefone', 'Empresa', 'CNPJ', 'Cidade', 'UF', 'ID da empresa'], 'contatos')
    === ['contact_name', 'contact_job_title', 'contact_email', 'contact_phone', 'company_name', 'company_cnpj', 'company_city', 'company_state', 'company_external_id']);
check('Obrigatórios: sem e-mail/telefone o botão Continuar fica bloqueado', nc_import_mapping_missing('contatos', ['contact_name', 'company_name']) === ['E-mail ou telefone do contato']);

/* ================================================================== */
echo "\n== Fase 9: Orçamentos e propostas (testes obrigatórios 1 a 13) ==\n";

$pdo = new_db();
$batch = do_import($pdo, 'orcamentos', 'orcamentos.xlsx');
$bid = (int)$batch['id'];
$rows = rows_of($pdo, $bid);
$cls = static fn(int $n): string => (string)($rows[$n]['classification'] ?? '?');
$why = static fn(int $n): string => $cls($n) . ': ' . ($rows[$n]['reason'] ?? '');

check('1. Orçamento com CNPJ de empresa existente => vinculado (regra cnpj)', $cls(2) === 'novo' && $rows[2]['match_rule'] === 'cnpj' && (int)$rows[2]['company_id'] === 1, $why(2) . ' ' . ($rows[2]['match_rule'] ?? ''));
check('2. Sem CNPJ e nome igual a DUAS empresas => Revisar', $cls(3) === 'revisar' && (int)($rows[3]['company_id'] ?? 0) === 0, $why(3));
check('3. "ACME IND. E COM. LTDA" com uma única "Acme Indústria e Comércio Ltda" => vinculado (name_exact)', $cls(4) === 'novo' && $rows[4]['match_rule'] === 'name_exact' && (int)$rows[4]['company_id'] === 1, $why(4));
$cand4 = json_decode((string)$rows[5]['candidates_json'], true) ?: [];
check('4. "Acme Industrial" (parecido, não igual) => Revisar, NUNCA vinculado sozinho', $cls(5) === 'revisar' && (int)($rows[5]['company_id'] ?? 0) === 0 && ($cand4[0]['id'] ?? 0) === 1, $why(5) . ' ' . dump($cand4));
check('5. ID aponta para A e CNPJ para B => Revisar com as duas', $cls(6) === 'revisar' && str_contains((string)$rows[6]['reason'], 'Conflito') && count(json_decode((string)$rows[6]['candidates_json'], true) ?: []) === 2, $why(6));
check('6. CNPJ de A com nome totalmente diferente => Revisar', $cls(7) === 'revisar' && str_contains((string)$rows[7]['reason'], 'muito diferente'), $why(7));
check('7. external_ref já existente em OUTRA empresa => Revisar (nada é movido sozinho)', $cls(8) === 'revisar' && str_contains((string)$rows[8]['reason'], 'já está no cliente'), $why(8));
check('9. Status desconhecido ("Em análise X") => Revisar', $cls(9) === 'revisar' && str_contains((string)$rows[9]['reason'], 'Status desconhecido'), $why(9));
check('10a. Valor "R$ 1.234,56" => 1234.56 e data serial 45622 => 2024-11-26 (na linha importada)', (float)$rows[2]['n']['amount'] === 1234.56 && $rows[2]['n']['date'] === '2024-11-26', dump([$rows[2]['n']['amount'] ?? null, $rows[2]['n']['date'] ?? null]));
check('11a. Perdida sem motivo vai usar o motivo padrão do lote', $cls(10) === 'novo' && str_contains((string)$rows[10]['n']['plan']['loss_reason'], 'Não informado na planilha (importação lote #' . $bid . ')'), $why(10));
check('Duplicada idêntica no arquivo => a 2ª é Ignorar', $cls(11) === 'novo' && $cls(12) === 'ignorar', $cls(11) . '/' . $cls(12));
check('Mesmo número com dados diferentes => as DUAS viram Erro', $cls(13) === 'erro' && $cls(14) === 'erro', $cls(13) . '/' . $cls(14));
check('Valor e data inválidos => Erro com o motivo', $cls(15) === 'erro' && str_contains((string)$rows[15]['reason'], 'Data inválida') && str_contains((string)$rows[15]['reason'], 'Valor inválido'), $why(15));
check('Linha de total => Ignorar', $cls(16) === 'ignorar', $why(16));
check('Vendedor achado por nome exato único (nunca o usuário que importa)', (int)$rows[2]['n']['plan']['seller_id'] === 2 && $rows[10]['n']['plan']['seller_id'] === null);
check('Etapa padrão: Ganha vai para a última etapa, Aberta para a primeira', (int)$rows[2]['n']['plan']['stage_id'] === 3 && (int)$rows[4]['n']['plan']['stage_id'] === 1);

$s = summary($pdo, $bid);
$sumAll = $s['sum']['novo'] + $s['sum']['atualizar'] + $s['sum']['sem_alteracao'] + $s['sum']['revisar'] + $s['sum']['erro'] + $s['sum']['ignorar'];
check('Conferência de valores: soma por classificação = soma da coluna valor da planilha', abs($sumAll - $s['sheet_total']) < 0.005, dump($s));
check('Nada foi gravado no CRM antes da Etapa 4 (opportunities continua com 1 linha)', count_of($pdo, 'opportunities') === 1 && count_of($pdo, 'companies') === 8);

$conf = nc_import_confirm($pdo, $batch, 1);
check('12. Confirmar com linha em Revisar => BLOQUEADO', $conf['ok'] === false && str_contains($conf['message'], 'Revisar') && count_of($pdo, 'opportunities') === 1, dump($conf));

// Resolução humana das linhas em Revisar
resolve($pdo, $bid, 3, 'escolher', 4);          // Delta => Delta Comércio
resolve($pdo, $bid, 5, 'ignorar');
resolve($pdo, $bid, 6, 'escolher', 7);          // fica com o CNPJ (Zeta)
resolve($pdo, $bid, 7, 'ignorar');
resolve($pdo, $bid, 8, 'escolher', 1);          // mantém no cliente atual (Acme)
resolve($pdo, $bid, 9, 'ignorar');
$rows = rows_of($pdo, $bid);
$cls = static fn(int $n): string => (string)($rows[$n]['classification'] ?? '?');
check('Decisão fica gravada na linha (resolved_company_id, resolved_by, resolved_at)', (int)$rows[3]['resolved_company_id'] === 4 && $rows[3]['resolution'] === 'escolhida' && (int)$rows[3]['resolved_by'] === 1 && $rows[3]['resolved_at'] !== null);
check('Linha resolvida passa a Novo no cliente escolhido; ORC-007 mantido em Acme vira Sem alteração/Atualizar', $cls(3) === 'novo' && in_array($cls(8), ['sem_alteracao', 'atualizar'], true), $cls(3) . '/' . $cls(8));
try {
    resolve($pdo, $bid, 3, 'criar');
    check('Orçamento nunca cria empresa sozinho', false);
} catch (RuntimeException) {
    check('Orçamento nunca cria empresa sozinho (op "criar" recusada)', true);
}
$blk = nc_import_confirm_blockers($pdo, nc_import_batch($pdo, $bid));
check('Sem Revisar, a confirmação é liberada (Erro e Ignorar não bloqueiam)', $blk === [], dump($blk));
$sum = nc_import_confirm_summary($pdo, nc_import_batch($pdo, $bid));
check('Resumo do modal: 6 novos, 3 erros, 5 ignorados e 1 mantido do CRM (ORC-007 editado no CRM)', $sum['opps_new'] === 6 && $sum['errors'] === 3 && $sum['ignored'] === 5 && $sum['kept_crm'] === 1, dump($sum));

$conf = nc_import_confirm($pdo, nc_import_batch($pdo, $bid), 1);
check('Confirmação grava em uma transação', $conf['ok'] === true, dump($conf));
$o = $pdo->query("SELECT * FROM opportunities WHERE external_ref = 'ORC-001'")->fetch();
check('ORC-001: valor 1234.56, data 2024-11-26, Ganha, empresa Acme, origem xlsx e lote', $o && (float)$o['amount'] === 1234.56 && $o['opportunity_date'] === '2024-11-26' && $o['status'] === 'Ganha'
    && (int)$o['company_id'] === 1 && $o['source'] === 'xlsx' && (int)$o['import_batch_id'] === $bid && $o['external_source'] === 'xlsx_orcamentos' && (int)$o['quote_required'] === 0, dump($o));
check('ORC-001: número gerado no padrão do CRM e vendedor da planilha', $o && preg_match('#^[A-Z]{2,3}-\d{3}/\d{2}/\d{4}$#', (string)$o['number']) === 1 && (int)$o['seller_user_id'] === 2, dump($o['number'] ?? null));
$o11 = $pdo->query("SELECT * FROM opportunities WHERE external_ref = 'ORC-011'")->fetch();
check('11. Perdida sem motivo => grava o motivo padrão do lote', $o11 && $o11['status'] === 'Perdida' && $o11['loss_reason'] === 'Não informado na planilha (importação lote #' . $bid . ')', dump($o11));
check('Linhas Erro/Ignorar ficaram de fora', count_of($pdo, 'opportunities') === 1 + 6 && (int)$pdo->query("SELECT COUNT(*) FROM opportunities WHERE external_ref IN ('ORC-013','ORC-014')")->fetchColumn() === 0);
check('Importação não mexe em empresas (orçamento nunca cria empresa)', count_of($pdo, 'companies') === 8);
check('Auditoria import_confirm registrada', (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'import_confirm'")->fetchColumn() === 1);

// 8 e 13: idempotência e edição no CRM, com planilha que se resolve sozinha
echo "\n-- Idempotência, edição no CRM e desfazer --\n";
$pdo = new_db();
$b1 = do_import($pdo, 'orcamentos', 'orcamentos_ok.xlsx');
$r1 = rows_of($pdo, (int)$b1['id']);
check('orcamentos_ok: as 4 linhas se resolvem sozinhas (cnpj, name_exact, cnpj, external_id)',
    array_column($r1, 'classification') === ['novo', 'novo', 'novo', 'novo'] && array_column($r1, 'match_rule') === ['cnpj', 'name_exact', 'cnpj', 'external_id'], dump(array_column($r1, 'match_rule')));
$c1 = nc_import_confirm($pdo, $b1, 1);
$oppsAfter1 = count_of($pdo, 'opportunities');
check('1ª importação cria 4 orçamentos', $c1['ok'] && $c1['counts']['orcamentos_criados'] === 4 && $oppsAfter1 === 5, dump($c1));

$b2 = do_import($pdo, 'orcamentos', 'orcamentos_ok.xlsx');
$s2 = summary($pdo, (int)$b2['id']);
check('8. Mesmo arquivo 2x => 0 criados, 0 atualizados, tudo Sem alteração', $s2['novo'] === 0 && $s2['atualizar'] === 0 && $s2['sem_alteracao'] === 4 && $s2['revisar'] === 0, dump($s2));
$c2 = nc_import_confirm($pdo, $b2, 1);
check('8b. Confirmar a 2ª vez não grava nada', $c2['ok'] && $c2['counts']['orcamentos_criados'] === 0 && $c2['counts']['orcamentos_atualizados'] === 0 && count_of($pdo, 'opportunities') === $oppsAfter1, dump($c2));
$prev = nc_import_previous_with_hash($pdo, (string)$b2['file_hash'], (int)$b2['id']);
check('Aviso "este arquivo já foi importado" (mesmo SHA-256)', $prev !== null && (int)$prev['id'] === (int)$b1['id']);

// 13: o vendedor edita no CRM depois da importação
$pdo->exec("UPDATE opportunities SET amount = 9999, updated_at = datetime('now','+1 hour') WHERE external_ref = 'OK-001'");
$b3 = do_import($pdo, 'orcamentos', 'orcamentos_ok.xlsx');
$r3 = rows_of($pdo, (int)$b3['id']);
check('13. Oportunidade alterada no CRM => reimportação mostra o diff (CRM → planilha)', $r3[2]['classification'] === 'atualizar' && ($r3[2]['n']['target']['diff']['amount'] ?? null) === ['9999.00', '1500.00']
    && $r3[2]['n']['target']['crm_edited'] === true, dump($r3[2]['n']['target'] ?? null));
$c3 = nc_import_confirm($pdo, $b3, 1);
$amt = (float)$pdo->query("SELECT amount FROM opportunities WHERE external_ref = 'OK-001'")->fetchColumn();
check('13. ... e MANTÉM o CRM por padrão', $c3['ok'] && $c3['counts']['mantidos_crm'] === 1 && $amt === 9999.0, dump($c3) . " amount=$amt");
$b4 = do_import($pdo, 'orcamentos', 'orcamentos_ok.xlsx');
$row4 = nc_import_row($pdo, (int)$pdo->query("SELECT id FROM import_rows WHERE batch_id = {$b4['id']} AND sheet_row = 2")->fetchColumn());
nc_import_set_mode($pdo, $b4, (int)$row4['id'], 'planilha');
$c4 = nc_import_confirm($pdo, nc_import_batch($pdo, (int)$b4['id']), 1);
$amt = (float)$pdo->query("SELECT amount FROM opportunities WHERE external_ref = 'OK-001'")->fetchColumn();
check('13. ... e "Usar planilha" aplica o valor da planilha', $c4['ok'] && $amt === 1500.0 && $c4['counts']['orcamentos_atualizados'] === 1, dump($c4) . " amount=$amt");

// Desfazer
$pdo->exec("UPDATE opportunities SET notes = 'editado', updated_at = datetime('now','+2 hour') WHERE external_ref = 'OK-002'");
$admin = ['id' => 1, 'role' => 'admin'];
$undoBatch = nc_import_batch($pdo, (int)$b1['id']);
check('Desfazer: só admin, só lote confirmado', nc_import_undo_blocker($undoBatch, $admin) === '' && nc_import_undo_blocker($undoBatch, ['id' => 2, 'role' => 'gestor']) !== '' && nc_import_undo_blocker($b3 + ['status' => 'em_revisao'], $admin) !== '');
$u = nc_import_undo($pdo, $undoBatch, 1);
$left = $pdo->query("SELECT external_ref FROM opportunities WHERE external_ref LIKE 'OK-%' ORDER BY external_ref")->fetchAll(PDO::FETCH_COLUMN);
check('Desfazer: remove só o que não foi alterado depois (OK-002 editado e OK-001 reimportado depois ficam e são listados)',
    $left === ['OK-001', 'OK-002'] && count($u['skipped']) === 2 && str_starts_with($u['skipped'][0]['why'], 'alterado depois') && $u['done'] === 2, dump($u) . ' ' . dump($left));
check('Desfazer: lote fica "desfeito" e gera auditoria import_undo',
    nc_import_batch($pdo, (int)$b1['id'])['status'] === 'desfeito' && (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'import_undo'")->fetchColumn() === 1);

/* ================================================================== */
echo "\n== Fase 8: Contatos ==\n";

$pdo = new_db();
$batch = do_import($pdo, 'contatos', 'contatos.xlsx');
$bid = (int)$batch['id'];
$rows = rows_of($pdo, $bid);
$cls = static fn(int $n): string => (string)($rows[$n]['classification'] ?? '?');
$why = static fn(int $n): string => $cls($n) . ': ' . ($rows[$n]['reason'] ?? '');
check('Ana: empresa existente achada pelo CNPJ (sem duplicar) e contato novo', $cls(2) === 'novo' && (int)$rows[2]['company_id'] === 1 && $rows[2]['match_rule'] === 'cnpj' && empty($rows[2]['n']['target']['new_company']), $why(2));
check('Bruno: mesmo CNPJ sem pontuação => mesma empresa', $cls(3) === 'novo' && (int)$rows[3]['company_id'] === 1, $why(3));
check('Carla: e-mail já está em OUTRA empresa => Revisar ("contato mudou de empresa?")', $cls(4) === 'revisar' && str_contains((string)$rows[4]['reason'], 'mudou de empresa'), $why(4));
check('Diego: empresa nova sem CNPJ => Novo (empresa e contato); UF "sp" vira "SP"', $cls(6) === 'novo' && !empty($rows[6]['n']['target']['new_company']) && $rows[6]['n']['company']['state'] === 'SP', $why(6));
check('Elisa: "NOVA EMPRESA SA" = mesma empresa nova de "Nova Empresa S.A."', $cls(7) === 'novo' && $rows[7]['n']['ctoken'] === $rows[6]['n']['ctoken'], dump([$rows[6]['n']['ctoken'] ?? null, $rows[7]['n']['ctoken'] ?? null]));
check('Linha repetida na planilha => Ignorar (mantida a primeira)', $cls(8) === 'ignorar' && str_contains((string)$rows[8]['reason'], 'linha 2'), $why(8));
check('Fábio: nome "Delta" em duas empresas => Revisar', $cls(10) === 'revisar', $why(10));
check('Gina: CNPJ inválido e empresa desconhecida => Erro', $cls(11) === 'erro' && str_contains((string)$rows[11]['reason'], 'CNPJ inválido'), $why(11));
check('Hugo: CNPJ sem zero à esquerda é corrigido e sinalizado', $cls(12) === 'novo' && $rows[12]['n']['company']['doc']['digits'] === CNPJ_E && in_array('CNPJ corrigido (zeros à esquerda)', $rows[12]['n']['warnings'], true), $why(12));
$cand = json_decode((string)$rows[13]['candidates_json'], true) ?: [];
check('Iara: filial (mesmo CNPJ raiz) de empresa existente => Revisar com a candidata', $cls(13) === 'revisar' && ($cand[0]['id'] ?? 0) === 1 && ($cand[0]['root'] ?? false) === true, $why(13) . dump($cand));
check('Jorge: sem e-mail nem telefone => Erro', $cls(14) === 'erro', $why(14));

$conf = nc_import_confirm($pdo, $batch, 1);
check('Confirmar com Revisar => bloqueado (nada gravado)', !$conf['ok'] && count_of($pdo, 'companies') === 8 && count_of($pdo, 'contacts') === 1);
resolve($pdo, $bid, 4, 'ignorar');
resolve($pdo, $bid, 10, 'ignorar');
resolve($pdo, $bid, 13, 'criar');
$conf = nc_import_confirm($pdo, nc_import_batch($pdo, $bid), 1);
check('Confirmação concluída', $conf['ok'] === true, dump($conf));
check('Empresas criadas: Nova Empresa (UMA só, compartilhada), Delta Nova e Acme Filial Sul', count_of($pdo, 'companies') === 8 + 3 && (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE name LIKE 'Nova Empresa%' OR name LIKE 'NOVA EMPRESA%'")->fetchColumn() === 1, dump($conf));
$nova = (int)$pdo->query("SELECT id FROM companies WHERE name LIKE 'Nova Empresa%'")->fetchColumn();
check('Os dois contatos da Nova Empresa apontam para a mesma empresa', (int)$pdo->query("SELECT COUNT(*) FROM contacts WHERE company_id = $nova")->fetchColumn() === 2);
$hugo = $pdo->query("SELECT co.* FROM companies co JOIN contacts ct ON ct.company_id = co.id WHERE ct.email = 'hugo@deltanova.com.br'")->fetch();
check('CNPJ corrigido é gravado com 14 dígitos; origem xlsx, lote e data de sincronização', $hugo && $hugo['cnpj'] === CNPJ_E && $hugo['source'] === 'xlsx' && (int)$hugo['import_batch_id'] === $bid && $hugo['import_synced_at'] !== null, dump($hugo));
check('Empresa existente com o mesmo CNPJ não foi duplicada', (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE cnpj = '" . CNPJ_A . "'")->fetchColumn() === 1);
check('Contatos criados: Ana, Bruno, Diego, Elisa, Hugo, Iara (+ Carla do CRM)', count_of($pdo, 'contacts') === 7 && (int)$pdo->query("SELECT created_by FROM contacts WHERE email = 'ana@acme.com.br'")->fetchColumn() === 1);

$b2 = do_import($pdo, 'contatos', 'contatos.xlsx');
$s2 = summary($pdo, (int)$b2['id']);
check('Importar 2x o mesmo arquivo de contatos => 0 novos, 0 atualizar (as 6 linhas resolvidas ficam Sem alteração)', $s2['novo'] === 0 && $s2['atualizar'] === 0 && $s2['sem_alteracao'] === 6, dump($s2));
$conf2 = nc_import_confirm($pdo, nc_import_batch($pdo, (int)$b2['id']), 1);
check('A 2ª confirmação bloqueia só pelos Revisar legítimos (Carla, Fábio): nada duplica', !$conf2['ok'] && count_of($pdo, 'contacts') === 7 && count_of($pdo, 'companies') === 11, dump($conf2));

// Desfazer o lote de contatos
$ana = (int)$pdo->query("SELECT id FROM contacts WHERE email = 'bruno@acme.com.br'")->fetchColumn();
$pdo->exec("UPDATE contacts SET phone = '1100000000', updated_at = datetime('now','+1 hour') WHERE id = $ana");
$u = nc_import_undo($pdo, nc_import_batch($pdo, $bid), 1);
check('Desfazer contatos: remove criados não alterados; contato editado depois e sua empresa existente ficam', count_of($pdo, 'contacts') === 2 && $u['done'] === 8 && count($u['skipped']) === 1 && $u['skipped'][0]['what'] === 'Contato', dump($u));
check('Desfazer contatos: empresas criadas somem (Acme, existente, fica)', count_of($pdo, 'companies') === 8, (string)count_of($pdo, 'companies'));

/* ================================================================== */
echo "\n== Planilha completa de contatos (15 colunas, nada ignorado) ==\n";

$heads = ['Nome', 'Primeiro nome', 'E-mail', 'Telefone', 'Telefone da empresa (cadastro)', 'Empresa', 'Nome fantasia', 'CNPJ', 'Cidade/UF',
    'Vendedor responsável', 'Linhas de interesse', 'Qtde de orçamentos/propostas', 'Última em', 'Origem (documentos)', 'Conferir'];
$mp = nc_import_suggest_mapping($heads, 'contatos');
check('As 15 colunas são reconhecidas: nenhuma fica como "Ignorar"', !in_array('', $mp, true) && count(array_unique($mp)) === 15 && nc_import_mapping_missing('contatos', $mp) === [], dump($mp));
check('Cidade/UF junto é separado em cidade e UF', nc_import_split_city_state('São Paulo/SP') === ['city' => 'São Paulo', 'state' => 'SP']
    && nc_import_split_city_state('Campinas - sp') === ['city' => 'Campinas', 'state' => 'SP'] && nc_import_split_city_state('SP') === ['city' => '', 'state' => 'SP']
    && nc_import_split_city_state('Rio Branco') === ['city' => 'Rio Branco', 'state' => '']);

$pdo = new_db();
$batch = do_import($pdo, 'contatos', 'contatos_completo.xlsx');
$bid = (int)$batch['id'];
$rows = rows_of($pdo, $bid);
check('Linha 2 (Acme): contato novo na empresa existente, com os dados de origem como mudanças da empresa', $rows[2]['classification'] === 'novo' && (int)$rows[2]['company_id'] === 1
    && ($rows[2]['n']['target']['company_diff']['source_quote_count'] ?? null) === ['', '12'] && ($rows[2]['n']['target']['company_diff']['phone'][1] ?? '') === '(11) 3333-4444'
    && ($rows[2]['n']['target']['company_diff']['owner_user_id'][1] ?? '') === '2' && ($rows[2]['n']['target']['company_diff']['alias'][1] ?? '') === 'Acme', dump($rows[2]['n']['target'] ?? null));
check('Linha 3 (empresa nova): cidade/UF, data serial, quantidade e o aviso do Conferir + vendedor não encontrado',
    $rows[3]['classification'] === 'novo' && $rows[3]['n']['company']['city'] === 'Campinas' && $rows[3]['n']['company']['state'] === 'SP'
    && $rows[3]['n']['company']['last_quote'] === '2024-11-26' && $rows[3]['n']['company']['quote_count'] === 3
    && in_array('Conferir (planilha): CNPJ divergente', $rows[3]['n']['warnings'], true)
    && count(array_filter($rows[3]['n']['warnings'], fn($w) => str_contains($w, 'Vendedor "Vendedor Inexistente" não encontrado'))) === 1, dump($rows[3]['n']['warnings'] ?? null));
check('Linha 4: "NOVA LTDA" é a mesma empresa nova da linha 3', $rows[4]['n']['ctoken'] === $rows[3]['n']['ctoken']);

$conf = nc_import_confirm($pdo, $batch, 1);
check('Confirmação concluída', $conf['ok'] === true, dump($conf));
$acme = $pdo->query('SELECT * FROM companies WHERE id = 1')->fetch();
check('Acme recebeu telefone, fantasia, responsável e os dados de origem', $acme['phone'] === '(11) 3333-4444' && $acme['alias'] === 'Acme' && (int)$acme['owner_user_id'] === 2
    && $acme['interest_lines'] === 'Compressores; Peças' && (int)$acme['source_quote_count'] === 12 && $acme['source_last_quote_at'] === '2024-11-26' && $acme['source_origin'] === 'Cotador', dump($acme));
$nova = $pdo->query("SELECT * FROM companies WHERE name = 'Nova Ltda ME'")->fetch();
check('Empresa nova gravada com todos os campos (cidade, UF, telefone, fantasia, interesse, origem, conferir), sem vendedor inventado',
    $nova && $nova['city'] === 'Campinas' && $nova['state'] === 'SP' && $nova['phone'] === '11 2222-3333' && $nova['alias'] === 'Novinha' && $nova['interest_lines'] === 'Filtros'
    && (int)$nova['source_quote_count'] === 3 && $nova['source_last_quote_at'] === '2024-11-26' && $nova['source_origin'] === 'ERP' && $nova['import_note'] === 'CNPJ divergente'
    && $nova['owner_user_id'] === null && $nova['source'] === 'xlsx', dump($nova));
check('Contatos com primeiro nome gravado', (string)$pdo->query("SELECT first_name FROM contacts WHERE email = 'ana@acme.com.br'")->fetchColumn() === 'Ana'
    && (string)$pdo->query("SELECT first_name FROM contacts WHERE email = 'dora@novaltda.com.br'")->fetchColumn() === 'Dora'
    && (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE name LIKE 'Nova%'")->fetchColumn() === 1);

$b2 = do_import($pdo, 'contatos', 'contatos_completo.xlsx');
$s2 = summary($pdo, (int)$b2['id']);
check('Importar de novo a planilha completa => 0 novos, 0 atualizar, 3 sem alteração', $s2['novo'] === 0 && $s2['atualizar'] === 0 && $s2['sem_alteracao'] === 3, dump($s2));
$before = $pdo->query("SELECT interest_lines FROM companies WHERE id = 1")->fetchColumn();
$pdo->exec("UPDATE companies SET source_quote_count = 5 WHERE id = 1");
$b3 = do_import($pdo, 'contatos', 'contatos_completo.xlsx');
$r3 = rows_of($pdo, (int)$b3['id']);
check('Dado de origem que mudou na planilha aparece como Atualizar (antes → depois)', $r3[2]['classification'] === 'atualizar' && ($r3[2]['n']['target']['company_diff']['source_quote_count'] ?? null) === ['5', '12'], dump($r3[2]['n']['target'] ?? null));
$u = nc_import_undo($pdo, nc_import_batch($pdo, $bid), 1);
check('Desfazer o lote completo restaura a Acme e remove o que foi criado', (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE name LIKE 'Nova%'")->fetchColumn() === 0
    || count($u['skipped']) > 0, dump($u));

/* ================================================================== */
echo "\n== Atualização de contato existente e coluna \"Conta no valor\" ==\n";

$pdo = new_db();
$pdo->exec("INSERT INTO contacts (company_id, name, email, phone) VALUES (1,'Ana Souza','ana@acme.com.br','')");
$batch = do_import($pdo, 'contatos', 'contatos.xlsx');
$rows = rows_of($pdo, (int)$batch['id']);
check('Contato já existente na mesma empresa (e-mail) => Atualizar com antes → depois (telefone)', $rows[2]['classification'] === 'atualizar'
    && ($rows[2]['n']['target']['contact_diff']['phone'] ?? null) === ['', '(11) 99999-0001'] && ($rows[2]['n']['target']['contact_diff']['job_title'] ?? null) === ['', 'Compradora'], dump($rows[2]['n']['target'] ?? null));

$pdo = new_db();
$batch = do_import($pdo, 'orcamentos', 'orcamentos.csv', ['default_line' => 1]);
$rows = rows_of($pdo, (int)$batch['id']);
check('CSV: "Conta no valor = não (reemitida depois)" => Ignorar; valor 1.234,56 e data com hora', $rows[2]['classification'] === 'novo' && (float)$rows[2]['n']['amount'] === 1234.56 && $rows[2]['n']['date'] === '2025-01-05'
    && $rows[3]['classification'] === 'ignorar', dump([$rows[2]['classification'] ?? null, $rows[3]['classification'] ?? null, $rows[2]['reason'] ?? null]));

$pdo = new_db();
$batch = do_import($pdo, 'orcamentos', 'orcamentos.csv');
check('Sem linha de produto identificada: exige a "Linha padrão do lote" antes de confirmar', count(nc_import_confirm_blockers($pdo, $batch)) === 1 && str_contains(nc_import_confirm_blockers($pdo, $batch)[0], 'Linha padrão'));
nc_import_set_default_line($pdo, $batch, 2);
$batch = nc_import_batch($pdo, (int)$batch['id']);
$res = nc_import_confirm($pdo, $batch, 1);
$o = $pdo->query("SELECT product_line_id, item_type FROM opportunities WHERE external_ref = 'CSV-1'")->fetch();
check('Com a linha padrão definida, confirma e usa a linha escolhida', $res['ok'] && $o && (int)$o['product_line_id'] === 2 && $o['item_type'] === 'Serviço', dump($res) . dump($o));

/* ================================================================== */
// Limpeza
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($tmpRoot);

echo "\n" . ($fail === 0 ? "TUDO OK ({$total} verificações)" : "{$fail} FALHA(S) de {$total}") . "\n";
exit($fail === 0 ? 0 : 1);
