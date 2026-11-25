<?php
declare(strict_types=1);

/**
 * Equipe Comercial, Metas & Comissões — formulários do drawer (representante e pagamento).
 */

/** Perfis que o usuário logado pode atribuir ao cadastrar um representante. */
function nc_rep_allowed_roles(array $user): array
{
    // Mesma lista da raiz (usuarios.php). Gestor não escala privilégios: nunca cria admin nem gestor.
    return nc_is_admin($user)
        ? ['comercial' => 'Comercial', 'suporte' => 'Suporte', 'leitura' => 'Leitura', 'gestor' => 'Gestor', 'admin' => 'Admin']
        : ['comercial' => 'Comercial', 'suporte' => 'Suporte', 'leitura' => 'Leitura'];
}

/** Campos de parâmetros da política (os mesmos rótulos da raiz). */
function nc_rep_param_fields(string $policyKey, array $params): void
{
    $definitions = commission_policy_definitions();
    if (!isset($definitions[$policyKey])) {
        return;
    }
    $labels = commission_policy_field_labels($policyKey);
    $defaults = $definitions[$policyKey]['defaults'];
    if (!$labels) {
        echo '<p class="nc-help">Esta política não tem parâmetros editáveis.</p>';
        return;
    }
    echo '<div class="nc-field-grid">';
    foreach ($labels as $key => $label) {
        if (!array_key_exists($key, $defaults)) {
            continue;
        }
        $value = $params[$key] ?? $defaults[$key];
        ?>
        <div class="nc-field">
            <label for="rp-<?= e((string)$key) ?>"><?= e((string)$label) ?></label>
            <input class="nc-input" id="rp-<?= e((string)$key) ?>" type="number" step="0.01" min="0"
                   name="params[<?= e((string)$key) ?>]" value="<?= e((string)$value) ?>">
        </div>
        <?php
    }
    echo '</div>';
}

/**
 * Drawer "Cadastrar representante" (sem $v['user_id']) ou "Editar representante".
 * $v: user_id, name, email, role, link_existing, month, target_month, target_year, policy_key, operation_role,
 *     linked_user_id, linked_user_id2, mentor_user_id, hired_month, active_operation, params
 */
function nc_rep_form(PDO $pdo, array $user, array $v, string $error = '', string $notice = ''): void
{
    $editing = (int)($v['user_id'] ?? 0) > 0;
    $definitions = commission_policy_definitions();
    $policyKey = isset($definitions[$v['policy_key'] ?? '']) ? (string)$v['policy_key'] : 'sem_politica';
    $roles = nc_rep_allowed_roles($user);
    $users = nc_users($pdo);
    $month = valid_month($v['month'] ?? null);
    ?>
    <div data-drawer-title="<?= $editing ? 'Editar representante' : 'Cadastrar representante' ?>">
        <form class="nc-form" method="post" hx-post="<?= e(nc_url('acoes/representante-salvar.php')) ?>"
              hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <?= csrf_field() ?>
            <input type="hidden" name="mes" value="<?= e($month) ?>">
            <?php if ($editing): ?><input type="hidden" name="user_id" value="<?= (int)$v['user_id'] ?>"><?php endif; ?>
            <?php if (!empty($v['link_existing'])): ?><input type="hidden" name="link_existing" value="1"><?php endif; ?>
            <input type="hidden" name="mentor_user_id" value="<?= e((string)($v['mentor_user_id'] ?? '')) ?>">
            <input type="hidden" name="hired_month" value="<?= e((string)($v['hired_month'] ?? '')) ?>">

            <?php if ($error !== ''): ?><div class="nc-alert nc-alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="nc-alert nc-alert-warning" role="status"><?= e($notice) ?></div><?php endif; ?>

            <?php if (!$editing): ?>
                <fieldset class="nc-fieldset">
                    <legend>Acesso</legend>
                    <div class="nc-field">
                        <label for="rp-name">Nome <span class="nc-req" aria-hidden="true">*</span></label>
                        <input class="nc-input" id="rp-name" name="name" value="<?= e((string)($v['name'] ?? '')) ?>" required maxlength="150" autocomplete="off"<?= !empty($v['link_existing']) ? ' readonly' : '' ?>>
                    </div>
                    <div class="nc-field-grid">
                        <div class="nc-field">
                            <label for="rp-email">E-mail <span class="nc-req" aria-hidden="true">*</span></label>
                            <input class="nc-input" id="rp-email" type="email" name="email" value="<?= e((string)($v['email'] ?? '')) ?>" required maxlength="190" autocomplete="off"<?= !empty($v['link_existing']) ? ' readonly' : '' ?>>
                        </div>
                        <div class="nc-field">
                            <label for="rp-role">Papel</label>
                            <select class="nc-input" id="rp-role" name="role"<?= !empty($v['link_existing']) ? ' disabled' : '' ?>>
                                <?php foreach ($roles as $key => $label): ?>
                                    <option value="<?= e($key) ?>"<?= ($v['role'] ?? 'comercial') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php if (empty($v['link_existing'])): ?>
                        <div class="nc-field">
                            <label for="rp-pass">Senha inicial <span class="nc-req" aria-hidden="true">*</span></label>
                            <input class="nc-input" id="rp-pass" type="password" name="password" minlength="10" autocomplete="new-password" required>
                            <small class="nc-help">Mínimo de 10 caracteres. A pessoa pode trocá-la depois.</small>
                        </div>
                    <?php else: ?>
                        <p class="nc-help">O usuário já existe. Serão criadas só a política de comissão e as metas abaixo.</p>
                    <?php endif; ?>
                </fieldset>
            <?php endif; ?>

            <fieldset class="nc-fieldset">
                <legend>Metas de faturamento</legend>
                <div class="nc-field-grid">
                    <div class="nc-field">
                        <label for="rp-tm">Meta de <?= e(nc_month_label($month)) ?> (R$)</label>
                        <input class="nc-input" id="rp-tm" name="target_month" inputmode="decimal" value="<?= e((string)($v['target_month'] ?? '')) ?>" placeholder="Ex.: 250000">
                    </div>
                    <div class="nc-field">
                        <label for="rp-ty">Meta anual de <?= e(substr($month, 0, 4)) ?> (R$)</label>
                        <input class="nc-input" id="rp-ty" name="target_year" inputmode="decimal" value="<?= e((string)($v['target_year'] ?? '')) ?>" placeholder="Ex.: 3000000">
                    </div>
                </div>
                <small class="nc-help">Deixe em branco para remover a meta. Sem meta anual, o painel soma as metas mensais.</small>
            </fieldset>

            <fieldset class="nc-fieldset">
                <legend>Política de comissão</legend>
                <div class="nc-field-grid">
                    <div class="nc-field">
                        <label for="rp-policy">Política</label>
                        <select class="nc-input" id="rp-policy" name="policy_key"
                                hx-get="<?= e(nc_url('acoes/representante-campos.php')) ?>" hx-trigger="change"
                                hx-target="#rep-params" hx-swap="innerHTML" hx-include="closest form">
                            <?php foreach ($definitions as $key => $def): ?>
                                <option value="<?= e((string)$key) ?>"<?= $policyKey === $key ? ' selected' : '' ?>><?= e((string)$def['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="nc-field">
                        <label for="rp-oprole">Atuação</label>
                        <select class="nc-input" id="rp-oprole" name="operation_role">
                            <option value="vendedor"<?= ($v['operation_role'] ?? 'vendedor') === 'vendedor' ? ' selected' : '' ?>>Vendedor</option>
                            <option value="suporte"<?= ($v['operation_role'] ?? '') === 'suporte' ? ' selected' : '' ?>>Suporte</option>
                        </select>
                    </div>
                </div>
                <div class="nc-field-grid">
                    <?php foreach (['linked_user_id' => 'Vendedor vinculado 1', 'linked_user_id2' => 'Vendedor vinculado 2'] as $field => $label): ?>
                        <div class="nc-field">
                            <label for="rp-<?= e($field) ?>"><?= e($label) ?></label>
                            <select class="nc-input" id="rp-<?= e($field) ?>" name="<?= e($field) ?>">
                                <option value="">—</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>"<?= (string)($v[$field] ?? '') === (string)$u['id'] ? ' selected' : '' ?>><?= e((string)$u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
                <label class="nc-check"><input type="checkbox" name="active_operation" value="1"<?= !empty($v['active_operation']) ? ' checked' : '' ?>> Em operação ativa</label>
                <div id="rep-params"><?php nc_rep_param_fields($policyKey, (array)($v['params'] ?? [])); ?></div>
            </fieldset>

            <div class="nc-form-actions">
                <button type="button" class="nc-btn nc-btn-secondary" data-nc-drawer-close>Cancelar</button>
                <button type="submit" class="nc-btn nc-btn-primary">
                    <?= !empty($v['link_existing']) ? 'Vincular usuário existente' : ($editing ? 'Salvar' : 'Cadastrar representante') ?>
                </button>
            </div>
        </form>
    </div>
    <?php
}

/** Valores iniciais do formulário para um usuário já existente. */
function nc_rep_values_for_user(PDO $pdo, int $userId, string $month): array
{
    $q = $pdo->prepare('SELECT id,name,email,role FROM users WHERE id=?');
    $q->execute([$userId]);
    $row = $q->fetch();
    if (!$row) {
        throw new RuntimeException('Usuário não encontrado.');
    }
    $policy = user_commission_policy($pdo, $userId);
    [$year, $mon] = array_map('intval', explode('-', $month));
    $tm = nc_target($pdo, $userId, $year, $mon);
    $ty = nc_target($pdo, $userId, $year, null);
    return [
        'user_id' => $userId,
        'name' => (string)$row['name'],
        'email' => (string)$row['email'],
        'role' => (string)$row['role'],
        'month' => $month,
        'target_month' => $tm !== null ? number_format($tm, 2, '.', '') : '',
        'target_year' => $ty !== null ? number_format($ty, 2, '.', '') : '',
        'policy_key' => $policy['policy_key'],
        'operation_role' => $policy['operation_role'],
        'linked_user_id' => $policy['linked_user_id'] !== null ? (string)$policy['linked_user_id'] : '',
        'linked_user_id2' => $policy['linked_user_id2'] !== null ? (string)$policy['linked_user_id2'] : '',
        'mentor_user_id' => $policy['mentor_user_id'] !== null ? (string)$policy['mentor_user_id'] : '',
        'hired_month' => $policy['hired_month'],
        'active_operation' => $policy['active_operation'],
        'params' => $policy['params'],
    ];
}
