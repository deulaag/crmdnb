<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

$user = require_auth();
$pdo = db();
$openai = integration_get($pdo, 'openai');
$openaiReady = !empty($openai['is_active']) && secret_decrypt($openai['access_token'] ?? '') !== '';

function upload_files_array(array $files): array
{
    if (!isset($files['name'])) return [];
    if (!is_array($files['name'])) return [$files];
    $normalized = [];
    foreach ($files['name'] as $i => $name) {
        $normalized[] = [
            'name' => $name,
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
    }
    return $normalized;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'manual');
    try {
        if ($action === 'upload_pdf') {
            $files = upload_files_array($_FILES['visit_pdfs'] ?? []);
            if (!$files) throw new RuntimeException('Selecione pelo menos um PDF.');
            if (count($files) > 10) throw new RuntimeException('Envie no máximo 10 PDFs por vez.');

            $imported = 0; $updated = 0; $errors = [];
            $finfo = new finfo(FILEINFO_MIME_TYPE);

            foreach ($files as $file) {
                $name = (string) ($file['name'] ?? 'arquivo.pdf');
                try {
                    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('falha no upload');
                    $tmp = (string) ($file['tmp_name'] ?? '');
                    $size = (int) ($file['size'] ?? 0);
                    if ($size <= 0 || $size > 20 * 1024 * 1024) throw new RuntimeException('o arquivo deve ter até 20 MB');
                    if ($finfo->file($tmp) !== 'application/pdf') throw new RuntimeException('o arquivo não é um PDF válido');

                    $data = openai_extract_iliot_visit($pdo, $tmp, $name);
                    if ($data['cliente'] === '') throw new RuntimeException('cliente não identificado no documento');
                    if (!valid_date_string($data['data'])) throw new RuntimeException('data da visita não identificada no documento');

                    $sellerId = find_user_id_by_name($pdo, $data['vendedor']);
                    $existingId = null;
                    if ($data['os'] !== '') {
                        $q = $pdo->prepare('SELECT id FROM visits WHERE os_number = ? LIMIT 1');
                        $q->execute([$data['os']]);
                        $existingId = $q->fetchColumn();
                    }

                    if ($existingId) {
                        $q = $pdo->prepare('UPDATE visits SET visit_date=?,client_name=?,seller_user_id=?,visit_type="visita",contact_name=?,phone=?,sector=?,equipment=?,summary="",opportunity_text=?,source="ia-pdf",updated_at=CURRENT_TIMESTAMP WHERE id=?');
                        $q->execute([$data['data'],$data['cliente'],$sellerId,$data['contato'],$data['fone'],$data['setor'],$data['equipamentos'],$data['oportunidade'],(int)$existingId]);
                        $visitId = (int) $existingId;
                        $updated++;
                    } else {
                        $q = $pdo->prepare('INSERT INTO visits (os_number,visit_date,client_name,seller_user_id,visit_type,contact_name,phone,sector,equipment,summary,opportunity_text,source,created_by) VALUES (?,?,?,?,"visita",?,?,?,?,"",?,"ia-pdf",?)');
                        $q->execute([$data['os'] ?: null,$data['data'],$data['cliente'],$sellerId,$data['contato'],$data['fone'],$data['setor'],$data['equipamentos'],$data['oportunidade'],(int)$user['id']]);
                        $visitId = (int) $pdo->lastInsertId();
                        $imported++;
                    }

                    $clientNeedle = '%' . substr(strtolower($data['cliente']), 0, 24) . '%';
                    $q = $pdo->prepare("SELECT id FROM agenda WHERE LOWER(client_name) LIKE ? AND status IN ('sugerido','agendado','confirmado') AND event_date BETWEEN DATE_SUB(?, INTERVAL 8 DAY) AND DATE_ADD(?, INTERVAL 8 DAY) ORDER BY event_date LIMIT 1");
                    $q->execute([$clientNeedle, $data['data'], $data['data']]);
                    $agendaId = $q->fetchColumn();
                    if ($agendaId) {
                        $pdo->prepare("UPDATE agenda SET status='concluido',visit_id=? WHERE id=?")->execute([$visitId, (int) $agendaId]);
                    }

                    if ($data['agendar'] !== '') {
                        $suggestedDate = valid_date_string($data['data_sugerida']) ? $data['data_sugerida'] : (new DateTimeImmutable($data['data']))->modify('+14 days')->format('Y-m-d');
                        $q = $pdo->prepare("SELECT COUNT(*) FROM agenda WHERE visit_id=? AND status='sugerido'");
                        $q->execute([$visitId]);
                        if (!(int) $q->fetchColumn()) {
                            $q = $pdo->prepare('INSERT INTO agenda (event_date,event_time,client_name,user_id,contact_name,objective,status,source,visit_id,created_by) VALUES (?,NULL,?,?,?,?,"sugerido","ia-os",?,?)');
                            $contact = trim(implode(' · ', array_filter([$data['contato'], $data['fone']])));
                            $q->execute([$suggestedDate,$data['cliente'],$sellerId,$contact,$data['agendar'],$visitId,(int)$user['id']]);
                        }
                    }

                    $modelExtra = json_decode((string)($openai['extra_json'] ?? '{}'), true) ?: [];
                    $modelName = trim((string)($modelExtra['model'] ?? '')) ?: 'gpt-5.6-sol';
                    $q = $pdo->prepare('INSERT INTO ai_logs (feature,entity_type,entity_id,model_name,status,prompt_hash,response_excerpt,created_by) VALUES ("visita_pdf","visit",?,?,?,?,?,?)');
                    $q->execute([$visitId,$modelName,'success',hash('sha256','iliot-visit-v1'),substr($data['oportunidade'],0,500),(int)$user['id']]);
                    audit($pdo,(int)$user['id'],'import_pdf','visit',$visitId,['file'=>$name,'os'=>$data['os']]);
                } catch (Throwable $fileError) {
                    $errors[] = $name . ': ' . $fileError->getMessage();
                }
            }

            $parts = [];
            if ($imported) $parts[] = $imported . ' importado(s)';
            if ($updated) $parts[] = $updated . ' atualizado(s)';
            if ($errors) $parts[] = count($errors) . ' com erro';
            $message = 'Processamento concluído: ' . implode(', ', $parts) . '.';
            if ($errors) $message .= ' ' . implode(' | ', array_slice($errors, 0, 3));
            flash($errors && !$imported && !$updated ? 'danger' : ($errors ? 'warning' : 'success'), $message);
        } else {
            $client = trim((string) ($_POST['client_name'] ?? ''));
            if ($client === '') throw new RuntimeException('Informe o cliente.');
            $q=$pdo->prepare('INSERT INTO visits (os_number,visit_date,client_name,seller_user_id,visit_type,contact_name,phone,sector,equipment,summary,opportunity_text,source,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,"manual",?)');
            $q->execute([trim((string)($_POST['os_number']??''))?:null,(string)($_POST['visit_date']??date('Y-m-d')),$client,($_POST['seller_user_id']??'')!==''?(int)$_POST['seller_user_id']:null,in_array($_POST['visit_type']??'visita',['visita','prospeccao'],true)?$_POST['visit_type']:'visita',trim((string)($_POST['contact_name']??'')),trim((string)($_POST['phone']??'')),trim((string)($_POST['sector']??'')),trim((string)($_POST['equipment']??'')),trim((string)($_POST['summary']??'')),trim((string)($_POST['opportunity_text']??'')),(int)$user['id']]);
            $id=(int)$pdo->lastInsertId(); audit($pdo,(int)$user['id'],'create','visit',$id); flash('success','Visita registrada.');
        }
    } catch (Throwable $e) {
        flash('danger',$e->getMessage());
    }
    redirect('visitas.php');
}

$users=$pdo->query("SELECT id,name FROM users WHERE active=1 ORDER BY name")->fetchAll();
$rows=$pdo->query("SELECT v.*,u.name seller_name FROM visits v LEFT JOIN users u ON u.id=v.seller_user_id ORDER BY visit_date DESC,id DESC LIMIT 300")->fetchAll();
page_header('Visitas','visitas');
?>
<div class="page-actions"><div><span class="eyebrow">CAMPO</span><h2>Visitas e OS de campo</h2><p>Importe as OS Simplificadas do iliot ou registre a atividade manualmente.</p></div></div>

<div class="card">
    <div class="card-title"><div><span class="tag">PDF + IA</span><h3>Importar OS de visita do iliot (PDF)</h3><p>Anexe as OS Simplificadas exportadas do iliot (app.iliot.tech). O agente extrai nº da OS, data do primeiro check-in, cliente, vendedor, contato, telefone, setor, equipamentos e o comentário de oportunidades. Aceita vários PDFs de uma vez.</p></div></div>
    <?php if (!$openaiReady): ?><div class="alert alert-warning">A área de upload está ativa, mas a leitura automática só funcionará depois de configurar e ativar <strong>ChatGPT / OpenAI</strong> em Configurações.</div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="upload-form" data-upload-form>
        <?= csrf_field() ?><input type="hidden" name="action" value="upload_pdf">
        <label class="upload-drop"><span class="upload-icon">PDF</span><span><strong>Selecionar OS em PDF</strong><small>Até 10 arquivos por vez · máximo 20 MB por PDF</small></span><input type="file" name="visit_pdfs[]" accept="application/pdf,.pdf" multiple required></label>
        <div class="upload-selected" data-upload-selected>Nenhum arquivo selecionado.</div>
        <div class="form-actions"><button class="btn btn-primary" type="submit" data-upload-button <?= !$openaiReady ? 'disabled' : '' ?>>Ler e importar PDFs</button></div>
    </form>
</div>

<div class="card">
    <div class="card-title"><div><span class="tag">ATIVIDADE</span><h3>Registrar atividade comercial (OS de campo / prospecção)</h3><p>Visitas espelham a OS Simplificada do iliot. Também é possível registrar prospecção manualmente.</p></div></div>
    <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="manual">
        <label><span>Tipo</span><select name="visit_type"><option value="visita">Visita comercial</option><option value="prospeccao">Prospecção — novo cliente</option></select></label>
        <label><span>Nº OS / referência</span><input name="os_number"></label>
        <label><span>Data</span><input type="date" name="visit_date" value="<?= date('Y-m-d') ?>" required></label>
        <label><span>Responsável</span><select name="seller_user_id"><option value="">—</option><?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></label>
        <label class="span-2"><span>Cliente</span><input name="client_name" required></label>
        <label><span>Contato</span><input name="contact_name"></label><label><span>Telefone</span><input name="phone"></label>
        <label><span>Setor</span><input name="sector"></label><label><span>Equipamentos citados</span><input name="equipment"></label>
        <label class="span-2"><span>Resumo</span><textarea name="summary" rows="3"></textarea></label>
        <label class="span-2"><span>Oportunidades identificadas / desfecho</span><textarea name="opportunity_text" rows="4"></textarea></label>
        <div class="span-2 form-actions"><button class="btn btn-primary" type="submit">Registrar</button></div>
    </form>
</div>

<div class="card"><div class="card-title"><div><span class="tag">HISTÓRICO</span><h3>Atividades registradas (<?= count($rows) ?>)</h3></div></div>
<?php if(!$rows): ?><?php empty_state('Nenhuma atividade registrada','Os registros reais de campo aparecerão aqui.'); ?>
<?php else: ?><div class="table-wrap"><table><thead><tr><th>Data</th><th>Tipo</th><th>OS</th><th>Cliente</th><th>Responsável</th><th>Contato</th><th>Oportunidade</th><th>Origem</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><?= e($row['visit_date']) ?></td><td><?= e($row['visit_type']==='prospeccao'?'Prospecção':'Visita') ?></td><td class="mono"><?= e($row['os_number']?:'—') ?></td><td><strong><?= e($row['client_name']) ?></strong><?php if($row['equipment']): ?><small><?= e($row['equipment']) ?></small><?php endif; ?></td><td><?= e($row['seller_name']?:'—') ?></td><td><?= e($row['contact_name']?:'—') ?><small><?= e($row['phone']?:'') ?></small></td><td><?= e($row['opportunity_text']?:'—') ?></td><td><?= e($row['source']==='ia-pdf'?'PDF + IA':'Manual') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></div>
<?php page_footer(); ?>
