<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$user = require_auth();
$pdo = db();
ensure_opportunity_visit_schema($pdo);

function opportunity_line_context(PDO $pdo, int $lineId): array
{
    $q = $pdo->prepare('SELECT * FROM product_lines WHERE id=? AND active=1');
    $q->execute([$lineId]);
    $line = $q->fetch();
    if (!$line) {
        throw new RuntimeException('Linha de produto inválida.');
    }

    $q = $pdo->prepare('SELECT id FROM funnels WHERE funnel_key=? AND active=1');
    $q->execute([$line['funnel_key']]);
    $funnelId = (int)$q->fetchColumn();
    if ($funnelId < 1) {
        throw new RuntimeException('Funil automático não encontrado para esta linha de produto.');
    }

    $q = $pdo->prepare('SELECT id FROM funnel_stages WHERE funnel_id=? ORDER BY sort_order LIMIT 1');
    $q->execute([$funnelId]);
    $stageId = (int)$q->fetchColumn();
    if ($stageId < 1) {
        throw new RuntimeException('O funil não possui etapa inicial configurada.');
    }

    return [$line, $funnelId, $stageId];
}

function opportunity_company_from_post(PDO $pdo, ?int $seller, ?int $currentCompanyId = null): ?int
{
    $cnpj = cnpj_digits((string)($_POST['client_cnpj'] ?? ''));
    if ($cnpj === '') {
        return $currentCompanyId;
    }
    if (!cnpj_is_valid($cnpj)) {
        throw new RuntimeException('O CNPJ do cliente é inválido.');
    }

    $lookupEntry = $_SESSION['cnpja_lookup'][$cnpj] ?? null;
    $lookupData = null;
    if (is_array($lookupEntry)
        && (int)($lookupEntry['expires_at'] ?? 0) >= time()
        && is_array($lookupEntry['data'] ?? null)
    ) {
        $lookupData = $lookupEntry['data'];
    }

    return company_upsert_from_customer_data($pdo, [
        'cnpj' => $cnpj,
        'name' => trim((string)($_POST['client_name'] ?? '')),
        'alias' => trim((string)($_POST['client_alias'] ?? '')),
        'registration_status' => trim((string)($_POST['client_registration_status'] ?? '')),
        'registration_status_date' => trim((string)($_POST['client_registration_status_date'] ?? '')),
        'founded' => trim((string)($_POST['client_founded'] ?? '')),
        'company_type' => trim((string)($_POST['client_company_type'] ?? '')),
        'company_size' => trim((string)($_POST['client_company_size'] ?? '')),
        'legal_nature' => trim((string)($_POST['client_legal_nature'] ?? '')),
        'capital_social' => trim((string)($_POST['client_capital_social'] ?? '')),
        'email' => trim((string)($_POST['client_email'] ?? '')),
        'phone' => trim((string)($_POST['client_phone'] ?? '')),
        'zip' => trim((string)($_POST['client_zip'] ?? '')),
        'street' => trim((string)($_POST['client_street'] ?? '')),
        'address_number' => trim((string)($_POST['client_address_number'] ?? '')),
        'address_details' => trim((string)($_POST['client_address_details'] ?? '')),
        'district' => trim((string)($_POST['client_district'] ?? '')),
        'industry' => trim((string)($_POST['client_industry'] ?? '')),
        'main_activity_code' => trim((string)($_POST['client_main_activity_code'] ?? '')),
        'city' => trim((string)($_POST['client_city'] ?? '')),
        'state' => trim((string)($_POST['client_state'] ?? '')),
        'cnpja_updated_at' => trim((string)($_POST['client_cnpja_updated_at'] ?? '')),
    ], $seller, is_array($lookupData) ? ($lookupData['raw'] ?? null) : null);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'create');

    try {
        if ($action === 'create' || $action === 'update') {
            $id = $action === 'update' ? (int)($_POST['id'] ?? 0) : 0;
            $current = null;

            if ($action === 'update') {
                $q = $pdo->prepare('SELECT * FROM opportunities WHERE id=? LIMIT 1');
                $q->execute([$id]);
                $current = $q->fetch();
                if (!$current) {
                    throw new RuntimeException('Oportunidade não encontrada.');
                }
            }

            $date = (string)($_POST['date'] ?? date('Y-m-d'));
            $client = trim((string)($_POST['client_name'] ?? ''));
            $seller = ($_POST['seller_user_id'] ?? '') !== '' ? (int)$_POST['seller_user_id'] : null;
            $support = ($_POST['support_user_id'] ?? '') !== '' ? (int)$_POST['support_user_id'] : null;
            $lineId = (int)($_POST['product_line_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $commission = (float)($_POST['dnb_commission_estimated'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));
            $visitId = $action === 'create'
                ? (int)($_POST['source_visit_id'] ?? 0)
                : (int)($current['visit_id'] ?? 0);

            if (!valid_date_string($date)) {
                throw new RuntimeException('Informe uma data válida.');
            }
            if ($client === '' || $lineId < 1 || $amount < 0) {
                throw new RuntimeException('Informe cliente, linha de produto e valor válido.');
            }

            [$line, $funnelId, $firstStageId] = opportunity_line_context($pdo, $lineId);

            $stageId = $firstStageId;
            if ($current && (int)$current['funnel_id'] === $funnelId) {
                $stageId = (int)$current['stage_id'];
            }

            if ($visitId > 0) {
                $q = $pdo->prepare('SELECT id, lead_status FROM visits WHERE id=? LIMIT 1');
                $q->execute([$visitId]);
                $visit = $q->fetch();
                if (!$visit) {
                    throw new RuntimeException('A visita de origem não existe mais.');
                }

                $q = $pdo->prepare('SELECT id FROM opportunities WHERE visit_id=?' . ($id > 0 ? ' AND id<>?' : '') . ' LIMIT 1');
                $id > 0 ? $q->execute([$visitId, $id]) : $q->execute([$visitId]);
                $duplicateOpportunity = (int)($q->fetchColumn() ?: 0);
                if ($duplicateOpportunity > 0) {
                    throw new RuntimeException('Esta visita já foi transformada em outra oportunidade.');
                }
            }

            $companyId = opportunity_company_from_post(
                $pdo,
                $seller,
                $current ? ((int)($current['company_id'] ?? 0) ?: null) : null
            );

            $numberInput = trim((string)($_POST['number'] ?? ''));
            $number = $numberInput !== ''
                ? $numberInput
                : ($current ? (string)$current['number'] : generate_opportunity_number($pdo, $seller, $date));

            $q = $pdo->prepare('SELECT id FROM opportunities WHERE number=?' . ($id > 0 ? ' AND id<>?' : '') . ' LIMIT 1');
            $id > 0 ? $q->execute([$number, $id]) : $q->execute([$number]);
            if ($q->fetchColumn()) {
                throw new RuntimeException('Já existe uma oportunidade com este número.');
            }

            if ($action === 'create') {
                $source = $visitId > 0 ? 'visita' : 'manual';
                $q = $pdo->prepare(
                    'INSERT INTO opportunities
                    (number,opportunity_date,client_name,company_id,visit_id,seller_user_id,support_user_id,
                     product_line_id,funnel_id,stage_id,item_type,amount,dnb_commission_estimated,status,source,notes,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,"Aberta",?,?,?)'
                );
                $q->execute([
                    $number,
                    $date,
                    $client,
                    $companyId,
                    $visitId ?: null,
                    $seller,
                    $support,
                    $lineId,
                    $funnelId,
                    $stageId,
                    $line['item_type'],
                    $amount,
                    $commission,
                    $source,
                    $notes,
                    (int)$user['id'],
                ]);

                $id = (int)$pdo->lastInsertId();

                if ($visitId > 0) {
                    $pdo->prepare("UPDATE visits SET lead_status='promovida' WHERE id=?")->execute([$visitId]);
                }

                audit($pdo, (int)$user['id'], 'create', 'opportunity', $id, [
                    'number' => $number,
                    'company_id' => $companyId,
                    'visit_id' => $visitId ?: null,
                ]);

                flash('success', $visitId > 0
                    ? 'Visita transformada na oportunidade ' . $number . '.'
                    : 'Oportunidade ' . $number . ' registrada.'
                );
            } else {
                $q = $pdo->prepare(
                    'UPDATE opportunities SET
                     number=?, opportunity_date=?, client_name=?, company_id=?, seller_user_id=?, support_user_id=?,
                     product_line_id=?, funnel_id=?, stage_id=?, item_type=?, amount=?, dnb_commission_estimated=?, notes=?,
                     updated_at=CURRENT_TIMESTAMP
                     WHERE id=?'
                );
                $q->execute([
                    $number,
                    $date,
                    $client,
                    $companyId,
                    $seller,
                    $support,
                    $lineId,
                    $funnelId,
                    $stageId,
                    $line['item_type'],
                    $amount,
                    $commission,
                    $notes,
                    $id,
                ]);

                audit($pdo, (int)$user['id'], 'update', 'opportunity', $id, ['number' => $number]);
                flash('success', 'Oportunidade ' . $number . ' atualizada.');
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            $q = $pdo->prepare('SELECT number, visit_id FROM opportunities WHERE id=? LIMIT 1');
            $q->execute([$id]);
            $opportunity = $q->fetch();
            if (!$opportunity) {
                throw new RuntimeException('Oportunidade não encontrada.');
            }

            $visitId = (int)($opportunity['visit_id'] ?? 0);
            $number = (string)$opportunity['number'];

            $pdo->prepare('DELETE FROM opportunities WHERE id=?')->execute([$id]);

            if ($visitId > 0) {
                $q = $pdo->prepare('SELECT COUNT(*) FROM opportunities WHERE visit_id=?');
                $q->execute([$visitId]);
                if (!(int)$q->fetchColumn()) {
                    $pdo->prepare("UPDATE visits SET lead_status='pendente' WHERE id=?")->execute([$visitId]);
                }
            }

            audit($pdo, (int)$user['id'], 'delete', 'opportunity', $id, ['number' => $number]);
            flash('success', 'Oportunidade ' . $number . ' excluída.');
        } elseif ($action === 'stage') {
            $id = (int)($_POST['id'] ?? 0);
            $stage = (int)($_POST['stage_id'] ?? 0);

            $q = $pdo->prepare('SELECT funnel_id FROM opportunities WHERE id=?');
            $q->execute([$id]);
            $funnel = (int)$q->fetchColumn();

            $q = $pdo->prepare('SELECT COUNT(*) FROM funnel_stages WHERE id=? AND funnel_id=?');
            $q->execute([$stage,$funnel]);
            if (!(int)$q->fetchColumn()) {
                throw new RuntimeException('Etapa inválida.');
            }

            $pdo->prepare('UPDATE opportunities SET stage_id=? WHERE id=?')->execute([$stage,$id]);
            audit($pdo, (int)$user['id'], 'update_stage', 'opportunity', $id);
            flash('success', 'Etapa atualizada.');
        } elseif ($action === 'status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');

            if (!in_array($status, ['Aberta','Ganha','Perdida'], true)) {
                throw new RuntimeException('Status inválido.');
            }

            $pdo->prepare('UPDATE opportunities SET status=? WHERE id=?')->execute([$status,$id]);
            audit($pdo, (int)$user['id'], 'update_status', 'opportunity', $id, ['status'=>$status]);
            flash('success', 'Status atualizado.');
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect('orcamentos.php');
}

$users = $pdo->query("SELECT id,name FROM users WHERE active=1 ORDER BY name")->fetchAll();
$lines = $pdo->query("SELECT * FROM product_lines WHERE active=1 ORDER BY sort_order")->fetchAll();

$editId = (int)($_GET['edit'] ?? 0);
$fromVisitId = (int)($_GET['from_visit'] ?? 0);
$editing = null;
$sourceVisit = null;

$form = [
    'id' => 0,
    'number' => '',
    'date' => date('Y-m-d'),
    'client_name' => '',
    'client_cnpj' => '',
    'client_alias' => '',
    'client_registration_status' => '',
    'client_registration_status_date' => '',
    'client_founded' => '',
    'client_company_type' => '',
    'client_company_size' => '',
    'client_legal_nature' => '',
    'client_capital_social' => '',
    'client_email' => '',
    'client_phone' => '',
    'client_zip' => '',
    'client_street' => '',
    'client_address_number' => '',
    'client_address_details' => '',
    'client_district' => '',
    'client_city' => '',
    'client_state' => '',
    'client_main_activity_code' => '',
    'client_industry' => '',
    'client_cnpja_updated_at' => '',
    'seller_user_id' => '',
    'support_user_id' => '',
    'product_line_id' => '',
    'amount' => '',
    'dnb_commission_estimated' => '',
    'notes' => '',
    'visit_id' => 0,
];

if ($editId > 0) {
    $q = $pdo->prepare(
        'SELECT o.*,
                c.cnpj company_cnpj,c.alias company_alias,c.registration_status company_registration_status,
                c.registration_status_date company_registration_status_date,c.founded company_founded,
                c.company_type company_type,c.company_size company_size,c.legal_nature company_legal_nature,
                c.capital_social company_capital_social,c.email company_email,c.phone company_phone,
                c.zip company_zip,c.street company_street,c.address_number company_address_number,
                c.address_details company_address_details,c.district company_district,c.city company_city,
                c.state company_state,c.main_activity_code company_main_activity_code,c.industry company_industry,
                c.cnpja_updated_at company_cnpja_updated_at
         FROM opportunities o
         LEFT JOIN companies c ON c.id=o.company_id
         WHERE o.id=? LIMIT 1'
    );
    $q->execute([$editId]);
    $editing = $q->fetch();

    if ($editing) {
        $form = array_merge($form, [
            'id' => (int)$editing['id'],
            'number' => (string)$editing['number'],
            'date' => (string)$editing['opportunity_date'],
            'client_name' => (string)$editing['client_name'],
            'client_cnpj' => (string)($editing['company_cnpj'] ?? ''),
            'client_alias' => (string)($editing['company_alias'] ?? ''),
            'client_registration_status' => (string)($editing['company_registration_status'] ?? ''),
            'client_registration_status_date' => (string)($editing['company_registration_status_date'] ?? ''),
            'client_founded' => (string)($editing['company_founded'] ?? ''),
            'client_company_type' => (string)($editing['company_type'] ?? ''),
            'client_company_size' => (string)($editing['company_size'] ?? ''),
            'client_legal_nature' => (string)($editing['company_legal_nature'] ?? ''),
            'client_capital_social' => (string)($editing['company_capital_social'] ?? ''),
            'client_email' => (string)($editing['company_email'] ?? ''),
            'client_phone' => (string)($editing['company_phone'] ?? ''),
            'client_zip' => (string)($editing['company_zip'] ?? ''),
            'client_street' => (string)($editing['company_street'] ?? ''),
            'client_address_number' => (string)($editing['company_address_number'] ?? ''),
            'client_address_details' => (string)($editing['company_address_details'] ?? ''),
            'client_district' => (string)($editing['company_district'] ?? ''),
            'client_city' => (string)($editing['company_city'] ?? ''),
            'client_state' => (string)($editing['company_state'] ?? ''),
            'client_main_activity_code' => (string)($editing['company_main_activity_code'] ?? ''),
            'client_industry' => (string)($editing['company_industry'] ?? ''),
            'client_cnpja_updated_at' => (string)($editing['company_cnpja_updated_at'] ?? ''),
            'seller_user_id' => $editing['seller_user_id'] !== null ? (string)$editing['seller_user_id'] : '',
            'support_user_id' => $editing['support_user_id'] !== null ? (string)$editing['support_user_id'] : '',
            'product_line_id' => (string)$editing['product_line_id'],
            'amount' => (string)$editing['amount'],
            'dnb_commission_estimated' => (string)$editing['dnb_commission_estimated'],
            'notes' => (string)($editing['notes'] ?? ''),
            'visit_id' => (int)($editing['visit_id'] ?? 0),
        ]);
    }
} elseif ($fromVisitId > 0) {
    $q = $pdo->prepare('SELECT * FROM visits WHERE id=? LIMIT 1');
    $q->execute([$fromVisitId]);
    $sourceVisit = $q->fetch();

    if ($sourceVisit) {
        if (($sourceVisit['lead_status'] ?? 'pendente') === 'promovida') {
            $q = $pdo->prepare('SELECT id FROM opportunities WHERE visit_id=? ORDER BY id DESC LIMIT 1');
            $q->execute([$fromVisitId]);
            $existingId = (int)($q->fetchColumn() ?: 0);
            if ($existingId > 0) {
                redirect('orcamentos.php?edit=' . $existingId . '#opportunity-form');
            }
        }

        $osText = trim((string)($sourceVisit['os_number'] ?? ''));
        $originLabel = $osText !== '' ? 'Visita / OS ' . $osText : 'Visita #' . $fromVisitId;
        $visitNotes = trim((string)($sourceVisit['opportunity_text'] ?? ''));
        if ($visitNotes === '') {
            $visitNotes = trim((string)($sourceVisit['summary'] ?? ''));
        }

        $form = array_merge($form, [
            'date' => (string)$sourceVisit['visit_date'],
            'client_name' => (string)$sourceVisit['client_name'],
            'seller_user_id' => $sourceVisit['seller_user_id'] !== null ? (string)$sourceVisit['seller_user_id'] : '',
            'notes' => $originLabel . ($visitNotes !== '' ? "\n\n" . $visitNotes : ''),
            'visit_id' => $fromVisitId,
        ]);
    }
}

$rows = $pdo->query(
    "SELECT o.*,pl.name line_name,f.label funnel_label,u.name seller_name,c.cnpj company_cnpj
     FROM opportunities o
     JOIN product_lines pl ON pl.id=o.product_line_id
     JOIN funnels f ON f.id=o.funnel_id
     LEFT JOIN users u ON u.id=o.seller_user_id
     LEFT JOIN companies c ON c.id=o.company_id
     ORDER BY o.opportunity_date DESC,o.id DESC
     LIMIT 300"
)->fetchAll();

$stages = [];
foreach ($pdo->query('SELECT * FROM funnel_stages ORDER BY funnel_id,sort_order')->fetchAll() as $stageRow) {
    $stages[(int)$stageRow['funnel_id']][] = $stageRow;
}

$cnpjaConfig = integration_get($pdo, 'cnpja');
$cnpjaReady = !empty($cnpjaConfig['is_active']) && secret_decrypt($cnpjaConfig['access_token'] ?? '') !== '';

page_header('Orçamentos','orcamentos');
?>

<div class="page-actions">
    <div>
        <span class="eyebrow">OPORTUNIDADES</span>
        <h2><?= $editing ? 'Editar oportunidade / orçamento' : ($sourceVisit ? 'Transformar visita em oportunidade' : 'Nova oportunidade / orçamento') ?></h2>
        <p>Uma oportunidade por linha de produto, encaminhada automaticamente ao funil correspondente.</p>
    </div>
    <?php if ($editing || $sourceVisit): ?>
        <a class="btn btn-ghost" href="orcamentos.php">Cancelar</a>
    <?php endif; ?>
</div>

<?php if ($sourceVisit): ?>
<div class="visit-origin-banner">
    <div>
        <strong>Virar oportunidade?</strong>
        <span>
            A visita <?= e($sourceVisit['os_number'] ? 'OS ' . $sourceVisit['os_number'] : '#' . $sourceVisit['id']) ?>
            de <strong><?= e($sourceVisit['client_name']) ?></strong> foi carregada abaixo.
            Complete linha de produto e valor para criar o orçamento.
        </span>
    </div>
</div>
<?php endif; ?>

<form method="post" id="opportunity-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$form['id'] ?>"><?php endif; ?>
    <input type="hidden" name="source_visit_id" value="<?= (int)$form['visit_id'] ?>">

    <div class="card customer-card">
        <div class="card-title">
            <div>
                <span class="tag">CLIENTE</span>
                <h3>Dados do cliente</h3>
                <p>Informe o CNPJ e consulte a CNPJá para preencher os dados cadastrais retornados pela Receita Federal.</p>
            </div>
        </div>

        <?php if (!$cnpjaReady): ?>
            <div class="info-panel">
                <strong>Consulta CNPJ indisponível.</strong>
                <p>O administrador precisa configurar e ativar CNPJá · Receita Federal em Configurações.</p>
            </div>
        <?php endif; ?>

        <div class="cnpja-lookup-row">
            <label class="cnpj-field">
                <span>CNPJ</span>
                <input name="client_cnpj" inputmode="numeric" autocomplete="off" maxlength="18"
                       placeholder="00.000.000/0000-00" data-cnpj-input
                       value="<?= e($form['client_cnpj'] ? format_cnpj($form['client_cnpj']) : '') ?>">
            </label>

            <button class="btn btn-secondary" type="button" data-cnpj-lookup data-endpoint="cnpja_lookup.php"
                    <?= !$cnpjaReady ? 'disabled' : '' ?>>Consultar CNPJ</button>

            <div class="cnpja-status" data-cnpja-status></div>
        </div>

        <div class="source-grid source-grid-4 customer-data-grid">
            <label class="source-span-2"><span>Razão Social</span><input name="client_name" data-cnpja-field="name" value="<?= e($form['client_name']) ?>" required></label>
            <label class="source-span-2"><span>Nome Fantasia</span><input name="client_alias" data-cnpja-field="alias" value="<?= e($form['client_alias']) ?>"></label>
            <label><span>Situação cadastral</span><input name="client_registration_status" data-cnpja-field="registration_status" value="<?= e($form['client_registration_status']) ?>"></label>
            <label><span>Data da situação</span><input type="date" name="client_registration_status_date" data-cnpja-field="registration_status_date" value="<?= e($form['client_registration_status_date']) ?>"></label>
            <label><span>Data de abertura</span><input type="date" name="client_founded" data-cnpja-field="founded" value="<?= e($form['client_founded']) ?>"></label>
            <label><span>Tipo</span><input name="client_company_type" data-cnpja-field="company_type" value="<?= e($form['client_company_type']) ?>"></label>
            <label><span>Porte</span><input name="client_company_size" data-cnpja-field="company_size" value="<?= e($form['client_company_size']) ?>"></label>
            <label class="source-span-2"><span>Natureza jurídica</span><input name="client_legal_nature" data-cnpja-field="legal_nature" value="<?= e($form['client_legal_nature']) ?>"></label>
            <label><span>Capital social (R$)</span><input type="number" step="0.01" min="0" name="client_capital_social" data-cnpja-field="capital_social" value="<?= e($form['client_capital_social']) ?>"></label>
            <label><span>E-mail</span><input type="email" name="client_email" data-cnpja-field="email" value="<?= e($form['client_email']) ?>"></label>
            <label><span>Telefone</span><input name="client_phone" data-cnpja-field="phone" value="<?= e($form['client_phone']) ?>"></label>
            <label><span>CEP</span><input name="client_zip" data-cnpja-field="zip" value="<?= e($form['client_zip']) ?>"></label>
            <label class="source-span-2"><span>Logradouro</span><input name="client_street" data-cnpja-field="street" value="<?= e($form['client_street']) ?>"></label>
            <label><span>Número</span><input name="client_address_number" data-cnpja-field="address_number" value="<?= e($form['client_address_number']) ?>"></label>
            <label><span>Complemento</span><input name="client_address_details" data-cnpja-field="address_details" value="<?= e($form['client_address_details']) ?>"></label>
            <label><span>Bairro</span><input name="client_district" data-cnpja-field="district" value="<?= e($form['client_district']) ?>"></label>
            <label><span>Cidade</span><input name="client_city" data-cnpja-field="city" value="<?= e($form['client_city']) ?>"></label>
            <label><span>UF</span><input name="client_state" maxlength="2" data-cnpja-field="state" value="<?= e($form['client_state']) ?>"></label>
            <label><span>CNAE principal</span><input name="client_main_activity_code" data-cnpja-field="main_activity_code" value="<?= e($form['client_main_activity_code']) ?>"></label>
            <label class="source-span-2"><span>Atividade principal</span><input name="client_industry" data-cnpja-field="industry" value="<?= e($form['client_industry']) ?>"></label>
        </div>

        <input type="hidden" name="client_cnpja_updated_at" data-cnpja-field="cnpja_updated_at" value="<?= e($form['client_cnpja_updated_at']) ?>">
    </div>

    <div class="card">
        <div class="card-title">
            <div>
                <span class="tag"><?= $editing ? 'EDIÇÃO' : ($sourceVisit ? 'VISITA → OPORTUNIDADE' : 'OPORTUNIDADE') ?></span>
                <h3>Dados da oportunidade</h3>
            </div>
        </div>

        <div class="form-grid">
            <label><span>Nº</span><input name="number" placeholder="automático" value="<?= e($form['number']) ?>"></label>
            <label><span>Data</span><input type="date" name="date" value="<?= e($form['date']) ?>" required></label>

            <label>
                <span>Vendedor</span>
                <select name="seller_user_id">
                    <option value="">—</option>
                    <?php foreach($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (string)$u['id']===(string)$form['seller_user_id']?'selected':'' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Suporte</span>
                <select name="support_user_id">
                    <option value="">—</option>
                    <?php foreach($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (string)$u['id']===(string)$form['support_user_id']?'selected':'' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Linha de produto</span>
                <select name="product_line_id" required>
                    <option value="">— selecione —</option>
                    <?php foreach($lines as $line): ?>
                        <option value="<?= (int)$line['id'] ?>" <?= (string)$line['id']===(string)$form['product_line_id']?'selected':'' ?>><?= e($line['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label><span>Valor (R$)</span><input type="number" step="0.01" min="0" name="amount" value="<?= e($form['amount']) ?>" required></label>
            <label><span>Comissão DNB estimada (R$)</span><input type="number" step="0.01" min="0" name="dnb_commission_estimated" value="<?= e($form['dnb_commission_estimated']) ?>"></label>
            <label class="span-2"><span>Observações</span><textarea name="notes" rows="4"><?= e($form['notes']) ?></textarea></label>

            <div class="span-2 form-actions">
                <button class="btn btn-primary" type="submit">
                    <?= $editing ? 'Salvar alterações' : ($sourceVisit ? 'Criar oportunidade a partir da visita' : 'Registrar oportunidade') ?>
                </button>
            </div>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-title">
        <div>
            <span class="tag">FUNIL</span>
            <h3>Orçamentos (<?= count($rows) ?>)</h3>
        </div>
    </div>

    <?php if(!$rows): ?>
        <?php empty_state('Nenhum orçamento cadastrado','O CRM está limpo, sem dados demonstrativos.'); ?>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Nº</th>
                        <th>Cliente</th>
                        <th>Vendedor</th>
                        <th>Linha / funil</th>
                        <th>Etapa</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($rows as $row): ?>
                    <tr>
                        <td><?= e($row['opportunity_date']) ?></td>
                        <td class="mono"><?= e($row['number']) ?></td>
                        <td>
                            <strong><?= e($row['client_name']) ?></strong>
                            <?php if($row['company_cnpj']): ?><small><?= e(format_cnpj($row['company_cnpj'])) ?></small><?php endif; ?>
                            <?php if((int)($row['visit_id'] ?? 0)>0): ?><small>Origem: visita</small><?php endif; ?>
                        </td>
                        <td><?= e($row['seller_name'] ?: '—') ?></td>
                        <td><?= e($row['line_name']) ?><small><?= e($row['funnel_label']) ?></small></td>
                        <td>
                            <?php if($row['status'] === 'Aberta'): ?>
                                <form method="post" class="cell-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="stage">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <select name="stage_id" onchange="this.form.submit()">
                                        <?php foreach($stages[(int)$row['funnel_id']] ?? [] as $s): ?>
                                            <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id']===(int)$row['stage_id']?'selected':'' ?>><?= e($s['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= money($row['amount']) ?></td>
                        <td><span class="status status-<?= strtolower(e($row['status'])) ?>"><?= e($row['status']) ?></span></td>
                        <td>
                            <div class="opportunity-actions">
                                <a class="table-action" href="orcamentos.php?edit=<?= (int)$row['id'] ?>#opportunity-form">Editar</a>

                                <?php if($row['status'] === 'Aberta'): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="status">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="status" value="Ganha">
                                        <button class="table-action success" type="submit">Ganha</button>
                                    </form>

                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="status">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="status" value="Perdida">
                                        <button class="table-action warning" type="submit">Perdida</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" data-confirm="Excluir definitivamente a oportunidade <?= e($row['number']) ?>? Esta ação não poderá ser desfeita." data-confirm-action="Excluir">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button class="table-action danger" type="submit">Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php page_footer(); ?>
