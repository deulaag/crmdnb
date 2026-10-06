<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$user = require_auth();
$pdo = db();
ensure_opportunity_visit_schema($pdo);
ensure_opportunity_tracking_schema($pdo);

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
    if ($cnpj === '') return $currentCompanyId;
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

function opportunity_insert(PDO $pdo, int $createdBy, array $data): int
{
    $date = valid_date_string($data['date'] ?? '') ? (string)$data['date'] : date('Y-m-d');
    $client = trim((string)($data['client_name'] ?? ''));
    $seller = !empty($data['seller_user_id']) ? (int)$data['seller_user_id'] : null;
    $support = !empty($data['support_user_id']) ? (int)$data['support_user_id'] : null;
    $lineId = (int)($data['product_line_id'] ?? 0);
    $amount = max(0, (float)($data['amount'] ?? 0));

    if ($client === '' || $lineId < 1) {
        throw new RuntimeException('Cliente e linha de produto são obrigatórios.');
    }

    [$line, $funnelId, $stageId] = opportunity_line_context($pdo, $lineId);

    $number = trim((string)($data['number'] ?? ''));
    if ($number === '') {
        $number = generate_opportunity_number($pdo, $seller, $date);
    }

    $q = $pdo->prepare('SELECT id FROM opportunities WHERE number=? LIMIT 1');
    $q->execute([$number]);
    if ($q->fetchColumn()) {
        throw new RuntimeException('Já existe uma oportunidade com o número ' . $number . '.');
    }

    $q = $pdo->prepare(
        'INSERT INTO opportunities
        (number,opportunity_date,client_name,company_id,visit_id,seller_user_id,support_user_id,
         product_line_id,funnel_id,stage_id,item_type,amount,status,quote_required,quote_required_at,source,notes,rd_deal_id,created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,"Aberta",?,?,?,?,?,?)'
    );

    $q->execute([
        $number,
        $date,
        $client,
        !empty($data['company_id']) ? (int)$data['company_id'] : null,
        !empty($data['visit_id']) ? (int)$data['visit_id'] : null,
        $seller,
        $support,
        $lineId,
        $funnelId,
        $stageId,
        $line['item_type'],
        $amount,
        !empty($data['visit_id']) ? 1 : 0,
        !empty($data['visit_id']) ? date('Y-m-d H:i:s') : null,
        trim((string)($data['source'] ?? 'manual')) ?: 'manual',
        trim((string)($data['notes'] ?? '')) ?: null,
        trim((string)($data['rd_deal_id'] ?? '')) ?: null,
        $createdBy,
    ]);

    return (int)$pdo->lastInsertId();
}

function crm_number_value(string $value): float
{
    $value = trim($value);
    if ($value === '') return 0.0;

    $value = preg_replace('/[^0-9,\.\-]/', '', $value) ?: '0';

    if (str_contains($value, ',') && str_contains($value, '.')) {
        if (strrpos($value, ',') > strrpos($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif (str_contains($value, ',')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }

    return (float)$value;
}

function salesforce_stage_match(PDO $pdo, int $funnelId, string $stageText): ?int
{
    $needle = normalized_name($stageText);
    $stages = $pdo->prepare('SELECT id,name FROM funnel_stages WHERE funnel_id=? ORDER BY sort_order');
    $stages->execute([$funnelId]);
    $rows = $stages->fetchAll();

    foreach ($rows as $stage) {
        if (normalized_name((string)$stage['name']) === $needle) {
            return (int)$stage['id'];
        }
    }

    $map = [
        'Fechamento' => ['closed','fechamento','ganho','won','perdido','lost'],
        'Negociação' => ['negociacao','negotiation'],
        'Proposta emitida' => ['proposta','proposal','quote'],
        'Levantamento técnico / Visita' => ['levantamento','visita','discovery','technical'],
        'Prospecção' => ['prospeccao','prospecting','prospect'],
    ];

    foreach ($map as $name => $keywords) {
        foreach ($keywords as $keyword) {
            if ($needle !== '' && str_contains($needle, normalized_name($keyword))) {
                foreach ($rows as $stage) {
                    if ((string)$stage['name'] === $name) return (int)$stage['id'];
                }
            }
        }
    }

    return $rows ? (int)$rows[0]['id'] : null;
}

function salesforce_status(string $stageText): string
{
    $needle = normalized_name($stageText);
    if (str_contains($needle, 'closed won') || str_contains($needle, 'ganho') || str_contains($needle, 'won')) {
        return 'Ganha';
    }
    if (str_contains($needle, 'closed lost') || str_contains($needle, 'perdido') || str_contains($needle, 'lost')) {
        return 'Perdida';
    }
    return 'Aberta';
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
                if (!$current) throw new RuntimeException('Oportunidade não encontrada.');
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

            if (!valid_date_string($date)) throw new RuntimeException('Informe uma data válida.');
            if ($client === '' || $lineId < 1 || $amount < 0) {
                throw new RuntimeException('Informe cliente, linha de produto e valor válido.');
            }

            [$line, $funnelId, $firstStageId] = opportunity_line_context($pdo, $lineId);
            $stageId = ($current && (int)$current['funnel_id'] === $funnelId)
                ? (int)$current['stage_id']
                : $firstStageId;

            if ($visitId > 0) {
                $q = $pdo->prepare('SELECT id FROM visits WHERE id=? LIMIT 1');
                $q->execute([$visitId]);
                if (!$q->fetchColumn()) throw new RuntimeException('A visita de origem não existe mais.');

                $q = $pdo->prepare('SELECT id FROM opportunities WHERE visit_id=?' . ($id > 0 ? ' AND id<>?' : '') . ' LIMIT 1');
                $id > 0 ? $q->execute([$visitId, $id]) : $q->execute([$visitId]);
                if ($q->fetchColumn()) throw new RuntimeException('Esta visita já foi transformada em outra oportunidade.');
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
            if ($q->fetchColumn()) throw new RuntimeException('Já existe uma oportunidade com este número.');

            if ($action === 'create') {
                $q = $pdo->prepare(
                    'INSERT INTO opportunities
                    (number,opportunity_date,client_name,company_id,visit_id,seller_user_id,support_user_id,
                     product_line_id,funnel_id,stage_id,item_type,amount,status,quote_required,quote_required_at,source,notes,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,"Aberta",?,?,?,?,?)'
                );
                $q->execute([
                    $number,$date,$client,$companyId,$visitId ?: null,$seller,$support,
                    $lineId,$funnelId,$stageId,$line['item_type'],$amount,
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

                if ($visitId > 0) {
                    redirect('oportunidade.php?id=' . $id);
                }
            } else {
                $q = $pdo->prepare(
                    'UPDATE opportunities SET
                     number=?,opportunity_date=?,client_name=?,company_id=?,seller_user_id=?,support_user_id=?,
                     product_line_id=?,funnel_id=?,stage_id=?,item_type=?,amount=?,notes=?,updated_at=CURRENT_TIMESTAMP
                     WHERE id=?'
                );
                $q->execute([
                    $number,$date,$client,$companyId,$seller,$support,$lineId,$funnelId,$stageId,
                    $line['item_type'],$amount,$notes ?: null,$id,
                ]);

                audit($pdo, (int)$user['id'], 'update', 'opportunity', $id, ['number'=>$number]);
                flash('success', 'Oportunidade ' . $number . ' atualizada.');
            }

        } elseif ($action === 'promote_visit') {
            $visitId = (int)($_POST['visit_id'] ?? 0);
            $q = $pdo->prepare('SELECT * FROM visits WHERE id=? AND lead_status="pendente" LIMIT 1');
            $q->execute([$visitId]);
            $visit = $q->fetch();
            if (!$visit) throw new RuntimeException('A visita não está disponível para qualificação.');

            $suggestedLineId = product_line_id_from_text(
                $pdo,
                trim((string)$visit['opportunity_text'] . ' ' . (string)$visit['equipment'])
            );

            if (!$suggestedLineId) {
                flash('warning', 'A linha de produto não pôde ser sugerida com segurança. Confirme manualmente antes de criar a oportunidade.');
                redirect('orcamentos.php?from_visit=' . $visitId . '#opportunity-form');
            }

            $notes = [];
            if ($visit['os_number']) $notes[] = 'Origem: OS ' . $visit['os_number'];
            if ($visit['opportunity_text']) $notes[] = trim((string)$visit['opportunity_text']);
            if ($visit['equipment']) $notes[] = 'Equipamentos: ' . trim((string)$visit['equipment']);
            if ($visit['contact_name']) $notes[] = 'Contato: ' . trim((string)$visit['contact_name']) . ($visit['phone'] ? ' (' . trim((string)$visit['phone']) . ')' : '');

            $newId = opportunity_insert($pdo, (int)$user['id'], [
                'date' => $visit['visit_date'],
                'client_name' => $visit['client_name'],
                'seller_user_id' => $visit['seller_user_id'],
                'product_line_id' => $suggestedLineId,
                'amount' => 0,
                'visit_id' => $visitId,
                'source' => 'visita',
                'notes' => implode("\n", $notes),
            ]);

            $pdo->prepare("UPDATE visits SET lead_status='promovida' WHERE id=?")->execute([$visitId]);
            audit($pdo, (int)$user['id'], 'promote_visit', 'visit', $visitId, ['opportunity_id'=>$newId]);
            flash('success', 'Lead promovido ao funil. A pendência "Realizar orçamento" foi criada.');
            redirect('oportunidade.php?id=' . $newId);

        } elseif ($action === 'discard_visit') {
            $visitId = (int)($_POST['visit_id'] ?? 0);
            $pdo->prepare("UPDATE visits SET lead_status='descartada' WHERE id=? AND lead_status='pendente'")->execute([$visitId]);
            audit($pdo, (int)$user['id'], 'discard_lead', 'visit', $visitId);
            flash('success', 'Lead da visita descartado da triagem.');

        } elseif ($action === 'import_cotador_json') {
            $raw = trim((string)($_POST['cotador_json'] ?? ''));
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $items = array_is_list($decoded) ? $decoded : [$decoded];
            $imported = 0;
            $errors = [];

            foreach ($items as $index => $item) {
                if (!is_array($item)) continue;

                try {
                    $client = trim((string)($item['cliente'] ?? ''));
                    $lineId = product_line_id_from_text($pdo, (string)($item['linha'] ?? $item['tipo'] ?? ''));
                    if ($client === '' || !$lineId) {
                        throw new RuntimeException('cliente ou linha de produto não identificado');
                    }

                    $date = valid_date_string($item['data'] ?? '') ? (string)$item['data'] : date('Y-m-d');
                    $seller = find_user_id_by_name($pdo, (string)($item['vendedor'] ?? ''));
                    $support = find_user_id_by_name($pdo, (string)($item['suporte'] ?? ''));

                    opportunity_insert($pdo, (int)$user['id'], [
                        'number' => trim((string)($item['numero'] ?? '')),
                        'date' => $date,
                        'client_name' => $client,
                        'seller_user_id' => $seller,
                        'support_user_id' => $support,
                        'product_line_id' => $lineId,
                        'amount' => crm_number_value((string)($item['valor'] ?? 0)),
                        'source' => 'cotador',
                    ]);
                    $imported++;
                } catch (Throwable $itemError) {
                    $errors[] = 'Item ' . ($index + 1) . ': ' . $itemError->getMessage();
                }
            }

            flash(
                $imported > 0 ? ($errors ? 'warning' : 'success') : 'danger',
                $imported . ' proposta(s) importada(s) do Cotador.'
                . ($errors ? ' ' . implode(' | ', array_slice($errors, 0, 3)) : '')
            );

        } elseif ($action === 'import_erp_pdf') {
            $files = $_FILES['erp_pdfs'] ?? [];
            $names = $files['name'] ?? [];
            if (!is_array($names)) $names = [$names];
            if (!$names || $names === ['']) throw new RuntimeException('Selecione pelo menos um PDF do ERP.');

            $imported = 0;
            $errors = [];

            foreach ($names as $i => $filename) {
                try {
                    $file = [
                        'name' => $filename,
                        'tmp_name' => is_array($files['tmp_name']) ? ($files['tmp_name'][$i] ?? '') : ($files['tmp_name'] ?? ''),
                        'error' => is_array($files['error']) ? ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) : ($files['error'] ?? UPLOAD_ERR_NO_FILE),
                        'size' => is_array($files['size']) ? ($files['size'][$i] ?? 0) : ($files['size'] ?? 0),
                    ];

                    if ((int)$file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('falha no upload');
                    if ((int)$file['size'] <= 0 || (int)$file['size'] > 20 * 1024 * 1024) throw new RuntimeException('PDF inválido ou acima de 20 MB');

                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    if ($finfo->file((string)$file['tmp_name']) !== 'application/pdf') throw new RuntimeException('arquivo não reconhecido como PDF');

                    $data = openai_extract_erp_quote($pdo, (string)$file['tmp_name'], (string)$filename);
                    $lineId = product_line_id_from_text($pdo, $data['linha']);
                    if (!$lineId) throw new RuntimeException('linha de produto não identificada pela IA');
                    if ($data['cliente'] === '') throw new RuntimeException('cliente não identificado');

                    opportunity_insert($pdo, (int)$user['id'], [
                        'number' => $data['numero'],
                        'date' => valid_date_string($data['data']) ? $data['data'] : date('Y-m-d'),
                        'client_name' => $data['cliente'],
                        'seller_user_id' => find_user_id_by_name($pdo, $data['vendedor']),
                        'support_user_id' => find_user_id_by_name($pdo, $data['suporte']),
                        'product_line_id' => $lineId,
                        'amount' => (float)$data['valor'],
                        'source' => 'erp_pdf',
                        'notes' => 'Importado do PDF do ERP. Revise os dados antes de usar.',
                    ]);
                    $imported++;
                } catch (Throwable $fileError) {
                    $errors[] = (string)$filename . ': ' . $fileError->getMessage();
                }
            }

            flash(
                $imported > 0 ? ($errors ? 'warning' : 'success') : 'danger',
                $imported . ' orçamento(s) importado(s) do ERP.'
                . ($errors ? ' ' . implode(' | ', array_slice($errors, 0, 3)) : '')
            );

        } elseif ($action === 'sync_salesforce') {
            $file = $_FILES['salesforce_file'] ?? null;
            if (!is_array($file)) throw new RuntimeException('Selecione o relatório Salesforce.');

            $items = openai_read_salesforce_report($pdo, $file);
            if (!$items) throw new RuntimeException('A IA não identificou oportunidades no relatório.');

            $lineId = product_line_id_from_text($pdo, 'Compressores IR (agenciamento)');
            if (!$lineId) throw new RuntimeException('A linha Compressores IR (agenciamento) não está configurada.');
            [$line, $funnelId, $firstStageId] = opportunity_line_context($pdo, $lineId);

            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($items as $item) {
                if (!is_array($item)) {
                    $skipped++;
                    continue;
                }

                $dealId = trim((string)($item['deal_id'] ?? ''));
                $dealName = trim((string)($item['name'] ?? ''));
                $client = trim((string)($item['client'] ?? ''));
                $stageText = trim((string)($item['stage'] ?? ''));
                $owner = trim((string)($item['owner'] ?? ''));
                $amount = max(0, (float)($item['amount'] ?? 0));
                $commission = max(0, (float)($item['commission'] ?? 0));

                if ($client === '' || ($dealId === '' && $dealName === '')) {
                    $skipped++;
                    continue;
                }

                $status = salesforce_status($stageText);
                $stageId = salesforce_stage_match($pdo, $funnelId, $stageText) ?: $firstStageId;
                $seller = find_user_id_by_name($pdo, $owner);

                $existing = null;
                if ($dealId !== '') {
                    $q = $pdo->prepare('SELECT * FROM opportunities WHERE rd_deal_id=? LIMIT 1');
                    $q->execute([$dealId]);
                    $existing = $q->fetch();
                }
                if (!$existing && $dealName !== '') {
                    $q = $pdo->prepare('SELECT * FROM opportunities WHERE number=? LIMIT 1');
                    $q->execute([$dealName]);
                    $existing = $q->fetch();
                }

                if ($existing) {
                    $q = $pdo->prepare(
                        'UPDATE opportunities SET
                         client_name=?,seller_user_id=?,product_line_id=?,funnel_id=?,stage_id=?,item_type=?,
                         amount=?,dnb_commission_estimated=?,status=?,source="salesforce_ai",rd_deal_id=?,updated_at=CURRENT_TIMESTAMP
                         WHERE id=?'
                    );
                    $q->execute([
                        $client,$seller,$lineId,$funnelId,$stageId,$line['item_type'],$amount,$commission,
                        $status,$dealId ?: ($existing['rd_deal_id'] ?? null),(int)$existing['id'],
                    ]);
                    $updated++;
                } else {
                    $number = $dealName !== '' ? $dealName : ('SF-' . $dealId);
                    if ($number === '') $number = generate_opportunity_number($pdo, $seller, date('Y-m-d'));

                    $q = $pdo->prepare(
                        'INSERT INTO opportunities
                        (number,opportunity_date,client_name,seller_user_id,product_line_id,funnel_id,stage_id,item_type,
                         amount,dnb_commission_estimated,status,source,rd_deal_id,created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,"salesforce_ai",?,?)'
                    );
                    $q->execute([
                        $number,date('Y-m-d'),$client,$seller,$lineId,$funnelId,$stageId,$line['item_type'],
                        $amount,$commission,$status,$dealId ?: null,(int)$user['id'],
                    ]);
                    $created++;
                }
            }

            audit($pdo, (int)$user['id'], 'sync_salesforce_ai', 'opportunity', null, [
                'created'=>$created,'updated'=>$updated,'skipped'=>$skipped,
            ]);
            flash('success', 'Agente leu o relatório Salesforce: ' . $created . ' nova(s), ' . $updated . ' atualizada(s), ' . $skipped . ' ignorada(s).');

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $q = $pdo->prepare('SELECT number,visit_id FROM opportunities WHERE id=? LIMIT 1');
            $q->execute([$id]);
            $opportunity = $q->fetch();
            if (!$opportunity) throw new RuntimeException('Oportunidade não encontrada.');

            $pdo->prepare('DELETE FROM opportunities WHERE id=?')->execute([$id]);

            $visitId = (int)($opportunity['visit_id'] ?? 0);
            if ($visitId > 0) {
                $q = $pdo->prepare('SELECT COUNT(*) FROM opportunities WHERE visit_id=?');
                $q->execute([$visitId]);
                if (!(int)$q->fetchColumn()) {
                    $pdo->prepare("UPDATE visits SET lead_status='pendente' WHERE id=?")->execute([$visitId]);
                }
            }

            audit($pdo, (int)$user['id'], 'delete', 'opportunity', $id, ['number'=>$opportunity['number']]);
            flash('success', 'Oportunidade ' . $opportunity['number'] . ' excluída.');

        } elseif ($action === 'stage') {
            $id = (int)($_POST['id'] ?? 0);
            $stage = (int)($_POST['stage_id'] ?? 0);

            $q = $pdo->prepare('SELECT funnel_id FROM opportunities WHERE id=?');
            $q->execute([$id]);
            $funnel = (int)$q->fetchColumn();

            $q = $pdo->prepare('SELECT COUNT(*) FROM funnel_stages WHERE id=? AND funnel_id=?');
            $q->execute([$stage,$funnel]);
            if (!(int)$q->fetchColumn()) throw new RuntimeException('Etapa inválida.');

            $pdo->prepare('UPDATE opportunities SET stage_id=? WHERE id=?')->execute([$stage,$id]);
            audit($pdo, (int)$user['id'], 'update_stage', 'opportunity', $id);
            flash('success', 'Etapa atualizada.');

        } elseif ($action === 'status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');
            if (!in_array($status, ['Aberta','Ganha','Perdida'], true)) throw new RuntimeException('Status inválido.');

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
    'id'=>0,'number'=>'','date'=>date('Y-m-d'),'client_name'=>'','client_cnpj'=>'','client_alias'=>'',
    'client_registration_status'=>'','client_registration_status_date'=>'','client_founded'=>'',
    'client_company_type'=>'','client_company_size'=>'','client_legal_nature'=>'','client_capital_social'=>'',
    'client_email'=>'','client_phone'=>'','client_zip'=>'','client_street'=>'','client_address_number'=>'',
    'client_address_details'=>'','client_district'=>'','client_city'=>'','client_state'=>'',
    'client_main_activity_code'=>'','client_industry'=>'','client_cnpja_updated_at'=>'',
    'seller_user_id'=>'','support_user_id'=>'','product_line_id'=>'','amount'=>'','notes'=>'','visit_id'=>0,
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
            'id'=>(int)$editing['id'],
            'number'=>(string)$editing['number'],
            'date'=>(string)$editing['opportunity_date'],
            'client_name'=>(string)$editing['client_name'],
            'client_cnpj'=>(string)($editing['company_cnpj'] ?? ''),
            'client_alias'=>(string)($editing['company_alias'] ?? ''),
            'client_registration_status'=>(string)($editing['company_registration_status'] ?? ''),
            'client_registration_status_date'=>(string)($editing['company_registration_status_date'] ?? ''),
            'client_founded'=>(string)($editing['company_founded'] ?? ''),
            'client_company_type'=>(string)($editing['company_type'] ?? ''),
            'client_company_size'=>(string)($editing['company_size'] ?? ''),
            'client_legal_nature'=>(string)($editing['company_legal_nature'] ?? ''),
            'client_capital_social'=>(string)($editing['company_capital_social'] ?? ''),
            'client_email'=>(string)($editing['company_email'] ?? ''),
            'client_phone'=>(string)($editing['company_phone'] ?? ''),
            'client_zip'=>(string)($editing['company_zip'] ?? ''),
            'client_street'=>(string)($editing['company_street'] ?? ''),
            'client_address_number'=>(string)($editing['company_address_number'] ?? ''),
            'client_address_details'=>(string)($editing['company_address_details'] ?? ''),
            'client_district'=>(string)($editing['company_district'] ?? ''),
            'client_city'=>(string)($editing['company_city'] ?? ''),
            'client_state'=>(string)($editing['company_state'] ?? ''),
            'client_main_activity_code'=>(string)($editing['company_main_activity_code'] ?? ''),
            'client_industry'=>(string)($editing['company_industry'] ?? ''),
            'client_cnpja_updated_at'=>(string)($editing['company_cnpja_updated_at'] ?? ''),
            'seller_user_id'=>$editing['seller_user_id'] !== null ? (string)$editing['seller_user_id'] : '',
            'support_user_id'=>$editing['support_user_id'] !== null ? (string)$editing['support_user_id'] : '',
            'product_line_id'=>(string)$editing['product_line_id'],
            'amount'=>(string)$editing['amount'],
            'notes'=>(string)($editing['notes'] ?? ''),
            'visit_id'=>(int)($editing['visit_id'] ?? 0),
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
            if ($existingId > 0) redirect('orcamentos.php?edit=' . $existingId . '#opportunity-form');
        }

        $suggestedLine = product_line_id_from_text(
            $pdo,
            trim((string)$sourceVisit['opportunity_text'] . ' ' . (string)$sourceVisit['equipment'])
        );

        $notes = [];
        if ($sourceVisit['os_number']) $notes[] = 'Visita / OS ' . $sourceVisit['os_number'];
        if ($sourceVisit['opportunity_text']) $notes[] = trim((string)$sourceVisit['opportunity_text']);
        if ($sourceVisit['equipment']) $notes[] = 'Equipamentos: ' . trim((string)$sourceVisit['equipment']);

        $form = array_merge($form, [
            'date'=>(string)$sourceVisit['visit_date'],
            'client_name'=>(string)$sourceVisit['client_name'],
            'seller_user_id'=>$sourceVisit['seller_user_id'] !== null ? (string)$sourceVisit['seller_user_id'] : '',
            'product_line_id'=>$suggestedLine ? (string)$suggestedLine : '',
            'notes'=>implode("\n\n", $notes),
            'visit_id'=>$fromVisitId,
        ]);
    }
}

$rows = $pdo->query(
    "SELECT o.*,pl.name line_name,pl.is_agency,f.label funnel_label,u.name seller_name,c.cnpj company_cnpj
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

$funnels = $pdo->query('SELECT * FROM funnels WHERE active=1 ORDER BY sort_order,id')->fetchAll();
$funnelOpenRows = $pdo->query(
    "SELECT o.id,o.amount,o.dnb_commission_estimated,o.funnel_id,o.stage_id,pl.is_agency
     FROM opportunities o
     JOIN product_lines pl ON pl.id=o.product_line_id
     WHERE o.status='Aberta'"
)->fetchAll();

$funnelSummary = [];
foreach ($funnels as $funnel) {
    $fid = (int)$funnel['id'];
    $funnelSummary[$fid] = [
        'count'=>0,'volume'=>0.0,'effective'=>0.0,'stages'=>[],
    ];

    foreach ($stages[$fid] ?? [] as $stage) {
        $funnelSummary[$fid]['stages'][(int)$stage['id']] = ['count'=>0,'amount'=>0.0];
    }
}

foreach ($funnelOpenRows as $row) {
    $fid = (int)$row['funnel_id'];
    $sid = (int)$row['stage_id'];
    if (!isset($funnelSummary[$fid])) continue;

    $amount = (float)$row['amount'];
    $effective = (int)$row['is_agency']
        ? (float)$row['dnb_commission_estimated']
        : $amount;

    $funnelSummary[$fid]['count']++;
    $funnelSummary[$fid]['volume'] += $amount;
    $funnelSummary[$fid]['effective'] += $effective;

    if (!isset($funnelSummary[$fid]['stages'][$sid])) {
        $funnelSummary[$fid]['stages'][$sid] = ['count'=>0,'amount'=>0.0];
    }
    $funnelSummary[$fid]['stages'][$sid]['count']++;
    $funnelSummary[$fid]['stages'][$sid]['amount'] += $amount;
}

$pendingLeads = $pdo->query(
    "SELECT v.*,u.name seller_name
     FROM visits v
     LEFT JOIN users u ON u.id=v.seller_user_id
     WHERE v.lead_status='pendente'
       AND TRIM(COALESCE(v.opportunity_text,'')) <> ''
     ORDER BY v.visit_date DESC,v.id DESC"
)->fetchAll();

$cnpjaConfig = integration_get($pdo, 'cnpja');
$cnpjaReady = !empty($cnpjaConfig['is_active']) && secret_decrypt($cnpjaConfig['access_token'] ?? '') !== '';
$openaiConfig = integration_get($pdo, 'openai');
$openaiReady = !empty($openaiConfig['is_active']) && secret_decrypt($openaiConfig['access_token'] ?? '') !== '';

page_header('Orçamentos','orcamentos');
?>

<?php if ($pendingLeads): ?>
<div class="card lead-triage-card">
    <div class="card-title">
        <div>
            <span class="tag">TRIAGEM</span>
            <h3>Triagem de leads das visitas (<?= count($pendingLeads) ?> aguardando qualificação)</h3>
            <p>Oportunidades identificadas nas OS de visita. Promova ao funil ou descarte. Nada entra no pipeline sem esta validação.</p>
        </div>
    </div>

    <div class="lead-triage-list">
        <?php foreach ($pendingLeads as $lead):
            $suggestedId = product_line_id_from_text($pdo, trim((string)$lead['opportunity_text'] . ' ' . (string)$lead['equipment']));
            $suggestedName = '';
            foreach ($lines as $line) {
                if ((int)$line['id'] === (int)$suggestedId) {
                    $suggestedName = (string)$line['name'];
                    break;
                }
            }
        ?>
            <article class="lead-triage-item">
                <div class="lead-triage-copy">
                    <div class="lead-triage-title">
                        <strong><?= e($lead['client_name']) ?></strong>
                        <span>OS <?= e($lead['os_number'] ?: 's/n') ?> · <?= e($lead['visit_date']) ?> · <?= e($lead['seller_name'] ?: 'responsável a definir') ?></span>
                    </div>
                    <p><?= e($lead['opportunity_text']) ?></p>
                    <?php if ($lead['equipment']): ?><small>Equipamentos citados: <strong><?= e($lead['equipment']) ?></strong></small><?php endif; ?>
                    <?php if ($suggestedName): ?><small class="lead-suggestion">Linha sugerida: <strong><?= e($suggestedName) ?></strong></small><?php endif; ?>
                </div>

                <div class="lead-triage-actions">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="promote_visit">
                        <input type="hidden" name="visit_id" value="<?= (int)$lead['id'] ?>">
                        <button class="btn btn-primary" type="submit"><?= $suggestedId ? 'Promover ao funil' : 'Qualificar oportunidade' ?></button>
                    </form>

                    <form method="post" data-confirm="Descartar esta oportunidade identificada na visita? Ela sairá da triagem." data-confirm-action="Descartar">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="discard_visit">
                        <input type="hidden" name="visit_id" value="<?= (int)$lead['id'] ?>">
                        <button class="btn btn-ghost" type="submit">Descartar</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card funnel-board-card">
    <div class="card-title">
        <div>
            <span class="tag">FUNIS DE VENDA · OPORTUNIDADES ABERTAS</span>
        </div>
    </div>

    <div class="funnel-board-list">
        <?php foreach ($funnels as $funnel):
            $fid = (int)$funnel['id'];
            $summary = $funnelSummary[$fid];
        ?>
            <section class="funnel-board">
                <div class="funnel-board-head">
                    <div>
                        <strong><?= e($funnel['label']) ?></strong>
                        <?php if ($funnel['description']): ?><span><?= e($funnel['description']) ?></span><?php endif; ?>
                    </div>
                    <div class="funnel-board-metrics">
                        <?= (int)$summary['count'] ?> oportunidade(s) · volume <strong><?= money($summary['volume']) ?></strong> · receita efetiva projetada <strong><?= money($summary['effective']) ?></strong>
                    </div>
                </div>

                <div class="funnel-stage-grid" style="--stage-count:<?= max(1,count($stages[$fid] ?? [])) ?>">
                    <?php foreach ($stages[$fid] ?? [] as $stage):
                        $stageSummary = $summary['stages'][(int)$stage['id']] ?? ['count'=>0,'amount'=>0];
                    ?>
                        <div class="funnel-stage-box">
                            <span><?= e($stage['name']) ?></span>
                            <strong><?= (int)$stageSummary['count'] ?></strong>
                            <small><?= $stageSummary['count'] ? money($stageSummary['amount']) : '—' ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="source-help source-help-tight">
        Agenciamento: o funil mostra o volume negociado do equipamento. A receita efetiva projetada usa a comissão DNB quando ela vier de uma integração/relatório; esse valor não é digitado manualmente no orçamento.
    </div>
</div>

<div class="page-actions">
    <div>
        <span class="eyebrow">OPORTUNIDADES</span>
        <h2><?= $editing ? 'Editar oportunidade / orçamento' : ($sourceVisit ? 'Transformar visita em oportunidade' : 'Nova oportunidade / orçamento') ?></h2>
    </div>
    <?php if ($editing || $sourceVisit): ?><a class="btn btn-ghost" href="orcamentos.php#opportunity-form">Cancelar</a><?php endif; ?>
</div>

<?php if ($sourceVisit): ?>
<div class="visit-origin-banner">
    <div>
        <strong>Virar oportunidade?</strong>
        <span>A visita <?= e($sourceVisit['os_number'] ? 'OS ' . $sourceVisit['os_number'] : '#' . $sourceVisit['id']) ?> de <strong><?= e($sourceVisit['client_name']) ?></strong> foi carregada. Confirme a linha e informe o valor.</span>
    </div>
</div>
<?php endif; ?>

<div class="card" id="opportunity-form">
    <div class="card-title">
        <div>
            <span class="tag"><?= $editing ? 'EDITAR OPORTUNIDADE / ORÇAMENTO' : 'NOVA OPORTUNIDADE / ORÇAMENTO' ?></span>
        </div>
    </div>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$form['id'] ?>"><?php endif; ?>
        <input type="hidden" name="source_visit_id" value="<?= (int)$form['visit_id'] ?>">

        <div class="opportunity-core-grid">
            <label>
                <span>Nº (automático — INICIAIS-SEQ/MM/AAAA)</span>
                <input name="number" value="<?= e($form['number']) ?>" placeholder="gerado ao salvar">
            </label>

            <label>
                <span>Data</span>
                <input type="date" name="date" value="<?= e($form['date']) ?>" required>
            </label>

            <label class="opportunity-client">
                <span>Cliente</span>
                <input name="client_name" data-cnpja-field="name" value="<?= e($form['client_name']) ?>" required>
            </label>

            <label>
                <span>Vendedor</span>
                <select name="seller_user_id">
                    <option value="">—</option>
                    <?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (string)$u['id']===(string)$form['seller_user_id']?'selected':'' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Suporte (opcional)</span>
                <select name="support_user_id">
                    <option value="">—</option>
                    <?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (string)$u['id']===(string)$form['support_user_id']?'selected':'' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Linha de Produto (obrigatória)</span>
                <select name="product_line_id" required>
                    <option value="">— selecione —</option>
                    <?php foreach($lines as $line): ?><option value="<?= (int)$line['id'] ?>" <?= (string)$line['id']===(string)$form['product_line_id']?'selected':'' ?>><?= e($line['name']) ?></option><?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Valor (R$)</span>
                <input type="number" step="0.01" min="0" name="amount" value="<?= e($form['amount']) ?>" required>
            </label>
        </div>

        <details class="customer-details" <?= $form['client_cnpj'] ? 'open' : '' ?>>
            <summary>Dados do cliente · consultar CNPJ</summary>
            <div class="customer-details-body">
                <?php if (!$cnpjaReady): ?>
                    <div class="info-panel">
                        <strong>Consulta CNPJ indisponível.</strong>
                        <p>O administrador precisa configurar e ativar CNPJá · Receita Federal em Configurações.</p>
                    </div>
                <?php endif; ?>

                <div class="cnpja-lookup-row">
                    <label class="cnpj-field">
                        <span>CNPJ</span>
                        <input name="client_cnpj" inputmode="numeric" autocomplete="off" maxlength="18" placeholder="00.000.000/0000-00" data-cnpj-input value="<?= e($form['client_cnpj'] ? format_cnpj($form['client_cnpj']) : '') ?>">
                    </label>
                    <button class="btn btn-secondary" type="button" data-cnpj-lookup data-endpoint="cnpja_lookup.php" <?= !$cnpjaReady ? 'disabled' : '' ?>>Consultar CNPJ</button>
                    <div class="cnpja-status" data-cnpja-status></div>
                </div>

                <div class="source-grid source-grid-4 customer-data-grid">
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
        </details>

        <label class="source-textarea">
            <span>Observações</span>
            <textarea name="notes" rows="3"><?= e($form['notes']) ?></textarea>
        </label>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Salvar alterações' : ($sourceVisit ? 'Criar oportunidade a partir da visita' : 'Registrar oportunidade') ?></button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-title">
        <div><span class="tag">IMPORTAR DO COTADOR DNB (JSON)</span></div>
    </div>
    <p class="source-help">Cole o JSON gerado pelo botão “Exportar p/ CRM” do Cotador. Formato: {"numero","data","cliente","vendedor","linha","tipo","valor"} — aceita lista. Nenhum exemplo comercial é pré-carregado.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_cotador_json">
        <textarea class="source-json" name="cotador_json" rows="5" placeholder='[{"numero":"...","data":"YYYY-MM-DD","cliente":"...","vendedor":"...","linha":"...","tipo":"...","valor":0}]'></textarea>
        <div class="source-inline"><button class="btn btn-secondary" type="submit">Importar propostas</button></div>
    </form>
</div>

<div class="card">
    <div class="card-title">
        <div><span class="tag">IMPORTAR ORÇAMENTO DO ERP (PDF)</span></div>
    </div>
    <p class="source-help">Anexe o PDF do orçamento emitido pelo ERP — o agente lê o documento e extrai nº, data, cliente, vendedor, valor e linha automaticamente. Aceita vários arquivos de uma vez. Revise os dados importados na tabela antes de usar.</p>
    <?php if (!$openaiReady): ?>
        <div class="info-panel"><strong>IA necessária.</strong><p>Configure ChatGPT / OpenAI em Configurações para habilitar a leitura dos PDFs do ERP.</p></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" data-upload-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_erp_pdf">
        <div class="pdf-upload-row">
            <input type="file" name="erp_pdfs[]" accept="application/pdf,.pdf" multiple class="plain-file-input" <?= !$openaiReady ? 'disabled' : '' ?>>
            <button class="btn btn-primary" type="submit" data-upload-button data-loading-label="Lendo orçamentos..." <?= !$openaiReady ? 'disabled' : '' ?>>Importar PDFs</button>
        </div>
        <div class="upload-selected" data-upload-selected>Nenhum PDF selecionado.</div>
    </form>
</div>

<div class="card">
    <div class="card-title">
        <div><span class="tag">SINCRONIZAR RELATÓRIO SEMANAL SALESFORCE (AGENCIADOS IR)</span></div>
    </div>
    <p class="source-help">Exporte o relatório de oportunidades do Salesforce (CSV ou Excel) e anexe aqui. Oportunidades já sincronizadas são atualizadas — nunca duplicadas. Novas entram no funil de Equipamentos como Compressores IR (agenciamento), com a fase mapeada para a etapa do funil. Colunas reconhecidas: Opportunity Name/ID, Account/Conta, Amount/Valor, Stage/Fase, Owner/Proprietário, Comissão.</p>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sync_salesforce">
        <div class="pdf-upload-row">
            <input type="file" name="salesforce_file" accept=".csv,.xlsx" class="plain-file-input" required>
            <button class="btn btn-secondary" type="submit">Sincronizar relatório</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-title">
        <div>
            <span class="tag">OPORTUNIDADES</span>
            <h3>Orçamentos registrados (<?= count($rows) ?>)</h3>
        </div>
    </div>

    <?php if(!$rows): ?>
        <?php empty_state('Nenhum orçamento cadastrado','O CRM está limpo, sem dados demonstrativos.'); ?>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Data</th><th>Nº</th><th>Cliente</th><th>Vendedor</th><th>Linha / funil</th>
                        <th>Etapa</th><th>Valor</th><th>Status</th><th>Ações</th>
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
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= money($row['amount']) ?></td>
                        <td><span class="status status-<?= strtolower(e($row['status'])) ?>"><?= e($row['status']) ?></span></td>
                        <td>
                            <div class="opportunity-actions">
                                <a class="table-action" href="orcamentos.php?edit=<?= (int)$row['id'] ?>#opportunity-form">Editar</a>

                                <?php if($row['status'] === 'Aberta'): ?>
                                    <form method="post">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="status" value="Ganha">
                                        <button class="table-action success" type="submit">Ganha</button>
                                    </form>
                                    <form method="post">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="status" value="Perdida">
                                        <button class="table-action warning" type="submit">Perdida</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" data-confirm="Excluir definitivamente a oportunidade <?= e($row['number']) ?>? Esta ação não poderá ser desfeita." data-confirm-action="Excluir">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
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
