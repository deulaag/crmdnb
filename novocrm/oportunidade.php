<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/partials/oportunidade.php';

$user = nc_boot();
$pdo = db();
ensure_opportunity_visit_schema($pdo);
ensure_opportunity_tracking_schema($pdo);

$id = max(0, (int)($_GET['id'] ?? 0));

try {
    $opp = nc_opp_load($pdo, $id);
} catch (Throwable $e) {
    if (nc_is_htmx()) {
        nc_fail($e->getMessage(), 404);
    }
    http_response_code(404);
    nc_header('Oportunidade não encontrada', 'oportunidades', ['Oportunidades', 'Não encontrada']);
    nc_empty('Oportunidade não encontrada', 'Ela pode ter sido excluída ou o link está incorreto.', '<a class="nc-btn nc-btn-primary" href="' . e(nc_url('oportunidades.php')) . '">Voltar para Oportunidades</a>');
    nc_footer();
    exit;
}

// Dentro do drawer (htmx): só o fragmento. Acesso direto pelo link: página completa.
if (nc_is_htmx()) {
    header('Content-Type: text/html; charset=utf-8');
    nc_opp_ficha($pdo, $opp, $user);
    exit;
}

nc_header('Oportunidade ' . $opp['number'], 'oportunidades', ['Oportunidades', (string)$opp['number']]);
echo '<p><a href="' . e(nc_url('oportunidades.php?funil=' . (int)$opp['funnel_id'])) . '">← Voltar para Oportunidades</a></p>';
echo '<div class="nc-card nc-ficha-page">';
nc_opp_ficha($pdo, $opp, $user);
echo '</div>';
nc_footer();
