<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$user = require_auth();
$pdo = db();
ensure_opportunity_visit_schema($pdo);
ensure_opportunity_tracking_schema($pdo);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id < 1) {
    flash('warning', 'Selecione uma oportunidade.');
    redirect('orcamentos.php');
}

function load_opportunity_detail(PDO $pdo, int $id): array
{
    $q = $pdo->prepare(
        'SELECT o.*,
                pl.name line_name,
                f.label funnel_label,
                fs.name stage_name,
                seller.name seller_name,seller.email seller_email,
                support.name support_name,support.email support_email,
                c.cnpj company_cnpj,
                v.os_number visit_os,v.visit_date source_visit_date
         FROM opportunities o
         JOIN product_lines pl ON pl.id=o.product_line_id
         JOIN funnels f ON f.id=o.funnel_id
         JOIN funnel_stages fs ON fs.id=o.stage_id
         LEFT JOIN users seller ON seller.id=o.seller_user_id
         LEFT JOIN users support ON support.id=o.support_user_id
         LEFT JOIN companies c ON c.id=o.company_id
         LEFT JOIN visits v ON v.id=o.visit_id
         WHERE o.id=? LIMIT 1'
    );
    $q->execute([$id]);
    $row = $q->fetch();
    if (!$row) {
        throw new RuntimeException('Oportunidade não encontrada.');
    }
    return $row;
}

try {
    $opportunity = load_opportunity_detail($pdo, $id);
} catch (Throwable $e) {
    flash('danger', $e->getMessage());
    redirect('orcamentos.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'add_note') {
            $note = trim((string)($_POST['note'] ?? ''));
            if ($note === '') {
                throw new RuntimeException('Escreva uma anotação antes de salvar.');
            }
            if (mb_strlen($note) > 5000) {
                throw new RuntimeException('A anotação deve ter no máximo 5.000 caracteres.');
            }

            $q = $pdo->prepare('INSERT INTO opportunity_notes (opportunity_id,user_id,note) VALUES (?,?,?)');
            $q->execute([$id,(int)$user['id'],$note]);

            audit($pdo, (int)$user['id'], 'add_note', 'opportunity', $id);
            flash('success', 'Anotação adicionada ao histórico.');

        } elseif ($action === 'quote_complete') {
            if (!(int)$opportunity['quote_required']) {
                throw new RuntimeException('Esta oportunidade não possui pendência de orçamento.');
            }

            $reference = trim((string)($_POST['quote_reference'] ?? ''));
            if ($reference === '') {
                throw new RuntimeException('Informe o número ou referência do orçamento realizado.');
            }

            $pdo->prepare(
                'UPDATE opportunities
                 SET quote_completed_at=NOW(),quote_reference=?,quote_reminder_last_sent_at=NULL,updated_at=CURRENT_TIMESTAMP
                 WHERE id=?'
            )->execute([$reference,$id]);

            $note = 'Orçamento realizado · referência ' . $reference . '.';
            $pdo->prepare('INSERT INTO opportunity_notes (opportunity_id,user_id,note) VALUES (?,?,?)')
                ->execute([$id,(int)$user['id'],$note]);

            audit($pdo, (int)$user['id'], 'quote_complete', 'opportunity', $id, ['reference'=>$reference]);
            flash('success', 'Pendência de orçamento concluída. Os lembretes diários foram encerrados.');

        } elseif ($action === 'status_won') {
            if ((int)$opportunity['quote_required'] === 1 && empty($opportunity['quote_completed_at'])) {
                throw new RuntimeException('Conclua primeiro a pendência "Realizar orçamento" antes de marcar a oportunidade como Ganha.');
            }

            $pdo->prepare(
                'UPDATE opportunities
                 SET status="Ganha",loss_reason=NULL,loss_recorded_at=NULL,updated_at=CURRENT_TIMESTAMP
                 WHERE id=?'
            )->execute([$id]);

            $pdo->prepare('INSERT INTO opportunity_notes (opportunity_id,user_id,note) VALUES (?,?,?)')
                ->execute([$id,(int)$user['id'],'Oportunidade marcada como Ganha.']);

            audit($pdo, (int)$user['id'], 'status_won', 'opportunity', $id);
            flash('success', 'Oportunidade marcada como Ganha.');

        } elseif ($action === 'status_lost') {
            $reason = trim((string)($_POST['loss_reason'] ?? ''));
            if ($reason === '') {
                throw new RuntimeException('Informe o motivo da perda para manter o rastreio comercial.');
            }
            if (mb_strlen($reason) > 3000) {
                throw new RuntimeException('O motivo da perda deve ter no máximo 3.000 caracteres.');
            }

            $pdo->prepare(
                'UPDATE opportunities
                 SET status="Perdida",loss_reason=?,loss_recorded_at=NOW(),updated_at=CURRENT_TIMESTAMP
                 WHERE id=?'
            )->execute([$reason,$id]);

            $pdo->prepare('INSERT INTO opportunity_notes (opportunity_id,user_id,note) VALUES (?,?,?)')
                ->execute([$id,(int)$user['id'],'Oportunidade perdida. Motivo: ' . $reason]);

            audit($pdo, (int)$user['id'], 'status_lost', 'opportunity', $id, ['reason'=>$reason]);
            flash('success', 'Perda registrada com o motivo.');

        } elseif ($action === 'status_reopen') {
            $pdo->prepare(
                'UPDATE opportunities
                 SET status="Aberta",loss_reason=NULL,loss_recorded_at=NULL,updated_at=CURRENT_TIMESTAMP
                 WHERE id=?'
            )->execute([$id]);

            $pdo->prepare('INSERT INTO opportunity_notes (opportunity_id,user_id,note) VALUES (?,?,?)')
                ->execute([$id,(int)$user['id'],'Oportunidade reaberta.']);

            audit($pdo, (int)$user['id'], 'status_reopen', 'opportunity', $id);
            flash('success', 'Oportunidade reaberta.');

        } else {
            throw new RuntimeException('Ação inválida.');
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect('oportunidade.php?id=' . $id);
}

$opportunity = load_opportunity_detail($pdo, $id);

$notesQ = $pdo->prepare(
    'SELECT n.*,u.name author_name
     FROM opportunity_notes n
     JOIN users u ON u.id=n.user_id
     WHERE n.opportunity_id=?
     ORDER BY n.id DESC'
);
$notesQ->execute([$id]);
$timeline = $notesQ->fetchAll();

$pendingSince = $opportunity['quote_required_at'] ?: $opportunity['created_at'];
$pendingDays = 0;
if ((int)$opportunity['quote_required'] && empty($opportunity['quote_completed_at']) && $pendingSince) {
    $start = new DateTimeImmutable((string)$pendingSince);
    $pendingDays = max(0, (int)$start->diff(new DateTimeImmutable('now'))->format('%a'));
}

page_header('Oportunidade ' . $opportunity['number'],'orcamentos');
?>

<div class="page-actions">
    <div>
        <span class="eyebrow">OPORTUNIDADE</span>
        <h2><?= e($opportunity['number']) ?> · <?= e($opportunity['client_name']) ?></h2>
        <p>Acompanhe o andamento do cliente, as pendências e o histórico comercial desta oportunidade.</p>
    </div>
    <div class="opportunity-page-actions">
        <a class="btn btn-secondary" href="orcamentos.php?edit=<?= $id ?>#opportunity-form">Editar dados</a>
        <a class="btn btn-ghost" href="orcamentos.php">Voltar aos orçamentos</a>
    </div>
</div>

<?php if ((int)$opportunity['quote_required']): ?>
    <?php if (empty($opportunity['quote_completed_at'])): ?>
        <div class="quote-pending-banner">
            <div>
                <span class="tag">PENDÊNCIA</span>
                <h3>Realizar orçamento</h3>
                <p>
                    Esta oportunidade veio de uma visita e ainda não teve o orçamento concluído.
                    <?php if ($pendingDays > 0): ?>Pendência aberta há <strong><?= $pendingDays ?> dia(s)</strong>.<?php endif; ?>
                    O agente envia lembrete diário ao vendedor e ao suporte após as primeiras 24 horas.
                </p>
                <?php if ($opportunity['quote_reminder_last_sent_at']): ?>
                    <small>Último lembrete enviado em <?= e(date('d/m/Y H:i', strtotime((string)$opportunity['quote_reminder_last_sent_at']))) ?>.</small>
                <?php endif; ?>
            </div>

            <form method="post" class="quote-complete-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="quote_complete">
                <label>
                    <span>Nº / referência do orçamento</span>
                    <input name="quote_reference" placeholder="Ex.: 45872" required>
                </label>
                <button class="btn btn-primary" type="submit">Realizar orçamento</button>
            </form>
        </div>
    <?php else: ?>
        <div class="quote-complete-banner">
            <strong>Orçamento realizado</strong>
            <span>
                Referência <?= e($opportunity['quote_reference'] ?: '—') ?> ·
                concluído em <?= e(date('d/m/Y H:i', strtotime((string)$opportunity['quote_completed_at']))) ?>
            </span>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="opportunity-detail-grid">
    <section class="card opportunity-summary-card">
        <div class="card-title">
            <div>
                <span class="tag">DADOS COMERCIAIS</span>
                <h3>Resumo da oportunidade</h3>
            </div>
            <span class="status status-<?= strtolower(e($opportunity['status'])) ?>"><?= e($opportunity['status']) ?></span>
        </div>

        <dl class="opportunity-meta">
            <div><dt>Cliente</dt><dd><?= e($opportunity['client_name']) ?><?php if($opportunity['company_cnpj']): ?><small><?= e(format_cnpj($opportunity['company_cnpj'])) ?></small><?php endif; ?></dd></div>
            <div><dt>Data</dt><dd><?= e(date('d/m/Y', strtotime((string)$opportunity['opportunity_date']))) ?></dd></div>
            <div><dt>Vendedor</dt><dd><?= e($opportunity['seller_name'] ?: '—') ?><small><?= e($opportunity['seller_email'] ?: '') ?></small></dd></div>
            <div><dt>Suporte</dt><dd><?= e($opportunity['support_name'] ?: '—') ?><small><?= e($opportunity['support_email'] ?: '') ?></small></dd></div>
            <div><dt>Linha</dt><dd><?= e($opportunity['line_name']) ?></dd></div>
            <div><dt>Funil / etapa</dt><dd><?= e($opportunity['funnel_label']) ?><small><?= e($opportunity['stage_name']) ?></small></dd></div>
            <div><dt>Valor</dt><dd><strong><?= money($opportunity['amount']) ?></strong></dd></div>
            <div><dt>Origem</dt><dd><?= e($opportunity['source']) ?><?php if($opportunity['visit_os']): ?><small>OS <?= e($opportunity['visit_os']) ?> · <?= e($opportunity['source_visit_date']) ?></small><?php endif; ?></dd></div>
        </dl>

        <?php if ($opportunity['notes']): ?>
            <div class="opportunity-initial-note">
                <strong>Observação inicial</strong>
                <p><?= nl2br(e($opportunity['notes'])) ?></p>
            </div>
        <?php endif; ?>
    </section>

    <section class="card opportunity-result-card" id="resultado">
        <div class="card-title">
            <div>
                <span class="tag">RESULTADO</span>
                <h3>Status e motivo da perda</h3>
                <p>O motivo da perda é obrigatório para manter rastreabilidade comercial.</p>
            </div>
        </div>

        <?php if ($opportunity['status'] === 'Aberta'): ?>
            <form method="post" class="stack-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="status_won">
                <button class="btn btn-primary" type="submit">Marcar como Ganha</button>
            </form>

            <form method="post" class="stack-form loss-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="status_lost">
                <label>
                    <span>Motivo da perda</span>
                    <textarea name="loss_reason" rows="5" placeholder="Descreva por que a oportunidade foi perdida..." required></textarea>
                </label>
                <button class="btn btn-danger" type="submit">Registrar como Perdida</button>
            </form>
        <?php elseif ($opportunity['status'] === 'Perdida'): ?>
            <div class="loss-trace">
                <strong>Motivo registrado</strong>
                <p><?= nl2br(e($opportunity['loss_reason'] ?: 'Motivo não informado.')) ?></p>
                <?php if($opportunity['loss_recorded_at']): ?><small><?= e(date('d/m/Y H:i', strtotime((string)$opportunity['loss_recorded_at']))) ?></small><?php endif; ?>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="status_reopen">
                <button class="btn btn-secondary" type="submit">Reabrir oportunidade</button>
            </form>
        <?php else: ?>
            <div class="success-trace"><strong>Oportunidade ganha</strong><span>O histórico e as anotações continuam disponíveis abaixo.</span></div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="status_reopen">
                <button class="btn btn-secondary" type="submit">Reabrir oportunidade</button>
            </form>
        <?php endif; ?>
    </section>
</div>

<section class="card opportunity-notes-card">
    <div class="card-title">
        <div>
            <span class="tag">ACOMPANHAMENTO</span>
            <h3>Anotações do andamento do cliente</h3>
            <p>Registre cada contato, decisão, pendência e próximo passo para preservar o histórico da negociação.</p>
        </div>
    </div>

    <form method="post" class="opportunity-note-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="add_note">
        <label>
            <span>Nova anotação</span>
            <textarea name="note" rows="4" placeholder="Ex.: cliente pediu revisão da proposta; retorno combinado para sexta-feira..." required></textarea>
        </label>
        <button class="btn btn-primary" type="submit">Adicionar anotação</button>
    </form>

    <div class="opportunity-timeline">
        <?php foreach ($timeline as $entry): ?>
            <article class="timeline-entry">
                <div class="timeline-dot" aria-hidden="true"></div>
                <div>
                    <div class="timeline-meta">
                        <strong><?= e($entry['author_name']) ?></strong>
                        <span><?= e(date('d/m/Y H:i', strtotime((string)$entry['created_at']))) ?></span>
                    </div>
                    <p><?= nl2br(e($entry['note'])) ?></p>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if (!$timeline): ?>
            <?php empty_state('Nenhuma anotação registrada','Use o campo acima para começar o acompanhamento desta oportunidade.'); ?>
        <?php endif; ?>
    </div>
</section>

<?php page_footer(); ?>
