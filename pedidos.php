<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$user = require_auth();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'create');

    try {
        if ($action === 'create') {
            $number = trim((string)($_POST['number'] ?? ''));
            $date = (string)($_POST['date'] ?? date('Y-m-d'));
            $client = trim((string)($_POST['client_name'] ?? ''));
            $seller = ($_POST['seller_user_id'] ?? '') !== '' ? (int)$_POST['seller_user_id'] : null;
            $support = ($_POST['support_user_id'] ?? '') !== '' ? (int)$_POST['support_user_id'] : null;
            $line = (int)($_POST['product_line_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $modality = (string)($_POST['modality'] ?? 'revenda');
            $margin = (float)($_POST['margin_amount'] ?? 0);
            $invoiceDate = trim((string)($_POST['invoice_date'] ?? '')) ?: null;
            $invoiced = $invoiceDate ? 1 : 0;

            if ($number === '' || $client === '' || $line <= 0) {
                throw new RuntimeException('Informe número, cliente e linha de produto.');
            }
            if (!valid_date_string($date)) {
                throw new RuntimeException('Informe uma data válida.');
            }
            if ($invoiceDate !== null && !valid_date_string($invoiceDate)) {
                throw new RuntimeException('Informe uma data de faturamento válida.');
            }
            if (!in_array($modality, ['revenda','agenciada'], true)) {
                throw new RuntimeException('Modalidade inválida.');
            }

            $q = $pdo->prepare(
                'INSERT INTO orders
                (number,order_date,client_name,seller_user_id,support_user_id,product_line_id,amount,modality,margin_amount,invoiced,invoice_date,created_by)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $q->execute([
                $number,
                $date,
                $client,
                $seller,
                $support,
                $line,
                $amount,
                $modality,
                $margin,
                $invoiced,
                $invoiceDate,
                (int)$user['id'],
            ]);

            $id = (int)$pdo->lastInsertId();
            audit($pdo, (int)$user['id'], 'create', 'order', $id, ['number'=>$number]);
            flash('success', 'Pedido registrado.');
        } elseif ($action === 'invoice') {
            $id = (int)($_POST['id'] ?? 0);
            $date = trim((string)($_POST['invoice_date'] ?? ''));
            if (!valid_date_string($date)) {
                throw new RuntimeException('Informe a data de faturamento.');
            }

            $pdo->prepare('UPDATE orders SET invoiced=1,invoice_date=? WHERE id=?')->execute([$date,$id]);
            audit($pdo, (int)$user['id'], 'invoice', 'order', $id, ['invoice_date'=>$date]);
            flash('success', 'Faturamento confirmado.');
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect('pedidos.php');
}

$users = $pdo->query("SELECT id,name FROM users WHERE active=1 ORDER BY name")->fetchAll();
$lines = $pdo->query("SELECT id,name FROM product_lines WHERE active=1 ORDER BY sort_order")->fetchAll();
$rows = $pdo->query(
    "SELECT o.*,pl.name line_name,u.name seller_name
     FROM orders o
     JOIN product_lines pl ON pl.id=o.product_line_id
     LEFT JOIN users u ON u.id=o.seller_user_id
     ORDER BY o.order_date DESC,o.id DESC
     LIMIT 300"
)->fetchAll();

page_header('Pedidos','pedidos');
?>

<div class="page-actions">
    <div>
        <span class="eyebrow">PEDIDOS</span>
        <h2>Novo pedido de venda</h2>
        <p>O faturamento é confirmado nesta tela, separado da oportunidade. Comissões individuais são calculadas pelas políticas configuradas por pessoa.</p>
    </div>
</div>

<div class="card">
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <label><span>Nº do pedido</span><input name="number" required></label>
        <label><span>Data</span><input type="date" name="date" value="<?= date('Y-m-d') ?>" required></label>
        <label class="span-2"><span>Cliente</span><input name="client_name" required></label>

        <label>
            <span>Vendedor</span>
            <select name="seller_user_id">
                <option value="">—</option>
                <?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?>
            </select>
        </label>

        <label>
            <span>Suporte</span>
            <select name="support_user_id">
                <option value="">—</option>
                <?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?>
            </select>
        </label>

        <label>
            <span>Linha de produto</span>
            <select name="product_line_id" required>
                <option value="">— selecione —</option>
                <?php foreach($lines as $line): ?><option value="<?= (int)$line['id'] ?>"><?= e($line['name']) ?></option><?php endforeach; ?>
            </select>
        </label>

        <label>
            <span>Modalidade</span>
            <select name="modality">
                <option value="revenda">Revenda</option>
                <option value="agenciada">Agenciada</option>
            </select>
        </label>

        <label><span>Valor (R$)</span><input type="number" step="0.01" min="0" name="amount" required></label>
        <label><span>Margem (R$)</span><input type="number" step="0.01" name="margin_amount"></label>
        <label><span>Data de faturamento</span><input type="date" name="invoice_date"></label>

        <div class="span-2 form-actions">
            <button class="btn btn-primary" type="submit">Registrar pedido</button>
        </div>
    </form>
</div>

<div class="info-panel">
    <strong>Comissão não é digitada no pedido</strong>
    <p>O cálculo do colaborador utiliza a política configurada em Comissões. Em negócios agenciados, a base de comissão DNB fica reservada para sincronizações/importações, sem campo manual nesta tela.</p>
</div>

<div class="card">
    <div class="card-title">
        <div>
            <span class="tag">PEDIDOS</span>
            <h3>Registros (<?= count($rows) ?>)</h3>
        </div>
    </div>

    <?php if(!$rows): ?>
        <?php empty_state('Nenhum pedido','Não há pedidos de exemplo. Os pedidos reais aparecerão aqui.'); ?>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Nº</th>
                        <th>Cliente</th>
                        <th>Vendedor</th>
                        <th>Linha</th>
                        <th>Modalidade</th>
                        <th>Valor</th>
                        <th>Faturamento</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($rows as $row): ?>
                    <tr>
                        <td><?= e($row['order_date']) ?></td>
                        <td class="mono"><?= e($row['number']) ?></td>
                        <td><strong><?= e($row['client_name']) ?></strong></td>
                        <td><?= e($row['seller_name'] ?: '—') ?></td>
                        <td><?= e($row['line_name']) ?></td>
                        <td><?= e($row['modality']==='agenciada' ? 'Agenciada' : 'Revenda') ?></td>
                        <td><?= money($row['amount']) ?></td>
                        <td>
                            <?php if((int)$row['invoiced']): ?>
                                <span class="status status-ganha">Faturado</span>
                                <small><?= e($row['invoice_date']) ?></small>
                            <?php else: ?>
                                <form method="post" class="cell-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="invoice">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <input type="date" name="invoice_date" value="<?= date('Y-m-d') ?>" required>
                                    <button class="link-btn" type="submit">Confirmar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php page_footer(); ?>
