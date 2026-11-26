<?php
declare(strict_types=1);

/**
 * Processa um passo da fila do Saneamento e devolve a caixa de progresso, que se chama de novo sozinha (HTMX) até acabar.
 * É o mesmo passo que o n8n dispara por api/saneamento.php.
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../_saneamento.php';

$user = nc_boot();
nc_require_post();
if (!nc_is_manager($user)) {
    nc_fail('Ação não permitida.', 403);
}
@set_time_limit(120);
$pdo = db();
header('Content-Type: text/html; charset=utf-8');

if (!nc_san_ready($pdo)) {
    echo '<div class="nc-alert nc-alert-danger" role="alert">Aplique a migração 011 antes de usar o Saneamento.</div>';
    exit;
}
ensure_company_cnpja_schema($pdo);
$r = nc_san_step($pdo, (int)$user['id']);
$feitas = (int)($_POST['feitas'] ?? 0) + $r['processed'];
$self = nc_url('acoes/saneamento-processar.php');

$summary = static function (array $r): string {
    $parts = [];
    foreach (['ok' => 'conferidas', 'sugeridas' => 'com CNPJ sugerido', 'divergentes' => 'com nome divergente', 'duplicadas' => 'já existentes',
        'nao_encontradas' => 'sem resultado', 'erros' => 'com erro'] as $k => $label) {
        if ($r[$k] > 0) {
            $parts[] = $r[$k] . ' ' . $label;
        }
    }
    return $parts ? implode(' · ', $parts) : '';
};

if ($r['fatal'] !== '') {
    echo '<div class="nc-alert nc-alert-danger" role="alert">' . e($r['fatal']) . ' ' . (int)$feitas . ' empresa(s) processadas até aqui.</div>';
    exit;
}
if ($r['throttled']) {
    echo '<div class="nc-alert nc-alert-warning" role="alert">O limite de consultas da BrasilAPI foi atingido. ' . (int)$feitas . ' empresa(s) processadas; '
        . (int)$r['remaining'] . ' continuam na fila. Tente de novo em alguns minutos (ou deixe o n8n continuar).</div>';
    exit;
}
if ($r['remaining'] === 0 || $r['processed'] === 0) {
    audit($pdo, (int)$user['id'], 'saneamento_processar', 'company', null, ['processed' => $feitas, 'remaining' => $r['remaining'], 'via' => 'novocrm']);
    echo '<div class="nc-alert nc-alert-success" role="status">Pronto: ' . (int)$feitas . ' empresa(s) processadas'
        . ($r['remaining'] > 0 ? '; ' . (int)$r['remaining'] . ' ficaram para outra rodada (erros temporários).' : '.')
        . ' <a href="' . e(nc_url('saneamento.php')) . '">Atualizar a tela</a> para ver as sugestões.</div>';
    exit;
}
?>
<div class="nc-alert" role="status">
    Processando… <strong><?= (int)$feitas ?></strong> feitas, <strong><?= (int)$r['remaining'] ?></strong> na fila.
    <?php if ($summary($r) !== ''): ?><small class="nc-muted">Último passo: <?= e($summary($r)) ?>.</small><?php endif; ?>
    <form method="post" action="<?= e($self) ?>" hx-post="<?= e($self) ?>" hx-trigger="load delay:800ms" hx-target="#nc-san-run" hx-swap="innerHTML">
        <?= csrf_field() ?><input type="hidden" name="feitas" value="<?= (int)$feitas ?>">
    </form>
</div>
