<?php
require_once __DIR__ . '/../../../includes/task_service.php';
require_once __DIR__ . '/../../../includes/whatsapp_provider.php';
header('Content-Type: application/json; charset=utf-8');
$tokenEsperado = whatsappEnv('DNB_WHATSAPP_CRON_TOKEN');
$tokenRecebido = (string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? $_GET['token'] ?? '');
if ($tokenEsperado === '' || $tokenRecebido === '' || !hash_equals($tokenEsperado, $tokenRecebido)) {
    http_response_code(401); echo json_encode(['ok'=>false,'erro'=>'Token de cron inválido.']); exit;
}
$pdo = db();
if (!tarefasDisponiveis($pdo)) {
    http_response_code(503); echo json_encode(['ok'=>false,'erro'=>'Módulo de tarefas ainda não instalado.']); exit;
}
$cfg = configuracaoTarefas($pdo);
if (empty($cfg['reminder_enabled'])) { echo json_encode(['ok'=>true,'sent'=>0]); exit; }
$dias = max(0,min(30,(int)$cfg['reminder_days_before']));
$stmt = $pdo->prepare("SELECT t.*,o.numero,o.cliente,u.nome responsavel_nome,DATEDIFF(t.due_date,CURDATE()) dias
    FROM crm_tasks t JOIN orcamentos o ON o.id=t.orcamento_id JOIN usuarios u ON u.id=t.assigned_to_user_id
    WHERE t.status='pending' AND t.reminder_sent_at IS NULL AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL ? DAY)
    ORDER BY t.due_date,t.id");
$stmt->execute([$dias]);
$sent = 0;
foreach ($stmt->fetchAll() as $tarefa) {
    $mensagem = renderizarTemplateTarefa((string)$cfg['reminder_template'],$tarefa);
    criarNotificacao($pdo,(int)$tarefa['assigned_to_user_id'],'crm_task_due','Tarefa próxima do prazo',$mensagem,'/painel.php');
    $pdo->prepare('UPDATE crm_tasks SET reminder_sent_at=NOW() WHERE id=?')->execute([(int)$tarefa['id']]);
    $sent++;
}
echo json_encode(['ok'=>true,'sent'=>$sent]);
