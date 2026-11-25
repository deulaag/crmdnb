<?php
declare(strict_types=1);

/**
 * Cadastrar / editar representante: usuário (mesma lógica de usuarios.php), política de comissão
 * (mesma lógica de configuracoes.php, action commission_policy) e metas (sales_targets).
 * Somente admin e gestor.
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/comissao.php';
require_once __DIR__ . '/../partials/equipe.php';

$user = nc_boot();
nc_require_post();
if (!nc_is_manager($user)) {
    nc_fail('Ação não permitida.', 403);
}
$pdo = db();
ensure_commission_policy_schema($pdo);

$month = valid_month($_POST['mes'] ?? null);
$v = [
    'user_id' => (int)($_POST['user_id'] ?? 0),
    'name' => trim((string)($_POST['name'] ?? '')),
    'email' => strtolower(trim((string)($_POST['email'] ?? ''))),
    'role' => (string)($_POST['role'] ?? 'comercial'),
    'link_existing' => !empty($_POST['link_existing']),
    'month' => $month,
    'target_month' => trim((string)($_POST['target_month'] ?? '')),
    'target_year' => trim((string)($_POST['target_year'] ?? '')),
    'policy_key' => (string)($_POST['policy_key'] ?? 'sem_politica'),
    'operation_role' => (string)($_POST['operation_role'] ?? 'vendedor'),
    'linked_user_id' => (string)($_POST['linked_user_id'] ?? ''),
    'linked_user_id2' => (string)($_POST['linked_user_id2'] ?? ''),
    'mentor_user_id' => (string)($_POST['mentor_user_id'] ?? ''),
    'hired_month' => (string)($_POST['hired_month'] ?? ''),
    'active_operation' => !empty($_POST['active_operation']),
    'params' => is_array($_POST['params'] ?? null) ? $_POST['params'] : [],
];
$wasEdit = $v['user_id'] > 0;

try {
    $targetId = $v['user_id'];

    if ($targetId > 0) {
        $q = $pdo->prepare('SELECT id FROM users WHERE id=?');
        $q->execute([$targetId]);
        if (!$q->fetchColumn()) {
            throw new RuntimeException('Usuário não encontrado.');
        }
    } else {
        // --- Cadastro (validações de usuarios.php, action create) ---
        $allowedRoles = array_keys(nc_rep_allowed_roles($user));
        $existing = null;
        if ($v['email'] !== '') {
            $q = $pdo->prepare('SELECT id,name,role FROM users WHERE email=? LIMIT 1');
            $q->execute([$v['email']]);
            $existing = $q->fetch() ?: null;
        }

        if ($existing && !$v['link_existing']) {
            $v['link_existing'] = true;
            $v['name'] = (string)$existing['name'];
            http_response_code(422);
            header('Content-Type: text/html; charset=utf-8');
            nc_rep_form($pdo, $user, $v, '', 'Já existe um usuário com este e-mail (' . $existing['name'] . '). Você pode vincular o usuário existente: serão criadas só a política e as metas.');
            exit;
        }

        if ($existing) {
            $targetId = (int)$existing['id'];
        } else {
            $password = (string)($_POST['password'] ?? '');
            if ($v['name'] === '' || !filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
                throw new RuntimeException('Informe nome, e-mail válido e senha com pelo menos 10 caracteres.');
            }
            if (!in_array($v['role'], ['admin', 'gestor', 'comercial', 'suporte', 'leitura'], true)) {
                throw new RuntimeException('Perfil inválido.');
            }
            if (!in_array($v['role'], $allowedRoles, true)) {
                throw new RuntimeException('Você não pode criar usuários com este perfil.');
            }
            $pdo->prepare('INSERT INTO users(name,email,password_hash,role,active) VALUES(?,?,?,?,1)')
                ->execute([$v['name'], $v['email'], password_hash($password, PASSWORD_DEFAULT), $v['role']]);
            $targetId = (int)$pdo->lastInsertId();
            audit($pdo, (int)$user['id'], 'create', 'user', $targetId, ['role' => $v['role']]);
            $v['link_existing'] = true; // se algo abaixo falhar, o reenvio vincula em vez de recriar
        }
    }

    // --- Política (configuracoes.php, action commission_policy) ---
    save_user_commission_policy($pdo, $targetId, [
        'operation_role' => $v['operation_role'],
        'policy_key' => $v['policy_key'],
        'params' => $v['params'],
        'linked_user_id' => $v['linked_user_id'],
        'linked_user_id2' => $v['linked_user_id2'],
        'mentor_user_id' => $v['mentor_user_id'],
        'hired_month' => $v['hired_month'],
        'active_operation' => $v['active_operation'],
    ], (int)$user['id']);
    audit($pdo, (int)$user['id'], 'save_policy', 'user', $targetId, ['policy_key' => $v['policy_key']]);

    // --- Metas ---
    [$year, $mon] = array_map('intval', explode('-', $month));
    nc_target_save($pdo, $targetId, $year, $mon, nc_parse_amount($v['target_month']), (int)$user['id']);
    nc_target_save($pdo, $targetId, $year, null, nc_parse_amount($v['target_year']), (int)$user['id']);

    flash('success', $wasEdit ? 'Representante atualizado.' : 'Representante cadastrado.');
    nc_hx_redirect(nc_url('equipe.php?mes=' . $month));
} catch (Throwable $e) {
    http_response_code(422);
    header('Content-Type: text/html; charset=utf-8');
    nc_rep_form($pdo, $user, $v, $e->getMessage());
}
