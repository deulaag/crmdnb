<?php
declare(strict_types=1);

require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/oportunidade.php';

$user = nc_boot();
$pdo = db();
ensure_company_cnpja_schema($pdo);
ensure_opportunity_visit_schema($pdo);
ensure_opportunity_tracking_schema($pdo);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$id = max(0, (int)($_GET['id'] ?? 0));
$values = nc_opp_defaults();
$values['seller_user_id'] = (string)(int)$user['id'];

if ($id > 0) {
    $q = $pdo->prepare(
        'SELECT o.*, c.cnpj company_cnpj, c.alias company_alias, c.registration_status company_registration_status,
                c.registration_status_date company_registration_status_date, c.founded company_founded,
                c.company_type company_company_type, c.company_size company_company_size, c.legal_nature company_legal_nature,
                c.capital_social company_capital_social, c.email company_email, c.phone company_phone, c.zip company_zip,
                c.street company_street, c.address_number company_address_number, c.address_details company_address_details,
                c.district company_district, c.city company_city, c.state company_state,
                c.main_activity_code company_main_activity_code, c.industry company_industry, c.cnpja_updated_at company_cnpja_updated_at
         FROM opportunities o LEFT JOIN companies c ON c.id=o.company_id WHERE o.id=? LIMIT 1'
    );
    $q->execute([$id]);
    $o = $q->fetch();
    if (!$o) {
        nc_fail('Oportunidade não encontrada.', 404);
    }
    $values = array_merge($values, [
        'id' => (int)$o['id'],
        'number' => (string)$o['number'],
        'date' => (string)$o['opportunity_date'],
        'client_name' => (string)$o['client_name'],
        'client_cnpj' => (string)($o['company_cnpj'] ?? ''),
        'seller_user_id' => $o['seller_user_id'] !== null ? (string)$o['seller_user_id'] : '',
        'support_user_id' => $o['support_user_id'] !== null ? (string)$o['support_user_id'] : '',
        'product_line_id' => (string)$o['product_line_id'],
        'amount' => (string)$o['amount'],
        'notes' => (string)($o['notes'] ?? ''),
        'source_visit_id' => (int)($o['visit_id'] ?? 0),
    ]);
    foreach (nc_opp_client_keys() as $key) {
        $values['client_' . $key] = (string)($o['company_' . $key] ?? '');
    }
} elseif ((int)($_GET['from_visit'] ?? 0) > 0) {
    // Visita escolhida para virar oportunidade (usado pela tela de Visitas).
    $q = $pdo->prepare('SELECT * FROM visits WHERE id=? LIMIT 1');
    $q->execute([(int)$_GET['from_visit']]);
    if ($visit = $q->fetch()) {
        $notes = [];
        if ($visit['os_number']) $notes[] = 'Origem: OS ' . $visit['os_number'];
        if ($visit['opportunity_text']) $notes[] = trim((string)$visit['opportunity_text']);
        if ($visit['equipment']) $notes[] = 'Equipamentos: ' . trim((string)$visit['equipment']);
        if ($visit['contact_name']) $notes[] = 'Contato: ' . trim((string)$visit['contact_name']) . ($visit['phone'] ? ' (' . trim((string)$visit['phone']) . ')' : '');
        $values['date'] = (string)$visit['visit_date'];
        $values['client_name'] = (string)$visit['client_name'];
        $values['seller_user_id'] = $visit['seller_user_id'] !== null ? (string)$visit['seller_user_id'] : '';
        $values['notes'] = implode("\n", $notes);
        $values['source_visit_id'] = (int)$visit['id'];
    }
}

// Cliente pré-preenchido (usado pela Cadência: "Criar oportunidade").
if ($id === 0 && (int)($_GET['from_visit'] ?? 0) < 1 && trim((string)($_GET['cliente'] ?? '')) !== '') {
    $values['client_name'] = trim((string)$_GET['cliente']);
    $q = $pdo->prepare('SELECT cnpj FROM companies WHERE name = ? ORDER BY id LIMIT 1');
    $q->execute([$values['client_name']]);
    $cnpj = $q->fetchColumn();
    if ($cnpj) {
        $values['client_cnpj'] = (string)$cnpj;
    }
}

nc_opp_form($pdo, $values);
