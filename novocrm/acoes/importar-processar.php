<?php
declare(strict_types=1);

/**
 * Processa o próximo bloco (500 linhas) da prévia. Chamada por HTMX com polling: devolve a barra de progresso
 * ou, ao terminar, manda recarregar a página do lote. Não grava nada em empresas, contatos nem oportunidades.
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/clientes-importacoes.php';
require_once __DIR__ . '/../partials/importar.php';

$user = nc_boot();
nc_require_post();
if (!nc_is_manager($user)) {
    nc_fail('Somente administradores e gestores importam planilhas.', 403);
}
@set_time_limit(120);
$pdo = db();
$id = (int)($_POST['lote'] ?? 0);
$batch = nc_import_batch($pdo, $id);
if (!$batch) {
    nc_fail('Lote não encontrado.', 404);
}
$url = nc_url('importar.php?lote=' . $id);

if ($batch['status'] !== 'mapeado') {
    nc_hx_redirect($url);
}
try {
    $r = nc_import_process_chunk($pdo, $batch);
} catch (Throwable $e) {
    nc_fail('Não consegui processar a prévia: ' . $e->getMessage(), 500);
}
if ($r['done']) {
    nc_hx_redirect($url);
}
$counters = nc_import_decode((nc_import_batch($pdo, $id) ?: [])['counters_json'] ?? null);
header('Content-Type: text/html; charset=utf-8');
nc_import_processing($batch, (int)($counters['rows'] ?? $r['pending']), (int)$r['pending']);
