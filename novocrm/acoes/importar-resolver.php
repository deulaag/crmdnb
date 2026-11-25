<?php
declare(strict_types=1);

/**
 * Decisão humana sobre uma linha em Revisar: escolher empresa, criar empresa nova (Contatos), ignorar ou limpar a decisão.
 * A decisão fica na própria linha (resolved_company_id, resolved_by, resolved_at). Nada é gravado no CRM aqui.
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
$rowId = (int)($_POST['linha_id'] ?? 0);
$row = nc_import_row($pdo, $rowId);
$batch = $row ? nc_import_batch($pdo, (int)$row['batch_id']) : null;
if (!$row || !$batch) {
    nc_fail('Linha não encontrada.', 404);
}

try {
    $op = (string)($_POST['op'] ?? '');
    $companyId = (int)($_POST['company_id'] ?? 0);
    $applySame = ($_POST['aplicar_mesmo'] ?? '') === '1';
    $n = nc_import_resolve($pdo, $batch, $rowId, $op, $companyId > 0 ? $companyId : null, (int)$user['id'], $applySame);
    audit($pdo, (int)$user['id'], 'import_resolve', 'import_batch', (int)$batch['id'], ['row' => (int)$row['sheet_row'], 'op' => $op, 'company_id' => $companyId ?: null, 'rows' => $n, 'via' => 'novocrm']);
    flash('success', $n === 1 ? 'Linha ' . (int)$row['sheet_row'] . ' resolvida.' : $n . ' linhas resolvidas.');
    header('HX-Refresh: true');
    exit;
} catch (Throwable $e) {
    http_response_code(422);
    header('Content-Type: text/html; charset=utf-8');
    $row = nc_import_row($pdo, $rowId) ?: $row;
    nc_import_review_drawer($pdo, $batch, $row, $e->getMessage());
}
