<?php
declare(strict_types=1);

/**
 * Aplica, em bloco, os vínculos que a IA sugeriu e o usuário marcou (cliente da planilha -> empresa).
 * Cada escolha vale para todas as linhas do cliente. Nada é gravado no CRM aqui: só na confirmação do lote.
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
    $choices = [];
    foreach ((array)($_POST['ia'] ?? []) as $item) {
        if (is_array($item) && ($item['on'] ?? '') === '1' && (int)($item['cid'] ?? 0) > 0 && (string)($item['key'] ?? '') !== '') {
            $choices[(string)$item['key']] = (int)$item['cid'];
        }
    }
    if (!$choices) {
        throw new RuntimeException('Marque ao menos um vínculo sugerido.');
    }
    $r = nc_import_resolve_bulk($pdo, $batch, 'escolher', (int)$user['id'], $choices);
    audit($pdo, (int)$user['id'], 'import_ai_apply', 'import_batch', (int)$batch['id'], ['clients' => count($choices), 'rows' => $r['done'], 'via' => 'novocrm']);
    $msg = $r['done'] . ' linha(s) vinculadas pelas sugestões da IA.';
    if ($r['still_review'] > 0) {
        $msg .= ' ' . $r['still_review'] . ' continuam em Revisar por um motivo que exige decisão individual.';
    }
    if ($r['left'] > 0) {
        $msg .= ' Faltam ' . $r['left'] . ': aplique de novo.';
    }
    flash($r['done'] > 0 ? 'success' : 'warning', $r['done'] > 0 ? $msg : 'Nenhuma linha se encaixou nas escolhas.');
} catch (Throwable $e) {
    flash('danger', $e->getMessage());
}
header('Location: ' . $back);
exit;
