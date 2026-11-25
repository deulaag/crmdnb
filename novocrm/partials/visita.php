<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* Visita manual (visitas.php: action manual)                          */
/* ------------------------------------------------------------------ */

function nc_visita_defaults(): array
{
    return ['visit_type' => 'visita', 'visit_date' => date('Y-m-d'), 'os_number' => '', 'client_name' => '',
        'seller_user_id' => '', 'contact_name' => '', 'sector' => '', 'opportunity_text' => ''];
}

function nc_visita_from_post(): array
{
    $v = nc_visita_defaults();
    foreach ($v as $key => $_) {
        $v[$key] = trim((string)($_POST[$key] ?? ''));
    }
    return $v;
}

/** Copiado de visitas.php: visitas_insert(). */
function nc_visita_insert(PDO $pdo, int $createdBy, array $data, string $source): int
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

function nc_visita_form(PDO $pdo, array $v, string $error = ''): void
{
    $v = array_merge(nc_visita_defaults(), $v);
    $users = nc_users($pdo);
    ?>
    <div data-drawer-title="Nova visita">
        <form class="nc-form" method="post" hx-post="<?= e(nc_url('acoes/visita-salvar.php')) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <?php if ($error !== ''): ?><div class="nc-alert nc-alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
            <div class="nc-field-grid">
                <div class="nc-field">
                    <label for="vf-type">Tipo</label>
                    <select class="nc-input" id="vf-type" name="visit_type">
                        <option value="visita"<?= $v['visit_type'] === 'visita' ? ' selected' : '' ?>>Visita comercial</option>
                        <option value="prospeccao"<?= $v['visit_type'] === 'prospeccao' ? ' selected' : '' ?>>Prospecção — novo cliente</option>
                    </select>
                </div>
                <div class="nc-field">
                    <label for="vf-date">Data <span class="nc-req" aria-hidden="true">*</span></label>
                    <input class="nc-input" id="vf-date" type="date" name="visit_date" value="<?= e($v['visit_date']) ?>" required>
                </div>
            </div>
            <div class="nc-field-grid">
                <div class="nc-field">
                    <label for="vf-os">Nº da OS</label>
                    <input class="nc-input" id="vf-os" name="os_number" value="<?= e($v['os_number']) ?>" maxlength="80">
                </div>
                <div class="nc-field">
                    <label for="vf-seller">Responsável <span class="nc-req" aria-hidden="true">*</span></label>
                    <select class="nc-input" id="vf-seller" name="seller_user_id" required>
                        <option value="">Selecione…</option>
                        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $v['seller_user_id'] === (string)$u['id'] ? ' selected' : '' ?>><?= e((string)$u['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="nc-field">
                <label for="vf-client">Cliente <span class="nc-req" aria-hidden="true">*</span></label>
                <input class="nc-input" id="vf-client" name="client_name" value="<?= e($v['client_name']) ?>" required maxlength="190" autocomplete="off">
            </div>
            <div class="nc-field-grid">
                <div class="nc-field">
                    <label for="vf-contact">Contato</label>
                    <input class="nc-input" id="vf-contact" name="contact_name" value="<?= e($v['contact_name']) ?>" maxlength="160">
                </div>
                <div class="nc-field">
                    <label for="vf-sector">Setor</label>
                    <input class="nc-input" id="vf-sector" name="sector" value="<?= e($v['sector']) ?>" maxlength="120">
                </div>
            </div>
            <div class="nc-field">
                <label for="vf-opp">Oportunidade identificada</label>
                <textarea class="nc-input" id="vf-opp" name="opportunity_text" rows="4"><?= e($v['opportunity_text']) ?></textarea>
            </div>
            <div class="nc-form-actions">
                <button type="button" class="nc-btn nc-btn-secondary" data-nc-drawer-close>Cancelar</button>
                <button type="submit" class="nc-btn nc-btn-primary">Registrar visita</button>
            </div>
        </form>
    </div>
    <?php
}

/* ------------------------------------------------------------------ */
/* Agenda / cronograma (cronograma.php)                                */
/* ------------------------------------------------------------------ */

function nc_agenda_defaults(): array
{
    return ['event_date' => date('Y-m-d'), 'event_time' => '', 'client_name' => '', 'user_id' => '', 'contact_name' => '',
        'objective' => '', 'status' => 'agendado', 'event_type' => 'compromisso', 'volta' => ''];
}

function nc_agenda_from_post(): array
{
    $v = nc_agenda_defaults();
    foreach ($v as $key => $_) {
        $v[$key] = trim((string)($_POST[$key] ?? ''));
    }
    return $v;
}

function nc_agenda_type_label(string $type): string
{
    return match ($type) {
        'followup' => 'Follow-up',
        'visita_retorno' => 'Retorno de visita',
        default => 'Compromisso',
    };
}

function nc_agenda_form(PDO $pdo, array $v, string $error = ''): void
{
    $v = array_merge(nc_agenda_defaults(), $v);
    $users = nc_users($pdo);
    ?>
    <div data-drawer-title="<?= $v['event_type'] === 'followup' ? 'Novo follow-up' : 'Novo compromisso' ?>">
        <form class="nc-form" method="post" hx-post="<?= e(nc_url('acoes/agenda-salvar.php')) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML" hx-disabled-elt="find button[type=submit]">
            <input type="hidden" name="volta" value="<?= e($v['volta']) ?>">
            <?php if ($error !== ''): ?><div class="nc-alert nc-alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
            <div class="nc-field-grid">
                <div class="nc-field">
                    <label for="af-date">Data <span class="nc-req" aria-hidden="true">*</span></label>
                    <input class="nc-input" id="af-date" type="date" name="event_date" value="<?= e($v['event_date']) ?>" required>
                </div>
                <div class="nc-field">
                    <label for="af-time">Horário</label>
                    <input class="nc-input" id="af-time" type="time" name="event_time" value="<?= e($v['event_time']) ?>">
                </div>
            </div>
            <div class="nc-field">
                <label for="af-client">Cliente <span class="nc-req" aria-hidden="true">*</span></label>
                <input class="nc-input" id="af-client" name="client_name" value="<?= e($v['client_name']) ?>" required maxlength="190" autocomplete="off">
            </div>
            <div class="nc-field-grid">
                <div class="nc-field">
                    <label for="af-user">Responsável</label>
                    <select class="nc-input" id="af-user" name="user_id">
                        <option value="">—</option>
                        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $v['user_id'] === (string)$u['id'] ? ' selected' : '' ?>><?= e((string)$u['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="nc-field">
                    <label for="af-contact">Contato</label>
                    <input class="nc-input" id="af-contact" name="contact_name" value="<?= e($v['contact_name']) ?>" maxlength="160">
                </div>
            </div>
            <div class="nc-field-grid">
                <div class="nc-field">
                    <label for="af-type">Tipo</label>
                    <select class="nc-input" id="af-type" name="event_type">
                        <?php foreach (['compromisso' => 'Compromisso', 'followup' => 'Follow-up', 'visita_retorno' => 'Retorno de visita'] as $k => $label): ?>
                            <option value="<?= e($k) ?>"<?= $v['event_type'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="nc-field">
                    <label for="af-status">Situação</label>
                    <select class="nc-input" id="af-status" name="status">
                        <?php foreach (['sugerido' => 'Sugerido', 'agendado' => 'Agendado', 'confirmado' => 'Confirmado'] as $k => $label): ?>
                            <option value="<?= e($k) ?>"<?= $v['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="nc-field">
                <label for="af-obj">Objetivo <span class="nc-req" aria-hidden="true">*</span></label>
                <textarea class="nc-input" id="af-obj" name="objective" rows="4" required><?= e($v['objective']) ?></textarea>
            </div>
            <div class="nc-form-actions">
                <button type="button" class="nc-btn nc-btn-secondary" data-nc-drawer-close>Cancelar</button>
                <button type="submit" class="nc-btn nc-btn-primary">Adicionar à agenda</button>
            </div>
        </form>
    </div>
    <?php
}

/** Card de compromisso com ações rápidas (Confirmar, Concluir, Cancelar). */
function nc_agenda_card(array $a, bool $actions = true): void
{
    $statusUrl = nc_url('acoes/agenda-status.php');
    $tone = match ($a['status']) {
        'confirmado', 'concluido' => 'success',
        'sugerido' => 'warning',
        'cancelado' => 'neutral',
        default => 'info',
    };
    $open = in_array($a['status'], ['sugerido', 'agendado', 'confirmado'], true);
    ?>
    <article class="nc-agenda-card">
        <div class="nc-agenda-date">
            <strong><?= e(substr(nc_date((string)$a['event_date']), 0, 5)) ?></strong>
            <small><?= e($a['event_time'] ? substr((string)$a['event_time'], 0, 5) : 'dia todo') ?></small>
        </div>
        <div class="nc-agenda-body">
            <strong><?= e((string)$a['client_name']) ?></strong>
            <span><?= e(nc_truncate((string)$a['objective'], 140)) ?></span>
            <small class="nc-muted">
                <?= e(nc_agenda_type_label((string)$a['event_type'])) ?>
                <?= !empty($a['user_name']) ? ' · ' . e((string)$a['user_name']) : '' ?>
                <?= !empty($a['contact_name']) ? ' · ' . e((string)$a['contact_name']) : '' ?>
                <?= !empty($a['os_number']) ? ' · OS ' . e((string)$a['os_number']) : '' ?>
            </small>
        </div>
        <div class="nc-agenda-side">
            <?= nc_badge(ucfirst((string)$a['status']), $tone) ?>
            <?php if ($actions && $open): ?>
                <div class="nc-agenda-actions">
                    <?php if ($a['status'] !== 'confirmado'): ?>
                        <button class="nc-btn nc-btn-secondary" type="button" hx-post="<?= e($statusUrl) ?>" hx-vals='{"id":<?= (int)$a['id'] ?>,"status":"confirmado"}' hx-swap="none" hx-disabled-elt="this">Confirmar</button>
                    <?php endif; ?>
                    <button class="nc-btn nc-btn-primary" type="button" hx-post="<?= e($statusUrl) ?>" hx-vals='{"id":<?= (int)$a['id'] ?>,"status":"concluido"}' hx-swap="none" hx-disabled-elt="this">Concluir</button>
                    <button class="nc-btn nc-btn-danger-outline" type="button" hx-post="<?= e($statusUrl) ?>" hx-vals='{"id":<?= (int)$a['id'] ?>,"status":"cancelado"}' hx-swap="none"
                            data-nc-confirm="Cancelar o compromisso com <?= e((string)$a['client_name']) ?>?" data-nc-confirm-ok="Cancelar compromisso">Cancelar</button>
                </div>
            <?php endif; ?>
        </div>
    </article>
    <?php
}
