<?php
declare(strict_types=1);

/**
 * Promover visita a oportunidade — regra copiada de orcamentos.php (action promote_visit).
 * Se a linha de produto não puder ser sugerida com segurança, abre o formulário no drawer.
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/oportunidade.php';

$user = nc_boot();
nc_require_post();
$pdo = db();
ensure_company_cnpja_schema($pdo);
ensure_opportunity_visit_schema($pdo);
ensure_opportunity_tracking_schema($pdo);

try {
    $visitId = (int)($_POST['visit_id'] ?? 0);
    $q = $pdo->prepare('SELECT * FROM visits WHERE id=? AND lead_status="pendente" LIMIT 1');
    $q->execute([$visitId]);
    $visit = $q->fetch();
    if (!$visit) {
        throw new RuntimeException('A visita não está disponível para qualificação.');
    }

    $suggestedLineId = product_line_id_from_text(
        $pdo,
        trim((string)$visit['opportunity_text'] . ' ' . (string)$visit['equipment'])
    );

    $notes = [];
    if ($visit['os_number']) $notes[] = 'Origem: OS ' . $visit['os_number'];
    if ($visit['opportunity_text']) $notes[] = trim((string)$visit['opportunity_text']);
    if ($visit['equipment']) $notes[] = 'Equipamentos: ' . trim((string)$visit['equipment']);
    if ($visit['contact_name']) $notes[] = 'Contato: ' . trim((string)$visit['contact_name']) . ($visit['phone'] ? ' (' . trim((string)$visit['phone']) . ')' : '');

    if (!$suggestedLineId) {
        // Mesma mensagem da raiz; o vendedor confirma a linha no formulário.
        nc_hx_toast('warning', 'A linha de produto não pôde ser sugerida com segurança. Confirme manualmente antes de criar a oportunidade.');
        header('Content-Type: text/html; charset=utf-8');
        nc_opp_form($pdo, array_merge(nc_opp_defaults(), [
            'date' => (string)$visit['visit_date'],
            'client_name' => (string)$visit['client_name'],
            'seller_user_id' => $visit['seller_user_id'] !== null ? (string)$visit['seller_user_id'] : '',
            'notes' => implode("\n", $notes),
            'source_visit_id' => $visitId,
        ]));
        exit;
    }

    $newId = nc_opp_insert($pdo, (int)$user['id'], [
        'date' => $visit['visit_date'],
        'client_name' => $visit['client_name'],
        'seller_user_id' => $visit['seller_user_id'],
        'product_line_id' => $suggestedLineId,
        'amount' => 0,
        'visit_id' => $visitId,
        'source' => 'visita',
        'notes' => implode("\n", $notes),
    ]);

    $pdo->prepare("UPDATE visits SET lead_status='promovida' WHERE id=?")->execute([$visitId]);
    audit($pdo, (int)$user['id'], 'promote_visit', 'visit', $visitId, ['opportunity_id' => $newId]);
    flash('success', 'Lead promovido ao funil. A pendência "Realizar orçamento" foi criada.');

    $f = $pdo->prepare('SELECT funnel_id FROM opportunities WHERE id=?');
    $f->execute([$newId]);
    nc_hx_redirect(nc_url('oportunidades.php?funil=' . (int)$f->fetchColumn() . '&abrir=' . $newId));
} catch (Throwable $e) {
    nc_fail($e->getMessage(), 400);
}
