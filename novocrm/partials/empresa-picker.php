<?php
declare(strict_types=1);

/**
 * Seletor de empresa com autocomplete (nome ou CNPJ). O valor enviado é só o ID escolhido
 * (campo oculto); o texto digitado serve apenas para buscar, nunca é gravado como empresa.
 *
 * @param array{id:int|string,name:string,cnpj?:?string}|null $selected
 */
function nc_company_picker(string $fieldId, ?array $selected = null, string $label = 'Empresa', bool $required = true): void
{
    $selId = $selected ? (int)$selected['id'] : 0;
    $selName = $selected ? (string)$selected['name'] : '';
    $selCnpj = $selected && !empty($selected['cnpj']) ? format_cnpj((string)$selected['cnpj']) : '';
    ?>
    <div class="nc-field nc-picker" data-nc-picker>
        <label for="<?= e($fieldId) ?>-q"><?= e($label) ?><?php if ($required): ?> <span class="nc-req" aria-hidden="true">*</span><?php endif; ?></label>
        <input type="hidden" name="company_id" value="<?= $selId > 0 ? $selId : '' ?>" data-nc-picker-value>
        <div class="nc-picker-chosen" data-nc-picker-chosen<?= $selId > 0 ? '' : ' hidden' ?>>
            <span data-nc-picker-label><?= e($selName . ($selCnpj !== '' ? ' · ' . $selCnpj : '')) ?></span>
            <button type="button" class="nc-btn nc-btn-secondary nc-btn-sm" data-nc-picker-clear>Trocar</button>
        </div>
        <div data-nc-picker-search<?= $selId > 0 ? ' hidden' : '' ?>>
            <input class="nc-input" id="<?= e($fieldId) ?>-q" type="search" name="q" autocomplete="off" spellcheck="false"
                   placeholder="Digite o nome ou o CNPJ da empresa" role="combobox" aria-expanded="false" aria-controls="<?= e($fieldId) ?>-list"
                   data-nc-picker-input
                   hx-get="<?= e(nc_url('acoes/empresa-busca.php')) ?>" hx-trigger="input changed delay:250ms, search"
                   hx-target="#<?= e($fieldId) ?>-list" hx-swap="innerHTML" hx-sync="this:replace">
            <div class="nc-picker-list" id="<?= e($fieldId) ?>-list" role="listbox" aria-label="Empresas encontradas" aria-live="polite"></div>
        </div>
        <small class="nc-help">Escolha uma empresa da lista. Não é possível digitar um nome livre.</small>
    </div>
    <?php
}
