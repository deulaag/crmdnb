<?php
declare(strict_types=1);

/**
 * Vincula em lote contatos SEM empresa a uma empresa (admin e gestor).
 * Só toca em contatos com company_id IS NULL: nunca move contato que já pertence a outra empresa.
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/contato.php';

$user = nc_boot();
nc_require_post();
if (!nc_is_manager($user)) {
    nc_fail('Ação não permitida.', 403);
}
$pdo = db();

$ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), static fn($i) => $i > 0)));

try {
    if (!$ids) {
        throw new RuntimeException('Nenhum contato selecionado.');
    }
    $ids = array_slice($ids, 0, 500);
    $companyId = (int)($_POST['company_id'] ?? 0);
    $q = $pdo->prepare('SELECT id, name FROM companies WHERE id = ? LIMIT 1');
    $q->execute([$companyId]);
    $company = $q->fetch();
    if (!$company) {
        throw new RuntimeException('Escolha a empresa na lista de sugestões (busque por nome ou CNPJ).');
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE contacts SET company_id = ? WHERE id IN ($in) AND company_id IS NULL");
    $stmt->execute(array_merge([$companyId], $ids));
    $moved = $stmt->rowCount();
    audit($pdo, (int)$user['id'], 'contact_link', 'company', $companyId, ['contact_ids' => $ids, 'linked' => $moved, 'via' => 'novocrm']);
    $pdo->commit();

    $skipped = count($ids) - $moved;
    flash('success', $moved . ' contato' . ($moved === 1 ? '' : 's') . ' vinculado' . ($moved === 1 ? '' : 's') . ' a ' . $company['name'] . '.'
        . ($skipped > 0 ? ' ' . $skipped . ' já tinha(m) empresa e foi(ram) mantido(s).' : ''));
    nc_hx_redirect(nc_url('clientes.php?aba=contatos'));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    header('Content-Type: text/html; charset=utf-8');
    nc_contact_link_form($ids, $e->getMessage());
}
