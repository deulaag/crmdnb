<?php
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/task_service.php';
header('Content-Type: application/json; charset=utf-8');
exigirPapel(PAPEL_ADMINISTRADOR);
$pdo = db();

if (!tarefasDisponiveis($pdo)) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'erro'=>'Instale a migração 20260909_crm_tasks.sql.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    echo json_encode(['ok'=>true,'csrf'=>csrfToken(),'settings'=>configuracaoTarefas($pdo)]);
    exit;
}

exigirCsrf();
$enabled = !empty($_POST['reminder_enabled']) ? 1 : 0;
$days = max(0, min(30, (int) ($_POST['reminder_days_before'] ?? 1)));
$template = trim((string) ($_POST['reminder_template'] ?? ''));
if ($template === '') {
    http_response_code(422);
    echo json_encode(['ok'=>false,'erro'=>'Informe o template do lembrete.']);
    exit;
}
$pdo->prepare('UPDATE crm_task_settings SET reminder_enabled=?,reminder_days_before=?,reminder_template=?,updated_by_user_id=?,updated_at=NOW() WHERE id=1')
    ->execute([$enabled,$days,mb_substr($template,0,1000),(int)usuarioLogado()['id']]);
echo json_encode(['ok'=>true,'mensagem'=>'Template de tarefas salvo.','settings'=>configuracaoTarefas($pdo)]);
