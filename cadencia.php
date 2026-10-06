<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

require_auth();
$pdo = db();
ensure_commercial_automation_schema($pdo);

$openCount = (int)$pdo->query("SELECT COUNT(*) FROM opportunities WHERE status='Aberta'")->fetchColumn();
$followups = (int)$pdo->query(
    "SELECT COUNT(*) FROM agenda
     WHERE event_type='followup'
       AND status IN ('sugerido','agendado','confirmado')"
)->fetchColumn();
$returns = (int)$pdo->query(
    "SELECT COUNT(*) FROM agenda
     WHERE event_type='visita_retorno'
       AND status IN ('sugerido','agendado','confirmado')"
)->fetchColumn();

$dueRows = $pdo->query(
    "SELECT a.*,u.name user_name,u.email user_email
     FROM agenda a
     LEFT JOIN users u ON u.id=a.user_id
     WHERE a.event_type IN ('followup','visita_retorno')
       AND a.status IN ('sugerido','agendado','confirmado')
       AND a.event_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
     ORDER BY a.event_date ASC,a.id ASC
     LIMIT 60"
)->fetchAll();

page_header('Cadência · Agente','cadencia');
?>

<div class="page-actions">
    <div>
        <span class="eyebrow">ASSISTÊNCIA</span>
        <h2>Cadência · Agente</h2>
        <p>Follow-ups e retornos comerciais gerados automaticamente a partir das visitas importadas.</p>
    </div>
</div>

<div class="kpi-grid kpi-grid-3">
    <article class="kpi">
        <span>Oportunidades abertas</span>
        <strong><?= $openCount ?></strong>
        <small>pipeline comercial atual</small>
    </article>
    <article class="kpi">
        <span>Follow-ups programados</span>
        <strong><?= $followups ?></strong>
        <small>automação padrão: +3 dias</small>
    </article>
    <article class="kpi">
        <span>Próximas visitas</span>
        <strong><?= $returns ?></strong>
        <small>automação padrão: +60 dias</small>
    </article>
</div>

<div class="card">
    <div class="card-title">
        <div>
            <span class="tag">PRÓXIMOS 7 DIAS</span>
            <h3>Follow-ups e retornos que exigem atenção</h3>
            <p>Os avisos automáticos são enviados pelo Resend ao colaborador responsável quando a data programada chegar.</p>
        </div>
    </div>

    <?php if (!$dueRows): ?>
        <?php empty_state('Nenhuma cadência próxima','Não há follow-ups ou visitas de retorno vencendo nos próximos 7 dias.'); ?>
    <?php else: ?>
        <div class="cadence-list">
            <?php foreach ($dueRows as $row):
                $isFollowup = ($row['event_type'] ?? '') === 'followup';
            ?>
                <article class="cadence-item">
                    <div class="cadence-date">
                        <strong><?= e(date('d/m',strtotime((string)$row['event_date']))) ?></strong>
                        <span><?= $isFollowup ? 'follow-up' : 'visita' ?></span>
                    </div>
                    <div class="cadence-copy">
                        <div>
                            <span class="tag"><?= $isFollowup ? 'FOLLOW-UP' : 'PRÓXIMA VISITA' ?></span>
                            <h4><?= e($row['client_name']) ?></h4>
                        </div>
                        <p><?= e($row['objective']) ?></p>
                        <small>
                            <?= e($row['user_name'] ?: 'Sem responsável') ?>
                            <?php if ($row['reminder_sent_at']): ?>
                                · e-mail enviado <?= e(date('d/m H:i',strtotime((string)$row['reminder_sent_at']))) ?>
                            <?php else: ?>
                                · aviso automático pendente
                            <?php endif; ?>
                        </small>
                    </div>
                    <a class="table-action" href="cronograma.php">Abrir cronograma</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card agent-card">
    <div class="agent-symbol">✦</div>
    <div>
        <span class="tag">AUTOMAÇÃO COMERCIAL</span>
        <h3>Visita → follow-up → retorno</h3>
        <p>Quando uma visita é importada, o CRM agenda o follow-up para 3 dias e a próxima visita para 60 dias. Os modelos de e-mail podem ser editados em Configurações.</p>
        <p class="muted">O job diário já utilizado pelo CRM processa os avisos de orçamento, follow-up e cronograma no mesmo ciclo.</p>
    </div>
</div>

<?php page_footer(); ?>
