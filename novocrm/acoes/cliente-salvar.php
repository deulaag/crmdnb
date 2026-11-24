<?php
declare(strict_types=1);

/** Cria um cliente (empresa) pelo novo CRM — mesma função da raiz: company_upsert_from_customer_data(). */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/cliente-form.php';

$user = nc_boot();
nc_require_post();
$pdo = db();
ensure_company_cnpja_schema($pdo);
ensure_rd_crm_schema($pdo);

$values = nc_cliente_from_post();

try {
    $cnpj = cnpj_digits($values['cnpj']);
    if ($values['name'] === '') {
        throw new RuntimeException('Informe a razão social do cliente.');
    }
    if ($cnpj !== '' && !cnpj_is_valid($cnpj)) {
        throw new RuntimeException('O CNPJ informado para o cliente é inválido.');
    }
    if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Informe um e-mail válido ou deixe o campo em branco.');
    }
    if ($values['state'] !== '' && !preg_match('/^[A-Za-z]{2}$/', $values['state'])) {
        throw new RuntimeException('A UF deve ter 2 letras (ex.: SP).');
    }

    if ($cnpj !== '') {
        $q = $pdo->prepare('SELECT id,name FROM companies WHERE cnpj = ? LIMIT 1');
        $q->execute([$cnpj]);
        if ($dup = $q->fetch()) {
            throw new RuntimeException('Já existe um cliente com este CNPJ: ' . $dup['name'] . '.');
        }
    }

    $ownerId = $values['owner_user_id'] !== '' ? (int)$values['owner_user_id'] : null;
    if ($ownerId !== null) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id=? AND active=1');
        $q->execute([$ownerId]);
        if (!(int)$q->fetchColumn()) {
            throw new RuntimeException('Responsável inválido.');
        }
    }

    // Reaproveita o resultado da consulta CNPJá feita nesta sessão (30 min), como na raiz.
    $lookupData = null;
    $entry = $cnpj !== '' ? ($_SESSION['cnpja_lookup'][$cnpj] ?? null) : null;
    if (is_array($entry) && (int)($entry['expires_at'] ?? 0) >= time() && is_array($entry['data'] ?? null)) {
        $lookupData = $entry['data'];
    }

    $values['cnpj'] = $cnpj;
    $id = company_upsert_from_customer_data($pdo, $values, $ownerId, is_array($lookupData) ? ($lookupData['raw'] ?? null) : null);

    audit($pdo, (int)$user['id'], 'create', 'company', $id, ['name' => $values['name'], 'cnpj' => $cnpj ?: null, 'via' => 'novocrm']);
    flash('success', 'Cliente ' . $values['name'] . ' cadastrado.');
    nc_hx_redirect(nc_url('clientes.php'));
} catch (Throwable $e) {
    http_response_code(422);
    header('Content-Type: text/html; charset=utf-8');
    nc_cliente_form($values, nc_users($pdo), $e->getMessage());
}
