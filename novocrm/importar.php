<?php
declare(strict_types=1);

/**
 * Central de Importação (V3, seção 2): planilhas XLSX/CSV de Contatos e de Orçamentos e propostas.
 * Enviar → Mapear colunas → Conferir (prévia, sem gravar) → Confirmar (uma transação). Acesso: admin e gestor.
 *
 * Esta página só LÊ. Quem grava são as ações em acoes/importar-*.php (POST + CSRF).
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/partials/clientes-importacoes.php';
require_once __DIR__ . '/partials/importar.php';

$user = nc_boot();
$pdo = db();

if (!nc_is_manager($user)) {
    http_response_code(403);
    nc_header('Importar planilha', 'clientes', ['Clientes', 'Importar planilha']);
    nc_empty('Acesso restrito', 'Somente administradores e gestores importam planilhas.', '<a class="nc-btn nc-btn-primary" href="' . e(nc_url('clientes.php')) . '">Voltar para Clientes</a>');
    nc_footer();
    exit;
}

nc_header('Importar planilha', 'clientes', ['Clientes', 'Importar planilha']);

$missing = nc_import_missing_setup($pdo);
if ($missing) {
    nc_page_header('Central de Importação', 'Importar planilha');
    nc_import_setup_missing($missing, $user);
    nc_footer();
    exit;
}

$types = nc_import_types();
$loteId = (int)($_GET['lote'] ?? 0);
$batch = $loteId > 0 ? nc_import_batch($pdo, $loteId) : null;
$backToClientes = '<a class="nc-btn nc-btn-secondary" href="' . e(nc_url('clientes.php?aba=importacoes')) . '">Histórico de importações</a>';

if ($loteId > 0 && !$batch) {
    nc_page_header('Central de Importação', 'Importar planilha', $backToClientes);
    nc_empty('Lote não encontrado', 'Este lote de importação não existe.', '<a class="nc-btn nc-btn-primary" href="' . e(nc_url('importar.php')) . '">Nova importação</a>');
    nc_footer();
    exit;
}

/* ---------------------------- Etapa 1: enviar ---------------------------- */
if (!$batch) {
    $type = (string)($_GET['tipo'] ?? 'contatos');
    $type = isset($types[$type]) ? $type : 'contatos';
    nc_page_header('Clientes', 'Importar planilha', $backToClientes);
    nc_import_steps(1);
    nc_import_step_upload($pdo, $type);
    nc_footer();
    exit;
}

$type = (string)$batch['import_type'];
$status = (string)$batch['status'];
nc_page_header('Lote #' . (int)$batch['id'] . ' · ' . $types[$type], 'Importar planilha', $backToClientes);

/* ------------------------- Etapa 2: mapear colunas ------------------------ */
if ($status === 'enviado') {
    nc_import_steps(2);
    try {
        $path = nc_import_file_path($batch);
        $sheets = nc_sheet_names($path);
        if (count($sheets) > 1 && ($batch['sheet_name'] ?? '') === '') {
            nc_import_step_sheet($batch, $sheets);
            nc_footer();
            exit;
        }
        $sheet = nc_sheet_read($path, ($batch['sheet_name'] ?? '') !== '' ? (string)$batch['sheet_name'] : null);
        if (count($sheet['rows']) < 2) {
            throw new RuntimeException('A planilha não tem linhas de dados abaixo do cabeçalho.');
        }
        $headers = $sheet['rows'][0]['cells'];
        $dataRows = array_slice($sheet['rows'], 1);
        $signature = nc_import_header_signature($headers);
        $template = nc_import_find_template($pdo, $type, $signature);

        $refresh = isset($_GET['map']) && is_array($_GET['map']);
        $fields = nc_import_fields($type);
        $mapping = array_fill(0, count($headers), '');
        $valueMaps = ['status' => [], 'line' => []];
        $fromTemplate = false;
        if ($refresh) {
            foreach ($_GET['map'] as $i => $key) {
                if ((int)$i >= 0 && (int)$i < count($headers) && isset($fields[(string)$key])) {
                    $mapping[(int)$i] = (string)$key;
                }
            }
            foreach ((array)($_GET['vs_text'] ?? []) as $k => $text) {
                $val = (string)($_GET['vs_val'][$k] ?? '');
                if (in_array($val, ['Aberta', 'Ganha', 'Perdida'], true)) {
                    $valueMaps['status'][nc_norm((string)$text)] = $val;
                }
            }
            foreach ((array)($_GET['vl_text'] ?? []) as $k => $text) {
                $val = (int)($_GET['vl_val'][$k] ?? 0);
                if ($val > 0) {
                    $valueMaps['line'][nc_norm((string)$text)] = $val;
                }
            }
        } elseif ($template) {
            foreach (nc_import_decode($template['mapping_json']) as $i => $key) {
                if ((int)$i < count($headers) && isset($fields[(string)$key])) {
                    $mapping[(int)$i] = (string)$key;
                }
            }
            $vm = nc_import_decode($template['value_maps_json'] ?? null);
            $valueMaps = ['status' => (array)($vm['status'] ?? []), 'line' => (array)($vm['line'] ?? [])];
            $fromTemplate = true;
        } else {
            $mapping = nc_import_suggest_mapping($headers, $type);
        }
        nc_import_step_map($pdo, $batch, $headers, $dataRows, $mapping, $valueMaps, $fromTemplate);
    } catch (Throwable $e) {
        nc_empty('Não consegui ler a planilha', $e->getMessage(), '<a class="nc-btn nc-btn-primary" href="' . e(nc_url('importar.php?tipo=' . $type)) . '">Enviar outro arquivo</a>');
    }
    nc_footer();
    exit;
}

/* ----------------------- Processamento da prévia ------------------------- */
if ($status === 'mapeado') {
    nc_import_steps(3);
    $pending = nc_import_pending($pdo, (int)$batch['id']);
    $counters = nc_import_decode($batch['counters_json'] ?? null);
    if ($pending > 0) {
        nc_import_processing($batch, (int)($counters['rows'] ?? $pending), $pending);
    } else {
        nc_import_finish_processing($pdo, $batch, 0);
        header('Location: ' . nc_url('importar.php?lote=' . (int)$batch['id']));
        exit;
    }
    nc_footer();
    exit;
}

/* ------------------------ Etapas 3 e 4: conferir ------------------------- */
if ($status === 'em_revisao') {
    nc_import_steps(3);
    nc_import_step_review($pdo, $batch, $user);
    nc_footer();
    exit;
}

/* ------------------------------ Lote concluído --------------------------- */
nc_import_steps(4);
nc_import_report($pdo, $batch, $user);
if ($status === 'falhou') {
    ?>
    <form method="post" action="<?= e(nc_url('acoes/importar-acao.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="op" value="reabrir"><input type="hidden" name="lote" value="<?= (int)$batch['id'] ?>">
        <button type="submit" class="nc-btn nc-btn-primary">Voltar à conferência</button>
    </form>
    <?php
}
nc_footer();
