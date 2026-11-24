<?php
declare(strict_types=1);

/**
 * Consulta de CNPJ (CNPJá) — mesma regra de /cnpja_lookup.php da raiz:
 * valida o CNPJ, guarda o resultado em $_SESSION['cnpja_lookup'] por 30 minutos e audita.
 * Devolve o formulário preenchido (fragmento HTML para o drawer).
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/cliente-form.php';
require_once __DIR__ . '/../partials/oportunidade.php';

$user = nc_boot();
nc_require_post();
$pdo = db();
ensure_company_cnpja_schema($pdo);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$forOpportunity = ($_POST['mode'] ?? '') === 'oportunidade';
$values = $forOpportunity ? nc_opp_from_post() : nc_cliente_from_post();
$users = nc_users($pdo);

try {
    $cnpj = cnpj_digits((string)($_POST['cnpj'] ?? ''));
    if (!cnpj_is_valid($cnpj)) {
        throw new RuntimeException('CNPJ inválido. Confira os 14 dígitos.');
    }

    $data = cnpja_lookup($pdo, $cnpj);

    if (!isset($_SESSION['cnpja_lookup']) || !is_array($_SESSION['cnpja_lookup'])) {
        $_SESSION['cnpja_lookup'] = [];
    }
    $_SESSION['cnpja_lookup'][$cnpj] = [
        'expires_at' => time() + 1800,
        'data' => $data,
    ];

    audit($pdo, (int)$user['id'], 'lookup', 'cnpja', null, [
        'cnpj' => $cnpj,
        'strategy' => $data['strategy'] ?? '',
        'stale' => !empty($data['stale']),
    ]);

    if ($forOpportunity) {
        foreach (nc_opp_client_keys() as $key) {
            if (isset($data[$key]) && $data[$key] !== null && $data[$key] !== '') {
                $values['client_' . $key] = (string)$data[$key];
            }
        }
        $values['client_cnpj'] = $cnpj;
        if (trim((string)$values['client_name']) === '' && !empty($data['name'])) {
            $values['client_name'] = (string)$data['name'];
        }
        nc_opp_form($pdo, $values, '', 'Dados do cliente localizados na CNPJá. Complete a oportunidade e salve.');
        exit;
    }

    foreach (array_keys(nc_cliente_defaults()) as $key) {
        if ($key !== 'owner_user_id' && isset($data[$key]) && $data[$key] !== null && $data[$key] !== '') {
            $values[$key] = (string)$data[$key];
        }
    }
    $values['cnpj'] = $cnpj;

    nc_cliente_form($values, $users, '', 'Dados localizados na CNPJá. Revise e salve.');
} catch (Throwable $e) {
    http_response_code(422);
    if ($forOpportunity) {
        nc_opp_form($pdo, $values, $e->getMessage());
    } else {
        nc_cliente_form($values, $users, $e->getMessage());
    }
}
