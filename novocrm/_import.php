<?php
declare(strict_types=1);

/**
 * NOVOCRM v3 — Central de Importação: leitor de planilha, conversões e campos (V3, seções 3 e 4).
 *
 * Nada aqui altera o leitor da raiz (spreadsheet_rows_from_upload). Funções puras: sem banco.
 * Dependências: normalized_name(), cnpj_digits(), cnpj_is_valid() (includes/functions.php) e nc_fold() (_empresas.php).
 */

require_once __DIR__ . '/_empresas.php';

const NC_IMPORT_MAX_BYTES = 15 * 1024 * 1024;

/* ------------------------------------------------------------------ */
/* Normalização de texto                                               */
/* ------------------------------------------------------------------ */

/** Texto para comparar cabeçalhos e nomes: sem acento, minúsculo, só letras e números. */
function nc_norm(string $value): string
{
    return normalized_name(nc_fold($value));
}

/** Limpa o conteúdo de uma célula: trim, NBSP, quebras de linha internas e apóstrofo inicial do Excel. */
function nc_clean_cell(string $value): string
{
    $value = str_replace(["\xC2\xA0", "\r\n", "\r", "\n", "\t"], [' ', ' ', ' ', ' ', ' '], $value);
    $value = trim($value);
    if (str_starts_with($value, "'")) {
        $value = ltrim(substr($value, 1));
    }
    return (string)preg_replace('/\s{2,}/u', ' ', $value);
}

/** Corta o texto ao tamanho da coluna do banco (o MySQL em modo estrito recusaria a gravação) e avisa. */
function nc_import_cut(string $value, int $max, array &$warnings, string $label): string
{
    if (function_exists('mb_strlen') ? mb_strlen($value) <= $max : strlen($value) <= $max) {
        return $value;
    }
    $warnings[] = $label . ' cortado em ' . $max . ' caracteres.';
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

/** Só os dígitos de um telefone. */
function nc_phone_digits(string $value): string
{
    return (string)preg_replace('/\D+/', '', $value);
}

/** E-mail em minúsculo e validado: devolve '' se vazio ou inválido (o chamador decide o aviso). */
function nc_email_clean(string $value): string
{
    $email = strtolower(trim($value));
    return ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : '';
}

/* ------------------------------------------------------------------ */
/* Leitura de planilha (XLSX e CSV)                                    */
/* ------------------------------------------------------------------ */

/** Número do XLSX como texto estável (sem notação científica nem sujeira de ponto flutuante). */
function nc_xlsx_number(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || !is_numeric($raw)) {
        return $raw;
    }
    if (preg_match('/^-?\d+$/', $raw)) {
        return $raw;
    }
    $f = (float)$raw;
    if (abs($f) < 1e15 && floor($f) === $f) {
        return sprintf('%.0F', $f);
    }
    $out = rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
    return $out === '' || $out === '-0' ? '0' : $out;
}

/** Índice de coluna (A=0) a partir da referência "B7". */
function nc_xlsx_col_index(string $ref): int
{
    if (!preg_match('/^([A-Za-z]+)/', $ref, $m)) {
        return -1;
    }
    $letters = strtoupper($m[1]);
    $index = 0;
    for ($i = 0, $n = strlen($letters); $i < $n; $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return $index - 1;
}

/** Texto de um nó <si> ou <is>: junta <t> e <r><t>, ignorando fonética (<rPh>). */
function nc_xlsx_text_of(DOMNode $node): string
{
    $out = '';
    foreach ($node->childNodes as $child) {
        if (!($child instanceof DOMElement)) {
            continue;
        }
        if ($child->localName === 't') {
            $out .= $child->textContent;
        } elseif ($child->localName === 'r') {
            foreach ($child->childNodes as $t) {
                if ($t instanceof DOMElement && $t->localName === 't') {
                    $out .= $t->textContent;
                }
            }
        }
    }
    return $out;
}

/** @return string[] */
function nc_xlsx_shared_strings(string $xml): array
{
    $out = [];
    if ($xml === '') {
        return $out;
    }
    $r = new XMLReader();
    if (!$r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
        return $out;
    }
    while ($r->read()) {
        if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'si') {
            $node = $r->expand(new DOMDocument());
            $out[] = $node ? nc_xlsx_text_of($node) : '';
        }
    }
    $r->close();
    return $out;
}

/**
 * Abas do workbook: [['name'=>, 'path'=>'xl/worksheets/sheetN.xml', 'state'=>'visible'|'hidden'|...], ...] e date1904.
 *
 * @return array{sheets:array<int,array{name:string,path:string,state:string}>,date1904:bool}
 */
function nc_xlsx_workbook(callable $get): array
{
    $wb = (string)$get('xl/workbook.xml');
    if ($wb === '') {
        throw new RuntimeException('XLSX inválido: não encontrei xl/workbook.xml.');
    }
    $rels = [];
    $relsXml = (string)$get('xl/_rels/workbook.xml.rels');
    if ($relsXml !== '') {
        $rr = new XMLReader();
        $rr->XML($relsXml, null, LIBXML_NONET);
        while ($rr->read()) {
            if ($rr->nodeType === XMLReader::ELEMENT && $rr->localName === 'Relationship') {
                $target = (string)$rr->getAttribute('Target');
                $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . ltrim($target, '/');
                $rels[(string)$rr->getAttribute('Id')] = $target;
            }
        }
        $rr->close();
    }

    $sheets = [];
    $date1904 = false;
    $r = new XMLReader();
    $r->XML($wb, null, LIBXML_NONET);
    while ($r->read()) {
        if ($r->nodeType !== XMLReader::ELEMENT) {
            continue;
        }
        if ($r->localName === 'workbookPr') {
            $v = strtolower((string)$r->getAttribute('date1904'));
            $date1904 = $v === '1' || $v === 'true';
        } elseif ($r->localName === 'sheet') {
            $rid = (string)($r->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')
                ?? $r->getAttribute('r:id'));
            $path = $rels[$rid] ?? '';
            if ($path === '') {
                $idx = count($sheets) + 1;
                $path = 'xl/worksheets/sheet' . $idx . '.xml';
            }
            $sheets[] = [
                'name' => (string)$r->getAttribute('name'),
                'path' => $path,
                'state' => (string)($r->getAttribute('state') ?: 'visible'),
            ];
        }
    }
    $r->close();
    if (!$sheets) {
        throw new RuntimeException('XLSX sem abas legíveis.');
    }
    return ['sheets' => $sheets, 'date1904' => $date1904];
}

/**
 * Lê uma aba de XLSX. $get(nome) devolve o conteúdo de um arquivo interno (ou '' se não existir).
 *
 * @return array{sheet:string,sheets:string[],date1904:bool,rows:array<int,array{n:int,cells:string[]}>}
 */
function nc_xlsx_parse(callable $get, ?string $sheetName = null): array
{
    $wb = nc_xlsx_workbook($get);
    $visible = array_values(array_filter($wb['sheets'], static fn($s) => !in_array($s['state'], ['hidden', 'veryHidden'], true)));
    $names = array_map(static fn($s) => $s['name'], $visible ?: $wb['sheets']);

    $chosen = null;
    if ($sheetName !== null && $sheetName !== '') {
        foreach ($wb['sheets'] as $s) {
            if ($s['name'] === $sheetName) {
                $chosen = $s;
                break;
            }
        }
        if (!$chosen) {
            throw new RuntimeException('A aba "' . $sheetName . '" não existe nesta planilha.');
        }
    } else {
        $chosen = ($visible ?: $wb['sheets'])[0];
    }

    $shared = nc_xlsx_shared_strings((string)$get('xl/sharedStrings.xml'));
    $sheetXml = (string)$get($chosen['path']);
    if ($sheetXml === '') {
        throw new RuntimeException('Não consegui abrir a aba "' . $chosen['name'] . '".');
    }

    $rows = [];
    $r = new XMLReader();
    $r->XML($sheetXml, null, LIBXML_NONET | LIBXML_COMPACT);
    $seq = 0;
    while ($r->read()) {
        if ($r->nodeType !== XMLReader::ELEMENT || $r->localName !== 'row') {
            continue;
        }
        $seq++;
        $rowNo = (int)$r->getAttribute('r') ?: $seq;
        $node = $r->expand(new DOMDocument());
        $cells = [];
        $pos = 0;
        if ($node) {
            foreach ($node->childNodes as $c) {
                if (!($c instanceof DOMElement) || $c->localName !== 'c') {
                    continue;
                }
                $col = nc_xlsx_col_index((string)$c->getAttribute('r'));
                if ($col < 0) {
                    $col = $pos;
                }
                $pos = $col + 1;
                $type = (string)$c->getAttribute('t');
                $v = '';
                $is = null;
                foreach ($c->childNodes as $ch) {
                    if ($ch instanceof DOMElement) {
                        if ($ch->localName === 'v') {
                            $v = $ch->textContent;
                        } elseif ($ch->localName === 'is') {
                            $is = $ch;
                        }
                    }
                }
                $value = match ($type) {
                    's' => $shared[(int)$v] ?? '',
                    'inlineStr' => $is ? nc_xlsx_text_of($is) : $v,
                    'str' => $v,
                    'b' => $v === '1' ? 'TRUE' : 'FALSE',
                    'e' => '',
                    default => $is ? nc_xlsx_text_of($is) : nc_xlsx_number($v),
                };
                $cells[$col] = nc_clean_cell($value);
            }
        }
        if ($cells) {
            ksort($cells);
            $dense = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $i => $val) {
                $dense[$i] = $val;
            }
            while ($dense && end($dense) === '') {
                array_pop($dense);
            }
            if ($dense) {
                $rows[] = ['n' => $rowNo, 'cells' => $dense];
            }
        }
    }
    $r->close();

    return ['sheet' => $chosen['name'], 'sheets' => $names, 'date1904' => $wb['date1904'], 'rows' => $rows];
}

/** Lê CSV (delimitador detectado, BOM e Latin-1 tratados). @return array<int,array{n:int,cells:string[]}> */
function nc_csv_parse(string $content): array
{
    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }
    if ($content !== '' && !preg_match('//u', $content)) {
        $converted = function_exists('iconv') ? @iconv('Windows-1252', 'UTF-8//TRANSLIT', $content) : false;
        if ($converted === false && function_exists('mb_convert_encoding')) {
            $converted = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $content = is_string($converted) && $converted !== ''
            ? $converted
            : (string)preg_replace_callback('/[-ÿ]/', static fn($m) => chr(0xC0 | (ord($m[0]) >> 6)) . chr(0x80 | (ord($m[0]) & 0x3F)), $content);
    }
    $firstLine = strtok($content, "\n") ?: '';
    $best = ';';
    $bestCount = -1;
    foreach ([';', ',', "\t", '|'] as $d) {
        $count = substr_count($firstLine, $d);
        if ($count > $bestCount) {
            $best = $d;
            $bestCount = $count;
        }
    }
    $h = fopen('php://temp', 'r+b');
    fwrite($h, $content);
    rewind($h);
    $rows = [];
    $n = 0;
    while (($row = fgetcsv($h, 0, $best, '"', '')) !== false) {
        $n++;
        if ($row === [null]) {
            continue;
        }
        $cells = array_map(static fn($v) => nc_clean_cell((string)$v), $row);
        while ($cells && end($cells) === '') {
            array_pop($cells);
        }
        if ($cells) {
            $rows[] = ['n' => $n, 'cells' => $cells];
        }
    }
    fclose($h);
    return $rows;
}

/**
 * Abre o XLSX e entrega ao callback um leitor de arquivos internos. Usa ZipArchive; se a extensão não existir,
 * tenta o PharData (que também lê ZIP) para não depender de uma única extensão do servidor.
 */
function nc_xlsx_with_zip(string $path, callable $fn): mixed
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Não foi possível abrir o XLSX. O arquivo está corrompido?');
        }
        try {
            return $fn(static function (string $name) use ($zip): string {
                $data = $zip->getFromName($name);
                return $data === false ? '' : $data;
            });
        } finally {
            $zip->close();
        }
    }
    if (class_exists('PharData')) {
        $tmp = tempnam(sys_get_temp_dir(), 'ncx') ?: '';
        $zipCopy = $tmp . '.zip';
        if ($tmp === '' || !@copy($path, $zipCopy)) {
            throw new RuntimeException('Não consegui preparar o XLSX para leitura.');
        }
        try {
            $phar = new PharData($zipCopy);
            return $fn(static function (string $name) use ($phar): string {
                return isset($phar[$name]) ? (string)$phar[$name]->getContent() : '';
            });
        } catch (UnexpectedValueException $e) {
            throw new RuntimeException('Não foi possível abrir o XLSX. O arquivo está corrompido?');
        } finally {
            @unlink($zipCopy);
            @unlink($tmp);
        }
    }
    throw new RuntimeException('O servidor precisa da extensão ZIP do PHP para ler XLSX.');
}

/** Nomes das abas (XLSX) ou ['CSV'] para arquivos .csv. @return string[] */
function nc_sheet_names(string $path): array
{
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
        return ['CSV'];
    }
    return nc_xlsx_with_zip($path, static function (callable $get): array {
        $wb = nc_xlsx_workbook($get);
        $visible = array_values(array_filter($wb['sheets'], static fn($s) => !in_array($s['state'], ['hidden', 'veryHidden'], true)));
        return array_map(static fn($s) => $s['name'], $visible ?: $wb['sheets']);
    });
}

/**
 * Lê a planilha inteira, preservando o número real da linha (V3, seção 3).
 *
 * @return array{sheet:string,sheets:string[],date1904:bool,rows:array<int,array{n:int,cells:string[]}>}
 */
function nc_sheet_read(string $path, ?string $sheetName = null): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Arquivo da importação não encontrado.');
    }
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
        return ['sheet' => 'CSV', 'sheets' => ['CSV'], 'date1904' => false, 'rows' => nc_csv_parse((string)file_get_contents($path))];
    }
    return nc_xlsx_with_zip($path, static fn(callable $get) => nc_xlsx_parse($get, $sheetName));
}

/* ------------------------------------------------------------------ */
/* Conversões (V3, seção 3)                                            */
/* ------------------------------------------------------------------ */

/**
 * Valor monetário: 1234.56 | 1.234,56 | R$ 1.234,56 | 1234,5. Com vírgula e ponto, o decimal é o último que aparece.
 * Negativo ou não numérico devolve null. Um único ponto com 3 casas e inteiro curto ("1.234") é milhar.
 */
function nc_parse_money(string $raw): ?float
{
    $s = trim(str_ireplace(['R$', 'BRL'], '', nc_clean_cell($raw)));
    $s = str_replace(' ', '', $s);
    if ($s === '' || str_contains($s, '-') || str_contains($s, '(')) {
        return null;
    }
    if (!preg_match('/^[0-9.,]+$/', $s)) {
        return null;
    }
    $lastComma = strrpos($s, ',');
    $lastDot = strrpos($s, '.');
    if ($lastComma !== false && $lastDot !== false) {
        $decimalSep = $lastComma > $lastDot ? ',' : '.';
    } elseif ($lastComma !== false) {
        $decimalSep = substr_count($s, ',') === 1 ? ',' : '';
    } elseif ($lastDot !== false) {
        if (substr_count($s, '.') > 1) {
            $decimalSep = '';
        } else {
            [$int, $dec] = explode('.', $s);
            $decimalSep = (strlen($dec) === 3 && strlen($int) >= 1 && strlen($int) <= 3 && $int !== '0') ? '' : '.';
        }
    } else {
        $decimalSep = '';
    }
    if ($decimalSep === '') {
        $clean = str_replace([',', '.'], '', $s);
    } else {
        $other = $decimalSep === ',' ? '.' : ',';
        $clean = str_replace($decimalSep, '.', str_replace($other, '', $s));
    }
    if ($clean === '' || !is_numeric($clean)) {
        return null;
    }
    $value = round((float)$clean, 2);
    return $value < 0 ? null : $value;
}

/**
 * Data para Y-m-d: serial do Excel (base 1899-12-30, ou 1904 se $date1904), dd/mm/aaaa, dd/mm/aa,
 * aaaa-mm-dd e dd/mm/aaaa hh:mm. Inválida devolve null.
 */
function nc_parse_date(string $raw, bool $date1904 = false): ?string
{
    $s = nc_clean_cell($raw);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^\d{4,6}(\.\d+)?$/', $s) && !preg_match('/^\d{4}$/', $s)) {
        $serial = (int)floor((float)$s);
        if ($serial < 20000 || $serial > 90000) {
            return null;
        }
        $base = new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30');
        return $base->modify('+' . $serial . ' days')->format('Y-m-d');
    }
    if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2}|\d{4})(?:[ T]+\d{1,2}:\d{2}(?::\d{2})?)?$#', $s, $m)) {
        $y = (int)$m[3];
        if (strlen($m[3]) === 2) {
            $y += $y < 70 ? 2000 : 1900;
        }
        return checkdate((int)$m[2], (int)$m[1], $y) ? sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]) : null;
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T]+\d{1,2}:\d{2}(?::\d{2})?(?:\.\d+)?Z?)?$/', $s, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]) : null;
    }
    return null;
}

/** CPF válido (11 dígitos). */
function nc_cpf_is_valid(string $value): bool
{
    $cpf = (string)preg_replace('/\D+/', '', $value);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) {
            $sum += (int)$cpf[$i] * (($t + 1) - $i);
        }
        $digit = ((10 * $sum) % 11) % 10;
        if ((int)$cpf[$t] !== $digit) {
            return false;
        }
    }
    return true;
}

/**
 * Documento da planilha. Devolve:
 *  kind: '' (vazio) | 'cnpj' | 'cpf' | 'invalido'; digits (14 p/ cnpj, 11 p/ cpf); corrected (zeros à esquerda); raw.
 */
function nc_normalize_document(string $raw): array
{
    $raw = nc_clean_cell($raw);
    if ($raw === '') {
        return ['kind' => '', 'digits' => '', 'corrected' => false, 'raw' => ''];
    }
    // Excel pode ter gravado o número em notação científica (1.1222333000181E+13).
    if (preg_match('/^\d+(?:[.,]\d+)?[eE]\+?\d+$/', $raw)) {
        $raw = sprintf('%.0F', (float)str_replace(',', '.', $raw));
    }
    $digits = cnpj_digits($raw);
    if ($digits === '') {
        return ['kind' => 'invalido', 'digits' => '', 'corrected' => false, 'raw' => $raw];
    }
    if (strlen($digits) === 14) {
        return cnpj_is_valid($digits)
            ? ['kind' => 'cnpj', 'digits' => $digits, 'corrected' => false, 'raw' => $raw]
            : ['kind' => 'invalido', 'digits' => $digits, 'corrected' => false, 'raw' => $raw];
    }
    if (strlen($digits) === 11 && nc_cpf_is_valid($digits)) {
        return ['kind' => 'cpf', 'digits' => $digits, 'corrected' => false, 'raw' => $raw];
    }
    if (strlen($digits) <= 13) {
        $padded = str_pad($digits, 14, '0', STR_PAD_LEFT);
        if (cnpj_is_valid($padded)) {
            return ['kind' => 'cnpj', 'digits' => $padded, 'corrected' => true, 'raw' => $raw];
        }
    }
    return ['kind' => 'invalido', 'digits' => $digits, 'corrected' => false, 'raw' => $raw];
}

/* ------------------------------------------------------------------ */
/* Campos e sinônimos (V3, seção 4)                                    */
/* ------------------------------------------------------------------ */

/** Tipos de importação válidos. */
function nc_import_types(): array
{
    return ['contatos' => 'Contatos', 'orcamentos' => 'Orçamentos e propostas'];
}

/** Origem fixa por tipo (chave do orçamento = external_source + external_ref). */
function nc_import_external_source(string $type): string
{
    return $type === 'orcamentos' ? 'xlsx_orcamentos' : 'xlsx_contatos';
}

/**
 * Campos do CRM por tipo, na ordem de prioridade para sugerir colunas.
 * required: true | 'email_ou_telefone' | false.
 *
 * @return array<string,array{label:string,group:string,required:bool|string,syn:string[]}>
 */
function nc_import_fields(string $type): array
{
    if ($type === 'orcamentos') {
        return [
            'external_ref' => ['label' => 'Nº do orçamento/proposta (origem)', 'group' => 'Orçamento', 'required' => true,
                'syn' => ['numero', 'nº', 'n°', 'numero do orcamento', 'numero da proposta', 'orcamento', 'proposta', 'id', 'codigo']],
            'opportunity_date' => ['label' => 'Data', 'group' => 'Orçamento', 'required' => true,
                'syn' => ['data', 'data do orcamento', 'data da proposta', 'emissao', 'data da emissao', 'data de emissao', 'criado em']],
            'client_name' => ['label' => 'Cliente', 'group' => 'Empresa', 'required' => true,
                'syn' => ['cliente', 'empresa', 'razao social', 'conta']],
            'company_cnpj' => ['label' => 'CNPJ do cliente', 'group' => 'Empresa', 'required' => false,
                'syn' => ['cnpj', 'cnpj do cliente', 'documento', 'cnpj/cpf']],
            'company_external_id' => ['label' => 'ID da empresa (origem)', 'group' => 'Empresa', 'required' => false,
                'syn' => ['id da empresa', 'codigo cliente', 'cod cliente', 'id empresa', 'id rd']],
            'contact_email' => ['label' => 'E-mail do contato', 'group' => 'Empresa', 'required' => false,
                'syn' => ['email do contato', 'e-mail do contato', 'contato email', 'email contato']],
            'amount' => ['label' => 'Valor (R$)', 'group' => 'Orçamento', 'required' => true,
                'syn' => ['valor', 'valor total', 'total', 'total r', 'total rs', 'valor da proposta', 'valor do orcamento']],
            'status' => ['label' => 'Status', 'group' => 'Orçamento', 'required' => false,
                'syn' => ['status', 'situacao', 'fase']],
            'stage' => ['label' => 'Etapa do funil', 'group' => 'Orçamento', 'required' => false,
                'syn' => ['etapa', 'estagio', 'fase do funil']],
            'product_line' => ['label' => 'Linha de produto', 'group' => 'Orçamento', 'required' => false,
                'syn' => ['linha', 'produto', 'linha de produto', 'familia', 'categoria', 'tipo']],
            'seller' => ['label' => 'Vendedor (nome)', 'group' => 'Vendedor', 'required' => false,
                'syn' => ['vendedor', 'responsavel', 'representante', 'dono']],
            'seller_email' => ['label' => 'Vendedor (e-mail)', 'group' => 'Vendedor', 'required' => false,
                'syn' => ['email do vendedor', 'e-mail do vendedor', 'email do responsavel', 'e-mail do responsavel', 'email', 'e-mail']],
            'loss_reason' => ['label' => 'Motivo da perda', 'group' => 'Orçamento', 'required' => false,
                'syn' => ['motivo da perda', 'motivo perda', 'motivo']],
            'notes' => ['label' => 'Observações', 'group' => 'Orçamento', 'required' => false,
                'syn' => ['observacao', 'observacoes', 'descricao', 'titulo', 'nome da proposta']],
            'quote_reference' => ['label' => 'Referência', 'group' => 'Orçamento', 'required' => false,
                'syn' => ['referencia', 'ref', 'cotacao']],
            'version' => ['label' => 'Versão (vai para as observações)', 'group' => 'Orçamento', 'required' => false,
                'syn' => ['versao']],
            'counts_in_value' => ['label' => 'Conta no valor (não = ignorar linha)', 'group' => 'Orçamento', 'required' => false,
                'syn' => ['conta no valor', 'conta no total', 'considerar']],
        ];
    }
    return [
        'contact_name' => ['label' => 'Nome do contato', 'group' => 'Contato', 'required' => true,
            'syn' => ['nome', 'nome do contato', 'contato', 'pessoa']],
        'contact_email' => ['label' => 'E-mail do contato', 'group' => 'Contato', 'required' => 'email_ou_telefone',
            'syn' => ['email', 'e-mail', 'email do contato']],
        'contact_phone' => ['label' => 'Telefone do contato', 'group' => 'Contato', 'required' => 'email_ou_telefone',
            'syn' => ['telefone', 'celular', 'whatsapp', 'fone']],
        'contact_job_title' => ['label' => 'Cargo', 'group' => 'Contato', 'required' => false,
            'syn' => ['cargo', 'funcao']],
        'company_name' => ['label' => 'Empresa', 'group' => 'Empresa', 'required' => true,
            'syn' => ['empresa', 'cliente', 'razao social', 'nome da empresa', 'organizacao', 'conta']],
        'company_alias' => ['label' => 'Nome fantasia', 'group' => 'Empresa', 'required' => false,
            'syn' => ['nome fantasia', 'fantasia', 'apelido']],
        'company_cnpj' => ['label' => 'CNPJ', 'group' => 'Empresa', 'required' => false,
            'syn' => ['cnpj', 'cnpj da empresa', 'documento', 'cnpj/cpf']],
        'company_city' => ['label' => 'Cidade', 'group' => 'Empresa', 'required' => false,
            'syn' => ['cidade', 'municipio']],
        'company_state' => ['label' => 'UF', 'group' => 'Empresa', 'required' => false,
            'syn' => ['uf', 'estado']],
        'company_external_id' => ['label' => 'ID da empresa (origem)', 'group' => 'Empresa', 'required' => false,
            'syn' => ['id da empresa', 'id empresa', 'codigo cliente', 'cod cliente', 'id rd']],
        'contact_external_id' => ['label' => 'ID do contato (origem)', 'group' => 'Contato', 'required' => false,
            'syn' => ['id do contato', 'id contato']],
        'owner_email' => ['label' => 'Responsável (e-mail)', 'group' => 'Empresa', 'required' => false,
            'syn' => ['responsavel', 'dono', 'vendedor', 'email do responsavel']],
    ];
}

/** Assinatura dos cabeçalhos: sha256 dos cabeçalhos normalizados, em ordem. */
function nc_import_header_signature(array $headers): string
{
    return hash('sha256', implode("\n", array_map(static fn($h) => nc_norm((string)$h), $headers)));
}

/**
 * Sugere o campo de cada coluna pelos sinônimos (comparação exata do cabeçalho normalizado).
 * Cada campo usa no máximo uma coluna; o campo mais prioritário (ordem da lista) escolhe primeiro.
 *
 * @param string[] $headers
 * @return array<int,string> índice da coluna => chave do campo ('' = ignorar)
 */
function nc_import_suggest_mapping(array $headers, string $type): array
{
    $norm = array_map(static fn($h) => nc_norm((string)$h), $headers);
    $mapping = array_fill(0, count($headers), '');
    foreach (nc_import_fields($type) as $key => $def) {
        $synonyms = array_map('nc_norm', $def['syn']);
        foreach ($norm as $i => $h) {
            if ($mapping[$i] === '' && $h !== '' && in_array($h, $synonyms, true)) {
                $mapping[$i] = $key;
                break;
            }
        }
    }
    return $mapping;
}

/** Campos obrigatórios que faltam no mapeamento (rótulos). Vazio = pode continuar. @return string[] */
function nc_import_mapping_missing(string $type, array $mapping): array
{
    $used = array_filter($mapping, static fn($v) => $v !== '' && $v !== null);
    $fields = nc_import_fields($type);
    $missing = [];
    foreach ($fields as $key => $def) {
        if ($def['required'] === true && !in_array($key, $used, true)) {
            $missing[] = $def['label'];
        }
    }
    if ($type === 'contatos' && !in_array('contact_email', $used, true) && !in_array('contact_phone', $used, true)) {
        $missing[] = 'E-mail ou telefone do contato';
    }
    return $missing;
}

/** Valores da linha da planilha por campo do CRM, já limpos (texto). @return array<string,string> */
function nc_import_row_values(array $cells, array $mapping): array
{
    $out = [];
    foreach ($mapping as $i => $key) {
        if ($key === '' || $key === null) {
            continue;
        }
        $value = nc_clean_cell((string)($cells[(int)$i] ?? ''));
        // Se duas colunas apontarem para o mesmo campo, vale a primeira preenchida.
        if (!isset($out[$key]) || $out[$key] === '') {
            $out[$key] = $value;
        }
    }
    return $out;
}

/** Linha de total/subtotal: uma célula com exatamente "Total"/"Subtotal" e as demais vazias ou numéricas (nunca um cliente "Total Engenharia"). */
function nc_import_row_is_total(array $cells): bool
{
    $label = false;
    foreach ($cells as $c) {
        $raw = nc_clean_cell((string)$c);
        if ($raw === '') {
            continue;
        }
        if (in_array(nc_norm($raw), ['total', 'totais', 'subtotal', 'sub total', 'total geral'], true)) {
            $label = true;
        } elseif (nc_parse_money($raw) === null && !is_numeric($raw)) {
            return false;
        }
    }
    return $label;
}

/* ------------------------------------------------------------------ */
/* Mapeamento de valores (status, linha)                               */
/* ------------------------------------------------------------------ */

/** Sinônimos de status (V3, seção 4.2). */
function nc_import_status_synonyms(): array
{
    return [
        'Aberta' => ['aberto', 'aberta', 'em andamento', 'em aberto', 'enviada', 'enviado', 'pendente', 'negociacao'],
        'Ganha' => ['ganho', 'ganha', 'aprovado', 'aprovada', 'fechado ganho', 'vendido', 'aceito', 'aceita'],
        'Perdida' => ['perdido', 'perdida', 'recusado', 'recusada', 'cancelado', 'cancelada', 'fechado perdido', 'reprovado', 'reprovada'],
    ];
}

/**
 * Status canônico (Aberta/Ganha/Perdida) de um texto. Vazio = Aberta. $valueMap (texto normalizado => status)
 * prevalece sobre os sinônimos. Desconhecido devolve null (a linha vai para Revisar; nunca um palpite).
 */
function nc_import_status_resolve(string $text, array $valueMap = []): ?string
{
    $n = nc_norm($text);
    if ($n === '') {
        return 'Aberta';
    }
    if (isset($valueMap[$n]) && in_array($valueMap[$n], ['Aberta', 'Ganha', 'Perdida'], true)) {
        return $valueMap[$n];
    }
    foreach (nc_import_status_synonyms() as $status => $list) {
        if (in_array($n, $list, true)) {
            return $status;
        }
    }
    return null;
}

/** "Conta no valor": 'nao', 'não (reemitida depois)', 'n', 'false' => false. */
function nc_import_counts_in_value(string $text): bool
{
    $n = nc_norm($text);
    if ($n === '') {
        return true;
    }
    return !(preg_match('/^(nao|n|false|0|falso)(\s|$)/', $n) === 1);
}
