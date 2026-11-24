<?php
declare(strict_types=1);

/** Criar/editar oportunidade — regras copiadas de orcamentos.php (actions create/update). */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/oportunidade.php';

$user = nc_boot();
nc_require_post();
$pdo = db();
ensure_company_cnpja_schema($pdo);
ensure_opportunity_visit_schema($pdo);
ensure_opportunity_tracking_schema($pdo);

$v = nc_opp_from_post();
$id = (int)$v['id'];
$action = $id > 0 ? 'update' : 'create';

try {
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

    [$line, $funnelId, $firstStageId] = nc_opp_line_context($pdo, $lineId);
    $stageId = ($current && (int)$current['funnel_id'] === $funnelId)
        ? (int)$current['stage_id']
        : $firstStageId;

    if ($visitId > 0) {
        $q = $pdo->prepare('SELECT id FROM visits WHERE id=? LIMIT 1');
        $q->execute([$visitId]);
        if (!$q->fetchColumn()) {
            throw new RuntimeException('A visita de origem não existe mais.');
        }

        $q = $pdo->prepare('SELECT id FROM opportunities WHERE visit_id=?' . ($id > 0 ? ' AND id<>?' : '') . ' LIMIT 1');
        $id > 0 ? $q->execute([$visitId, $id]) : $q->execute([$visitId]);
        if ($q->fetchColumn()) {
            throw new RuntimeException('Esta visita já foi transformada em outra oportunidade.');
        }
    }

    $companyId = nc_opp_company_from_post(
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
        $q = $pdo->prepare(
            'INSERT INTO opportunities
            (number,opportunity_date,client_name,company_id,visit_id,seller_user_id,support_user_id,
             product_line_id,funnel_id,stage_id,item_type,amount,status,quote_required,quote_required_at,source,notes,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,"Aberta",?,?,?,?,?)'
        );
        $q->execute([
            $number, $date, $client, $companyId, $visitId ?: null, $seller, $support,
            $lineId, $funnelId, $stageId, $line['item_type'], $amount,
            $visitId > 0 ? 1 : 0,
            $visitId > 0 ? date('Y-m-d H:i:s') : null,
            $visitId > 0 ? 'visita' : 'manual',
            $notes ?: null,
            (int)$user['id'],
        ]);

        $id = (int)$pdo->lastInsertId();
        if ($visitId > 0) {
            $pdo->prepare("UPDATE visits SET lead_status='promovida' WHERE id=?")->execute([$visitId]);
        }

        audit($pdo, (int)$user['id'], 'create', 'opportunity', $id, [
            'number' => $number,
            'visit_id' => $visitId ?: null,
        ]);
        flash('success', $visitId > 0
            ? 'Visita transformada na oportunidade ' . $number . '. A pendência "Realizar orçamento" foi criada.'
            : 'Oportunidade ' . $number . ' registrada.'
        );

        nc_hx_redirect($visitId > 0
            ? nc_url('oportunidades.php?funil=' . $funnelId . '&abrir=' . $id)
            : nc_url('oportunidades.php?funil=' . $funnelId));
    }

    $q = $pdo->prepare(
        'UPDATE opportunities SET
         number=?,opportunity_date=?,client_name=?,company_id=?,seller_user_id=?,support_user_id=?,
         product_line_id=?,funnel_id=?,stage_id=?,item_type=?,amount=?,notes=?,updated_at=CURRENT_TIMESTAMP
         WHERE id=?'
    );
    $q->execute([
        $number, $date, $client, $companyId, $seller, $support, $lineId, $funnelId, $stageId,
        $line['item_type'], $amount, $notes ?: null, $id,
    ]);

    audit($pdo, (int)$user['id'], 'update', 'opportunity', $id, ['number' => $number]);
    flash('success', 'Oportunidade ' . $number . ' atualizada.');
    header('HX-Refresh: true');
    exit;
} catch (Throwable $e) {
    http_response_code(422);
    header('Content-Type: text/html; charset=utf-8');
    nc_opp_form($pdo, $v, $e->getMessage());
}
