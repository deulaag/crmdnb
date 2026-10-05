<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

$user = require_auth();
$pdo = db();
ensure_opportunity_visit_schema($pdo);
$openai = integration_get($pdo, 'openai');
$openaiExtra = json_decode((string)($openai['extra_json'] ?? '{}'), true) ?: [];
$openaiModel = trim((string)($openaiExtra['model'] ?? '')) ?: 'gpt-5.6-sol';
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

function visitas_upload_error(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE => 'o PDF ultrapassa o upload_max_filesize do servidor',
        UPLOAD_ERR_FORM_SIZE => 'o PDF ultrapassa o limite permitido pelo formulário',
        UPLOAD_ERR_PARTIAL => 'o upload do PDF foi recebido apenas parcialmente',
        UPLOAD_ERR_NO_FILE => 'nenhum PDF foi recebido',
        UPLOAD_ERR_NO_TMP_DIR => 'o servidor está sem diretório temporário para upload',
        UPLOAD_ERR_CANT_WRITE => 'o servidor não conseguiu gravar o PDF temporariamente',
        UPLOAD_ERR_EXTENSION => 'uma extensão do PHP interrompeu o upload',
        default => 'falha de upload (código ' . $code . ')',
    };
}

function visitas_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') return 0;
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    return match ($unit) {
        'g' => (int)($number * 1024 * 1024 * 1024),
        'm' => (int)($number * 1024 * 1024),
        'k' => (int)($number * 1024),
        default => (int)$number,
    };
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
        trim((string)($data['resumo'] ?? '')),
        trim((string)($data['oportunidade'] ?? '')),
        $source,
        $createdBy,
    ]);
    return (int)$pdo->lastInsertId();
}

function visitas_update_agenda(PDO $pdo, int $visitId, array $data, int $createdBy): bool
{
    $client = trim((string)($data['cliente'] ?? ''));
    $visitDate = (string)($data['data'] ?? date('Y-m-d'));
    $clientPrefix = strtolower(substr($client, 0, 12));

    if ($clientPrefix !== '') {
        $q = $pdo->query("SELECT id, client_name, event_date FROM agenda WHERE status IN ('agendado','confirmado') ORDER BY id DESC");
        foreach ($q->fetchAll() as $agenda) {
            $agendaClient = strtolower((string)$agenda['client_name']);
            $days = abs((strtotime((string)$agenda['event_date']) - strtotime($visitDate)) / 86400);
            if (str_contains($agendaClient, $clientPrefix) && $days < 8) {
                $pdo->prepare("UPDATE agenda SET status='concluido', visit_id=? WHERE id=?")
                    ->execute([$visitId, (int)$agenda['id']]);
            }
        }
    }

    if (trim((string)($data['agendar'] ?? '')) === '') {
        return false;
    }

    $suggestedDate = valid_date_string($data['data_sugerida'] ?? '')
        ? (string)$data['data_sugerida']
        : date('Y-m-d', strtotime('+14 days'));

    $q = $pdo->prepare("SELECT COUNT(*) FROM agenda WHERE visit_id=? AND status='sugerido'");
    $q->execute([$visitId]);
    if ((int)$q->fetchColumn()) return false;

    $contact = trim(implode(' · ', array_filter([
        trim((string)($data['contato'] ?? '')),
        trim((string)($data['fone'] ?? '')),
    ])));

    $q = $pdo->prepare(
        'INSERT INTO agenda
        (event_date,event_time,client_name,user_id,contact_name,objective,status,source,visit_id,created_by)
        VALUES (?,NULL,?,?,?,?,"sugerido","ia-os",?,?)'
    );
    $q->execute([
        $suggestedDate,
        $client,
        ($data['seller_user_id'] ?? '') !== '' ? (int)$data['seller_user_id'] : null,
        $contact,
        trim((string)$data['agendar']),
        $visitId,
        $createdBy,
    ]);

    return true;
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMax = visitas_ini_bytes((string)ini_get('post_max_size'));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $contentLength > 0 && $postMax > 0 && $contentLength > $postMax && empty($_POST)) {
    $_SESSION['visitas_status'] = 'O PDF não chegou ao CRM porque o envio ultrapassou o post_max_size do servidor (' . ini_get('post_max_size') . ').';
    redirect('visitas.php');
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

        if ($action === 'extract_pdf') {
            if (!$openaiReady) {
                throw new RuntimeException('A integração ChatGPT / OpenAI não está ativa ou não possui API Key salva.');
            }

            @set_time_limit(300);
            $files = visitas_upload_files($_FILES['visit_pdfs'] ?? []);
            if (!$files) throw new RuntimeException('Selecione pelo menos um PDF.');
            if (count($files) > 10) throw new RuntimeException('Envie no máximo 10 PDFs por vez.');

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $drafts = [];
            $errors = [];

            foreach ($files as $file) {
                $filename = (string)($file['name'] ?? 'arquivo.pdf');

                try {
                    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
                    if ($uploadError !== UPLOAD_ERR_OK) {
                        throw new RuntimeException(visitas_upload_error($uploadError));
                    }

                    $tmp = (string)($file['tmp_name'] ?? '');
                    $size = (int)($file['size'] ?? 0);

                    if ($size <= 0) throw new RuntimeException('arquivo vazio');
                    if ($size > 20 * 1024 * 1024) throw new RuntimeException('o PDF ultrapassa 20 MB');
                    if (!is_uploaded_file($tmp)) throw new RuntimeException('o arquivo temporário do upload não é válido');
                    if ($finfo->file($tmp) !== 'application/pdf') throw new RuntimeException('o arquivo não foi reconhecido como PDF');

                    $extracted = openai_extract_iliot_visit($pdo, $tmp, $filename);
                    $draftId = bin2hex(random_bytes(8));
                    $sellerId = find_user_id_by_name($pdo, (string)($extracted['vendedor'] ?? ''));

                    $drafts[$draftId] = [
                        'draft_id' => $draftId,
                        'arquivo' => $filename,
                        'os' => trim((string)($extracted['os'] ?? '')),
                        'data' => valid_date_string($extracted['data'] ?? '') ? (string)$extracted['data'] : '',
                        'cliente' => trim((string)($extracted['cliente'] ?? '')),
                        'vendedor' => trim((string)($extracted['vendedor'] ?? '')),
                        'seller_user_id' => $sellerId,
                        'contato' => trim((string)($extracted['contato'] ?? '')),
                        'fone' => trim((string)($extracted['fone'] ?? '')),
                        'setor' => trim((string)($extracted['setor'] ?? '')),
                        'equipamentos' => trim((string)($extracted['equipamentos'] ?? '')),
                        'oportunidade' => trim((string)($extracted['oportunidade'] ?? '')),
                        'agendar' => trim((string)($extracted['agendar'] ?? '')),
                        'data_sugerida' => valid_date_string($extracted['data_sugerida'] ?? '') ? (string)$extracted['data_sugerida'] : '',
                    ];

                    $q = $pdo->prepare(
                        'INSERT INTO ai_logs
                        (feature,entity_type,entity_id,model_name,status,prompt_hash,response_excerpt,created_by)
                        VALUES ("visita_pdf","visit",NULL,?,"success",?,?,?)'
                    );
                    $q->execute([
                        $openaiModel,
                        hash('sha256', 'iliot-visit-v2'),
                        substr((string)($extracted['oportunidade'] ?? ''), 0, 500),
                        (int)$user['id'],
                    ]);

                    audit($pdo, (int)$user['id'], 'extract_pdf', 'visit', null, ['file' => $filename]);
                } catch (Throwable $fileError) {
                    $errors[] = $filename . ': ' . $fileError->getMessage();

                    try {
                        $q = $pdo->prepare(
                            'INSERT INTO ai_logs
                            (feature,entity_type,entity_id,model_name,status,prompt_hash,response_excerpt,created_by)
                            VALUES ("visita_pdf","visit",NULL,?,"error",?,?,?)'
                        );
                        $q->execute([
                            $openaiModel,
                            hash('sha256', 'iliot-visit-v2'),
                            substr($fileError->getMessage(), 0, 500),
                            (int)$user['id'],
                        ]);
                    } catch (Throwable $ignored) {
                    }
                }
            }

            $_SESSION['visit_pdf_drafts'] = $drafts;

            if ($drafts) {
                $_SESSION['visitas_status'] = count($drafts) . ' PDF(s) extraído(s) com IA. Revise os campos abaixo e clique em Importar visita(s).'
                    . ($errors ? ' ' . count($errors) . ' arquivo(s) tiveram erro: ' . implode(' | ', array_slice($errors, 0, 3)) : '');
            } else {
                $_SESSION['visitas_status'] = 'Nenhum PDF foi extraído. ' . implode(' | ', array_slice($errors, 0, 3));
            }
        }

        if ($action === 'import_extracted') {
            $storedDrafts = $_SESSION['visit_pdf_drafts'] ?? [];
            $postedDrafts = $_POST['drafts'] ?? [];
            if (!is_array($storedDrafts) || !$storedDrafts || !is_array($postedDrafts)) {
                throw new RuntimeException('Não há dados extraídos aguardando importação.');
            }

            $imported = 0;
            $warnings = [];

            foreach ($postedDrafts as $draftId => $posted) {
                if (!isset($storedDrafts[$draftId]) || !is_array($posted)) continue;
                $original = $storedDrafts[$draftId];

                $data = [
                    'os' => trim((string)($posted['os'] ?? '')),
                    'data' => trim((string)($posted['data'] ?? '')),
                    'cliente' => trim((string)($posted['cliente'] ?? '')),
                    'seller_user_id' => ($posted['seller_user_id'] ?? '') !== '' ? (int)$posted['seller_user_id'] : null,
                    'tipo' => 'visita',
                    'contato' => trim((string)($posted['contato'] ?? '')),
                    'fone' => trim((string)($posted['fone'] ?? '')),
                    'setor' => trim((string)($posted['setor'] ?? '')),
                    'equipamentos' => trim((string)($posted['equipamentos'] ?? '')),
                    'oportunidade' => trim((string)($posted['oportunidade'] ?? '')),
                    'agendar' => trim((string)($posted['agendar'] ?? '')),
                    'data_sugerida' => trim((string)($posted['data_sugerida'] ?? '')),
                ];

                if ($data['cliente'] === '') {
                    $warnings[] = ($original['arquivo'] ?? 'PDF') . ': informe o cliente antes de importar.';
                    continue;
                }
                if (!valid_date_string($data['data'])) {
                    $warnings[] = ($original['arquivo'] ?? 'PDF') . ': informe uma data válida.';
                    continue;
                }

                $visitId = visitas_insert($pdo, (int)$user['id'], $data, 'ia-pdf');
                $scheduled = visitas_update_agenda($pdo, $visitId, $data, (int)$user['id']);

                audit($pdo, (int)$user['id'], 'import_extracted_pdf', 'visit', $visitId, [
                    'file' => $original['arquivo'] ?? '',
                    'os' => $data['os'],
                    'scheduled' => $scheduled,
                ]);

                unset($storedDrafts[$draftId]);
                $imported++;
            }

            $_SESSION['visit_pdf_drafts'] = $storedDrafts;
            $message = $imported . ' visita(s) importada(s).';
            if ($warnings) $message .= ' ' . implode(' | ', $warnings);
            flash($warnings && $imported === 0 ? 'danger' : ($warnings ? 'warning' : 'success'), $message);
        }

        if ($action === 'clear_extracted') {
            unset($_SESSION['visit_pdf_drafts']);
            flash('success', 'Prévia extraída descartada.');
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $q = $pdo->prepare('SELECT COUNT(*) FROM opportunities WHERE visit_id=?');
                $q->execute([$id]);
                if ((int)$q->fetchColumn() > 0) {
                    throw new RuntimeException('Esta visita já virou uma oportunidade. Exclua primeiro a oportunidade em Orçamentos.');
                }
                $pdo->prepare('DELETE FROM visits WHERE id=?')->execute([$id]);
                audit($pdo, (int)$user['id'], 'delete', 'visit', $id);
            }
        }
    } catch (Throwable $e) {
        if ($action === 'extract_pdf') {
            $_SESSION['visitas_status'] = $e->getMessage();
        } else {
            flash('danger', $e->getMessage());
        }
    }

    redirect('visitas.php');
}

$users = $pdo->query("SELECT id,name FROM users WHERE active=1 ORDER BY name")->fetchAll();
$rows = $pdo->query(
    "SELECT v.*,u.name seller_name,o.id opportunity_id,o.number opportunity_number
     FROM visits v
     LEFT JOIN users u ON u.id=v.seller_user_id
     LEFT JOIN opportunities o ON o.visit_id=v.id
     ORDER BY v.id DESC"
)->fetchAll();

$pdfStatus = (string)($_SESSION['visitas_status'] ?? '');
unset($_SESSION['visitas_status']);
$pdfDrafts = $_SESSION['visit_pdf_drafts'] ?? [];
if (!is_array($pdfDrafts)) $pdfDrafts = [];

page_header('Visitas','visitas');
?>

<div class="html-page">

<div class="card">
    <div class="card-title">
        <div>
            <h3>Importar OS de visita do iliot (PDF)</h3>
        </div>
    </div>

    <div class="source-help">
        Anexe as OS Simplificadas exportadas do iliot (app.iliot.tech) — o agente extrai nº da OS, data do check-in, cliente, vendedor, contato, setor e o comentário de oportunidades. Aceita vários PDFs de uma vez. Cada OS importada conta para a meta de visitas do vendedor.
    </div>

    <?php if ($openaiReady): ?>
        <div class="ai-ready"><span></span> ChatGPT / OpenAI ativo · <?= e($openaiModel) ?></div>
    <?php else: ?>
        <div class="alert alert-warning">Configure e ative ChatGPT / OpenAI em Configurações para a leitura automática funcionar.</div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" data-upload-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="extract_pdf">

        <div class="pdf-upload-row">
            <input type="file" name="visit_pdfs[]" accept="application/pdf,.pdf" multiple class="plain-file-input" data-pdf-input <?= !$openaiReady ? 'disabled' : '' ?>>
            <button
                class="btn btn-primary"
                type="submit"
                data-upload-button
                data-loading-label="Extraindo dados com IA..."
                <?= !$openaiReady ? 'disabled' : '' ?>
            >Extrair dados com IA</button>
        </div>

        <div class="upload-selected" data-upload-selected>Nenhum PDF selecionado.</div>
        <div class="source-help source-help-tight">
            Limite do CRM: 20 MB por PDF · servidor: upload_max_filesize <?= e((string)ini_get('upload_max_filesize')) ?> / post_max_size <?= e((string)ini_get('post_max_size')) ?>.
        </div>
    </form>

    <?php if ($pdfStatus !== ''): ?><div class="source-status"><?= e($pdfStatus) ?></div><?php endif; ?>
</div>

<?php if ($pdfDrafts): ?>
<div class="card extracted-preview" id="dados-extraidos">
    <div class="card-title">
        <div>
            <h3>Dados extraídos pela IA (<?= count($pdfDrafts) ?>)</h3>
            <p>Revise os campos antes de gravar a visita no CRM. A extração não cria registro enquanto você não clicar em Importar visita(s).</p>
        </div>
    </div>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_extracted">

        <?php foreach ($pdfDrafts as $draftId => $draft): ?>
            <div class="extracted-item">
                <div class="extracted-file">
                    <strong><?= e($draft['arquivo'] ?? 'PDF') ?></strong>
                    <span>extraído com <?= e($openaiModel) ?></span>
                </div>

                <div class="source-grid source-grid-4">
                    <label>
                        <span>Nº OS / referência</span>
                        <input name="drafts[<?= e((string)$draftId) ?>][os]" value="<?= e($draft['os'] ?? '') ?>">
                    </label>

                    <label>
                        <span>Data</span>
                        <input type="date" name="drafts[<?= e((string)$draftId) ?>][data]" value="<?= e($draft['data'] ?? '') ?>" required>
                    </label>

                    <label>
                        <span>Responsável</span>
                        <select name="drafts[<?= e((string)$draftId) ?>][seller_user_id]">
                            <option value="">— vendedor a definir —</option>
                            <?php foreach($users as $u): ?>
                                <option value="<?= (int)$u['id'] ?>" <?= (int)($draft['seller_user_id'] ?? 0)===(int)$u['id']?'selected':'' ?>><?= e($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (($draft['vendedor'] ?? '') !== '' && empty($draft['seller_user_id'])): ?>
                            <small class="extract-hint">PDF informou: <?= e($draft['vendedor']) ?></small>
                        <?php endif; ?>
                    </label>

                    <label>
                        <span>Setor</span>
                        <input name="drafts[<?= e((string)$draftId) ?>][setor]" value="<?= e($draft['setor'] ?? '') ?>">
                    </label>

                    <label class="source-span-2">
                        <span>Cliente</span>
                        <input name="drafts[<?= e((string)$draftId) ?>][cliente]" value="<?= e($draft['cliente'] ?? '') ?>" required>
                    </label>

                    <label>
                        <span>Contato</span>
                        <input name="drafts[<?= e((string)$draftId) ?>][contato]" value="<?= e($draft['contato'] ?? '') ?>">
                    </label>

                    <label>
                        <span>Telefone</span>
                        <input name="drafts[<?= e((string)$draftId) ?>][fone]" value="<?= e($draft['fone'] ?? '') ?>">
                    </label>

                    <label class="source-span-2">
                        <span>Equipamentos citados</span>
                        <input name="drafts[<?= e((string)$draftId) ?>][equipamentos]" value="<?= e($draft['equipamentos'] ?? '') ?>">
                    </label>

                    <label>
                        <span>Data sugerida para retorno</span>
                        <input type="date" name="drafts[<?= e((string)$draftId) ?>][data_sugerida]" value="<?= e($draft['data_sugerida'] ?? '') ?>">
                    </label>

                    <label>
                        <span>Retorno / nova visita sugerida</span>
                        <input name="drafts[<?= e((string)$draftId) ?>][agendar]" value="<?= e($draft['agendar'] ?? '') ?>">
                    </label>
                </div>

                <label class="source-textarea">
                    <span>Oportunidades identificadas / desfecho</span>
                    <textarea name="drafts[<?= e((string)$draftId) ?>][oportunidade]" rows="4"><?= e($draft['oportunidade'] ?? '') ?></textarea>
                </label>
            </div>
        <?php endforeach; ?>

        <div class="extract-actions">
            <button class="btn btn-primary" type="submit">Importar visita(s)</button>
        </div>
    </form>

    <form method="post" class="extract-discard" onsubmit="return confirm('Descartar os dados extraídos?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_extracted">
        <button class="btn btn-ghost" type="submit">Descartar extração</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-title"><div><h3>Registrar atividade comercial (OS de campo / prospecção)</h3></div></div>
    <div class="source-help">Visitas espelham a OS Simplificada (iliot.tech). Prospecção de novo cliente é a atividade do canal interno (meta: 40/mês).</div>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="manual">

        <div class="source-grid source-grid-4">
            <label><span>Tipo</span><select name="visit_type"><option value="visita">Visita comercial</option><option value="prospeccao">Prospecção — novo cliente</option></select></label>
            <label><span>Nº OS / referência</span><input name="os_number" placeholder="4391"></label>
            <label><span>Data</span><input type="date" name="visit_date" value="<?= date('Y-m-d') ?>"></label>
            <label><span>Responsável</span><select name="seller_user_id"><option value="">—</option><?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></label>
            <label class="source-span-2"><span>Cliente</span><input name="client_name"></label>
            <label><span>Contato</span><input name="contact_name"></label>
            <label><span>Setor</span><input name="sector"></label>
        </div>

        <label class="source-textarea">
            <span>Oportunidades identificadas / desfecho</span>
            <textarea name="opportunity_text" rows="4"></textarea>
        </label>

        <button class="btn btn-primary source-mt" type="submit">Registrar</button>
    </form>
</div>

<div class="card">
    <div class="card-title"><div><h3>Importar visitas / relatórios iliot (JSON)</h3></div></div>
    <div class="source-help">Cole o JSON com as visitas (campos: os, data, tipo [visita|prospeccao], cliente, vendedor, contato, setor, oportunidade) — aceita lista.</div>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_json">
        <textarea name="visits_json" class="source-json" rows="4" placeholder='[{"os":"4391","data":"2026-07-15","tipo":"visita","cliente":"Cliente","vendedor":"Nome do vendedor","oportunidade":"..."}]'></textarea>
        <div class="source-inline"><button class="btn btn-secondary" type="submit">Importar visitas</button></div>
    </form>
</div>

<div class="card">
    <div class="card-title"><div><h3>Atividades registradas (<?= count($rows) ?>)</h3></div></div>

    <div class="table-wrap">
        <table class="source-table">
            <thead>
                <tr><th>Data</th><th>Tipo</th><th>OS</th><th>Cliente</th><th>Responsável</th><th>Oportunidade</th><th>Virar oportunidade?</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach($rows as $row): ?>
                <tr>
                    <td><?= e($row['visit_date']) ?></td>
                    <td><span class="source-pill <?= $row['visit_type']==='prospeccao'?'source-pill-blue':'' ?>"><?= e($row['visit_type']==='prospeccao'?'Prospecção':'Visita') ?></span></td>
                    <td class="mono"><?= e($row['os_number'] ?: '') ?></td>
                    <td><strong><?= e($row['client_name']) ?></strong><small><?= e($row['contact_name'] ?: '') ?></small></td>
                    <td><?= e($row['seller_name'] ?: '—') ?></td>
                    <td class="source-opportunity"><?= e($row['opportunity_text'] ?: '—') ?></td>
                    <td>
                        <?php if (!empty($row['opportunity_id'])): ?>
                            <a class="visit-opportunity-done" href="orcamentos.php?edit=<?= (int)$row['opportunity_id'] ?>#opportunity-form">
                                Oportunidade <?= e($row['opportunity_number'] ?: 'criada') ?>
                            </a>
                        <?php else: ?>
                            <a class="visit-opportunity-btn" href="orcamentos.php?from_visit=<?= (int)$row['id'] ?>#opportunity-form">
                                Virar oportunidade?
                            </a>
                        <?php endif; ?>
                    </td>
                    <td class="source-right">
                        <form method="post" onsubmit="return confirm('Excluir esta atividade?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <button class="source-x" type="submit">✕</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if(!$rows): ?>
                <tr><td colspan="8" class="empty-row">Nenhuma atividade registrada.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>
<?php page_footer(); ?>
