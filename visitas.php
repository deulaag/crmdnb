<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

$user = require_auth();
$pdo = db();
$openai = integration_get($pdo, 'openai');
$openaiReady = !empty($openai['is_active']) && secret_decrypt($openai['access_token'] ?? '') !== '';

function visitas_upload_files(array $files): array
{
    if (!isset($files['name'])) return [];
    if (!is_array($files['name'])) return [$files];
    $out = [];
    foreach ($files['name'] as $i => $name) {
        $out[] = [
            'name' => $name,
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
    }
    return $out;
}

function visitas_insert(PDO $pdo, int $createdBy, array $data, string $source): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO visits
        (os_number,visit_date,client_name,seller_user_id,visit_type,contact_name,phone,sector,equipment,summary,opportunity_text,source,created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        trim((string)($data['os'] ?? '')) ?: null,
        (string)($data['data'] ?? date('Y-m-d')),
        trim((string)($data['cliente'] ?? '')),
        ($data['seller_user_id'] ?? '') !== '' && $data['seller_user_id'] !== null ? (int)$data['seller_user_id'] : null,
        ($data['tipo'] ?? 'visita') === 'prospeccao' ? 'prospeccao' : 'visita',
        trim((string)($data['contato'] ?? '')),
        trim((string)($data['fone'] ?? '')),
        trim((string)($data['setor'] ?? '')),
        trim((string)($data['equipamentos'] ?? '')),
        '',
        trim((string)($data['oportunidade'] ?? '')),
        $source,
        $createdBy,
    ]);
    return (int)$pdo->lastInsertId();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'manual');

    try {
        if ($action === 'manual') {
            $client = trim((string)($_POST['client_name'] ?? ''));
            $sellerId = ($_POST['seller_user_id'] ?? '') !== '' ? (int)$_POST['seller_user_id'] : null;
            if ($client === '' || !$sellerId) {
                throw new RuntimeException('Informe cliente e responsável.');
            }

            $visitId = visitas_insert($pdo, (int)$user['id'], [
                'os' => trim((string)($_POST['os_number'] ?? '')),
                'data' => valid_date_string($_POST['visit_date'] ?? '') ? $_POST['visit_date'] : date('Y-m-d'),
                'cliente' => $client,
                'seller_user_id' => $sellerId,
                'tipo' => ($_POST['visit_type'] ?? 'visita') === 'prospeccao' ? 'prospeccao' : 'visita',
                'contato' => trim((string)($_POST['contact_name'] ?? '')),
                'setor' => trim((string)($_POST['sector'] ?? '')),
                'oportunidade' => trim((string)($_POST['opportunity_text'] ?? '')),
            ], 'manual');
            audit($pdo, (int)$user['id'], 'create', 'visit', $visitId);
            flash('success', 'Atividade registrada.');
        }

        if ($action === 'import_json') {
            $raw = trim((string)($_POST['visits_json'] ?? ''));
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $items = array_is_list($decoded) ? $decoded : [$decoded];
            $count = 0;

            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $client = trim((string)($item['cliente'] ?? ''));
                if ($client === '') continue;

                $sellerId = find_user_id_by_name($pdo, (string)($item['vendedor'] ?? ''));
                $typeRaw = strtolower(trim((string)($item['tipo'] ?? 'visita')));
                $visitId = visitas_insert($pdo, (int)$user['id'], [
                    'os' => trim((string)($item['os'] ?? '')),
                    'data' => valid_date_string($item['data'] ?? '') ? $item['data'] : date('Y-m-d'),
                    'cliente' => $client,
                    'seller_user_id' => $sellerId,
                    'tipo' => str_starts_with($typeRaw, 'prosp') ? 'prospeccao' : 'visita',
                    'contato' => trim((string)($item['contato'] ?? '')),
                    'setor' => trim((string)($item['setor'] ?? '')),
                    'oportunidade' => trim((string)($item['oportunidade'] ?? $item['comentario'] ?? '')),
                ], 'json');
                audit($pdo, (int)$user['id'], 'import_json', 'visit', $visitId);
                $count++;
            }

            flash('success', $count . ' visita(s) importada(s).');
        }

        if ($action === 'upload_pdf') {
            if (!$openaiReady) {
                throw new RuntimeException('Configure e ative ChatGPT / OpenAI em Configurações para importar PDFs.');
            }

            $files = visitas_upload_files($_FILES['visit_pdfs'] ?? []);
            if (!$files) throw new RuntimeException('Selecione pelo menos um PDF.');
            if (count($files) > 10) throw new RuntimeException('Envie no máximo 10 PDFs por vez.');

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $success = 0;
            $messages = [];

            foreach ($files as $index => $file) {
                $name = (string)($file['name'] ?? 'arquivo.pdf');
                try {
                    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('não foi possível receber o arquivo');
                    }
                    $tmp = (string)($file['tmp_name'] ?? '');
                    $size = (int)($file['size'] ?? 0);
                    if ($size <= 0 || $size > 20 * 1024 * 1024) {
                        throw new RuntimeException('arquivo vazio ou acima de 20 MB');
                    }
                    if ($finfo->file($tmp) !== 'application/pdf') {
                        throw new RuntimeException('arquivo não é um PDF válido');
                    }

                    $extracted = openai_extract_iliot_visit($pdo, $tmp, $name);
                    $sellerId = find_user_id_by_name($pdo, $extracted['vendedor'] ?? '');

                    $visitDate = valid_date_string($extracted['data'] ?? '') ? $extracted['data'] : date('Y-m-d');
                    $client = trim((string)($extracted['cliente'] ?? ''));
                    if ($client === '') $client = $name;

                    $visitId = visitas_insert($pdo, (int)$user['id'], [
                        'os' => $extracted['os'] ?? '',
                        'data' => $visitDate,
                        'cliente' => $client,
                        'seller_user_id' => $sellerId,
                        'tipo' => 'visita',
                        'contato' => $extracted['contato'] ?? '',
                        'fone' => $extracted['fone'] ?? '',
                        'setor' => $extracted['setor'] ?? '',
                        'equipamentos' => $extracted['equipamentos'] ?? '',
                        'oportunidade' => $extracted['oportunidade'] ?? '',
                    ], 'ia-pdf');

                    $clientPrefix = strtolower(substr($client, 0, 12));
                    if ($clientPrefix !== '') {
                        $q = $pdo->prepare("SELECT id, client_name, event_date FROM agenda WHERE status IN ('agendado','confirmado') ORDER BY id DESC");
                        $q->execute();
                        foreach ($q->fetchAll() as $agenda) {
                            $agendaClient = strtolower((string)$agenda['client_name']);
                            $days = abs((strtotime((string)$agenda['event_date']) - strtotime($visitDate)) / 86400);
                            if (str_contains($agendaClient, $clientPrefix) && $days < 8) {
                                $pdo->prepare("UPDATE agenda SET status='concluido', visit_id=? WHERE id=?")->execute([$visitId, (int)$agenda['id']]);
                            }
                        }
                    }

                    $scheduled = false;
                    if (trim((string)($extracted['agendar'] ?? '')) !== '') {
                        $suggestedDate = valid_date_string($extracted['data_sugerida'] ?? '')
                            ? $extracted['data_sugerida']
                            : date('Y-m-d', strtotime('+14 days'));

                        $q = $pdo->prepare("SELECT COUNT(*) FROM agenda WHERE visit_id=? AND status='sugerido'");
                        $q->execute([$visitId]);
                        if (!(int)$q->fetchColumn()) {
                            $contact = trim(implode(' · ', array_filter([
                                trim((string)($extracted['contato'] ?? '')),
                                trim((string)($extracted['fone'] ?? '')),
                            ])));
                            $q = $pdo->prepare('INSERT INTO agenda (event_date,event_time,client_name,user_id,contact_name,objective,status,source,visit_id,created_by) VALUES (?,NULL,?,?,?,?,"sugerido","ia-os",?,?)');
                            $q->execute([
                                $suggestedDate,
                                $client,
                                $sellerId,
                                $contact,
                                trim((string)$extracted['agendar']),
                                $visitId,
                                (int)$user['id'],
                            ]);
                            $scheduled = true;
                        }
                    }

                    $modelExtra = json_decode((string)($openai['extra_json'] ?? '{}'), true) ?: [];
                    $modelName = trim((string)($modelExtra['model'] ?? '')) ?: 'gpt-6-luna';
                    $q = $pdo->prepare('INSERT INTO ai_logs (feature,entity_type,entity_id,model_name,status,prompt_hash,response_excerpt,created_by) VALUES ("visita_pdf","visit",?,?,?,?,?,?)');
                    $q->execute([
                        $visitId,
                        $modelName,
                        'success',
                        hash('sha256', 'iliot-visit-v1'),
                        substr((string)($extracted['oportunidade'] ?? ''), 0, 500),
                        (int)$user['id'],
                    ]);

                    audit($pdo, (int)$user['id'], 'import_pdf', 'visit', $visitId, ['file' => $name, 'os' => $extracted['os'] ?? '']);
                    $success++;
                    $sellerName = 'vendedor a definir';
                    if ($sellerId) {
                        $q = $pdo->prepare('SELECT name FROM users WHERE id=?');
                        $q->execute([$sellerId]);
                        $sellerName = (string)($q->fetchColumn() ?: $sellerName);
                    }
                    $messages[] = 'OS ' . (($extracted['os'] ?? '') ?: 's/n') . ': ' . $client . ' · ' . $sellerName . ' — importada' . ($scheduled ? ' · visita futura sugerida no Cronograma' : '') . '.';
                } catch (Throwable $fileError) {
                    $messages[] = $name . ': não foi possível extrair — registre manualmente. (' . $fileError->getMessage() . ')';
                }
            }

            if (count($files) > 1) {
                $messages[] = $success . ' de ' . count($files) . ' OS importadas. Revise cliente e vendedor na tabela.';
            }
            $_SESSION['visitas_status'] = implode(' ', $messages);
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare('DELETE FROM visits WHERE id=?')->execute([$id]);
                audit($pdo, (int)$user['id'], 'delete', 'visit', $id);
            }
        }
    } catch (Throwable $e) {
        if ($action === 'upload_pdf') {
            $_SESSION['visitas_status'] = $e->getMessage();
        } else {
            flash('danger', $e->getMessage());
        }
    }

    redirect('visitas.php');
}

$users = $pdo->query("SELECT id,name FROM users WHERE active=1 ORDER BY name")->fetchAll();
$rows = $pdo->query("SELECT v.*,u.name seller_name FROM visits v LEFT JOIN users u ON u.id=v.seller_user_id ORDER BY v.id DESC")->fetchAll();
$pdfStatus = (string)($_SESSION['visitas_status'] ?? '');
unset($_SESSION['visitas_status']);

page_header('Visitas','visitas');
?>

<div class="html-page">

<div class="card">
    <div class="card-title"><div><h3>Importar OS de visita do iliot (PDF)</h3></div></div>
    <div class="source-help">Anexe as OS Simplificadas exportadas do iliot (app.iliot.tech) — o agente extrai nº da OS, data do check-in, cliente, vendedor, contato, setor e o comentário de oportunidades. Aceita vários PDFs de uma vez. Cada OS importada conta para a meta de visitas do vendedor.</div>
    <?php if (!$openaiReady): ?><div class="alert alert-warning">Configure e ative ChatGPT / OpenAI em Configurações para a leitura automática funcionar.</div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" data-upload-form>
        <?= csrf_field() ?><input type="hidden" name="action" value="upload_pdf">
        <input type="file" name="visit_pdfs[]" accept="application/pdf" multiple class="plain-file-input" onchange="if(this.files.length){this.form.submit();}" <?= !$openaiReady ? 'disabled' : '' ?>>
    </form>
    <?php if ($pdfStatus !== ''): ?><div class="source-status"><?= e($pdfStatus) ?></div><?php endif; ?>
</div>

<div class="card">
    <div class="card-title"><div><h3>Registrar atividade comercial (OS de campo / prospecção)</h3></div></div>
    <div class="source-help">Visitas espelham a OS Simplificada (iliot.tech). Prospecção de novo cliente é a atividade do canal interno (meta: 40/mês).</div>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="manual">
        <div class="source-grid source-grid-4">
            <label><span>Tipo</span><select name="visit_type"><option value="visita">Visita comercial</option><option value="prospeccao">Prospecção — novo cliente</option></select></label>
            <label><span>Nº OS / referência</span><input name="os_number" placeholder="4391"></label>
            <label><span>Data</span><input type="date" name="visit_date" value="<?= date('Y-m-d') ?>"></label>
            <label><span>Responsável</span><select name="seller_user_id"><option value="">—</option><?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></label>
            <label class="source-span-2"><span>Cliente</span><input name="client_name"></label>
            <label><span>Contato</span><input name="contact_name"></label>
            <label><span>Setor</span><input name="sector"></label>
        </div>
        <label class="source-textarea"><span>Oportunidades identificadas / desfecho</span><textarea name="opportunity_text" rows="4"></textarea></label>
        <button class="btn btn-primary source-mt" type="submit">Registrar</button>
    </form>
</div>

<div class="card">
    <div class="card-title"><div><h3>Importar visitas / relatórios iliot (JSON)</h3></div></div>
    <div class="source-help">Cole o JSON com as visitas (campos: os, data, tipo [visita|prospeccao], cliente, vendedor, contato, setor, oportunidade) — aceita lista.</div>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="import_json">
        <textarea name="visits_json" class="source-json" rows="4" placeholder='[{"os":"4391","data":"2026-07-15","tipo":"visita","cliente":"Cliente","vendedor":"Nome do vendedor","oportunidade":"..."}]'></textarea>
        <div class="source-inline"><button class="btn btn-secondary" type="submit">Importar visitas</button></div>
    </form>
</div>

<div class="card">
    <div class="card-title"><div><h3>Atividades registradas (<?= count($rows) ?>)</h3></div></div>
    <div class="table-wrap">
        <table class="source-table">
            <thead><tr><th>Data</th><th>Tipo</th><th>OS</th><th>Cliente</th><th>Responsável</th><th>Oportunidade</th><th></th></tr></thead>
            <tbody>
            <?php foreach($rows as $row): ?>
                <tr>
                    <td><?= e($row['visit_date']) ?></td>
                    <td><span class="source-pill <?= $row['visit_type']==='prospeccao'?'source-pill-blue':'' ?>"><?= e($row['visit_type']==='prospeccao'?'Prospecção':'Visita') ?></span></td>
                    <td class="mono"><?= e($row['os_number'] ?: '') ?></td>
                    <td><strong><?= e($row['client_name']) ?></strong><small><?= e($row['contact_name'] ?: '') ?></small></td>
                    <td><?= e($row['seller_name'] ?: '—') ?></td>
                    <td class="source-opportunity"><?= e($row['opportunity_text'] ?: '—') ?></td>
                    <td class="source-right">
                        <form method="post" onsubmit="return confirm('Excluir esta atividade?');">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <button class="source-x" type="submit">✕</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if(!$rows): ?><tr><td colspan="7" class="empty-row">Nenhuma atividade registrada.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>
<?php page_footer(); ?>
