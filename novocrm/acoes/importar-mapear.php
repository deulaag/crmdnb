<?php
declare(strict_types=1);

/**
 * Etapa 2: confirma o mapeamento das colunas e dos valores, salva o modelo e grava a prévia (staging) em import_rows.
 * Ainda não grava nada em empresas, contatos nem oportunidades.
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../_import_run.php';

$user = nc_boot();
nc_require_post();
if (!nc_is_manager($user)) {
    nc_fail('Somente administradores e gestores importam planilhas.', 403);
}
@set_time_limit(180);
$pdo = db();
$id = (int)($_POST['lote'] ?? 0);

/** Volta ao mapeamento mantendo o que o usuário escolheu. */
$retry = static function (string $message) use ($id): never {
    flash('danger', $message);
    $keep = array_intersect_key($_POST, array_flip(['map', 'vs_text', 'vs_val', 'vl_text', 'vl_val']));
    redirect(nc_url('importar.php?lote=' . $id . ($keep ? '&' . http_build_query($keep) : '')));
};

$batch = nc_import_batch($pdo, $id);
if (!$batch) {
    nc_fail('Lote não encontrado.', 404);
}
if ($batch['status'] !== 'enviado') {
    redirect(nc_url('importar.php?lote=' . $id));
}
$type = (string)$batch['import_type'];
$fields = nc_import_fields($type);

try {
    $mapping = [];
    $used = [];
    foreach ((array)($_POST['map'] ?? []) as $i => $key) {
        $key = (string)$key;
        if ($key === '') {
            $mapping[(int)$i] = '';
            continue;
        }
        if (!isset($fields[$key])) {
            throw new RuntimeException('Campo inválido no mapeamento.');
        }
        if (isset($used[$key])) {
            throw new RuntimeException('O campo "' . $fields[$key]['label'] . '" foi escolhido para mais de uma coluna. Use cada campo uma vez.');
        }
        $used[$key] = true;
        $mapping[(int)$i] = $key;
    }
    ksort($mapping);
    $missing = nc_import_mapping_missing($type, $mapping);
    if ($missing) {
        throw new RuntimeException('Mapeie os campos obrigatórios: ' . implode(', ', $missing) . '.');
    }

    $valueMaps = ['status' => [], 'line' => []];
    foreach ((array)($_POST['vs_text'] ?? []) as $k => $text) {
        $val = (string)($_POST['vs_val'][$k] ?? '');
        if (in_array($val, ['Aberta', 'Ganha', 'Perdida'], true)) {
            $valueMaps['status'][nc_norm((string)$text)] = $val;
        }
    }
    if ($type === 'orcamentos') {
        $lineIds = array_map('intval', $pdo->query('SELECT id FROM product_lines WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN));
        foreach ((array)($_POST['vl_text'] ?? []) as $k => $text) {
            $val = (int)($_POST['vl_val'][$k] ?? 0);
            if ($val > 0 && in_array($val, $lineIds, true)) {
                $valueMaps['line'][nc_norm((string)$text)] = $val;
            }
        }
    }

    $rows = nc_import_stage($pdo, $batch, ['mapping' => $mapping, 'value_maps' => $valueMaps]);
    $fresh = nc_import_batch($pdo, $id);
    $cfg = nc_import_config($fresh);
    $templateId = nc_import_save_template($pdo, $type, nc_import_header_signature($cfg['headers']), $mapping, $valueMaps, (int)$user['id'], (string)$batch['file_name']);
    nc_import_batch_update($pdo, $id, ['template_id' => $templateId]);
    audit($pdo, (int)$user['id'], 'import_map', 'import_batch', $id, ['rows' => $rows, 'template_id' => $templateId, 'via' => 'novocrm']);
    redirect(nc_url('importar.php?lote=' . $id));
} catch (Throwable $e) {
    $retry($e->getMessage());
}
