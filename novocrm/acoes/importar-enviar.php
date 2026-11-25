<?php
declare(strict_types=1);

/**
 * Etapa 1: recebe o arquivo, guarda em storage/imports/AAAA/MM/<hash>.<ext> e cria o lote.
 * Não grava nada em empresas, contatos nem oportunidades.
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../_import_run.php';

$user = nc_boot();
nc_require_post();
if (!nc_is_manager($user)) {
    nc_fail('Somente administradores e gestores importam planilhas.', 403);
}
$pdo = db();
$type = (string)($_POST['tipo'] ?? '');
$back = nc_url('importar.php' . (isset(nc_import_types()[$type]) ? '?tipo=' . $type : ''));

try {
    if (!isset(nc_import_types()[$type])) {
        throw new RuntimeException('Escolha o que você vai importar: Contatos ou Orçamentos e propostas.');
    }
    $missing = nc_import_missing_setup($pdo);
    if ($missing) {
        throw new RuntimeException('Falta aplicar as migrações do NOVOCRM: ' . implode(', ', $missing) . '.');
    }
    $file = $_FILES['arquivo'] ?? null;
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if (!$file || $err !== UPLOAD_ERR_OK) {
        throw new RuntimeException(match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O arquivo é maior que o limite do servidor. Use no máximo 15 MB.',
            UPLOAD_ERR_NO_FILE => 'Escolha um arquivo .xlsx ou .csv.',
            default => 'Não foi possível receber o arquivo. Tente de novo.',
        });
    }
    $stored = nc_import_store_file((string)$file['tmp_name'], (string)$file['name']);
    $sheets = nc_sheet_names(nc_import_file_path(['file_path' => $stored['path']]));
    $sheet = ($stored['ext'] === 'xlsx' && count($sheets) === 1) ? $sheets[0] : null;
    $id = nc_import_create_batch($pdo, $type, $stored, (int)$user['id'], $sheet);
    audit($pdo, (int)$user['id'], 'import_upload', 'import_batch', $id, ['type' => $type, 'file' => $stored['name'], 'hash' => $stored['hash'], 'via' => 'novocrm']);

    $prev = nc_import_previous_with_hash($pdo, $stored['hash'], $id);
    if ($prev) {
        flash('warning', 'Este arquivo já foi importado em ' . date('d/m', (int)strtotime((string)$prev['confirmed_at'])) . ' por '
            . ((string)($prev['user_name'] ?? '') !== '' ? $prev['user_name'] : 'outro usuário') . ' (lote #' . (int)$prev['id'] . '). Importar de novo só vai mostrar diferenças.');
    }
    redirect(nc_url('importar.php?lote=' . $id));
} catch (Throwable $e) {
    flash('danger', $e->getMessage());
    redirect($back);
}
