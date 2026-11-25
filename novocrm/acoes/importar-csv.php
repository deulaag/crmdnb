<?php
declare(strict_types=1);

/** Baixa o CSV do lote: previa (todas as linhas), erros (Erro e Revisar) ou relatorio. GET autenticado, só admin e gestor. */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../_import_run.php';

$user = nc_boot();
if (!nc_is_manager($user)) {
    nc_fail('Ação não permitida.', 403);
}
$pdo = db();
$batch = nc_import_batch($pdo, (int)($_GET['lote'] ?? 0));
if (!$batch) {
    nc_fail('Lote não encontrado.', 404);
}
$kind = (string)($_GET['tipo'] ?? 'previa');
if (!in_array($kind, ['previa', 'erros', 'relatorio'], true)) {
    $kind = 'previa';
}
@set_time_limit(120);
$name = 'importacao-lote-' . (int)$batch['id'] . '-' . $kind . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF"); // BOM: o Excel abre em UTF-8 com acentos certos
foreach (nc_import_csv_rows($pdo, $batch, $kind) as $row) {
    fputcsv($out, $row, ';', '"', '');
}
fclose($out);
