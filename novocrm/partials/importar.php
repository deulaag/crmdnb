<?php
declare(strict_types=1);

/**
 * Telas da Central de Importação (V3, seção 2): Enviar → Mapear colunas → Conferir → Confirmar.
 * Tudo aqui só renderiza; as regras estão em _import_run.php e _import_match.php.
 */

require_once __DIR__ . '/../_import_run.php';
require_once __DIR__ . '/empresa-picker.php';

const NC_IMPORT_PER_PAGE = 50;

function nc_import_class_label(string $class): string
{
    return ['novo' => 'Novo', 'atualizar' => 'Atualizar', 'sem_alteracao' => 'Sem alteração', 'revisar' => 'Revisar', 'erro' => 'Erro', 'ignorar' => 'Ignorar'][$class] ?? $class;
}

function nc_import_class_badge(string $class): string
{
    $tone = ['novo' => 'success', 'atualizar' => 'info', 'sem_alteracao' => 'neutral', 'revisar' => 'warning', 'erro' => 'danger', 'ignorar' => 'neutral'][$class] ?? 'neutral';
    return nc_badge(nc_import_class_label($class), $tone);
}

function nc_import_rule_label(?string $rule): string
{
    return [
        'external_id' => 'ID externo', 'cnpj' => 'CNPJ', 'cpf' => 'CPF', 'contact_email' => 'E-mail do contato', 'name_exact' => 'Nome exato',
        'manual' => 'Escolha manual', 'novo' => 'Empresa nova', 'conflito' => 'Conflito entre regras',
    ][(string)$rule] ?? '';
}

/** Rótulo dos campos que aparecem em "antes → depois". */
function nc_import_diff_label(string $field): string
{
    return [
        'cnpj' => 'CNPJ', 'person_document' => 'CPF', 'alias' => 'Nome fantasia', 'city' => 'Cidade', 'state' => 'UF', 'external_id' => 'ID de origem',
        'owner_user_id' => 'Responsável', 'name' => 'Nome', 'email' => 'E-mail', 'phone' => 'Telefone', 'job_title' => 'Cargo', 'company_id' => 'Empresa',
        'opportunity_date' => 'Data', 'amount' => 'Valor', 'status' => 'Status', 'loss_reason' => 'Motivo da perda', 'stage_id' => 'Etapa',
        'seller_user_id' => 'Vendedor', 'product_line_id' => 'Linha de produto', 'quote_reference' => 'Referência',
        'first_name' => 'Primeiro nome', 'interest_lines' => 'Linhas de interesse', 'source_quote_count' => 'Qtde de orçamentos (origem)',
        'source_last_quote_at' => 'Última em (origem)', 'source_origin' => 'Origem dos documentos', 'import_note' => 'Conferir',
    ][$field] ?? $field;
}

/** Indicador das 4 etapas. $current: 1 a 4. */
function nc_import_steps(int $current): void
{
    $steps = ['Enviar', 'Mapear colunas', 'Conferir', 'Confirmar'];
    ?>
    <ol class="nc-steps" aria-label="Etapas da importação">
        <?php foreach ($steps as $i => $label): $n = $i + 1; ?>
            <li class="nc-step<?= $n < $current ? ' is-done' : '' ?><?= $n === $current ? ' is-current' : '' ?>"<?= $n === $current ? ' aria-current="step"' : '' ?>>
                <span class="nc-step-n"><?= $n < $current ? '✓' : $n ?></span><span><?= e($label) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php
}

/** Aviso quando as migrações 008/009 ainda não foram aplicadas. */
function nc_import_setup_missing(array $missing, array $user): void
{
    $link = nc_is_admin($user)
        ? '<a class="nc-btn nc-btn-primary" href="' . e(nc_url('migrations/run.php')) . '">Aplicar migrações</a>'
        : '';
    nc_empty('Falta preparar o banco de dados', 'Ainda não existem: ' . implode(', ', $missing) . '. Um administrador precisa aplicar as migrações do NOVOCRM.', $link);
}

/** Contatos/empresas aguardando revisão em lotes de Contatos (aviso na tela de Orçamentos). */
function nc_import_pending_contact_reviews(PDO $pdo): int
{
    return (int)$pdo->query(
        "SELECT COUNT(*) FROM import_rows r JOIN import_batches b ON b.id = r.batch_id
         WHERE b.import_type = 'contatos' AND b.status = 'em_revisao' AND r.classification = 'revisar'"
    )->fetchColumn();
}

/* ------------------------------------------------------------------ */
/* Etapa 1: Enviar                                                     */
/* ------------------------------------------------------------------ */

function nc_import_step_upload(PDO $pdo, string $type): void
{
    $types = nc_import_types();
    $pending = nc_import_pending_contact_reviews($pdo);
    ?>
    <div class="nc-alert nc-alert-warning" role="note">
        <strong>Importe primeiro os Contatos e depois os Orçamentos e propostas.</strong>
        Assim cada orçamento encontra a empresa certa. Nada é gravado no CRM até você confirmar, na última etapa.
    </div>
    <?php if ($type === 'orcamentos' && $pending > 0): ?>
        <div class="nc-alert nc-alert-warning" role="alert">
            Há <?= $pending ?> contato<?= $pending === 1 ? '' : 's' ?>/empresa<?= $pending === 1 ? '' : 's' ?> aguardando revisão. Orçamentos desses clientes vão cair em revisão. Você pode enviar mesmo assim.
        </div>
    <?php endif; ?>
    <section class="nc-card">
        <form class="nc-form" method="post" enctype="multipart/form-data" action="<?= e(nc_url('acoes/importar-enviar.php')) ?>">
            <?= csrf_field() ?>
            <fieldset class="nc-choice">
                <legend>O que você vai importar?</legend>
                <?php foreach ($types as $key => $label): ?>
                    <label class="nc-choice-item">
                        <input type="radio" name="tipo" value="<?= e($key) ?>"<?= $key === $type ? ' checked' : '' ?> required>
                        <span><strong><?= e($label) ?></strong>
                            <small class="nc-muted"><?= $key === 'contatos'
                                ? 'Cria ou atualiza empresas e contatos. Planilha com nome do contato, e-mail ou telefone e a empresa.'
                                : 'Cria ou atualiza orçamentos e propostas, ligados à empresa certa. Orçamento nunca cria empresa sozinho.' ?></small></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <div class="nc-field">
                <label for="imp-file">Arquivo (.xlsx ou .csv, até 15 MB) <span class="nc-req" aria-hidden="true">*</span></label>
                <input class="nc-input" id="imp-file" type="file" name="arquivo" accept=".xlsx,.csv" required>
                <small class="nc-help">A primeira linha deve ser o cabeçalho. O arquivo original fica guardado no servidor para auditoria.</small>
            </div>
            <div class="nc-form-actions">
                <button type="submit" class="nc-btn nc-btn-primary">Enviar e continuar</button>
            </div>
        </form>
    </section>
    <?php
    $recent = $pdo->query(
        'SELECT b.id, b.import_type, b.file_name, b.status, b.created_at FROM import_batches b ORDER BY b.id DESC LIMIT 5'
    )->fetchAll();
    if ($recent): ?>
        <h2 class="nc-section-title">Lotes recentes</h2>
        <div class="nc-table-wrap">
            <table class="nc-table">
                <thead><tr><th>Lote</th><th>Tipo</th><th>Arquivo</th><th>Status</th><th>Data</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td>#<?= (int)$r['id'] ?></td>
                        <td><?= e($types[$r['import_type']] ?? (string)$r['import_type']) ?></td>
                        <td><?= e(nc_truncate((string)$r['file_name'], 40)) ?></td>
                        <td><?= nc_import_status_badge((string)$r['status']) ?></td>
                        <td><?= e(nc_date((string)$r['created_at'])) ?></td>
                        <td class="is-actions"><a class="nc-btn nc-btn-secondary nc-btn-sm" href="<?= e(nc_url('importar.php?lote=' . (int)$r['id'])) ?>">
                            <?= in_array($r['status'], ['enviado', 'mapeado', 'em_revisao'], true) ? 'Continuar' : 'Ver relatório' ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif;
}

/** Escolha da aba quando o XLSX tem mais de uma. @param string[] $sheets */
function nc_import_step_sheet(array $batch, array $sheets): void
{
    ?>
    <section class="nc-card">
        <form class="nc-form" method="post" action="<?= e(nc_url('acoes/importar-acao.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="aba"><input type="hidden" name="lote" value="<?= (int)$batch['id'] ?>">
            <fieldset class="nc-choice">
                <legend>Esta planilha tem mais de uma aba. Qual deve ser importada?</legend>
                <?php foreach ($sheets as $i => $name): ?>
                    <label class="nc-choice-item"><input type="radio" name="aba" value="<?= e($name) ?>"<?= $i === 0 ? ' checked' : '' ?>><span><strong><?= e($name) ?></strong></span></label>
                <?php endforeach; ?>
            </fieldset>
            <div class="nc-form-actions"><button type="submit" class="nc-btn nc-btn-primary">Continuar</button></div>
        </form>
    </section>
    <?php
}

/* ------------------------------------------------------------------ */
/* Etapa 2: Mapear colunas                                             */
/* ------------------------------------------------------------------ */

/**
 * @param array<int,array{n:int,cells:string[]}> $dataRows
 * @param array<int,string> $mapping
 * @param array<string,mixed> $valueMaps
 */
function nc_import_step_map(PDO $pdo, array $batch, array $headers, array $dataRows, array $mapping, array $valueMaps, bool $fromTemplate, string $error = ''): void
{
    $type = (string)$batch['import_type'];
    $fields = nc_import_fields($type);
    $missing = nc_import_mapping_missing($type, $mapping);
    $cols = count($headers);
    ?>
    <form class="nc-form" method="post" action="<?= e(nc_url('acoes/importar-mapear.php')) ?>" id="nc-map-form" data-nc-refresh-url="<?= e(nc_url('importar.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="lote" value="<?= (int)$batch['id'] ?>">
        <?php if ($error !== ''): ?><div class="nc-alert nc-alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if ($fromTemplate): ?>
            <div class="nc-alert nc-alert-success" role="status">Modelo aplicado: já mapeei as colunas como você fez da última vez neste formato de planilha. Só confira.</div>
        <?php endif; ?>
        <p class="nc-help">O sistema sugeriu o campo de cada coluna pelo nome do cabeçalho. Confirme ou corrija. Colunas marcadas como “Ignorar” não são importadas.
            Arquivo: <strong><?= e((string)$batch['file_name']) ?></strong><?= ($batch['sheet_name'] ?? '') !== '' ? ' · aba ' . e((string)$batch['sheet_name']) : '' ?> · <?= count($dataRows) ?> linhas de dados.</p>

        <div class="nc-table-wrap">
            <table class="nc-table">
                <thead><tr><th>Coluna da planilha</th><th>Exemplos</th><th>Campo do CRM</th></tr></thead>
                <tbody>
                <?php for ($i = 0; $i < $cols; $i++):
                    $header = (string)($headers[$i] ?? '');
                    $samples = [];
                    foreach ($dataRows as $r) {
                        $v = (string)($r['cells'][$i] ?? '');
                        if ($v !== '' && !in_array($v, $samples, true)) {
                            $samples[] = $v;
                        }
                        if (count($samples) >= 3) {
                            break;
                        }
                    }
                    $current = (string)($mapping[$i] ?? ''); ?>
                    <tr>
                        <td><strong><?= e($header !== '' ? $header : '(sem título)') ?></strong><br><small class="nc-muted">Coluna <?= e(chr(65 + ($i % 26))) ?><?= $i >= 26 ? '+' : '' ?></small></td>
                        <td><?php foreach ($samples as $s): ?><span class="nc-sample"><?= e(nc_truncate($s, 40)) ?></span><?php endforeach; ?><?= $samples ? '' : '<span class="nc-muted">—</span>' ?></td>
                        <td>
                            <label class="nc-sr-only" for="map-<?= $i ?>">Campo para a coluna <?= e($header) ?></label>
                            <select class="nc-input" id="map-<?= $i ?>" name="map[<?= $i ?>]" data-nc-map>
                                <option value="">— Ignorar —</option>
                                <?php foreach ($fields as $key => $def): ?>
                                    <option value="<?= e($key) ?>"<?= $current === $key ? ' selected' : '' ?>><?= e($def['label'] . ($def['required'] === true ? ' *' : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <?php if ($type === 'orcamentos'): nc_import_value_maps($pdo, $mapping, $dataRows, $valueMaps); endif; ?>

        <div class="nc-alert<?= $missing ? ' nc-alert-warning' : ' nc-alert-success' ?>" role="status">
            <?php if ($missing): ?>
                Para continuar, mapeie: <strong><?= e(implode(', ', $missing)) ?></strong>.
            <?php else: ?>
                Todos os campos obrigatórios estão mapeados.
            <?php endif; ?>
        </div>
        <div class="nc-form-actions">
            <button type="button" class="nc-btn nc-btn-secondary" data-nc-map-refresh>Atualizar valores</button>
            <button type="submit" class="nc-btn nc-btn-primary"<?= $missing ? ' disabled' : '' ?>>Continuar e processar a prévia</button>
        </div>
    </form>
    <?php
}

/** Mapeamento de valores: status e linha de produto (editável e salvo no modelo). */
function nc_import_value_maps(PDO $pdo, array $mapping, array $dataRows, array $valueMaps): void
{
    $statusCol = array_search('status', $mapping, true);
    $lineCol = array_search('product_line', $mapping, true);
    $lines = $pdo->query('SELECT id, name FROM product_lines WHERE active = 1 ORDER BY sort_order, name')->fetchAll();
    if ($statusCol !== false):
        $values = nc_import_distinct_values($dataRows, (int)$statusCol); ?>
        <section class="nc-card">
            <h2 class="nc-card-title">Valores de Status</h2>
            <p class="nc-help">Valor que não for reconhecido vai para <strong>Revisar</strong>; o sistema nunca adivinha. Escolha o que cada um significa.</p>
            <?php if (!$values): ?><p class="nc-muted">A coluna de status está vazia: todos entram como Aberta.</p><?php endif; ?>
            <?php $k = 0; foreach ($values as $text => $qtd):
                $auto = nc_import_status_resolve((string)$text, []);
                $chosen = (string)($valueMaps['status'][nc_norm((string)$text)] ?? ''); ?>
                <div class="nc-valuemap">
                    <input type="hidden" name="vs_text[<?= $k ?>]" value="<?= e((string)$text) ?>">
                    <label for="vs-<?= $k ?>"><strong><?= e((string)$text) ?></strong> <span class="nc-muted">(<?= (int)$qtd ?> linha<?= $qtd === 1 ? '' : 's' ?>)</span></label>
                    <select class="nc-input" id="vs-<?= $k ?>" name="vs_val[<?= $k ?>]">
                        <option value=""><?= $auto ? 'Automático: ' . e($auto) : 'Não reconhecido (vai para Revisar)' ?></option>
                        <?php foreach (['Aberta', 'Ganha', 'Perdida'] as $s): ?>
                            <option value="<?= $s ?>"<?= $chosen === $s ? ' selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php $k++; endforeach; ?>
        </section>
    <?php endif;
    if ($lineCol !== false):
        $values = nc_import_distinct_values($dataRows, (int)$lineCol); ?>
        <section class="nc-card">
            <h2 class="nc-card-title">Valores de Linha de produto</h2>
            <p class="nc-help">O que não for reconhecido usa a <strong>Linha padrão do lote</strong>, que você escolhe na conferência.</p>
            <?php $k = 0; foreach ($values as $text => $qtd):
                $auto = product_line_id_from_text($pdo, (string)$text);
                $chosen = (int)($valueMaps['line'][nc_norm((string)$text)] ?? 0); ?>
                <div class="nc-valuemap">
                    <input type="hidden" name="vl_text[<?= $k ?>]" value="<?= e((string)$text) ?>">
                    <label for="vl-<?= $k ?>"><strong><?= e((string)$text) ?></strong> <span class="nc-muted">(<?= (int)$qtd ?> linha<?= $qtd === 1 ? '' : 's' ?>)</span></label>
                    <select class="nc-input" id="vl-<?= $k ?>" name="vl_val[<?= $k ?>]">
                        <option value=""><?= $auto ? 'Automático' : 'Não reconhecida (usa a linha padrão)' ?></option>
                        <?php foreach ($lines as $l): ?>
                            <option value="<?= (int)$l['id'] ?>"<?= $chosen === (int)$l['id'] ? ' selected' : '' ?>><?= e((string)$l['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php $k++; endforeach; ?>
        </section>
    <?php endif;
}

/* ------------------------------------------------------------------ */
/* Processamento da prévia (blocos de 500 com polling HTMX)            */
/* ------------------------------------------------------------------ */

function nc_import_processing(array $batch, int $total, int $pending): void
{
    $done = max(0, $total - $pending);
    $pct = $total > 0 ? (int)floor($done * 100 / $total) : 0;
    ?>
    <form id="nc-import-proc" class="nc-card" method="post" action="<?= e(nc_url('acoes/importar-processar.php')) ?>"
          hx-post="<?= e(nc_url('acoes/importar-processar.php')) ?>" hx-trigger="load delay:300ms" hx-swap="outerHTML" hx-target="this">
        <?= csrf_field() ?>
        <input type="hidden" name="lote" value="<?= (int)$batch['id'] ?>">
        <h2 class="nc-card-title">Montando a prévia…</h2>
        <p>Conferindo cada linha com o CRM, sem gravar nada. <strong><?= $done ?></strong> de <strong><?= $total ?></strong> linhas.</p>
        <div class="nc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>"><span style="width: <?= $pct ?>%"></span></div>
        <noscript><p class="nc-help">Seu navegador não executa JavaScript: use o botão para processar o próximo bloco.</p></noscript>
        <div class="nc-form-actions"><button type="submit" class="nc-btn nc-btn-secondary">Processar o próximo bloco</button></div>
    </form>
    <?php
}

/* ------------------------------------------------------------------ */
/* Etapa 3: Conferir                                                   */
/* ------------------------------------------------------------------ */

/** Nomes e CNPJs das empresas citadas pelas linhas. @return array<int,array{name:string,cnpj:string}> */
function nc_import_company_names(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $q = $pdo->prepare('SELECT id, name, cnpj FROM companies WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $q->execute($ids);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $out[(int)$r['id']] = ['name' => (string)$r['name'], 'cnpj' => (string)($r['cnpj'] ?? '')];
    }
    return $out;
}

function nc_import_step_review(PDO $pdo, array $batch, array $user): void
{
    $id = (int)$batch['id'];
    $type = (string)$batch['import_type'];
    $counters = nc_import_decode($batch['counters_json'] ?? null);
    $filtro = (string)($_GET['filtro'] ?? '');
    if ($filtro !== '' && !in_array($filtro, NC_IMPORT_CLASSES, true)) {
        $filtro = '';
    }
    $page = max(1, (int)($_GET['p'] ?? 1));
    $blockers = nc_import_confirm_blockers($pdo, $batch);
    $summary = nc_import_confirm_summary($pdo, $batch);
    $isOrc = $type === 'orcamentos';
    $sum = (array)($counters['sum'] ?? []);
    $chips = '';
    foreach (NC_IMPORT_CLASSES as $c) {
        $n = (int)($counters[$c] ?? 0);
        $chips .= '<a class="nc-chip' . ($filtro === $c ? ' is-active' : '') . '" href="' . e(nc_url('importar.php?lote=' . $id . '&filtro=' . $c)) . '">'
            . e(nc_import_class_label($c)) . ' <strong>' . $n . '</strong>'
            . ($isOrc && $n > 0 ? ' <small>' . e(money((float)($sum[$c] ?? 0))) . '</small>' : '') . '</a>';
    }
    $chips = '<a class="nc-chip' . ($filtro === '' ? ' is-active' : '') . '" href="' . e(nc_url('importar.php?lote=' . $id)) . '">Todas <strong>'
        . array_sum(array_map(static fn($c) => (int)($counters[$c] ?? 0), NC_IMPORT_CLASSES)) . '</strong></a>' . $chips;
    ?>
    <section class="nc-card">
        <div class="nc-review-head">
            <div>
                <h2 class="nc-card-title">Conferir: nada foi gravado no CRM ainda</h2>
                <p class="nc-help">Arquivo <strong><?= e((string)$batch['file_name']) ?></strong> · <?= e(nc_import_types()[$type]) ?> · lote #<?= $id ?>.
                    Resolva as linhas em <strong>Revisar</strong>; as demais já estão prontas.</p>
            </div>
            <div class="nc-toolbar-actions">
                <a class="nc-btn nc-btn-secondary" href="<?= e(nc_url('acoes/importar-csv.php?lote=' . $id . '&tipo=previa')) ?>">Baixar prévia (CSV)</a>
                <form method="post" action="<?= e(nc_url('acoes/importar-acao.php')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="op" value="remapear"><input type="hidden" name="lote" value="<?= $id ?>">
                    <button type="submit" class="nc-btn nc-btn-secondary" data-nc-confirm="Voltar ao mapeamento refaz a prévia e descarta as decisões de revisão já tomadas neste lote. Continuar?"
                            data-nc-confirm-title="Refazer o mapeamento" data-nc-confirm-ok="Voltar ao mapeamento">Alterar mapeamento</button>
                </form>
            </div>
        </div>
        <div class="nc-chips" role="navigation" aria-label="Filtrar por classificação"><?= $chips /* montado com e() acima */ ?></div>
        <?php if ($isOrc):
            $total = (float)($counters['sheet_total'] ?? 0);
            $parts = array_sum(array_map(static fn($c) => (float)($sum[$c] ?? 0), NC_IMPORT_CLASSES)); ?>
            <p class="nc-help" role="status">Conferência de valores: soma da coluna valor da planilha <strong><?= e(money($total)) ?></strong>
                = Novo + Atualizar + Sem alteração + Revisar + Erro + Ignorar <strong><?= e(money($parts)) ?></strong>
                <?= abs($total - $parts) < 0.005 ? '✓' : '(diferença: confira as linhas)' ?>.
                Vão para o CRM: <strong><?= e(money((float)($sum['novo'] ?? 0) + (float)($sum['atualizar'] ?? 0) + (float)($sum['sem_alteracao'] ?? 0))) ?></strong>.</p>
            <?php nc_import_default_line_form($pdo, $batch); ?>
        <?php endif; ?>
    </section>
    <?php

    if ($filtro === 'revisar') {
        nc_import_review_groups($pdo, $batch, $page);
        nc_import_confirm_panel($batch, $blockers, $summary);
        return;
    }

    $where = 'batch_id = ?';
    $params = [$id];
    if ($filtro !== '') {
        $where .= ' AND classification = ?';
        $params[] = $filtro;
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM import_rows WHERE $where");
    $q->execute($params);
    $totalRows = (int)$q->fetchColumn();
    $pages = max(1, (int)ceil($totalRows / NC_IMPORT_PER_PAGE));
    $page = min($page, $pages);
    $q = $pdo->prepare("SELECT * FROM import_rows WHERE $where ORDER BY sheet_row LIMIT " . NC_IMPORT_PER_PAGE . ' OFFSET ' . (($page - 1) * NC_IMPORT_PER_PAGE));
    $q->execute($params);
    $rows = $q->fetchAll();

    if (!$rows) {
        nc_empty('Nenhuma linha neste filtro', 'Escolha outra classificação acima.');
    } else {
        $names = nc_import_company_names($pdo, array_map(static fn($r) => (int)$r['company_id'], $rows));
        ?>
        <div class="nc-table-wrap">
            <table class="nc-table nc-import-table">
                <thead><tr><th class="is-num">Linha</th><th>Dados da planilha</th><th>Empresa vinculada</th><th>Classificação</th><th>Mudanças (antes → depois)</th><th class="is-actions">Ação</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r) { nc_import_review_row($r, $type, $names, $batch); } ?>
                </tbody>
            </table>
        </div>
        <?php
        nc_pagination($page, $pages);
    }

    nc_import_confirm_panel($batch, $blockers, $summary);
}

/** Rótulo e cor da faixa de confiança de um grupo de revisão. @return array{string,string} */
function nc_import_tier_badge(string $tier): array
{
    return match ($tier) {
        'alta' => ['Alta confiança', 'success'],
        'sem' => ['Sem empresa parecida', 'info'],
        'media' => ['Confira', 'warning'],
        default => ['Decisão individual', 'danger'],
    };
}

/**
 * Revisão por CLIENTE (não por linha): um painel para decidir tudo de uma vez e um grupo por nome de cliente.
 * As decisões valem para todas as linhas do cliente e, na confirmação, viram apelidos que a próxima importação já reconhece.
 */
function nc_import_review_groups(PDO $pdo, array $batch, int $page): void
{
    $id = (int)$batch['id'];
    $type = (string)$batch['import_type'];
    $isOrc = $type === 'orcamentos';
    $plan = nc_import_bulk_plan($pdo, $batch);
    $c = $plan['counts'];
    $groups = array_values($plan['groups']);
    $action = nc_url('acoes/importar-resolver-massa.php');
    $btn = static function (string $mode, string $label, string $confirm, int $enabled, string $style = 'nc-btn-primary') use ($action, $id): void {
        ?>
        <form method="post" action="<?= e($action) ?>" class="nc-bulk-form">
            <?= csrf_field() ?><input type="hidden" name="lote" value="<?= $id ?>"><input type="hidden" name="modo" value="<?= e($mode) ?>">
            <button type="submit" class="nc-btn <?= e($style) ?>"<?= $enabled > 0 ? '' : ' disabled' ?>
                    data-nc-confirm="<?= e($confirm) ?>" data-nc-confirm-title="<?= e($label) ?>" data-nc-confirm-ok="Aplicar"><?= e($label) ?></button>
        </form>
        <?php
    };
    ?>
    <section class="nc-card nc-bulk-panel" aria-labelledby="nc-bulk-title">
        <h2 class="nc-card-title" id="nc-bulk-title">Resolver tudo de uma vez</h2>
        <?php if ($c['rows'] === 0): ?>
            <p>Nenhuma linha em Revisar. ✓</p>
        <?php else: ?>
            <p class="nc-help"><strong><?= (int)$c['rows'] ?></strong> linha(s) em Revisar, de <strong><?= (int)$c['groups'] ?></strong> cliente(s) diferente(s).
                Cada decisão vale para todas as linhas do cliente. Nada é gravado no CRM antes de confirmar a importação.</p>
            <div class="nc-bulk-actions">
                <?php
                $btn('vincular', 'Vincular ' . (int)$c['alta_groups'] . ' cliente(s) de alta confiança',
                    'Vincular cada cliente de alta confiança (' . (int)$c['alta_rows'] . ' linha(s)) à empresa mais parecida do CRM. Só entram nomes com ' . NC_IMPORT_BULK_MIN_SIM . '% ou mais de semelhança, uma candidata clara e sem conflito de CNPJ ou UF.',
                    (int)$c['alta_rows']);
                $btn('criar', 'Criar empresa nova para ' . (int)$c['sem_groups'] . ' cliente(s) sem candidata',
                    'Criar uma empresa nova para cada cliente sem empresa parecida (' . (int)$c['sem_rows'] . ' linha(s)). '
                    . ($isOrc ? 'As empresas ficam sem CNPJ; o Saneamento completa o CNPJ depois. ' : '') . 'Só é gravado na confirmação.',
                    (int)$c['sem_rows'], 'nc-btn-secondary');
                $btn('ignorar', 'Ignorar ' . ((int)$c['sem_rows'] + (int)$c['media_rows']) . ' linha(s) sem decisão segura',
                    'Ignorar as linhas dos clientes sem candidata ou de confiança média. Elas não entram na importação.',
                    (int)$c['sem_rows'] + (int)$c['media_rows'], 'nc-btn-danger-outline');
                ?>
            </div>
            <p class="nc-help">
                Alta confiança: <?= (int)$c['alta_groups'] ?> · Sem empresa parecida: <?= (int)$c['sem_groups'] ?> ·
                Confira: <?= (int)$c['media_groups'] ?> · Decisão individual: <?= (int)$c['manual_groups'] ?>.
            </p>
            <?php if ((int)$c['media_groups'] + (int)$c['sem_groups'] > 0): ?>
                <form method="post" hx-post="<?= e(nc_url('acoes/importar-ia.php')) ?>" hx-target="#nc-ia-result" hx-swap="innerHTML" hx-indicator="#nc-ia-wait">
                    <?= csrf_field() ?><input type="hidden" name="lote" value="<?= $id ?>">
                    <button type="submit" class="nc-btn nc-btn-secondary">Sugerir com IA os clientes que sobraram</button>
                    <small id="nc-ia-wait" class="nc-help htmx-indicator">Consultando a IA…</small>
                    <small class="nc-help">Envia só o nome dos clientes e das empresas candidatas (sem dados de pessoas). Você aprova a lista com um clique.</small>
                </form>
                <div id="nc-ia-result"></div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
    if (!$groups) {
        nc_empty('Nenhuma linha neste filtro', 'Escolha outra classificação acima.');
        return;
    }
    $pages = max(1, (int)ceil(count($groups) / NC_IMPORT_PER_PAGE));
    $page = min($page, $pages);
    $slice = array_slice($groups, ($page - 1) * NC_IMPORT_PER_PAGE, NC_IMPORT_PER_PAGE);
    ?>
    <div class="nc-table-wrap">
        <table class="nc-table nc-import-table">
            <thead><tr><th>Cliente na planilha</th><th class="is-num">Linhas</th><?= $isOrc ? '<th class="is-num">Valor</th>' : '' ?><th>Melhor candidata</th><th>Faixa</th><th class="is-actions">Ação</th></tr></thead>
            <tbody>
            <?php foreach ($slice as $g):
                [$tierLabel, $tierKind] = nc_import_tier_badge((string)$g['tier']);
                $best = $g['best']; ?>
                <tr>
                    <td><strong><?= e((string)$g['label']) ?></strong></td>
                    <td class="is-num"><?= (int)$g['count'] ?></td>
                    <?php if ($isOrc): ?><td class="is-num"><?= e(money((float)$g['sum'])) ?></td><?php endif; ?>
                    <td>
                        <?php if ($best): ?>
                            <?= e((string)$best['name']) ?><br>
                            <small class="nc-muted"><?= e(implode(' · ', array_filter([
                                ($best['cnpj'] ?? '') !== '' ? format_cnpj((string)$best['cnpj']) : 'Sem CNPJ',
                                trim((string)($best['city'] ?? '') . (($best['state'] ?? '') !== '' ? '/' . $best['state'] : '')),
                                (int)($best['sim'] ?? 0) . '% parecido',
                            ]))) ?></small>
                        <?php else: ?><span class="nc-muted">—</span><?php endif; ?>
                    </td>
                    <td><?= nc_badge($tierLabel, $tierKind) ?><?php if ((string)$g['why'] !== ''): ?><br><small class="nc-muted"><?= e((string)$g['why']) ?></small><?php endif; ?></td>
                    <td class="is-actions">
                        <button type="button" class="nc-btn nc-btn-sm nc-btn-primary" hx-get="<?= e(nc_url('acoes/importar-revisar-form.php?linha=' . (int)$g['row_ids'][0])) ?>"
                                hx-target="#nc-drawer-body" hx-swap="innerHTML">Resolver…</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    nc_pagination($page, $pages);
}

/** "Linha padrão para este lote": obrigatória se houver orçamento sem linha identificada. */
function nc_import_default_line_form(PDO $pdo, array $batch): void
{
    $lines = $pdo->query('SELECT id, name FROM product_lines WHERE active = 1 ORDER BY sort_order, name')->fetchAll();
    $cur = (int)($batch['default_product_line_id'] ?? 0);
    ?>
    <form class="nc-inline-form" method="post" action="<?= e(nc_url('acoes/importar-acao.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="op" value="linha_padrao"><input type="hidden" name="lote" value="<?= (int)$batch['id'] ?>">
        <label for="imp-line">Linha padrão para este lote</label>
        <select class="nc-input" id="imp-line" name="linha">
            <option value="">— Escolher —</option>
            <?php foreach ($lines as $l): ?><option value="<?= (int)$l['id'] ?>"<?= $cur === (int)$l['id'] ? ' selected' : '' ?>><?= e((string)$l['name']) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="nc-btn nc-btn-secondary nc-btn-sm">Aplicar</button>
        <small class="nc-help">Usada nos orçamentos cuja linha de produto a planilha não informa ou o sistema não reconhece (marcados com “linha padrão”).</small>
    </form>
    <?php
}

/** Uma linha da prévia. @param array<int,array{name:string,cnpj:string}> $names */
function nc_import_review_row(array $r, string $type, array $names, array $batch): void
{
    $n = nc_import_decode($r['normalized_json']);
    $class = (string)$r['classification'];
    $cid = (int)$r['company_id'];
    $isOrc = $type === 'orcamentos';
    $resolved = (string)($r['resolution'] ?? '');
    ?>
    <tr class="nc-row-<?= e($class) ?>" id="row-<?= (int)$r['id'] ?>">
        <td class="is-num"><?= (int)$r['sheet_row'] ?></td>
        <td>
            <?php if (!empty($n['skipped'])): ?>
                <span class="nc-muted">—</span>
            <?php elseif ($isOrc): ?>
                <strong><?= e((string)($n['ref'] ?? '')) ?></strong>
                <?php if (!empty($n['date'])): ?> · <?= e(nc_date((string)$n['date'])) ?><?php endif; ?>
                <?php if (isset($n['amount']) && $n['amount'] !== null): ?> · <?= e(money((float)$n['amount'])) ?><?php endif; ?><br>
                <small>Cliente na planilha: <?= e((string)($n['client_name'] ?? '')) ?></small>
                <?php if (!empty($n['status_text'])): ?><br><small class="nc-muted">Status: <?= e((string)$n['status_text']) ?><?= !empty($n['plan']['status']) ? ' → ' . e((string)$n['plan']['status']) : '' ?></small><?php endif; ?>
                <?php foreach (['line_default' => 'linha padrão', 'stage_default' => 'etapa padrão', 'loss_default' => 'motivo padrão'] as $flag => $label):
                    if (!empty($n['plan'][$flag])): ?> <?= nc_badge($label) ?><?php endif; endforeach; ?>
            <?php else: ?>
                <strong><?= e((string)($n['contact']['name'] ?? '')) ?></strong><?= !empty($n['contact']['job']) ? ' · ' . e((string)$n['contact']['job']) : '' ?><br>
                <small><?= e(implode(' · ', array_filter([(string)($n['contact']['email'] ?? ''), (string)($n['contact']['phone'] ?? '')]))) ?></small><br>
                <small class="nc-muted">Empresa na planilha: <?= e((string)($n['company']['name'] ?? '')) ?><?php
                    $doc = $n['company']['doc'] ?? []; if (($doc['kind'] ?? '') === 'cnpj'): ?> · <?= e(format_cnpj((string)$doc['digits'])) ?><?php elseif (($doc['raw'] ?? '') !== ''): ?> · <?= e((string)$doc['raw']) ?><?php endif; ?></small>
                <?php $co = (array)($n['company'] ?? []);
                $extra = array_filter([
                    ($co['city'] ?? '') !== '' || ($co['state'] ?? '') !== '' ? trim(($co['city'] ?? '') . (($co['state'] ?? '') !== '' ? '/' . $co['state'] : '')) : '',
                    ($co['phone'] ?? '') !== '' ? 'Tel. empresa ' . $co['phone'] : '',
                    ($co['owner_email'] ?? '') !== '' ? 'Vendedor ' . $co['owner_email'] : '',
                    ($co['interest'] ?? '') !== '' ? 'Interesse: ' . $co['interest'] : '',
                    ($co['quote_count'] ?? null) !== null ? $co['quote_count'] . ' orçamento(s) na origem' : '',
                    ($co['last_quote'] ?? '') !== '' ? 'Última ' . nc_date((string)$co['last_quote']) : '',
                    ($co['origin'] ?? '') !== '' ? 'Origem: ' . $co['origin'] : '',
                ]);
                if ($extra): ?><br><small class="nc-muted"><?= e(implode(' · ', $extra)) ?></small><?php endif; ?>
            <?php endif; ?>
            <?php foreach ((array)($n['warnings'] ?? []) as $w): ?><br><small class="nc-warn">⚠ <?= e((string)$w) ?></small><?php endforeach; ?>
        </td>
        <td>
            <?php if ($cid > 0 && isset($names[$cid])): ?>
                <a href="<?= e(nc_url('cliente.php?id=' . $cid)) ?>" target="_blank" rel="noopener"><?= e($names[$cid]['name']) ?></a><br>
                <small class="nc-muted"><?= e($names[$cid]['cnpj'] !== '' ? format_cnpj($names[$cid]['cnpj']) : 'Sem CNPJ') ?></small>
            <?php elseif (!empty($n['target']['new_company'])): ?>
                <strong>Empresa nova:</strong> <?= e((string)($n['company']['name'] ?? '')) ?>
            <?php else: ?><span class="nc-muted">—</span><?php endif; ?>
            <?php $rule = nc_import_rule_label($r['match_rule'] ?? ''); if ($rule !== '' && in_array($class, ['novo', 'atualizar', 'sem_alteracao', 'revisar'], true)): ?>
                <br><small class="nc-muted">Regra: <?= e($rule) ?></small>
            <?php endif; ?>
            <?php if (in_array($class, ['novo', 'atualizar', 'sem_alteracao'], true)): ?>
                <br><?= nc_badge('Certo', 'success') ?>
            <?php elseif ($class === 'revisar'): ?>
                <br><?= nc_badge('Revisar', 'warning') ?>
            <?php endif; ?>
            <?php if ($resolved !== ''): ?><br><small class="nc-muted">Decisão: <?= $resolved === 'escolhida' ? 'empresa escolhida' : ($resolved === 'criada' ? 'criar empresa nova' : 'ignorada') ?></small><?php endif; ?>
        </td>
        <td><?= nc_import_class_badge($class) ?><?php if ((string)($r['reason'] ?? '') !== ''): ?><br><small class="nc-muted"><?= e((string)$r['reason']) ?></small><?php endif; ?></td>
        <td><?php nc_import_diff_cell($n, $r, $class); ?></td>
        <td class="is-actions"><?php nc_import_row_actions($r, $class, $isOrc, $resolved); ?></td>
    </tr>
    <?php
}

/** Antes → depois. */
function nc_import_diff_cell(array $n, array $r, string $class): void
{
    $diffs = [];
    foreach ((array)($n['target']['company_diff'] ?? []) as $f => $pair) {
        $diffs['Empresa · ' . nc_import_diff_label((string)$f)] = $pair;
    }
    foreach ((array)($n['target']['contact_diff'] ?? []) as $f => $pair) {
        $diffs['Contato · ' . nc_import_diff_label((string)$f)] = $pair;
    }
    foreach ((array)($n['target']['diff'] ?? []) as $f => $pair) {
        $diffs[nc_import_diff_label((string)$f)] = $pair;
    }
    if (!$diffs) {
        echo '<span class="nc-muted">—</span>';
        return;
    }
    echo '<ul class="nc-diff">';
    $names = (array)($n['target']['labels'] ?? []);
    foreach ($diffs as $label => [$before, $after]) {
        if (str_ends_with($label, 'Responsável') && isset($names['owner_user_id'])) {
            $after = $names['owner_user_id'];
        }
        echo '<li><strong>' . e($label) . ':</strong> <span class="nc-diff-old">' . e((string)$before !== '' ? (string)$before : '(vazio)') . '</span> → <span class="nc-diff-new">' . e((string)$after) . '</span></li>';
    }
    echo '</ul>';
    if (!empty($n['target']['crm_edited'])) {
        $mode = nc_import_row_mode($r);
        echo '<form class="nc-mode-form" method="post" action="' . e(nc_url('acoes/importar-acao.php')) . '">' . csrf_field()
            . '<input type="hidden" name="op" value="modo"><input type="hidden" name="lote" value="' . (int)$r['batch_id'] . '"><input type="hidden" name="linha_id" value="' . (int)$r['id'] . '">'
            . '<small>Editado no CRM depois da importação:</small> '
            . '<button class="nc-btn nc-btn-sm ' . ($mode === 'crm' ? 'nc-btn-primary' : 'nc-btn-secondary') . '" name="modo" value="crm" aria-pressed="' . ($mode === 'crm' ? 'true' : 'false') . '">Manter CRM</button> '
            . '<button class="nc-btn nc-btn-sm ' . ($mode === 'planilha' ? 'nc-btn-primary' : 'nc-btn-secondary') . '" name="modo" value="planilha" aria-pressed="' . ($mode === 'planilha' ? 'true' : 'false') . '">Usar planilha</button></form>';
    }
}

function nc_import_row_actions(array $r, string $class, bool $isOrc, string $resolved): void
{
    if ($class === 'revisar' || $resolved !== '') {
        $label = $class === 'revisar' ? 'Resolver…' : 'Mudar decisão';
        echo '<button type="button" class="nc-btn nc-btn-sm ' . ($class === 'revisar' ? 'nc-btn-primary' : 'nc-btn-secondary') . '" hx-get="'
            . e(nc_url('acoes/importar-revisar-form.php?linha=' . (int)$r['id'])) . '" hx-target="#nc-drawer-body" hx-swap="innerHTML">' . e($label) . '</button>';
    }
}

/** Etapa 4: confirmar. */
function nc_import_confirm_panel(array $batch, array $blockers, array $s): void
{
    $type = (string)$batch['import_type'];
    $lines = [];
    if ($type === 'contatos') {
        $lines[] = 'Criar ' . $s['companies_new'] . ' empresa(s) e ' . $s['contacts_new'] . ' contato(s)';
    } else {
        $lines[] = 'Criar ' . $s['opps_new'] . ' orçamento(s) (' . money((float)$s['amount_new']) . ')'
            . ($s['companies_new'] > 0 ? ' e ' . $s['companies_new'] . ' empresa(s) nova(s) sem CNPJ' : '');
    }
    $lines[] = 'atualizar ' . $s['updates'];
    if ($s['kept_crm'] > 0) {
        $lines[] = 'manter o CRM em ' . $s['kept_crm'];
    }
    $lines[] = 'ignorar ' . ($s['ignored'] + $s['errors']);
    $message = implode('; ', $lines) . '. Tudo é gravado em uma única transação: se algo falhar, nada é gravado.';
    ?>
    <section class="nc-card nc-confirm-panel" aria-labelledby="nc-confirm-title">
        <h2 class="nc-card-title" id="nc-confirm-title">Confirmar importação</h2>
        <p><?= e($message) ?></p>
        <?php if ($blockers): ?>
            <div class="nc-alert nc-alert-warning" role="alert"><strong>Ainda não dá para confirmar:</strong>
                <ul><?php foreach ($blockers as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
        <form method="post" action="<?= e(nc_url('acoes/importar-acao.php')) ?>">
            <?= csrf_field() ?><input type="hidden" name="op" value="confirmar"><input type="hidden" name="lote" value="<?= (int)$batch['id'] ?>">
            <button type="submit" class="nc-btn nc-btn-primary"<?= $blockers ? ' disabled' : '' ?>
                    data-nc-confirm="<?= e($message) ?>" data-nc-confirm-title="Confirmar importação" data-nc-confirm-ok="Confirmar importação">Confirmar importação</button>
        </form>
    </section>
    <?php
}

/* ------------------------------------------------------------------ */
/* Revisão de uma linha (drawer)                                       */
/* ------------------------------------------------------------------ */

function nc_import_review_drawer(PDO $pdo, array $batch, array $row, string $error = ''): void
{
    $type = (string)$batch['import_type'];
    $n = nc_import_decode($row['normalized_json']);
    $cands = nc_import_decode($row['candidates_json'] ?? null);
    $name = $type === 'orcamentos' ? (string)($n['client_name'] ?? '') : (string)($n['company']['name'] ?? '');
    $same = 0;
    $myKey = nc_import_row_client_name($row);
    $q = $pdo->prepare("SELECT * FROM import_rows WHERE batch_id = ? AND classification = 'revisar' AND id <> ?");
    $q->execute([(int)$batch['id'], (int)$row['id']]);
    foreach ($q->fetchAll() as $o) {
        if ($myKey !== '' && nc_import_row_client_name($o) === $myKey) {
            $same++;
        }
    }
    $counts = [];
    if ($cands) {
        $ids = array_map(static fn($c) => (int)$c['id'], $cands);
        $qq = $pdo->prepare('SELECT company_id, COUNT(*) c FROM opportunities WHERE company_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY company_id');
        $qq->execute($ids);
        foreach ($qq->fetchAll() as $c) {
            $counts[(int)$c['company_id']] = (int)$c['c'];
        }
    }
    $action = nc_url('acoes/importar-resolver.php');
    ?>
    <div data-drawer-title="Resolver linha <?= (int)$row['sheet_row'] ?>">
        <div class="nc-form">
            <?php if ($error !== ''): ?><div class="nc-alert nc-alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
            <p><strong><?= e($name) ?></strong><br><small class="nc-muted"><?= e((string)($row['reason'] ?? '')) ?></small></p>
            <?php if (in_array((string)($row['match_rule'] ?? ''), ['status'], true)): ?>
                <div class="nc-alert nc-alert-warning">O status desta linha precisa ser mapeado: volte ao mapeamento e escolha o que o valor significa, ou ignore a linha.</div>
            <?php endif; ?>

            <?php if ($cands): ?>
                <h3 class="nc-section-title">Empresas candidatas</h3>
                <ul class="nc-cands">
                    <?php foreach ($cands as $c): ?>
                        <li>
                            <form method="post" hx-post="<?= e($action) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML">
                                <?= csrf_field() ?>
                                <input type="hidden" name="lote" value="<?= (int)$batch['id'] ?>"><input type="hidden" name="linha_id" value="<?= (int)$row['id'] ?>">
                                <input type="hidden" name="op" value="escolher"><input type="hidden" name="company_id" value="<?= (int)$c['id'] ?>">
                                <div>
                                    <strong><?= e((string)$c['name']) ?></strong><br>
                                    <small class="nc-muted"><?= e(implode(' · ', array_filter([
                                        ($c['cnpj'] ?? '') !== '' ? format_cnpj((string)$c['cnpj']) : 'Sem CNPJ',
                                        trim((string)($c['city'] ?? '') . (($c['state'] ?? '') !== '' ? '/' . $c['state'] : '')),
                                        (string)($c['why'] ?? ''),
                                        ($counts[(int)$c['id']] ?? 0) . ' oportunidade(s)',
                                    ]))) ?></small>
                                </div>
                                <?php if ($same > 0): ?>
                                    <label class="nc-check"><input type="checkbox" name="aplicar_mesmo" value="1" checked> Aplicar às outras <?= $same ?> linha(s) com “<?= e(nc_truncate($name, 30)) ?>”</label>
                                <?php endif; ?>
                                <button type="submit" class="nc-btn nc-btn-primary nc-btn-sm">Usar esta</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h3 class="nc-section-title">Escolher outra empresa…</h3>
            <form class="nc-form" method="post" hx-post="<?= e($action) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML">
                <?= csrf_field() ?>
                <input type="hidden" name="lote" value="<?= (int)$batch['id'] ?>"><input type="hidden" name="linha_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="op" value="escolher">
                <?php nc_company_picker('imp-pick'); ?>
                <?php if ($same > 0): ?>
                    <label class="nc-check"><input type="checkbox" name="aplicar_mesmo" value="1" checked> Aplicar às outras <?= $same ?> linha(s) com “<?= e(nc_truncate($name, 30)) ?>”</label>
                <?php endif; ?>
                <div class="nc-form-actions"><button type="submit" class="nc-btn nc-btn-primary">Vincular à empresa escolhida</button></div>
            </form>

            <div class="nc-review-more">
                <form method="post" hx-post="<?= e($action) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML">
                    <?= csrf_field() ?>
                    <input type="hidden" name="lote" value="<?= (int)$batch['id'] ?>"><input type="hidden" name="linha_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="op" value="criar">
                    <?php if ($same > 0): ?><label class="nc-check"><input type="checkbox" name="aplicar_mesmo" value="1" checked> Aplicar às outras <?= $same ?> linha(s) com o mesmo nome</label><?php endif; ?>
                    <button type="submit" class="nc-btn nc-btn-secondary">Criar empresa nova com os dados da linha</button>
                    <small class="nc-help">Só é gravada na confirmação<?= $type === 'orcamentos' ? ', sem CNPJ (o Saneamento completa depois)' : '; o CNPJ é consultado depois, em fila' ?>.</small>
                </form>
                <form method="post" hx-post="<?= e($action) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML">
                    <?= csrf_field() ?>
                    <input type="hidden" name="lote" value="<?= (int)$batch['id'] ?>"><input type="hidden" name="linha_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="op" value="ignorar">
                    <?php if ($same > 0): ?><label class="nc-check"><input type="checkbox" name="aplicar_mesmo" value="1" checked> Aplicar às outras <?= $same ?> linha(s) com o mesmo nome</label><?php endif; ?>
                    <button type="submit" class="nc-btn nc-btn-danger-outline">Ignorar esta linha</button>
                </form>
                <?php if (!empty($row['resolution'])): ?>
                    <form method="post" hx-post="<?= e($action) ?>" hx-target="#nc-drawer-body" hx-swap="innerHTML">
                        <?= csrf_field() ?>
                        <input type="hidden" name="lote" value="<?= (int)$batch['id'] ?>"><input type="hidden" name="linha_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="op" value="limpar">
                        <button type="submit" class="nc-btn nc-btn-secondary">Desfazer a decisão desta linha</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

/* ------------------------------------------------------------------ */
/* Lote concluído: relatório                                           */
/* ------------------------------------------------------------------ */

function nc_import_report(PDO $pdo, array $batch, array $user): void
{
    $id = (int)$batch['id'];
    $c = nc_import_decode($batch['counters_json'] ?? null);
    $res = (array)($c['result'] ?? []);
    $undo = (array)($c['undo'] ?? []);
    $status = (string)$batch['status'];
    $blocker = nc_import_undo_blocker($batch, $user);
    ?>
    <section class="nc-card">
        <div class="nc-review-head">
            <div>
                <h2 class="nc-card-title">Lote #<?= $id ?> · <?= nc_import_status_badge($status) ?></h2>
                <p class="nc-help"><?= e((string)$batch['file_name']) ?> · <?= e(nc_import_types()[$batch['import_type']] ?? '') ?>
                    <?php if ($status === 'confirmado'): ?> · confirmado em <?= e(nc_date((string)$batch['confirmed_at'])) ?><?php endif; ?></p>
            </div>
            <div class="nc-toolbar-actions">
                <a class="nc-btn nc-btn-secondary" href="<?= e(nc_url('acoes/importar-csv.php?lote=' . $id . '&tipo=relatorio')) ?>">Baixar relatório (CSV)</a>
                <a class="nc-btn nc-btn-secondary" href="<?= e(nc_url('acoes/importar-csv.php?lote=' . $id . '&tipo=erros')) ?>">Baixar erros (CSV)</a>
            </div>
        </div>
        <?php if ($status === 'falhou'): ?>
            <div class="nc-alert nc-alert-danger" role="alert"><strong>A importação falhou e nada foi gravado.</strong> <?= e((string)($batch['error_message'] ?? '')) ?></div>
        <?php endif; ?>
        <dl class="nc-stats">
            <?php foreach (['novo' => 'Criados', 'atualizar' => 'Atualizados', 'sem_alteracao' => 'Sem alteração', 'revisados' => 'Revisados', 'erro' => 'Erros', 'ignorar' => 'Ignorados'] as $k => $label): ?>
                <div><dt><?= e($label) ?></dt><dd><?= (int)($c[$k] ?? 0) ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <?php if ($res): ?>
            <p class="nc-help">Gravado: <?= e(implode(' · ', array_map(
                static fn($k, $v) => str_replace('_', ' ', (string)$k) . ': ' . (int)$v, array_keys($res), $res))) ?>.</p>
        <?php endif; ?>
        <?php if ($status === 'desfeito' && $undo): ?>
            <div class="nc-alert nc-alert-success" role="status">Desfeito: <?= (int)($undo['done'] ?? 0) ?> registro(s) removido(s) ou restaurado(s).</div>
            <?php if (!empty($undo['skipped'])): ?>
                <div class="nc-alert nc-alert-warning"><strong>Não foram desfeitos (nada é apagado à força):</strong>
                    <ul><?php foreach ($undo['skipped'] as $s): ?><li><?= e((string)$s['what']) ?> #<?= (int)$s['id'] ?>: <?= e((string)$s['why']) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($status === 'confirmado'): nc_import_cnpja_box($pdo, $batch, $user); ?>
            <?php if ($blocker === ''): ?>
                <form method="post" action="<?= e(nc_url('acoes/importar-acao.php')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="op" value="desfazer"><input type="hidden" name="lote" value="<?= $id ?>">
                    <button type="submit" class="nc-btn nc-btn-danger-outline"
                            data-nc-confirm="Isto remove os registros criados por este lote que ninguém alterou depois e restaura os valores anteriores dos atualizados. O que foi alterado depois NÃO é tocado e aparece no relatório."
                            data-nc-confirm-title="Desfazer importação do lote #<?= $id ?>" data-nc-confirm-ok="Desfazer importação">Desfazer importação</button>
                </form>
            <?php else: ?>
                <p class="nc-help"><?= e($blocker) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
}

/** "Completar N empresas pelo CNPJá": fila em segundo plano, uma consulta por vez, com pausa. Só depois da confirmação. */
function nc_import_cnpja_box(PDO $pdo, array $batch, array $user): void
{
    if ($batch['import_type'] !== 'contatos' || !nc_is_manager($user)) {
        return;
    }
    $n = count(nc_import_cnpja_pending($pdo, (int)$batch['id']));
    if ($n < 1) {
        return;
    }
    ?>
    <div class="nc-alert" role="note" id="nc-cnpja-box">
        <strong><?= $n ?> empresa<?= $n === 1 ? '' : 's' ?> criada<?= $n === 1 ? '' : 's' ?> com CNPJ ainda sem os dados cadastrais.</strong>
        A importação não consulta o CNPJá (limite de uso e tempo). Você pode completar agora: uma consulta por vez, só nos campos vazios.
        <form method="post" action="<?= e(nc_url('acoes/importar-cnpja.php')) ?>" hx-post="<?= e(nc_url('acoes/importar-cnpja.php')) ?>" hx-target="#nc-cnpja-box" hx-swap="outerHTML">
            <?= csrf_field() ?><input type="hidden" name="lote" value="<?= (int)$batch['id'] ?>">
            <button type="submit" class="nc-btn nc-btn-secondary nc-btn-sm">Completar <?= $n ?> empresa<?= $n === 1 ? '' : 's' ?> pelo CNPJá</button>
        </form>
    </div>
    <?php
}
