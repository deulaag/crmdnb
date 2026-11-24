<?php
declare(strict_types=1);

/** Campos do cadastro de cliente (empresa). Chaves = nomes dos inputs. */
function nc_cliente_defaults(): array
{
    return [
        'cnpj' => '', 'name' => '', 'alias' => '', 'email' => '', 'phone' => '',
        'zip' => '', 'street' => '', 'address_number' => '', 'address_details' => '', 'district' => '',
        'city' => '', 'state' => '', 'industry' => '', 'main_activity_code' => '',
        'registration_status' => '', 'registration_status_date' => '', 'founded' => '',
        'company_type' => '', 'company_size' => '', 'legal_nature' => '', 'capital_social' => '',
        'cnpja_updated_at' => '', 'owner_user_id' => '',
    ];
}

/** Lê os campos do cadastro a partir do $_POST (somente chaves conhecidas). */
function nc_cliente_from_post(): array
{
    $v = nc_cliente_defaults();
    foreach ($v as $key => $_) {
        $v[$key] = trim((string)($_POST[$key] ?? ''));
    }
    return $v;
}

/**
 * Formulário "Novo cliente" (conteúdo do drawer).
 *
 * @param array<int,array{id:mixed,name:mixed}> $users
 */
function nc_cliente_form(array $v, array $users, string $error = '', string $notice = ''): void
{
    $v = array_merge(nc_cliente_defaults(), $v);
    $hidden = ['registration_status', 'registration_status_date', 'founded', 'company_type', 'company_size',
        'legal_nature', 'capital_social', 'main_activity_code', 'cnpja_updated_at'];
    ?>
    <div data-drawer-title="Novo cliente">
        <form id="cliente-form" class="nc-form" method="post"
              hx-post="<?= e(nc_url('acoes/cliente-salvar.php')) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML"
              hx-disabled-elt="find button[type=submit]">
            <?php if ($error !== ''): ?><div class="nc-alert nc-alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="nc-alert nc-alert-success" role="status"><?= e($notice) ?></div><?php endif; ?>

            <?php foreach ($hidden as $key): ?>
                <input type="hidden" name="<?= e($key) ?>" value="<?= e($v[$key]) ?>">
            <?php endforeach; ?>

            <div class="nc-field">
                <label for="cf-cnpj">CNPJ</label>
                <div class="nc-field-row">
                    <input class="nc-input" id="cf-cnpj" name="cnpj" value="<?= e($v['cnpj'] !== '' ? format_cnpj($v['cnpj']) : '') ?>"
                           inputmode="numeric" autocomplete="off" placeholder="00.000.000/0000-00">
                    <button type="button" class="nc-btn nc-btn-secondary"
                            hx-post="<?= e(nc_url('acoes/cnpj-consulta.php')) ?>" hx-include="#cliente-form"
                            hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-disabled-elt="this">Consultar</button>
                </div>
                <small class="nc-help">Digite o CNPJ e consulte para preencher os dados automaticamente (CNPJá).</small>
            </div>

            <div class="nc-field">
                <label for="cf-name">Razão social <span class="nc-req" aria-hidden="true">*</span></label>
                <input class="nc-input" id="cf-name" name="name" value="<?= e($v['name']) ?>" required maxlength="190" autocomplete="off">
            </div>
            <div class="nc-field">
                <label for="cf-alias">Nome fantasia</label>
                <input class="nc-input" id="cf-alias" name="alias" value="<?= e($v['alias']) ?>" maxlength="190" autocomplete="off">
            </div>

            <div class="nc-field-grid">
                <div class="nc-field">
                    <label for="cf-email">E-mail</label>
                    <input class="nc-input" id="cf-email" type="email" name="email" value="<?= e($v['email']) ?>" maxlength="190" autocomplete="off">
                </div>
                <div class="nc-field">
                    <label for="cf-phone">Telefone</label>
                    <input class="nc-input" id="cf-phone" name="phone" value="<?= e($v['phone']) ?>" maxlength="80" autocomplete="off">
                </div>
            </div>

            <div class="nc-field-grid nc-cols-3">
                <div class="nc-field">
                    <label for="cf-zip">CEP</label>
                    <input class="nc-input" id="cf-zip" name="zip" value="<?= e($v['zip']) ?>" maxlength="20" autocomplete="off">
                </div>
                <div class="nc-field nc-span-2">
                    <label for="cf-street">Logradouro</label>
                    <input class="nc-input" id="cf-street" name="street" value="<?= e($v['street']) ?>" maxlength="190" autocomplete="off">
                </div>
            </div>
            <div class="nc-field-grid nc-cols-3">
                <div class="nc-field">
                    <label for="cf-number">Número</label>
                    <input class="nc-input" id="cf-number" name="address_number" value="<?= e($v['address_number']) ?>" maxlength="40" autocomplete="off">
                </div>
                <div class="nc-field">
                    <label for="cf-details">Complemento</label>
                    <input class="nc-input" id="cf-details" name="address_details" value="<?= e($v['address_details']) ?>" maxlength="190" autocomplete="off">
                </div>
                <div class="nc-field">
                    <label for="cf-district">Bairro</label>
                    <input class="nc-input" id="cf-district" name="district" value="<?= e($v['district']) ?>" maxlength="120" autocomplete="off">
                </div>
            </div>
            <div class="nc-field-grid nc-cols-3">
                <div class="nc-field nc-span-2">
                    <label for="cf-city">Cidade</label>
                    <input class="nc-input" id="cf-city" name="city" value="<?= e($v['city']) ?>" maxlength="120" autocomplete="off">
                </div>
                <div class="nc-field">
                    <label for="cf-state">UF</label>
                    <input class="nc-input" id="cf-state" name="state" value="<?= e($v['state']) ?>" maxlength="2" autocomplete="off">
                </div>
            </div>

            <div class="nc-field-grid">
                <div class="nc-field">
                    <label for="cf-industry">Segmento</label>
                    <input class="nc-input" id="cf-industry" name="industry" value="<?= e($v['industry']) ?>" maxlength="120" autocomplete="off">
                </div>
                <div class="nc-field">
                    <label for="cf-owner">Responsável</label>
                    <select class="nc-input" id="cf-owner" name="owner_user_id">
                        <option value="">Sem responsável</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"<?= (string)$v['owner_user_id'] === (string)$u['id'] ? ' selected' : '' ?>><?= e((string)$u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="nc-form-actions">
                <button type="button" class="nc-btn nc-btn-secondary" data-nc-drawer-close>Cancelar</button>
                <button type="submit" class="nc-btn nc-btn-primary">Salvar cliente</button>
            </div>
        </form>
    </div>
    <?php
}
