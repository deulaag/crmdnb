<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$user = require_auth();
$pdo = db();
ensure_commercial_automation_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'create');

    try {
        if ($action === 'create') {
            $date = (string)($_POST['event_date'] ?? date('Y-m-d'));
            $client = trim((string)($_POST['client_name'] ?? ''));
            $objective = trim((string)($_POST['objective'] ?? ''));
            $status = in_array($_POST['status'] ?? 'agendado', ['sugerido','agendado','confirmado'], true)
                ? (string)$_POST['status']
                : 'agendado';
            $eventType = in_array($_POST['event_type'] ?? 'compromisso', ['compromisso','followup','visita_retorno'], true)
                ? (string)$_POST['event_type']
                : 'compromisso';

            if (!valid_date_string($date) || $client === '' || $objective === '') {
                throw new RuntimeException('Informe data, cliente e objetivo do compromisso.');
            }

            $q = $pdo->prepare(
                'INSERT INTO agenda
                 (event_date,event_time,client_name,user_id,contact_name,objective,status,event_type,auto_generated,source,created_by)
                 VALUES (?,?,?,?,?,?,?, ?,0,"manual",?)'
            );
            $q->execute([
                $date,
                trim((string)($_POST['event_time'] ?? '')) ?: null,
                $client,
                ($_POST['user_id'] ?? '') !== '' ? (int)$_POST['user_id'] : null,
                trim((string)($_POST['contact_name'] ?? '')) ?: null,
                $objective,
                $status,
                $eventType,
                (int)$user['id'],
            ]);

            $id = (int)$pdo->lastInsertId();
            audit($pdo, (int)$user['id'], 'create', 'agenda', $id, ['event_type'=>$eventType]);
            flash('success', 'Compromisso adicionado ao Cronograma Comercial.');

        } elseif ($action === 'status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');

            if ($id < 1 || !in_array($status, ['agendado','confirmado','concluido','cancelado'], true)) {
                throw new RuntimeException('Atualização de cronograma inválida.');
            }

            $pdo->prepare('UPDATE agenda SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,$id]);
            audit($pdo, (int)$user['id'], 'update_status', 'agenda', $id, ['status'=>$status]);
            flash('success', 'Status do compromisso atualizado.');
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect('cronograma.php');
}

$users = $pdo->query("SELECT id,name,email FROM users WHERE active=1 ORDER BY name")->fetchAll();

$rows = $pdo->query(
    "SELECT a.*,u.name user_name,u.email user_email,v.os_number,v.visit_date source_visit_date
     FROM agenda a
     LEFT JOIN users u ON u.id=a.user_id
     LEFT JOIN visits v ON v.id=a.visit_id
     WHERE a.status <> 'cancelado'
     ORDER BY
       CASE WHEN a.status='concluido' THEN 1 ELSE 0 END,
       a.event_date ASC,
       a.event_time ASC,
       a.id ASC
     LIMIT 400"
)->fetchAll();

$followupCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM agenda
     WHERE event_type='followup' AND status IN ('sugerido','agendado','confirmado')"
)->fetchColumn();

$returnCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM agenda
     WHERE event_type='visita_retorno' AND status IN ('sugerido','agendado','confirmado')"
)->fetchColumn();

$emailPendingCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM agenda
     WHERE event_type IN ('followup','visita_retorno')
       AND auto_generated=1
       AND status IN ('sugerido','agendado','confirmado')
       AND reminder_sent_at IS NULL"
)->fetchColumn();

page_header('Cronograma','cronograma');
?>

<div class="page-actions">
    <div>
        <span class="eyebrow">AGENDA</span>
        <h2>Cronograma comercial</h2>
        <p>Visitas, retornos e follow-ups programados. Visitas importadas geram automaticamente follow-up em 3 dias e próxima visita em 60 dias.</p>
    </div>
</div>

<div class="kpi-grid kpi-grid-3">
    <article class="kpi">
        <span>Follow-ups programados</span>
        <strong><?= $followupCount ?></strong>
        <small>retorno pós-visita em +3 dias</small>
    </article>
    <article class="kpi">
        <span>Próximas visitas</span>
        <strong><?= $returnCount ?></strong>
        <small>retorno comercial em +60 dias</small>
    </article>
    <article class="kpi">
        <span>Avisos de e-mail pendentes</span>
        <strong><?= $emailPendingCount ?></strong>
        <small>processados pelo Resend no Cron diário</small>
    </article>
</div>

<div class="card">
    <div class="card-title">
        <div>
            <span class="tag">NOVO COMPROMISSO</span>
            <h3>Adicionar ao cronograma</h3>
        </div>
    </div>

    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">

        <label>
            <span>Data</span>
            <input type="date" name="event_date" value="<?= date('Y-m-d') ?>" required>
        </label>
        <label>
            <span>Hora</span>
            <input type="time" name="event_time">
        </label>
        <label class="span-2">
            <span>Cliente</span>
            <input name="client_name" required>
        </label>

        <label>
            <span>Responsável</span>
            <select name="user_id">
                <option value="">—</option>
                <?php foreach($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            <span>Tipo</span>
            <select name="event_type">
                <option value="compromisso">Compromisso</option>
                <option value="followup">Follow-up</option>
                <option value="visita_retorno">Próxima visita</option>
            </select>
        </label>

        <label>
            <span>Status</span>
            <select name="status">
                <option value="agendado">Agendado</option>
                <option value="confirmado">Confirmado</option>
                <option value="sugerido">Sugerido</option>
            </select>
        </label>

        <label>
            <span>Contato</span>
            <input name="contact_name">
        </label>

        <label class="span-2">
            <span>Objetivo</span>
            <textarea name="objective" rows="3" required></textarea>
        </label>

        <div class="span-2 form-actions">
            <button class="btn btn-primary" type="submit">Adicionar ao cronograma</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-title">
        <div>
            <span class="tag">AGENDA</span>
            <h3>Registros programados</h3>
            <p>Os itens automáticos mostram a origem da visita e o estado do aviso por e-mail.</p>
        </div>
    </div>

    <?php if(!$rows): ?>
        <?php empty_state('Cronograma vazio','Nenhum compromisso de demonstração foi incluído.'); ?>
    <?php else: ?>
        <div class="commercial-timeline">
            <?php foreach($rows as $row):
                $type = (string)($row['event_type'] ?? 'compromisso');
                $typeLabel = match($type) {
                    'followup' => 'FOLLOW-UP · +3 DIAS',
                    'visita_retorno' => 'PRÓXIMA VISITA · +60 DIAS',
                    default => 'COMPROMISSO',
                };
            ?>
                <article class="commercial-timeline-item <?= e($type) ?>">
                    <div class="commercial-timeline-date">
                        <strong><?= e(date('d/m',strtotime((string)$row['event_date']))) ?></strong>
                        <span><?= e($row['event_time'] ? substr((string)$row['event_time'],0,5) : '—') ?></span>
                    </div>

                    <div class="commercial-timeline-content">
                        <div class="commercial-timeline-head">
                            <div>
                                <span class="tag"><?= e($typeLabel) ?></span>
                                <?php if ((int)($row['auto_generated'] ?? 0) === 1): ?><span class="auto-chip">automático</span><?php endif; ?>
                                <h4><?= e($row['client_name']) ?></h4>
                            </div>
                            <span class="status status-<?= e($row['status']) ?>"><?= e(ucfirst((string)$row['status'])) ?></span>
                        </div>

                        <p><?= e($row['objective']) ?></p>

                        <div class="commercial-timeline-meta">
                            <span>Responsável: <strong><?= e($row['user_name'] ?: 'Sem responsável') ?></strong></span>
                            <?php if ($row['contact_name']): ?><span>Contato: <?= e($row['contact_name']) ?></span><?php endif; ?>
                            <?php if ($row['os_number']): ?><span>Origem: OS <?= e($row['os_number']) ?></span><?php endif; ?>
                            <?php if ($row['source_visit_date']): ?><span>Visita anterior: <?= e(date('d/m/Y',strtotime((string)$row['source_visit_date']))) ?></span><?php endif; ?>
                        </div>

                        <?php if (in_array($type,['followup','visita_retorno'],true) && (int)($row['auto_generated'] ?? 0) === 1): ?>
                            <div class="email-reminder-state <?= $row['reminder_sent_at'] ? 'sent' : 'waiting' ?>">
                                <?php if ($row['reminder_sent_at']): ?>
                                    E-mail enviado em <?= e(date('d/m/Y H:i',strtotime((string)$row['reminder_sent_at']))) ?> para <?= e($row['user_email'] ?: 'responsável') ?>.
                                <?php else: ?>
                                    Resend notificará <?= e($row['user_name'] ?: 'o colaborador responsável') ?> quando este compromisso vencer.
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!in_array($row['status'],['concluido','cancelado'],true)): ?>
                            <div class="commercial-timeline-actions">
                                <?php if ($row['status'] !== 'confirmado'): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="status">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="status" value="confirmado">
                                        <button class="table-action" type="submit">Confirmar</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <input type="hidden" name="status" value="concluido">
                                    <button class="table-action success" type="submit">Concluir</button>
                                </form>

                                <form method="post" data-confirm="Cancelar este compromisso do Cronograma Comercial?" data-confirm-action="Cancelar">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <input type="hidden" name="status" value="cancelado">
                                    <button class="table-action danger" type="submit">Cancelar</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php page_footer(); ?>
