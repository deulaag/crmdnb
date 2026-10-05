<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$user = require_auth();
$pdo = db();
ensure_commission_policy_schema($pdo);

$month = valid_month($_GET['mes'] ?? null);
$definitions = commission_policy_definitions();

function commission_derived_proposal_target(array $policy): float
{
    $key = (string)($policy['policy_key'] ?? 'sem_politica');
    $p = $policy['params'] ?? [];

    if ($key === 'consultor_senior') {
        return (float)($p['metaPropostasMes'] ?? 0);
    }

    if ($key === 'representante') {
        $total = 0.0;
        if ((float)($p['metaCompressores'] ?? 0) > 0 && (float)($p['convEquip'] ?? 0) > 0) {
            $total += (float)$p['metaCompressores'] / ((float)$p['convEquip'] / 100);
        }
        if ((float)($p['metaAcessorios'] ?? 0) > 0 && (float)($p['convIR'] ?? 0) > 0) {
            $total += (float)$p['metaAcessorios'] / ((float)$p['convIR'] / 100);
        }
        return $total;
    }

    $total = 0.0;
    if ($key === 'interno') {
        if ((float)($p['metaRevenda'] ?? 0) > 0 && (float)($p['convDemais'] ?? 0) > 0) {
            $total += (float)$p['metaRevenda'] / ((float)$p['convDemais'] / 100);
        }
        return $total;
    }

    if ((float)($p['metaRevenda'] ?? 0) > 0 && (float)($p['convIR'] ?? 0) > 0) {
        $total += (float)$p['metaRevenda'] / ((float)$p['convIR'] / 100);
    }
    if ((float)($p['metaAgenciadas'] ?? 0) > 0 && (float)($p['convEquip'] ?? 0) > 0) {
        $total += (float)$p['metaAgenciadas'] / ((float)$p['convEquip'] / 100);
    }

    return $total;
}

function commission_base_for_policy(array $policy, float $revenda, float $dnbCommission, float $margin): array
{
    $key = (string)($policy['policy_key'] ?? 'sem_politica');
    $p = $policy['params'] ?? [];

    if ($key === 'representante') {
        $rev = $margin * ((float)($p['pctMargem'] ?? 0) / 100);
        $ag = $dnbCommission * ((float)($p['pctComDNB'] ?? 0) / 100);
        return [$rev, $ag, $rev + $ag];
    }

    if ($key === 'consultor_senior') {
        $ag = $dnbCommission * ((float)($p['pctAgenciada'] ?? 0) / 100);
        return [0.0, $ag, $ag];
    }

    $rev = $revenda * ((float)($p['pctPecasServ'] ?? 0) / 100);
    $ag = $dnbCommission * ((float)($p['pctAgenciada'] ?? 0) / 100);
    return [$rev, $ag, $rev + $ag];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        if (($user['role'] ?? '') !== 'admin') {
            throw new RuntimeException('Somente o administrador pode alterar políticas de comissão.');
        }

        $action = (string)($_POST['action'] ?? '');
        if ($action !== 'save_policy') {
            throw new RuntimeException('Ação inválida.');
        }

        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $q = $pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1');
        $q->execute([$targetUserId]);
        if (!$q->fetchColumn()) {
            throw new RuntimeException('Usuário não encontrado.');
        }

        $policyKey = (string)($_POST['policy_key'] ?? 'sem_politica');
        $params = $_POST['params'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }

        save_user_commission_policy($pdo, $targetUserId, [
            'operation_role' => (string)($_POST['operation_role'] ?? 'vendedor'),
            'policy_key' => $policyKey,
            'params' => $params,
            'linked_user_id' => (string)($_POST['linked_user_id'] ?? ''),
            'linked_user_id2' => (string)($_POST['linked_user_id2'] ?? ''),
            'mentor_user_id' => (string)($_POST['mentor_user_id'] ?? ''),
            'hired_month' => (string)($_POST['hired_month'] ?? ''),
            'active_operation' => isset($_POST['active_operation']),
        ], (int)$user['id']);

        audit($pdo, (int)$user['id'], 'save_policy', 'user', $targetUserId, ['policy_key' => $policyKey]);
        flash('success', 'Política da pessoa atualizada.');
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect('comissoes.php?mes=' . rawurlencode($month));
}

$users = $pdo->query('SELECT id,name,email,role,active FROM users ORDER BY active DESC,name')->fetchAll();
$policies = [];
foreach ($users as $person) {
    $policies[(int)$person['id']] = user_commission_policy($pdo, (int)$person['id']);
}

$q = $pdo->prepare(
    "SELECT o.*,pl.is_agency
     FROM orders o
     JOIN product_lines pl ON pl.id=o.product_line_id
     WHERE o.invoiced=1 AND DATE_FORMAT(o.invoice_date,'%Y-%m')=?"
);
$q->execute([$month]);
$orders = $q->fetchAll();

$summary = [];
foreach ($users as $person) {
    $id = (int)$person['id'];
    $policy = $policies[$id];
    $linked = array_values(array_filter([
        (int)($policy['linked_user_id'] ?? 0),
        (int)($policy['linked_user_id2'] ?? 0),
    ]));

    $relevant = array_filter($orders, static function(array $order) use ($id, $policy, $linked): bool {
        if (($policy['operation_role'] ?? 'vendedor') === 'suporte') {
            return (int)($order['support_user_id'] ?? 0) === $id
                || (int)($order['seller_user_id'] ?? 0) === $id
                || in_array((int)($order['seller_user_id'] ?? 0), $linked, true);
        }
        return (int)($order['seller_user_id'] ?? 0) === $id;
    });

    $revenda = 0.0;
    $agenciado = 0.0;
    $dnbCommission = 0.0;
    $margin = 0.0;

    foreach ($relevant as $order) {
        if (($order['modality'] ?? 'revenda') === 'agenciada') {
            $agenciado += (float)$order['amount'];
            $dnbCommission += (float)$order['dnb_commission'];
        } else {
            $revenda += (float)$order['amount'];
            $margin += (float)$order['margin_amount'];
        }
    }

    [$commissionRevenda, $commissionAgency, $baseTotal] = commission_base_for_policy($policy, $revenda, $dnbCommission, $margin);

    $summary[$id] = [
        'orders' => count($relevant),
        'revenda' => $revenda,
        'agenciado' => $agenciado,
        'dnb_commission' => $dnbCommission,
        'margin' => $margin,
        'commission_revenda' => $commissionRevenda,
        'commission_agency' => $commissionAgency,
        'base_total' => $baseTotal,
    ];
}

page_header('Comissões','comissoes');
?>

<div class="page-actions">
    <div>
        <span class="eyebrow">APURAÇÃO</span>
        <h2>Comissões e políticas</h2>
        <p>As regras ficam vinculadas às pessoas cadastradas no CRM. Nenhum usuário ou parâmetro demonstrativo é criado automaticamente.</p>
    </div>
    <form method="get" class="inline-form">
        <label>
            <span>Mês de referência</span>
            <input type="month" name="mes" value="<?= e($month) ?>" onchange="this.form.submit()">
        </label>
    </form>
</div>

<div class="card">
    <div class="card-title">
        <div>
            <span class="tag">RESULTADO</span>
            <h3>Base do mês</h3>
            <p>Somente pedidos marcados como faturados entram nesta apuração. A comissão DNB de agenciamentos é um dado interno de integração, não um campo manual em Orçamentos/Pedidos.</p>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Pessoa · política</th>
                    <th>Pedidos faturados</th>
                    <th>Revenda</th>
                    <th>Agenciadas</th>
                    <th>Comissão base revenda</th>
                    <th>Comissão base agenciada</th>
                    <th>Base calculada</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $person):
                $id = (int)$person['id'];
                $policy = $policies[$id];
                $s = $summary[$id];
                $definition = $definitions[$policy['policy_key']] ?? $definitions['sem_politica'];
            ?>
                <tr>
                    <td>
                        <strong><?= e($person['name']) ?></strong>
                        <small><?= e($definition['label']) ?></small>
                    </td>
                    <td><?= (int)$s['orders'] ?></td>
                    <td><?= money($s['revenda']) ?></td>
                    <td><?= money($s['agenciado']) ?></td>
                    <td><?= money($s['commission_revenda']) ?></td>
                    <td>
                        <?= money($s['commission_agency']) ?>
                        <?php if ($s['agenciado'] > 0 && $s['dnb_commission'] <= 0): ?>
                            <small>aguardando base de comissão DNB da integração</small>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= money($s['base_total']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?>
                <tr><td colspan="7" class="empty-row">Nenhum usuário cadastrado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="info-panel">
        <strong>Regra de segurança do cálculo</strong>
        <p>Os campos manuais de comissão foram removidos do fluxo comercial. Valores de comissão DNB referentes a agenciamentos ficam reservados para importações/integradores. Bonificações, faixas, tetos e KPIs usam os parâmetros abaixo.</p>
    </div>
</div>

<div class="card">
    <div class="card-title">
        <div>
            <span class="tag">CONFIGURAÇÃO</span>
            <h3>Políticas por pessoa (parâmetros editáveis)</h3>
            <p>Modelo trazido do HTML de referência, aplicado somente aos usuários reais cadastrados no novo CRM.</p>
        </div>
    </div>

    <?php foreach ($users as $person):
        $id = (int)$person['id'];
        $policy = $policies[$id];
        $definition = $definitions[$policy['policy_key']] ?? $definitions['sem_politica'];
        $fields = commission_policy_field_labels($policy['policy_key']);
        $derivedTarget = commission_derived_proposal_target($policy);
        $canEdit = ($user['role'] ?? '') === 'admin';
    ?>
        <form method="post" class="policy-person-card" data-no-draft>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_policy">
            <input type="hidden" name="user_id" value="<?= $id ?>">

            <div class="policy-person-head">
                <div>
                    <strong><?= e($person['name']) ?></strong>
                    <span><?= e($person['email']) ?></span>
                </div>

                <label class="toggle">
                    <input type="checkbox" name="active_operation" value="1" <?= $policy['active_operation'] ? 'checked' : '' ?> <?= !$canEdit ? 'disabled' : '' ?>>
                    <span><?= $policy['active_operation'] ? 'ativo na operação' : 'fora da operação' ?></span>
                </label>
            </div>

            <div class="policy-controls">
                <label>
                    <span>Função</span>
                    <select name="operation_role" <?= !$canEdit ? 'disabled' : '' ?>>
                        <option value="vendedor" <?= $policy['operation_role']==='vendedor'?'selected':'' ?>>vendedor</option>
                        <option value="suporte" <?= $policy['operation_role']==='suporte'?'selected':'' ?>>suporte</option>
                    </select>
                </label>

                <label class="policy-control-wide">
                    <span>Política</span>
                    <select name="policy_key" <?= !$canEdit ? 'disabled' : '' ?>>
                        <?php foreach ($definitions as $key => $def): ?>
                            <option value="<?= e($key) ?>" <?= $policy['policy_key']===$key?'selected':'' ?>><?= e($def['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="policy-fields">
                <?php foreach ($fields as $key => $label): ?>
                    <label>
                        <span><?= e($label) ?></span>
                        <input
                            type="number"
                            step="0.01"
                            name="params[<?= e($key) ?>]"
                            value="<?= e((string)($policy['params'][$key] ?? '')) ?>"
                            <?= !$canEdit ? 'disabled' : '' ?>
                        >
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="policy-relations">
                <?php if ($policy['operation_role'] === 'suporte'): ?>
                    <label>
                        <span>Suporte vinculado a</span>
                        <select name="linked_user_id" <?= !$canEdit ? 'disabled' : '' ?>>
                            <option value="">— sem vínculo (vendas próprias) —</option>
                            <?php foreach ($users as $candidate): if ((int)$candidate['id'] === $id) continue; ?>
                                <option value="<?= (int)$candidate['id'] ?>" <?= (int)$policy['linked_user_id']===(int)$candidate['id']?'selected':'' ?>><?= e($candidate['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span>Vínculo adicional (opcional)</span>
                        <select name="linked_user_id2" <?= !$canEdit ? 'disabled' : '' ?>>
                            <option value="">—</option>
                            <?php foreach ($users as $candidate): if ((int)$candidate['id'] === $id) continue; ?>
                                <option value="<?= (int)$candidate['id'] ?>" <?= (int)$policy['linked_user_id2']===(int)$candidate['id']?'selected':'' ?>><?= e($candidate['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>

                <label>
                    <span>Contratação (ativa a rampa de 6 meses)</span>
                    <input type="month" name="hired_month" value="<?= e($policy['hired_month']) ?>" <?= !$canEdit ? 'disabled' : '' ?>>
                </label>

                <label>
                    <span>Orientado por (Consultor Sênior)</span>
                    <select name="mentor_user_id" <?= !$canEdit ? 'disabled' : '' ?>>
                        <option value="">—</option>
                        <?php foreach ($users as $candidate):
                            if ((int)$candidate['id'] === $id) continue;
                            $candidatePolicy = $policies[(int)$candidate['id']];
                            if ($candidatePolicy['policy_key'] !== 'consultor_senior') continue;
                        ?>
                            <option value="<?= (int)$candidate['id'] ?>" <?= (int)$policy['mentor_user_id']===(int)$candidate['id']?'selected':'' ?>><?= e($candidate['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="policy-derived">
                    <span>Meta de propostas derivada</span>
                    <strong><?= money($derivedTarget) ?>/mês</strong>
                </div>
            </div>

            <?php if ($canEdit): ?>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Salvar política desta pessoa</button>
                </div>
            <?php endif; ?>
        </form>
    <?php endforeach; ?>

    <?php if (!$users): ?>
        <?php empty_state('Nenhuma pessoa cadastrada','Cadastre usuários para configurar as políticas individuais.'); ?>
    <?php endif; ?>

    <div class="info-panel">
        <strong>Importante</strong>
        <p>Alterar parâmetros aqui muda os cálculos correntes e seguintes. Mudanças formais de política devem continuar documentadas pela empresa.</p>
    </div>
</div>

<?php page_footer(); ?>
