<?php
declare(strict_types=1);

require_once __DIR__ . '/empresa-picker.php';

/**
 * Formulário "Novo contato" (drawer). A empresa vem do autocomplete (company_id), nunca de texto livre.
 *
 * @param array{name?:string,email?:string,phone?:string,job_title?:string,company?:?array} $v
 */
function nc_contact_form(array $v = [], string $error = ''): void
{
    $v = array_merge(['name' => '', 'email' => '', 'phone' => '', 'job_title' => '', 'company' => null, 'return' => ''], $v);
    ?>
    <div data-drawer-title="Novo contato">
        <form id="contato-form" class="nc-form" method="post"
              hx-post="<?= e(nc_url('acoes/contato-salvar.php')) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML"
              hx-disabled-elt="find button[type=submit]">
            <?php if ($error !== ''): ?><div class="nc-alert nc-alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
            <input type="hidden" name="return" value="<?= e((string)$v['return']) ?>">

            <div class="nc-field">
                <label for="ct-name">Nome <span class="nc-req" aria-hidden="true">*</span></label>
                <input class="nc-input" id="ct-name" name="name" value="<?= e((string)$v['name']) ?>" required maxlength="160" autocomplete="off">
            </div>
            <div class="nc-field">
                <label for="ct-job">Cargo</label>
                <input class="nc-input" id="ct-job" name="job_title" value="<?= e((string)$v['job_title']) ?>" maxlength="120" autocomplete="off">
            </div>
            <div class="nc-field-grid">
                <div class="nc-field">
                    <label for="ct-email">E-mail</label>
                    <input class="nc-input" id="ct-email" type="email" name="email" value="<?= e((string)$v['email']) ?>" maxlength="190" autocomplete="off">
                </div>
                <div class="nc-field">
                    <label for="ct-phone">Telefone</label>
                    <input class="nc-input" id="ct-phone" name="phone" value="<?= e((string)$v['phone']) ?>" maxlength="60" autocomplete="off">
                </div>
            </div>

            <?php nc_company_picker('ct-company', is_array($v['company']) ? $v['company'] : null); ?>

            <div class="nc-form-actions">
                <button type="button" class="nc-btn nc-btn-secondary" data-nc-drawer-close>Cancelar</button>
                <button type="submit" class="nc-btn nc-btn-primary">Salvar contato</button>
            </div>
        </form>
    </div>
    <?php
}

/**
 * Drawer "Vincular à empresa…" para contatos sem empresa (ação em lote de admin/gestor).
 *
 * @param int[] $ids
 */
function nc_contact_link_form(array $ids, string $error = ''): void
{
    ?>
    <div data-drawer-title="Vincular contatos à empresa">
        <form class="nc-form" method="post" hx-post="<?= e(nc_url('acoes/contato-vincular.php')) ?>"
              hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <?php if ($error !== ''): ?><div class="nc-alert nc-alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
            <p><?= count($ids) ?> contato<?= count($ids) === 1 ? '' : 's' ?> será<?= count($ids) === 1 ? '' : 'ão' ?> vinculado<?= count($ids) === 1 ? '' : 's' ?> à empresa escolhida.</p>
            <?php foreach ($ids as $id): ?><input type="hidden" name="ids[]" value="<?= (int)$id ?>"><?php endforeach; ?>
            <?php nc_company_picker('ln-company'); ?>
            <div class="nc-form-actions">
                <button type="button" class="nc-btn nc-btn-secondary" data-nc-drawer-close>Cancelar</button>
                <button type="submit" class="nc-btn nc-btn-primary">Vincular</button>
            </div>
        </form>
    </div>
    <?php
}
