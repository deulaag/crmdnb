<?php
declare(strict_types=1);

require_once __DIR__ . '/../_empresas_merge.php';

/**
 * Carrega as empresas do grupo com contagens (contatos e oportunidades) e o nome do responsável.
 *
 * @param int[] $ids
 * @return array<int,array<string,mixed>> id => linha
 */
function nc_merge_load(PDO $pdo, array $ids): array
{
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare(
        "SELECT co.*, ou.name owner_name,
                (SELECT COUNT(*) FROM contacts ct WHERE ct.company_id = co.id) contact_qtd,
                (SELECT COUNT(*) FROM opportunities o WHERE o.company_id = co.id) opp_qtd
         FROM companies co LEFT JOIN users ou ON ou.id = co.owner_user_id
         WHERE co.id IN ($in) ORDER BY co.id"
    );
    $q->execute($ids);
    $rows = [];
    foreach ($q->fetchAll() as $r) {
        $rows[(int)$r['id']] = $r;
    }
    return $rows;
}

/** Valor para exibir na comparação lado a lado. */
function nc_merge_display(string $field, array $row): string
{
    $v = $field === 'owner_user_id' ? (string)($row['owner_name'] ?? '') : (string)($row[$field] ?? '');
    if ($field === 'cnpj' && trim($v) !== '') {
        return format_cnpj($v);
    }
    return trim($v);
}

/**
 * Drawer "Mesclar empresas": escolha da principal e, campo a campo, qual valor manter.
 * Trocar a principal recarrega o drawer (hx-get) com os padrões recalculados no servidor.
 *
 * @param array<int,array<string,mixed>> $rows
 * @param array<string,int> $pick
 */
function nc_merge_form(array $rows, int $principalId, array $pick, string $error = ''): void
{
    $ids = array_keys($rows);
    $idList = implode(',', $ids);
    $blocked = false;
    $cnpjs = [];
    foreach ($rows as $r) {
        $c = nc_valid_cnpj_or_empty((string)($r['cnpj'] ?? ''));
        if ($c !== '') {
            $cnpjs[$c] = true;
        }
    }
    $blocked = count($cnpjs) > 1;
    ?>
    <div data-drawer-title="Mesclar empresas">
        <form class="nc-form nc-merge" method="post" hx-post="<?= e(nc_url('acoes/empresa-mesclar.php')) ?>"
              hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <?php if ($error !== ''): ?><div class="nc-alert nc-alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
            <?php if ($blocked): ?>
                <div class="nc-alert nc-alert-warning" role="alert">CNPJs válidos diferentes: são empresas distintas (por exemplo matriz e filial). A mesclagem está bloqueada.</div>
            <?php endif; ?>
            <input type="hidden" name="ids" value="<?= e($idList) ?>">

            <fieldset class="nc-fieldset">
                <legend>Empresa principal (as demais serão mescladas nela e excluídas)</legend>
                <?php foreach ($rows as $id => $r): ?>
                    <label class="nc-radio-row">
                        <input type="radio" name="principal" value="<?= (int)$id ?>"<?= $id === $principalId ? ' checked' : '' ?>
                               hx-get="<?= e(nc_url('acoes/empresa-mesclar-form.php?ids=' . $idList . '&principal=' . (int)$id)) ?>"
                               hx-trigger="change" hx-target="#nc-drawer-body" hx-swap="innerHTML">
                        <span><strong><?= e((string)$r['name']) ?></strong>
                            <small class="nc-muted"><?= e(implode(' · ', array_filter([
                                trim((string)$r['cnpj']) !== '' ? format_cnpj((string)$r['cnpj']) : 'Sem CNPJ',
                                (int)$r['contact_qtd'] . ' contato(s)', (int)$r['opp_qtd'] . ' oportunidade(s)', nc_origin_label($r),
                            ]))) ?></small></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <p class="nc-help">Contatos e oportunidades das duplicadas passam para a principal. Em cada campo, escolha o valor que fica; campos vazios são completados com os das duplicadas.</p>

            <div class="nc-table-wrap">
                <table class="nc-table nc-merge-table">
                    <thead>
                        <tr><th>Campo</th><?php foreach ($rows as $id => $r): ?><th><?= $id === $principalId ? 'Principal' : 'Duplicada' ?> #<?= (int)$id ?></th><?php endforeach; ?></tr>
                    </thead>
                    <tbody>
                    <?php foreach (NC_MERGE_PICK_FIELDS as $field => $label): ?>
                        <tr>
                            <th scope="row"><?= e($label) ?></th>
                            <?php foreach ($rows as $id => $r):
                                $val = nc_merge_display($field, $r); ?>
                                <td>
                                    <?php if ($val === ''): ?>
                                        <span class="nc-muted">—</span>
                                    <?php else: ?>
                                        <label class="nc-radio-row">
                                            <input type="radio" name="pick[<?= e($field) ?>]" value="<?= (int)$id ?>"<?= ($pick[$field] ?? $principalId) === $id ? ' checked' : '' ?>>
                                            <span><?= e($val) ?></span>
                                        </label>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="nc-form-actions">
                <button type="button" class="nc-btn nc-btn-secondary" data-nc-drawer-close>Cancelar</button>
                <button type="submit" class="nc-btn nc-btn-primary"<?= $blocked ? ' disabled' : '' ?>
                        data-nc-confirm="As empresas duplicadas serão excluídas e seus contatos e oportunidades passarão para a principal. Esta ação não pode ser desfeita."
                        data-nc-confirm-title="Mesclar empresas" data-nc-confirm-ok="Mesclar">Mesclar empresas</button>
            </div>
        </form>
    </div>
    <?php
}
