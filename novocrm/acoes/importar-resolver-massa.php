<?php
declare(strict_types=1);

/**
 * Decisão em massa das linhas em Revisar: vincular os clientes de alta confiança, criar empresa nova para quem não tem
 * candidata ou ignorar o que sobrou. Cada decisão vale para todas as linhas do cliente. Nada é gravado no CRM aqui:
 * as decisões ficam em import_rows até a confirmação do lote.
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/clientes-importacoes.php';
require_once __DIR__ . '/../partials/importar.php';

$user = nc_boot();
nc_require_post();
if (!nc_is_manager($user)) {
    nc_fail('Ação não permitida.', 403);
}
@set_time_limit(120);
$pdo = db();
$batch = nc_import_batch($pdo, (int)($_POST['lote'] ?? 0));
if (!$batch) {
    nc_fail('Lote não encontrado.', 404);
}
$back = nc_url('importar.php?lote=' . (int)$batch['id'] . '&filtro=revisar');

try {
    $mode = (string)($_POST['modo'] ?? '');
    $r = nc_import_resolve_bulk($pdo, $batch, $mode, (int)$user['id']);
    audit($pdo, (int)$user['id'], 'import_resolve_bulk', 'import_batch', (int)$batch['id'], ['mode' => $mode, 'rows' => $r['done'], 'left' => $r['left'], 'via' => 'novocrm']);
    $msg = $r['done'] . ' linha(s) decididas.';
    if ($r['still_review'] > 0) {
        $msg .= ' ' . $r['still_review'] . ' continuam em Revisar por um motivo que exige decisão individual.';
    }
    if ($r['left'] > 0) {
        $msg .= ' Faltam ' . $r['left'] . ': clique de novo para continuar.';
    }
    flash($r['done'] > 0 ? 'success' : 'warning', $r['done'] > 0 ? $msg : 'Nenhuma linha se encaixa nesta ação.');
} catch (Throwable $e) {
    flash('danger', $e->getMessage());
}
header('Location: ' . $back);
exit;
