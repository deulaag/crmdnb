<?php
declare(strict_types=1);

/**
 * Regras de oportunidades copiadas de orcamentos.php / oportunidade.php (raiz),
 * mantendo validações, mensagens e chamadas de audit() idênticas.
 */

function nc_strlen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

/** Copiado de orcamentos.php: opportunity_line_context(). */
function nc_opp_line_context(PDO $pdo, int $lineId): array
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

/** Campos client_* enviados pelo formulário (preenchidos pela consulta CNPJá). */
function nc_opp_client_keys(): array
{
    return ['alias', 'registration_status', 'registration_status_date', 'founded', 'company_type', 'company_size',
        'legal_nature', 'capital_social', 'email', 'phone', 'zip', 'street', 'address_number', 'address_details',
        'district', 'industry', 'main_activity_code', 'city', 'state', 'cnpja_updated_at'];
}

/**
 * Baseado em orcamentos.php: opportunity_company_from_post().
 * Diferença de segurança: se o CNPJ já existe e o formulário não trouxe dados da consulta,
 * apenas vincula a empresa existente (não sobrescreve cadastro com campos em branco).
 */
function nc_opp_company_from_post(PDO $pdo, ?int $seller, ?int $currentCompanyId = null): ?int
{
    $cnpj = cnpj_digits((string)($_POST['client_cnpj'] ?? ''));
    if ($cnpj === '') {
        return $currentCompanyId;
    }
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

    $data = ['cnpj' => $cnpj, 'name' => trim((string)($_POST['client_name'] ?? ''))];
    $hasExtras = false;
    foreach (nc_opp_client_keys() as $key) {
        $data[$key] = trim((string)($_POST['client_' . $key] ?? ''));
        if ($data[$key] !== '') {
            $hasExtras = true;
        }
    }

    if (!$hasExtras) {
        $q = $pdo->prepare('SELECT id FROM companies WHERE cnpj = ? ORDER BY id ASC LIMIT 1');
        $q->execute([$cnpj]);
        $existing = (int)($q->fetchColumn() ?: 0);
        if ($existing > 0) {
            return $existing;
        }
    }

    return company_upsert_from_customer_data($pdo, $data, $seller, is_array($lookupData) ? ($lookupData['raw'] ?? null) : null);
}

/** Copiado de oportunidade.php: load_opportunity_detail(). */
function nc_opp_load(PDO $pdo, int $id): array
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

/**
 * Executa uma ação sobre a oportunidade. Retorna a mensagem de sucesso.
 * Ações: add_note, quote_complete, status_won, status_lost, status_reopen (oportunidade.php),
 *        stage (orcamentos.php), delete (orcamentos.php).
 */
function nc_opp_apply_action(PDO $pdo, array $user, int $id, string $action, array $post): string
{
    $userId = (int)$user['id'];

    if ($action === 'stage') {
        // orcamentos.php: bloco $action === 'stage'
        $stage = (int)($post['stage_id'] ?? 0);

        $q = $pdo->prepare('SELECT funnel_id FROM opportunities WHERE id=?');
        $q->execute([$id]);
        $funnel = (int)$q->fetchColumn();

        $q = $pdo->prepare('SELECT COUNT(*) FROM funnel_stages WHERE id=? AND funnel_id=?');
        $q->execute([$stage, $funnel]);
        if (!(int)$q->fetchColumn()) {
            throw new RuntimeException('Etapa inválida.');
        }

        $pdo->prepare('UPDATE opportunities SET stage_id=? WHERE id=?')->execute([$stage, $id]);
        audit($pdo, $userId, 'update_stage', 'opportunity', $id);
        return 'Etapa atualizada.';
    }

    if ($action === 'delete') {
        // orcamentos.php: bloco $action === 'delete'
        $q = $pdo->prepare('SELECT number,visit_id FROM opportunities WHERE id=? LIMIT 1');
        $q->execute([$id]);
        $opportunity = $q->fetch();
        if (!$opportunity) {
            throw new RuntimeException('Oportunidade não encontrada.');
        }

        $pdo->prepare('DELETE FROM opportunities WHERE id=?')->execute([$id]);

        $visitId = (int)($opportunity['visit_id'] ?? 0);
        if ($visitId > 0) {
            $q = $pdo->prepare('SELECT COUNT(*) FROM opportunities WHERE visit_id=?');
            $q->execute([$visitId]);
            if (!(int)$q->fetchColumn()) {
                $pdo->prepare("UPDATE visits SET lead_status='pendente' WHERE id=?")->execute([$visitId]);
            }
        }

        audit($pdo, $userId, 'delete', 'opportunity', $id, ['number' => $opportunity['number']]);
        return 'Oportunidade ' . $opportunity['number'] . ' excluída.';
    }

    $opportunity = nc_opp_load($pdo, $id);

    if ($action === 'add_note') {
        $note = trim((string)($post['note'] ?? ''));
        if ($note === '') {
            throw new RuntimeException('Escreva uma anotação antes de salvar.');
        }
        if (nc_strlen($note) > 5000) {
            throw new RuntimeException('A anotação deve ter no máximo 5.000 caracteres.');
        }

        $q = $pdo->prepare('INSERT INTO opportunity_notes (opportunity_id,user_id,note) VALUES (?,?,?)');
        $q->execute([$id, $userId, $note]);

        audit($pdo, $userId, 'add_note', 'opportunity', $id);
        return 'Anotação adicionada ao histórico.';
    }

    if ($action === 'quote_complete') {
        if (!(int)$opportunity['quote_required']) {
            throw new RuntimeException('Esta oportunidade não possui pendência de orçamento.');
        }

        $reference = trim((string)($post['quote_reference'] ?? ''));
        if ($reference === '') {
            throw new RuntimeException('Informe o número ou referência do orçamento realizado.');
        }

        $pdo->prepare(
            'UPDATE opportunities
             SET quote_completed_at=NOW(),quote_reference=?,quote_reminder_last_sent_at=NULL,updated_at=CURRENT_TIMESTAMP
             WHERE id=?'
        )->execute([$reference, $id]);

        $pdo->prepare('INSERT INTO opportunity_notes (opportunity_id,user_id,note) VALUES (?,?,?)')
            ->execute([$id, $userId, 'Orçamento realizado · referência ' . $reference . '.']);

        audit($pdo, $userId, 'quote_complete', 'opportunity', $id, ['reference' => $reference]);
        return 'Pendência de orçamento concluída. Os lembretes diários foram encerrados.';
    }

    if ($action === 'status_won') {
        if ((int)$opportunity['quote_required'] === 1 && empty($opportunity['quote_completed_at'])) {
            throw new RuntimeException('Conclua primeiro a pendência "Realizar orçamento" antes de marcar a oportunidade como Ganha.');
        }

        $pdo->prepare(
            'UPDATE opportunities
             SET status="Ganha",loss_reason=NULL,loss_recorded_at=NULL,updated_at=CURRENT_TIMESTAMP
             WHERE id=?'
        )->execute([$id]);

        $pdo->prepare('INSERT INTO opportunity_notes (opportunity_id,user_id,note) VALUES (?,?,?)')
            ->execute([$id, $userId, 'Oportunidade marcada como Ganha.']);

        audit($pdo, $userId, 'status_won', 'opportunity', $id);
        return 'Oportunidade marcada como Ganha.';
    }

    if ($action === 'status_lost') {
        $reason = trim((string)($post['loss_reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Informe o motivo da perda para manter o rastreio comercial.');
        }
        if (nc_strlen($reason) > 3000) {
            throw new RuntimeException('O motivo da perda deve ter no máximo 3.000 caracteres.');
        }

        $pdo->prepare(
            'UPDATE opportunities
             SET status="Perdida",loss_reason=?,loss_recorded_at=NOW(),updated_at=CURRENT_TIMESTAMP
             WHERE id=?'
        )->execute([$reason, $id]);

        $pdo->prepare('INSERT INTO opportunity_notes (opportunity_id,user_id,note) VALUES (?,?,?)')
            ->execute([$id, $userId, 'Oportunidade perdida. Motivo: ' . $reason]);

        audit($pdo, $userId, 'status_lost', 'opportunity', $id, ['reason' => $reason]);
        return 'Perda registrada com o motivo.';
    }

    if ($action === 'status_reopen') {
        $pdo->prepare(
            'UPDATE opportunities
             SET status="Aberta",loss_reason=NULL,loss_recorded_at=NULL,updated_at=CURRENT_TIMESTAMP
             WHERE id=?'
        )->execute([$id]);

        $pdo->prepare('INSERT INTO opportunity_notes (opportunity_id,user_id,note) VALUES (?,?,?)')
            ->execute([$id, $userId, 'Oportunidade reaberta.']);

        audit($pdo, $userId, 'status_reopen', 'opportunity', $id);
        return 'Oportunidade reaberta.';
    }

    throw new RuntimeException('Ação inválida.');
}

/** Dias desde a última atualização (parado na etapa). */
function nc_days_since(?string $datetime): int
{
    if (!$datetime) {
        return 0;
    }
    try {
        return max(0, (int)(new DateTimeImmutable($datetime))->diff(new DateTimeImmutable('now'))->days);
    } catch (Throwable) {
        return 0;
    }
}

/** Card do kanban. */
function nc_deal_card(array $o): void
{
    $url = nc_url('oportunidade.php?id=' . (int)$o['id']);
    $days = nc_days_since((string)$o['updated_at']);
    $pending = (int)$o['quote_required'] === 1 && empty($o['quote_completed_at']);
    ?>
    <article class="nc-deal-card" data-id="<?= (int)$o['id'] ?>" data-amount="<?= e((string)(float)$o['amount']) ?>">
        <a class="nc-deal-title" href="<?= e($url) ?>" hx-get="<?= e($url) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-push-url="true"><?= e((string)$o['client_name']) ?></a>
        <div class="nc-deal-meta"><span class="nc-num-left"><?= e((string)$o['number']) ?></span> · <?= e((string)$o['line_name']) ?></div>
        <div class="nc-deal-row">
            <strong class="nc-num"><?= e(money($o['amount'])) ?></strong>
            <span class="nc-avatar nc-avatar-sm" title="<?= e((string)($o['seller_name'] ?: 'Sem vendedor')) ?>"><?= e($o['seller_name'] ? nc_initials((string)$o['seller_name']) : '—') ?></span>
        </div>
        <div class="nc-deal-foot">
            <?php if ($pending): ?><?= nc_badge('Orçamento pendente', 'warning') ?><?php endif; ?>
            <small class="nc-muted"><?= $days < 1 ? 'Atualizada hoje' : 'Parada há ' . $days . ' dia' . ($days > 1 ? 's' : '') ?></small>
        </div>
        <div class="nc-deal-tools">
            <?php $prob = isset($o['probability']) && $o['probability'] !== null ? (int)$o['probability'] : null; ?>
            <span class="nc-deal-prob" data-prob-slot title="Probabilidade da etapa"<?= $prob === null ? ' hidden' : '' ?>><?= $prob !== null ? $prob . '% de chance' : '' ?></span>
            <span class="nc-deal-arrows">
                <button type="button" class="nc-icon-btn nc-icon-btn-sm" data-nc-move="prev" aria-label="Mover para a etapa anterior" title="Etapa anterior"><?= nc_icon('arrow-left', 16) ?></button>
                <button type="button" class="nc-icon-btn nc-icon-btn-sm" data-nc-move="next" aria-label="Mover para a próxima etapa" title="Próxima etapa"><?= nc_icon('arrow-right', 16) ?></button>
            </span>
        </div>
    </article>
    <?php
}

/** Valores padrão do formulário de oportunidade. */
function nc_opp_defaults(): array
{
    $v = [
        'id' => 0, 'number' => '', 'date' => date('Y-m-d'), 'client_name' => '', 'client_cnpj' => '',
        'seller_user_id' => '', 'support_user_id' => '', 'product_line_id' => '', 'amount' => '', 'notes' => '',
        'source_visit_id' => 0,
    ];
    foreach (nc_opp_client_keys() as $key) {
        $v['client_' . $key] = '';
    }
    return $v;
}

/**
 * Formulário de oportunidade (drawer): criação e edição.
 */
function nc_opp_form(PDO $pdo, array $v, string $error = '', string $notice = ''): void
{
    $v = array_merge(nc_opp_defaults(), $v);
    $users = nc_users($pdo);
    $lines = $pdo->query('SELECT * FROM product_lines WHERE active=1 ORDER BY sort_order')->fetchAll();
    $editing = (int)$v['id'] > 0;
    ?>
    <div data-drawer-title="<?= $editing ? 'Editar oportunidade' : 'Nova oportunidade' ?>">
        <form id="opp-form" class="nc-form" method="post"
              hx-post="<?= e(nc_url('acoes/oportunidade-salvar.php')) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML"
              hx-disabled-elt="find button[type=submit]">
            <?php if ($error !== ''): ?><div class="nc-alert nc-alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="nc-alert nc-alert-success" role="status"><?= e($notice) ?></div><?php endif; ?>

            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
            <input type="hidden" name="source_visit_id" value="<?= (int)$v['source_visit_id'] ?>">
            <?php foreach (nc_opp_client_keys() as $key): ?>
                <input type="hidden" name="client_<?= e($key) ?>" value="<?= e((string)$v['client_' . $key]) ?>">
            <?php endforeach; ?>

            <div class="nc-field-grid">
                <div class="nc-field">
                    <label for="of-date">Data <span class="nc-req" aria-hidden="true">*</span></label>
                    <input class="nc-input" id="of-date" type="date" name="date" value="<?= e((string)$v['date']) ?>" required>
                </div>
                <div class="nc-field">
                    <label for="of-number">Número</label>
                    <input class="nc-input" id="of-number" name="number" value="<?= e((string)$v['number']) ?>" maxlength="80" placeholder="Gerado automaticamente">
                </div>
            </div>

            <div class="nc-field">
                <label for="of-client">Cliente <span class="nc-req" aria-hidden="true">*</span></label>
                <input class="nc-input" id="of-client" name="client_name" value="<?= e((string)$v['client_name']) ?>" required maxlength="190" autocomplete="off">
            </div>
            <div class="nc-field">
                <label for="of-cnpj">CNPJ do cliente</label>
                <div class="nc-field-row">
                    <input class="nc-input" id="of-cnpj" name="client_cnpj" value="<?= e((string)$v['client_cnpj'] !== '' ? format_cnpj((string)$v['client_cnpj']) : '') ?>" inputmode="numeric" autocomplete="off" placeholder="00.000.000/0000-00">
                    <button type="button" class="nc-btn nc-btn-secondary"
                            hx-post="<?= e(nc_url('acoes/cnpj-consulta.php')) ?>" hx-vals='{"mode":"oportunidade"}' hx-include="#opp-form"
                            hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-disabled-elt="this">Consultar</button>
                </div>
                <small class="nc-help">Opcional. A consulta preenche o cadastro do cliente (CNPJá).</small>
            </div>

            <div class="nc-field-grid">
                <div class="nc-field">
                    <label for="of-seller">Vendedor</label>
                    <select class="nc-input" id="of-seller" name="seller_user_id">
                        <option value="">Sem vendedor</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"<?= (string)$v['seller_user_id'] === (string)$u['id'] ? ' selected' : '' ?>><?= e((string)$u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="nc-field">
                    <label for="of-support">Suporte</label>
                    <select class="nc-input" id="of-support" name="support_user_id">
                        <option value="">Sem suporte</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"<?= (string)$v['support_user_id'] === (string)$u['id'] ? ' selected' : '' ?>><?= e((string)$u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="nc-field-grid">
                <div class="nc-field">
                    <label for="of-line">Linha de produto <span class="nc-req" aria-hidden="true">*</span></label>
                    <select class="nc-input" id="of-line" name="product_line_id" required>
                        <option value="">Selecione…</option>
                        <?php foreach ($lines as $l): ?>
                            <option value="<?= (int)$l['id'] ?>"<?= (string)$v['product_line_id'] === (string)$l['id'] ? ' selected' : '' ?>><?= e((string)$l['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="nc-help">O funil é definido pela linha de produto.</small>
                </div>
                <div class="nc-field">
                    <label for="of-amount">Valor (R$) <span class="nc-req" aria-hidden="true">*</span></label>
                    <input class="nc-input" id="of-amount" type="number" name="amount" min="0" step="0.01" value="<?= e((string)$v['amount']) ?>" required>
                </div>
            </div>

            <div class="nc-field">
                <label for="of-notes">Observações</label>
                <textarea class="nc-input" id="of-notes" name="notes" rows="4"><?= e((string)$v['notes']) ?></textarea>
            </div>

            <div class="nc-form-actions">
                <button type="button" class="nc-btn nc-btn-secondary" data-nc-drawer-close>Cancelar</button>
                <button type="submit" class="nc-btn nc-btn-primary"><?= $editing ? 'Salvar alterações' : 'Criar oportunidade' ?></button>
            </div>
        </form>
    </div>
    <?php
}

/** Lê o $_POST do formulário em um array no formato de nc_opp_form(). */
function nc_opp_from_post(): array
{
    $v = nc_opp_defaults();
    foreach ($v as $key => $_) {
        if (isset($_POST[$key])) {
            $v[$key] = is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key];
        }
    }
    $v['id'] = (int)($_POST['id'] ?? 0);
    $v['source_visit_id'] = (int)($_POST['source_visit_id'] ?? 0);
    return $v;
}

/** Ficha da oportunidade (resumo, notas e histórico). Usada no drawer e na página. */
function nc_opp_ficha(PDO $pdo, array $opp, array $user): void
{
    $id = (int)$opp['id'];
    $notesQ = $pdo->prepare(
        'SELECT n.*,u.name author_name FROM opportunity_notes n JOIN users u ON u.id=n.user_id WHERE n.opportunity_id=? ORDER BY n.id DESC'
    );
    $notesQ->execute([$id]);
    $notes = $notesQ->fetchAll();

    $logQ = $pdo->prepare(
        "SELECT a.action,a.context_json,a.created_at,u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id
         WHERE a.entity_type='opportunity' AND a.entity_id=? ORDER BY a.id DESC LIMIT 100"
    );
    $logQ->execute([$id]);
    $history = $logQ->fetchAll();

    $stagesQ = $pdo->prepare('SELECT id,name FROM funnel_stages WHERE funnel_id=? ORDER BY sort_order');
    $stagesQ->execute([(int)$opp['funnel_id']]);
    $stages = $stagesQ->fetchAll();

    $actionUrl = nc_url('acoes/oportunidade-acao.php');
    $pending = (int)$opp['quote_required'] === 1 && empty($opp['quote_completed_at']);
    $clientLink = !empty($opp['company_id']) ? nc_url('cliente.php?id=' . (int)$opp['company_id']) : '';
    $labels = [
        'create' => 'Criada', 'update' => 'Dados editados', 'update_stage' => 'Etapa alterada', 'status_won' => 'Marcada como Ganha',
        'status_lost' => 'Marcada como Perdida', 'status_reopen' => 'Reaberta', 'add_note' => 'Anotação adicionada',
        'quote_complete' => 'Orçamento concluído', 'promote_visit' => 'Promovida de visita', 'update_status' => 'Status alterado',
    ];
    ?>
    <div id="opp-ficha" class="nc-ficha" data-drawer-title="<?= e((string)$opp['number']) ?>">
        <header class="nc-ficha-head">
            <div>
                <p class="nc-muted nc-num-left"><?= e((string)$opp['number']) ?></p>
                <h2 class="nc-ficha-title">
                    <?php if ($clientLink !== ''): ?><a href="<?= e($clientLink) ?>" hx-get="<?= e($clientLink) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML"><?= e((string)$opp['client_name']) ?></a>
                    <?php else: ?><?= e((string)$opp['client_name']) ?><?php endif; ?>
                </h2>
                <p class="nc-ficha-amount nc-num"><?= e(money($opp['amount'])) ?></p>
            </div>
            <div class="nc-ficha-badges">
                <?= nc_status_badge((string)$opp['status']) ?>
                <?= nc_badge((string)$opp['stage_name'], 'info') ?>
            </div>
        </header>

        <?php if ((int)$opp['quote_required'] === 1): ?>
            <?php if ($pending): ?>
                <div class="nc-alert nc-alert-warning">
                    <strong>Pendência: realizar orçamento</strong>
                    <p>Esta oportunidade veio de uma visita e o orçamento ainda não foi concluído.</p>
                    <form class="nc-inline-fields" method="post" hx-post="<?= e($actionUrl) ?>" hx-target="#opp-ficha" hx-swap="outerHTML">
                        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="quote_complete">
                        <input class="nc-input" name="quote_reference" placeholder="Nº / referência do orçamento" required aria-label="Número ou referência do orçamento">
                        <button class="nc-btn nc-btn-primary" type="submit">Concluir orçamento</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="nc-alert nc-alert-success">
                    <strong>Orçamento realizado</strong> · referência <?= e((string)($opp['quote_reference'] ?: '—')) ?> ·
                    <?= e(date('d/m/Y H:i', strtotime((string)$opp['quote_completed_at']))) ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="nc-ficha-actions">
            <?php if ($opp['status'] === 'Aberta'): ?>
                <button class="nc-btn nc-btn-primary" type="button" hx-post="<?= e($actionUrl) ?>" hx-vals='{"id":<?= $id ?>,"action":"status_won"}' hx-target="#opp-ficha" hx-swap="outerHTML" hx-disabled-elt="this">Marcar ganha</button>
            <?php else: ?>
                <button class="nc-btn nc-btn-secondary" type="button" hx-post="<?= e($actionUrl) ?>" hx-vals='{"id":<?= $id ?>,"action":"status_reopen"}' hx-target="#opp-ficha" hx-swap="outerHTML" hx-disabled-elt="this">Reabrir</button>
            <?php endif; ?>
            <button class="nc-btn nc-btn-secondary" type="button" hx-get="<?= e(nc_url('acoes/oportunidade-form.php?id=' . $id)) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML">Editar</button>
            <button class="nc-btn nc-btn-danger-outline" type="button" hx-post="<?= e($actionUrl) ?>" hx-vals='{"id":<?= $id ?>,"action":"delete"}' hx-swap="none"
                    data-nc-confirm="Excluir a oportunidade <?= e((string)$opp['number']) ?>? Esta ação não pode ser desfeita." data-nc-confirm-ok="Excluir">Excluir</button>
        </div>

        <?php if ($opp['status'] === 'Aberta'): ?>
            <details class="nc-details">
                <summary>Marcar como perdida</summary>
                <form class="nc-form" method="post" hx-post="<?= e($actionUrl) ?>" hx-target="#opp-ficha" hx-swap="outerHTML">
                    <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="status_lost">
                    <div class="nc-field">
                        <label for="loss-reason">Motivo da perda <span class="nc-req" aria-hidden="true">*</span></label>
                        <textarea class="nc-input" id="loss-reason" name="loss_reason" rows="4" required maxlength="3000" placeholder="Descreva por que a oportunidade foi perdida…"></textarea>
                    </div>
                    <div><button class="nc-btn nc-btn-danger" type="submit">Registrar perda</button></div>
                </form>
            </details>
        <?php elseif ($opp['status'] === 'Perdida'): ?>
            <div class="nc-alert nc-alert-danger">
                <strong>Motivo da perda</strong>
                <p><?= nl2br(e((string)($opp['loss_reason'] ?: 'Motivo não informado.'))) ?></p>
                <?php if ($opp['loss_recorded_at']): ?><small><?= e(date('d/m/Y H:i', strtotime((string)$opp['loss_recorded_at']))) ?></small><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="nc-tabs" role="tablist" aria-label="Seções da oportunidade" data-nc-tabs>
            <button class="nc-tab is-active" role="tab" type="button" id="tab-resumo" aria-selected="true" aria-controls="panel-resumo">Resumo</button>
            <button class="nc-tab" role="tab" type="button" id="tab-notas" aria-selected="false" aria-controls="panel-notas" tabindex="-1">Notas <span class="nc-tab-count"><?= count($notes) ?></span></button>
            <button class="nc-tab" role="tab" type="button" id="tab-historico" aria-selected="false" aria-controls="panel-historico" tabindex="-1">Histórico</button>
        </div>

        <section id="panel-resumo" role="tabpanel" aria-labelledby="tab-resumo" data-nc-panel>
            <dl class="nc-dl">
                <div><dt>Data</dt><dd><?= e(nc_date((string)$opp['opportunity_date'])) ?></dd></div>
                <div><dt>Cliente</dt><dd><?= e((string)$opp['client_name']) ?><?= $opp['company_cnpj'] ? ' · ' . e(format_cnpj((string)$opp['company_cnpj'])) : '' ?></dd></div>
                <div><dt>Linha</dt><dd><?= e((string)$opp['line_name']) ?></dd></div>
                <div><dt>Funil</dt><dd><?= e((string)$opp['funnel_label']) ?></dd></div>
                <div><dt>Vendedor</dt><dd><?= e((string)($opp['seller_name'] ?: '—')) ?></dd></div>
                <div><dt>Suporte</dt><dd><?= e((string)($opp['support_name'] ?: '—')) ?></dd></div>
                <div><dt>Origem</dt><dd><?= e((string)$opp['source']) ?><?= $opp['visit_os'] ? ' · OS ' . e((string)$opp['visit_os']) : '' ?></dd></div>
            </dl>
            <?php if ($opp['status'] === 'Aberta' && $stages): ?>
                <form class="nc-inline-fields" method="post" hx-post="<?= e($actionUrl) ?>" hx-target="#opp-ficha" hx-swap="outerHTML">
                    <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="stage">
                    <label class="nc-sr-only" for="stage-select">Etapa</label>
                    <select class="nc-input" id="stage-select" name="stage_id">
                        <?php foreach ($stages as $st): ?>
                            <option value="<?= (int)$st['id'] ?>"<?= (int)$st['id'] === (int)$opp['stage_id'] ? ' selected' : '' ?>><?= e((string)$st['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="nc-btn nc-btn-secondary" type="submit">Mover de etapa</button>
                </form>
            <?php endif; ?>
            <?php if ($opp['notes']): ?>
                <div class="nc-quote"><strong>Observação inicial</strong><p><?= nl2br(e((string)$opp['notes'])) ?></p></div>
            <?php endif; ?>
        </section>

        <section id="panel-notas" role="tabpanel" aria-labelledby="tab-notas" data-nc-panel hidden>
            <form class="nc-form" method="post" hx-post="<?= e($actionUrl) ?>" hx-target="#opp-ficha" hx-swap="outerHTML">
                <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="add_note">
                <div class="nc-field">
                    <label for="note-text">Nova anotação</label>
                    <textarea class="nc-input" id="note-text" name="note" rows="3" required maxlength="5000" placeholder="Ex.: cliente pediu revisão da proposta; retorno combinado para sexta…"></textarea>
                </div>
                <div><button class="nc-btn nc-btn-primary" type="submit">Adicionar anotação</button></div>
            </form>
            <ol class="nc-timeline">
                <?php foreach ($notes as $n): ?>
                    <li>
                        <div class="nc-timeline-meta"><strong><?= e((string)$n['author_name']) ?></strong> <span class="nc-muted"><?= e(date('d/m/Y H:i', strtotime((string)$n['created_at']))) ?></span></div>
                        <p><?= nl2br(e((string)$n['note'])) ?></p>
                    </li>
                <?php endforeach; ?>
                <?php if (!$notes): ?><li class="nc-muted">Nenhuma anotação registrada.</li><?php endif; ?>
            </ol>
        </section>

        <section id="panel-historico" role="tabpanel" aria-labelledby="tab-historico" data-nc-panel hidden>
            <ol class="nc-timeline">
                <?php foreach ($history as $h): ?>
                    <li>
                        <div class="nc-timeline-meta"><strong><?= e($labels[$h['action']] ?? (string)$h['action']) ?></strong>
                            <span class="nc-muted"><?= e((string)($h['user_name'] ?: 'Sistema')) ?> · <?= e(date('d/m/Y H:i', strtotime((string)$h['created_at']))) ?></span></div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$history): ?><li class="nc-muted">Sem registros de auditoria.</li><?php endif; ?>
            </ol>
        </section>
    </div>
    <?php
}

/** Copiado de orcamentos.php: opportunity_insert() (usado ao promover uma visita). */
function nc_opp_insert(PDO $pdo, int $createdBy, array $data): int
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

    [$line, $funnelId, $stageId] = nc_opp_line_context($pdo, $lineId);

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
