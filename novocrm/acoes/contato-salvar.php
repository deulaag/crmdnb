<?php
declare(strict_types=1);

/** Cria um contato. Empresa obrigatória (autocomplete); e-mail validado; sem duplicar o mesmo e-mail na mesma empresa. */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../_empresas.php';
require_once __DIR__ . '/../partials/contato.php';

$user = nc_boot();
nc_require_post();
$pdo = db();
ensure_rd_crm_schema($pdo);

$post = [
    'name' => trim((string)($_POST['name'] ?? '')),
    'email' => trim((string)($_POST['email'] ?? '')),
    'phone' => trim((string)($_POST['phone'] ?? '')),
    'job_title' => trim((string)($_POST['job_title'] ?? '')),
    'return' => (string)($_POST['return'] ?? ''),
];

try {
    $v = nc_contact_validate($pdo, $_POST);

    if ($v['email'] !== null) {
        $q = $pdo->prepare('SELECT id FROM contacts WHERE company_id = ? AND LOWER(email) = ? LIMIT 1');
        $q->execute([$v['company_id'], $v['email']]);
        if ($q->fetchColumn()) {
            throw new RuntimeException('Já existe um contato com este e-mail nesta empresa.');
        }
    }

    $hasCreatedBy = nc_column_exists($pdo, 'contacts', 'created_by');
    $cols = 'company_id, name, email, phone, job_title, source' . ($hasCreatedBy ? ', created_by' : '');
    $marks = '?, ?, ?, ?, ?, ?' . ($hasCreatedBy ? ', ?' : '');
    $args = [$v['company_id'], $v['name'], $v['email'], $v['phone'], $v['job_title'], 'manual'];
    if ($hasCreatedBy) {
        $args[] = (int)$user['id'];
    }
    $pdo->prepare("INSERT INTO contacts ($cols) VALUES ($marks)")->execute($args);
    $id = (int)$pdo->lastInsertId();

    audit($pdo, (int)$user['id'], 'create', 'contact', $id, ['name' => $v['name'], 'company_id' => $v['company_id'], 'via' => 'novocrm']);
    flash('success', 'Contato ' . $v['name'] . ' cadastrado em ' . $v['company_name'] . '.');

    $return = $post['return'];
    nc_hx_redirect(str_starts_with($return, NC_BASE) ? $return : nc_url('clientes.php?aba=contatos'));
} catch (Throwable $e) {
    http_response_code(422);
    header('Content-Type: text/html; charset=utf-8');
    $company = null;
    $cid = (int)($_POST['company_id'] ?? 0);
    if ($cid > 0) {
        $q = $pdo->prepare('SELECT id, name, cnpj FROM companies WHERE id = ? LIMIT 1');
        $q->execute([$cid]);
        $company = $q->fetch() ?: null;
    }
    nc_contact_form($post + ['company' => $company], $e->getMessage());
}
