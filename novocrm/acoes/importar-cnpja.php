<?php
declare(strict_types=1);

/**
 * Depois da confirmação (V3, 5.6): completa, em fila, as empresas CRIADAS pelo lote com CNPJ válido, pelo CNPJá.
 * Uma consulta por chamada, com pausa entre elas (polling HTMX). Só preenche campos vazios; nunca troca o nome.
 * A importação em si NUNCA chama o CNPJá: isto só roda quando o usuário clica no botão.
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
$id = (int)($_POST['lote'] ?? 0);
$batch = nc_import_batch($pdo, $id);
if (!$batch || $batch['status'] !== 'confirmado' || $batch['import_type'] !== 'contatos') {
    nc_fail('Este lote não permite completar empresas pelo CNPJá.', 400);
}

$r = nc_import_cnpja_step($pdo, $id);
header('Content-Type: text/html; charset=utf-8');
$total = (int)($_POST['total'] ?? 0) ?: ($r['remaining'] + 1);
$done = max(0, $total - $r['remaining']);
if ($r['fatal']) {
    echo '<div class="nc-alert nc-alert-danger" role="alert" id="nc-cnpja-box">' . e($r['error']) . '</div>';
    exit;
}
if ($r['done']) {
    audit($pdo, (int)$user['id'], 'import_cnpja_complete', 'import_batch', $id, ['companies' => $total, 'via' => 'novocrm']);
    echo '<div class="nc-alert nc-alert-success" role="status" id="nc-cnpja-box">Pronto: ' . $total . ' empresa(s) consultadas no CNPJá. Só campos vazios foram preenchidos.</div>';
    exit;
}
?>
<div class="nc-alert" role="status" id="nc-cnpja-box">
    Consultando o CNPJá… <strong><?= $done ?></strong> de <strong><?= $total ?></strong>.
    <form method="post" action="<?= e(nc_url('acoes/importar-cnpja.php')) ?>" hx-post="<?= e(nc_url('acoes/importar-cnpja.php')) ?>"
          hx-trigger="load delay:1500ms" hx-target="#nc-cnpja-box" hx-swap="outerHTML">
        <?= csrf_field() ?><input type="hidden" name="lote" value="<?= $id ?>"><input type="hidden" name="total" value="<?= $total ?>">
    </form>
</div>
