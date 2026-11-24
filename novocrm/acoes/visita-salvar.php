<?php
declare(strict_types=1);

/** Registrar visita manual — regra copiada de visitas.php (action manual). */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/visita.php';

$user = nc_boot();
nc_require_post();
$pdo = db();

$v = nc_visita_from_post();

try {
    $client = trim((string)($_POST['client_name'] ?? ''));
    $sellerId = ($_POST['seller_user_id'] ?? '') !== '' ? (int)$_POST['seller_user_id'] : null;
    if ($client === '' || !$sellerId) {
        throw new RuntimeException('Informe cliente e responsável.');
    }

    $visitId = nc_visita_insert($pdo, (int)$user['id'], [
        'os' => trim((string)($_POST['os_number'] ?? '')),
        'data' => valid_date_string($_POST['visit_date'] ?? '') ? $_POST['visit_date'] : date('Y-m-d'),
        'cliente' => $client,
        'seller_user_id' => $sellerId,
        'tipo' => ($_POST['visit_type'] ?? 'visita') === 'prospeccao' ? 'prospeccao' : 'visita',
        'contato' => trim((string)($_POST['contact_name'] ?? '')),
        'setor' => trim((string)($_POST['sector'] ?? '')),
        'oportunidade' => trim((string)($_POST['opportunity_text'] ?? '')),
    ], 'manual');

    audit($pdo, (int)$user['id'], 'create', 'visit', $visitId);
    flash('success', 'Atividade registrada.');
    nc_hx_redirect(nc_url('visitas.php?aba=visitas'));
} catch (Throwable $e) {
    http_response_code(422);
    header('Content-Type: text/html; charset=utf-8');
    nc_visita_form($pdo, $v, $e->getMessage());
}
