<?php
declare(strict_types=1);

/** Valores padrão do formulário de pedido. */
function nc_pedido_defaults(): array
{
    return [
        'number' => '', 'date' => date('Y-m-d'), 'client_name' => '', 'seller_user_id' => '', 'support_user_id' => '',
        'product_line_id' => '', 'modality' => 'revenda', 'amount' => '', 'margin_amount' => '', 'invoice_date' => '',
    ];
}

function nc_pedido_from_post(): array
{
    $v = nc_pedido_defaults();
    foreach ($v as $key => $_) {
        $v[$key] = trim((string)($_POST[$key] ?? ''));
    }
    return $v;
}

/** Formulário "Novo pedido" (drawer). Mesmos campos de pedidos.php (action create). */
function nc_pedido_form(PDO $pdo, array $v, string $error = ''): void
{
    $v = array_merge(nc_pedido_defaults(), $v);
    $users = nc_users($pdo);
    $lines = $pdo->query('SELECT id,name FROM product_lines WHERE active=1 ORDER BY sort_order')->fetchAll();
    ?>
    <div data-drawer-title="Novo pedido">
        <form id="pedido-form" class="nc-form" method="post"
              hx-post="<?= e(nc_url('acoes/pedido-salvar.php')) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML"
              hx-disabled-elt="find button[type=submit]">
            <?php if ($error !== ''): ?><div class="nc-alert nc-alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>

            <div class="nc-alert">
                <strong>Comissão não é digitada no pedido.</strong>
                O cálculo do colaborador usa a política configurada em Comissões. Em negócios agenciados, a base de comissão DNB fica reservada para sincronizações e importações, sem campo manual nesta tela. O faturamento é confirmado separado da oportunidade.
            </div>

            <div class="nc-field-grid">
                <div class="nc-field">
                    <label for="pf-number">Nº do pedido <span class="nc-req" aria-hidden="true">*</span></label>
                    <input class="nc-input" id="pf-number" name="number" value="<?= e($v['number']) ?>" required maxlength="80" autocomplete="off">
                </div>
                <div class="nc-field">
                    <label for="pf-date">Data <span class="nc-req" aria-hidden="true">*</span></label>
                    <input class="nc-input" id="pf-date" type="date" name="date" value="<?= e($v['date']) ?>" required>
                </div>
            </div>
            <div class="nc-field">
                <label for="pf-client">Cliente <span class="nc-req" aria-hidden="true">*</span></label>
                <input class="nc-input" id="pf-client" name="client_name" value="<?= e($v['client_name']) ?>" required maxlength="190" autocomplete="off">
            </div>
            <div class="nc-field-grid">
                <div class="nc-field">
                    <label for="pf-seller">Vendedor</label>
                    <select class="nc-input" id="pf-seller" name="seller_user_id">
                        <option value="">—</option>
                        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $v['seller_user_id'] === (string)$u['id'] ? ' selected' : '' ?>><?= e((string)$u['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="nc-field">
                    <label for="pf-support">Suporte</label>
                    <select class="nc-input" id="pf-support" name="support_user_id">
                        <option value="">—</option>
                        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $v['support_user_id'] === (string)$u['id'] ? ' selected' : '' ?>><?= e((string)$u['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="nc-field-grid">
                <div class="nc-field">
                    <label for="pf-line">Linha de produto <span class="nc-req" aria-hidden="true">*</span></label>
                    <select class="nc-input" id="pf-line" name="product_line_id" required>
                        <option value="">Selecione…</option>
                        <?php foreach ($lines as $l): ?><option value="<?= (int)$l['id'] ?>"<?= $v['product_line_id'] === (string)$l['id'] ? ' selected' : '' ?>><?= e((string)$l['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="nc-field">
                    <label for="pf-modality">Modalidade</label>
                    <select class="nc-input" id="pf-modality" name="modality">
                        <option value="revenda"<?= $v['modality'] === 'revenda' ? ' selected' : '' ?>>Revenda</option>
                        <option value="agenciada"<?= $v['modality'] === 'agenciada' ? ' selected' : '' ?>>Agenciada</option>
                    </select>
                </div>
            </div>
            <div class="nc-field-grid nc-cols-3">
                <div class="nc-field">
                    <label for="pf-amount">Valor (R$) <span class="nc-req" aria-hidden="true">*</span></label>
                    <input class="nc-input" id="pf-amount" type="number" step="0.01" min="0" name="amount" value="<?= e($v['amount']) ?>" required>
                </div>
                <div class="nc-field">
                    <label for="pf-margin">Margem (R$)</label>
                    <input class="nc-input" id="pf-margin" type="number" step="0.01" name="margin_amount" value="<?= e($v['margin_amount']) ?>">
                </div>
                <div class="nc-field">
                    <label for="pf-invoice">Data de faturamento</label>
                    <input class="nc-input" id="pf-invoice" type="date" name="invoice_date" value="<?= e($v['invoice_date']) ?>">
                </div>
            </div>
            <small class="nc-help">Preencher a data de faturamento já registra o pedido como faturado.</small>

            <div class="nc-form-actions">
                <button type="button" class="nc-btn nc-btn-secondary" data-nc-drawer-close>Cancelar</button>
                <button type="submit" class="nc-btn nc-btn-primary">Registrar pedido</button>
            </div>
        </form>
    </div>
    <?php
}

/** Ficha do pedido (drawer/página): dados e itens opcionais. */
function nc_pedido_ficha(PDO $pdo, array $o): void
{
    $id = (int)$o['id'];
    $hasItems = nc_table_exists($pdo, 'order_items');
    $items = [];
    if ($hasItems) {
        $q = $pdo->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');
        $q->execute([$id]);
        $items = $q->fetchAll();
    }
    $itemsTotal = array_sum(array_map(static fn($i) => (float)$i['subtotal'], $items));
    $itemUrl = nc_url('acoes/pedido-item.php');
    ?>
    <div id="pedido-ficha" class="nc-ficha" data-drawer-title="Pedido <?= e((string)$o['number']) ?>">
        <header class="nc-ficha-head">
            <div>
                <p class="nc-muted nc-num-left">Pedido <?= e((string)$o['number']) ?></p>
                <h2 class="nc-ficha-title"><?= e((string)$o['client_name']) ?></h2>
                <p class="nc-ficha-amount nc-num"><?= e(money($o['amount'])) ?></p>
            </div>
            <div class="nc-ficha-badges">
                <?= (int)$o['invoiced'] === 1 ? nc_badge('Faturado', 'success') : nc_badge('A faturar', 'warning') ?>
                <?= nc_badge($o['modality'] === 'agenciada' ? 'Agenciada' : 'Revenda', $o['modality'] === 'agenciada' ? 'info' : 'neutral') ?>
            </div>
        </header>

        <dl class="nc-dl">
            <div><dt>Data</dt><dd><?= e(nc_date((string)$o['order_date'])) ?></dd></div>
            <div><dt>Linha</dt><dd><?= e((string)$o['line_name']) ?></dd></div>
            <div><dt>Vendedor</dt><dd><?= e((string)($o['seller_name'] ?: '—')) ?></dd></div>
            <div><dt>Suporte</dt><dd><?= e((string)($o['support_name'] ?: '—')) ?></dd></div>
            <div><dt>Comissão DNB</dt><dd class="nc-num"><?= e(money($o['dnb_commission'])) ?></dd></div>
            <div><dt>Margem</dt><dd class="nc-num"><?= e(money($o['margin_amount'])) ?></dd></div>
            <div><dt>Faturamento</dt><dd><?= (int)$o['invoiced'] === 1 ? e(nc_date((string)$o['invoice_date'])) : 'Ainda não faturado' ?></dd></div>
            <?php if (!empty($o['opportunity_number'])): ?><div><dt>Oportunidade</dt><dd><?= e((string)$o['opportunity_number']) ?></dd></div><?php endif; ?>
        </dl>

        <?php if ((int)$o['invoiced'] !== 1): ?>
            <form class="nc-inline-fields" method="post" hx-post="<?= e(nc_url('acoes/pedido-faturar.php')) ?>" hx-swap="none">
                <input type="hidden" name="id" value="<?= $id ?>">
                <label class="nc-sr-only" for="fat-date">Data de faturamento</label>
                <input class="nc-input" id="fat-date" type="date" name="invoice_date" value="<?= date('Y-m-d') ?>" required>
                <button class="nc-btn nc-btn-primary" type="submit">Confirmar faturamento</button>
            </form>
        <?php endif; ?>

        <section class="nc-ficha-section" aria-label="Itens do pedido">
            <h3>Itens <span class="nc-tab-count"><?= count($items) ?></span></h3>
            <?php if (!$hasItems): ?>
                <p class="nc-muted">Itens detalhados ficam disponíveis após aplicar as migrações do NOVOCRM.</p>
            <?php else: ?>
                <p class="nc-muted">Opcional. O valor do pedido continua sendo o informado acima<?= $items && abs($itemsTotal - (float)$o['amount']) > 0.009 ? '; a soma dos itens (' . e(money($itemsTotal)) . ') é diferente' : '' ?>.</p>
                <?php if ($items): ?>
                    <div class="nc-table-wrap nc-flat">
                        <table class="nc-table">
                            <thead><tr><th>Descrição</th><th class="is-num">Qtd.</th><th class="is-num">Unitário</th><th class="is-num">Subtotal</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($items as $i): ?>
                                <tr>
                                    <td><?= e((string)$i['description']) ?><?= $i['sku'] ? '<br><small class="nc-muted">' . e((string)$i['sku']) . '</small>' : '' ?></td>
                                    <td class="is-num"><?= e(rtrim(rtrim(number_format((float)$i['quantity'], 2, ',', '.'), '0'), ',')) ?></td>
                                    <td class="is-num"><?= e(money($i['unit_price'])) ?></td>
                                    <td class="is-num"><?= e(money($i['subtotal'])) ?></td>
                                    <td class="is-actions">
                                        <button class="nc-btn nc-btn-danger-outline" type="button" hx-post="<?= e($itemUrl) ?>" hx-vals='{"op":"delete","order_id":<?= $id ?>,"item_id":<?= (int)$i['id'] ?>}'
                                                hx-target="#pedido-ficha" hx-swap="outerHTML" data-nc-confirm="Remover este item do pedido?" data-nc-confirm-ok="Remover">Remover</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <form class="nc-form" method="post" hx-post="<?= e($itemUrl) ?>" hx-target="#pedido-ficha" hx-swap="outerHTML">
                    <input type="hidden" name="op" value="add"><input type="hidden" name="order_id" value="<?= $id ?>">
                    <div class="nc-field-grid">
                        <div class="nc-field"><label for="it-desc">Descrição</label><input class="nc-input" id="it-desc" name="description" required maxlength="255"></div>
                        <div class="nc-field"><label for="it-sku">Código / SKU</label><input class="nc-input" id="it-sku" name="sku" maxlength="80"></div>
                    </div>
                    <div class="nc-field-grid">
                        <div class="nc-field"><label for="it-qty">Quantidade</label><input class="nc-input" id="it-qty" type="number" name="quantity" step="0.01" min="0.01" value="1" required></div>
                        <div class="nc-field"><label for="it-price">Valor unitário (R$)</label><input class="nc-input" id="it-price" type="number" name="unit_price" step="0.01" min="0" required></div>
                    </div>
                    <div><button class="nc-btn nc-btn-secondary" type="submit">Adicionar item</button></div>
                </form>
            <?php endif; ?>
        </section>
    </div>
    <?php
}

function nc_pedido_load(PDO $pdo, int $id): array
{
    $q = $pdo->prepare(
        'SELECT o.*, pl.name line_name, u.name seller_name, s.name support_name, op.number opportunity_number
         FROM orders o
         JOIN product_lines pl ON pl.id=o.product_line_id
         LEFT JOIN users u ON u.id=o.seller_user_id
         LEFT JOIN users s ON s.id=o.support_user_id
         LEFT JOIN opportunities op ON op.id=o.opportunity_id
         WHERE o.id=? LIMIT 1'
    );
    $q->execute([$id]);
    $row = $q->fetch();
    if (!$row) {
        throw new RuntimeException('Pedido não encontrado.');
    }
    return $row;
}
