<?php
declare(strict_types=1);

/**
 * "Sugerir com IA" na revisão: para os clientes que sobraram (confiança média ou sem candidata clara), pede à IA que diga
 * qual empresa candidata é a mesma. Só compara nomes (sem dados de pessoas). Devolve uma lista que o usuário aprova de uma vez.
 * Nada é gravado aqui.
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/clientes-importacoes.php';
require_once __DIR__ . '/../partials/importar.php';
require_once __DIR__ . '/../_saneamento.php';

$user = nc_boot();
nc_require_post();
if (!nc_is_manager($user)) {
    nc_fail('Ação não permitida.', 403);
}
@set_time_limit(180);
$pdo = db();
$batch = nc_import_batch($pdo, (int)($_POST['lote'] ?? 0));
if (!$batch || !in_array($batch['status'], ['em_revisao', 'mapeado'], true)) {
    nc_fail('Lote não encontrado ou fora de revisão.', 404);
}
header('Content-Type: text/html; charset=utf-8');

try {
    $plan = nc_import_bulk_plan($pdo, $batch);
    $items = [];
    $names = [];
    foreach ($plan['groups'] as $key => $g) {
        if (!in_array($g['tier'], ['media', 'sem'], true) || count($items) >= 100) {
            continue;
        }
        $row = nc_import_row($pdo, (int)$g['row_ids'][0]);
        $cands = $row ? array_values(array_filter(nc_import_decode($row['candidates_json'] ?? null), 'is_array')) : [];
        if (!$cands) {
            continue;
        }
        $items[] = ['key' => (string)$key, 'name' => (string)$g['label'], 'cands' => array_map(static fn($c) => [
            'id' => (int)$c['id'], 'name' => (string)($c['name'] ?? ''), 'city' => (string)($c['city'] ?? ''), 'state' => (string)($c['state'] ?? ''),
        ], $cands)];
        foreach ($cands as $c) {
            $names[(int)$c['id']] = $c;
        }
    }
    if (!$items) {
        echo '<div class="nc-alert" role="status">Nenhum cliente com candidatas para a IA analisar.</div>';
        exit;
    }
    $suggest = nc_san_ai_match_groups($pdo, $items);
    audit($pdo, (int)$user['id'], 'import_ai_suggest', 'import_batch', (int)$batch['id'], ['clients' => count($items), 'suggested' => count($suggest), 'via' => 'novocrm']);
    if (!$suggest) {
        echo '<div class="nc-alert" role="status">A IA analisou ' . count($items) . ' cliente(s) e não achou nenhuma candidata claramente igual. Resolva-os pela lista abaixo.</div>';
        exit;
    }
    $labels = [];
    foreach ($plan['groups'] as $key => $g) {
        $labels[(string)$key] = $g;
    }
    ?>
    <form method="post" action="<?= e(nc_url('acoes/importar-ia-aplicar.php')) ?>" class="nc-card nc-ia-list">
        <?= csrf_field() ?><input type="hidden" name="lote" value="<?= (int)$batch['id'] ?>">
        <h3 class="nc-section-title">A IA sugere <?= count($suggest) ?> vínculo(s). Confira e aprove.</h3>
        <ul class="nc-cands">
            <?php $i = 0; foreach ($suggest as $key => $s):
                $g = $labels[(string)$key] ?? null;
                $co = $names[$s['cid']] ?? ['name' => '#' . $s['cid']]; ?>
                <li>
                    <label class="nc-check">
                        <input type="hidden" name="ia[<?= $i ?>][key]" value="<?= e((string)$key) ?>">
                        <input type="hidden" name="ia[<?= $i ?>][cid]" value="<?= (int)$s['cid'] ?>">
                        <input type="checkbox" name="ia[<?= $i ?>][on]" value="1"<?= $s['conf'] >= 80 ? ' checked' : '' ?>>
                        <span><strong><?= e((string)($g['label'] ?? $key)) ?></strong> (<?= (int)($g['count'] ?? 0) ?> linha(s))
                            → <strong><?= e((string)$co['name']) ?></strong>
                            <small class="nc-muted"> · <?= e(implode(' · ', array_filter([
                                ($co['cnpj'] ?? '') !== '' ? format_cnpj((string)$co['cnpj']) : 'Sem CNPJ',
                                trim((string)($co['city'] ?? '') . (($co['state'] ?? '') !== '' ? '/' . $co['state'] : '')),
                                'confiança da IA ' . (int)$s['conf'] . '%',
                            ]))) ?></small></span>
                    </label>
                </li>
            <?php $i++; endforeach; ?>
        </ul>
        <div class="nc-form-actions"><button type="submit" class="nc-btn nc-btn-primary">Aplicar as marcadas</button></div>
        <p class="nc-help">Marcadas por padrão: confiança de 80% ou mais. Nada é gravado no CRM antes de confirmar a importação.</p>
    </form>
    <?php
} catch (Throwable $e) {
    echo '<div class="nc-alert nc-alert-danger" role="alert">' . e($e->getMessage()) . '</div>';
}
