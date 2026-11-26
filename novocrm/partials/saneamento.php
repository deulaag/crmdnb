<?php
declare(strict_types=1);

/**
 * Telas do Saneamento: cobertura de CNPJ, fila de enriquecimento, sugestões de CNPJ, CNPJs com problema,
 * duplicadas e configurações (BrasilAPI e n8n). Só LÊ; as ações ficam em acoes/saneamento-*.php (POST + CSRF).
 */

function nc_san_status_label(string $status): array
{
    return match ($status) {
        'pendente' => ['Pendente', 'neutral'], 'erro' => ['Erro (tenta de novo)', 'warning'], 'ok' => ['Conferido', 'success'],
        'sugerido' => ['CNPJ sugerido', 'info'], 'duplicada' => ['Já existe com CNPJ', 'warning'], 'aprovado' => ['Aprovado', 'success'],
        'rejeitado' => ['Rejeitado', 'neutral'], 'divergente' => ['Nome diverge do CNPJ', 'warning'], 'invalido' => ['CNPJ inválido', 'danger'],
        'nao_encontrado' => ['CNPJ não encontrado', 'danger'], 'sem_resultado' => ['Sem resultado', 'neutral'],
        default => [$status, 'neutral'],
    };
}

/** Formulário de um botão que posta uma operação do Saneamento. */
function nc_san_op_form(string $op, string $label, array $fields = [], string $style = 'nc-btn-secondary', string $confirm = '', bool $small = false): string
{
    $html = '<form method="post" action="' . e(nc_url('acoes/saneamento-acao.php')) . '" class="nc-inline-form">' . csrf_field()
        . '<input type="hidden" name="op" value="' . e($op) . '">';
    foreach ($fields as $k => $v) {
        $html .= '<input type="hidden" name="' . e((string)$k) . '" value="' . e((string)$v) . '">';
    }
    $html .= '<button type="submit" class="nc-btn ' . e($style) . ($small ? ' nc-btn-sm' : '') . '"'
        . ($confirm !== '' ? ' data-nc-confirm="' . e($confirm) . '" data-nc-confirm-title="' . e($label) . '" data-nc-confirm-ok="Confirmar"' : '') . '>' . e($label) . '</button></form>';
    return $html;
}

function nc_saneamento_page(PDO $pdo, array $user): void
{
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $stats = nc_san_stats($pdo);
    $q = $stats['queue'];
    $count = static fn(string $step, string ...$statuses): int => array_sum(array_map(static fn($s) => (int)($q[$step][$s] ?? 0), $statuses));
    $semCnpj = (int)$stats['sem_cnpj'];
    $pct = $stats['companies'] > 0 ? (int)floor($stats['com_cnpj'] * 100 / $stats['companies']) : 0;
    $pend = (int)$stats['pendentes'];
    ?>
    <section class="nc-card" aria-labelledby="san-cov">
        <h2 class="nc-card-title" id="san-cov">Cobertura de CNPJ</h2>
        <div class="nc-kpis">
            <div class="nc-kpi"><div class="nc-kpi-label">Empresas</div><div class="nc-kpi-value"><?= (int)$stats['companies'] ?></div></div>
            <div class="nc-kpi"><div class="nc-kpi-label">Com CNPJ</div><div class="nc-kpi-value"><?= (int)$stats['com_cnpj'] ?></div><div class="nc-kpi-sub"><?= $pct ?>% da base</div></div>
            <div class="nc-kpi"><div class="nc-kpi-label">Sem CNPJ</div><div class="nc-kpi-value"><?= $semCnpj ?></div></div>
            <div class="nc-kpi"><div class="nc-kpi-label">Na fila</div><div class="nc-kpi-value"><?= $pend ?></div><div class="nc-kpi-sub">pendentes ou com erro</div></div>
        </div>
        <div class="nc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>"><span style="width: <?= $pct ?>%"></span></div>
        <p class="nc-help">
            Conferidas: <?= $count('validar', 'ok') ?> ·
            Nome diverge do CNPJ: <?= $count('validar', 'divergente') ?> ·
            CNPJ inválido ou não encontrado: <?= $count('validar', 'invalido', 'nao_encontrado') ?> ·
            Sugestões de CNPJ: <?= $count('descobrir', 'sugerido', 'duplicada') ?> ·
            Sem resultado: <?= $count('descobrir', 'sem_resultado') ?>.
        </p>
    </section>

    <section class="nc-card" aria-labelledby="san-run">
        <h2 class="nc-card-title" id="san-run">Processar a fila</h2>
        <p class="nc-help">Para cada empresa <strong>com CNPJ</strong>: confere na BrasilAPI (CNPJá de reserva) e completa os campos vazios, sem trocar o nome.
            Para cada empresa <strong>sem CNPJ</strong>: procura no próprio CRM e, se não achar, a IA propõe um CNPJ que a BrasilAPI confirma. Nada é gravado sem a sua aprovação.</p>
        <div id="nc-san-run">
            <form method="post" action="<?= e(nc_url('acoes/saneamento-processar.php')) ?>" hx-post="<?= e(nc_url('acoes/saneamento-processar.php')) ?>"
                  hx-target="#nc-san-run" hx-swap="innerHTML" class="nc-inline-form">
                <?= csrf_field() ?><input type="hidden" name="feitas" value="0">
                <button type="submit" class="nc-btn nc-btn-primary"><?= $pend > 0 ? 'Processar ' . $pend . ' empresa(s) da fila' : 'Montar a fila e processar' ?></button>
            </form>
        </div>
        <div class="nc-form-actions">
            <?= nc_san_op_form('reprocessar', 'Tentar de novo os erros e não encontrados', [], 'nc-btn-secondary', 'Recolocar na fila os itens com erro, sem resultado ou não encontrados para uma nova tentativa?') ?>
        </div>
    </section>
    <?php
    nc_san_suggestions($pdo);
    nc_san_problems($pdo);
    nc_san_duplicates_section($pdo);
    nc_san_settings_section($pdo, $isAdmin);
}

/** Sugestões de CNPJ para empresas sem CNPJ. */
function nc_san_suggestions(PDO $pdo): void
{
    $rows = $pdo->query(
        "SELECT e.*, c.name, c.city, c.state FROM company_enrich e JOIN companies c ON c.id = e.company_id
         WHERE e.step = 'descobrir' AND e.status IN ('sugerido','duplicada') ORDER BY (e.status = 'sugerido') DESC, e.confidence DESC, e.id LIMIT 100"
    )->fetchAll();
    $high = (int)$pdo->query("SELECT COUNT(*) FROM company_enrich WHERE step = 'descobrir' AND status = 'sugerido' AND confidence >= " . NC_SAN_AUTO_CONFIDENCE
        . " AND (evidence_json IS NULL OR evidence_json NOT LIKE '%merge_with%')")->fetchColumn();
    ?>
    <section class="nc-card" aria-labelledby="san-sug">
        <div class="nc-review-head">
            <div>
                <h2 class="nc-card-title" id="san-sug">Sugestões de CNPJ <span class="nc-tab-count"><?= count($rows) ?></span></h2>
                <p class="nc-help">O CNPJ só é gravado quando você aprova. Se o CNPJ já é de outra empresa, aprovar mescla as duas (a mesclagem fica registrada).</p>
            </div>
            <div class="nc-toolbar-actions">
                <?= nc_san_op_form('aprovar_todas', 'Aprovar todas de alta confiança (' . $high . ')', [], 'nc-btn-primary',
                    'Gravar o CNPJ de todas as sugestões com confiança de ' . NC_SAN_AUTO_CONFIDENCE . '% ou mais, sem mesclagem?') ?>
            </div>
        </div>
        <?php if (!$rows): ?>
            <p class="nc-muted">Nenhuma sugestão pendente. Processe a fila para a IA procurar o CNPJ das empresas que não têm.</p>
        <?php else: ?>
            <div class="nc-table-wrap"><table class="nc-table">
                <thead><tr><th>Empresa no CRM</th><th>CNPJ sugerido</th><th>Conferência</th><th class="is-num">Confiança</th><th class="is-actions">Ação</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $ev = json_decode((string)$r['evidence_json'], true);
                    $ev = is_array($ev) ? $ev : [];
                    [$label, $tone] = nc_san_status_label((string)$r['status']); ?>
                    <tr>
                        <td><a href="<?= e(nc_url('cliente.php?id=' . (int)$r['company_id'])) ?>" target="_blank" rel="noopener"><strong><?= e((string)$r['name']) ?></strong></a><br>
                            <small class="nc-muted"><?= e(trim((string)($r['city'] ?? '') . (($r['state'] ?? '') !== '' ? '/' . $r['state'] : '')) ?: 'Sem cidade') ?></small></td>
                        <td><?= e(format_cnpj((string)$r['suggested_cnpj'])) ?><br><?= nc_badge($label, $tone) ?></td>
                        <td>
                            <?php if (!empty($ev['razao_social'])): ?><?= e((string)$ev['razao_social']) ?><?= !empty($ev['fantasia']) ? ' · ' . e((string)$ev['fantasia']) : '' ?><br><?php endif; ?>
                            <small class="nc-muted"><?= e(implode(' · ', array_filter([
                                (string)($ev['situacao'] ?? ''),
                                trim((string)($ev['cidade'] ?? '') . (!empty($ev['uf']) ? '/' . $ev['uf'] : '')),
                                isset($ev['similaridade']) ? 'nome ' . (int)$ev['similaridade'] . '% parecido' : '',
                                (string)($ev['motivo'] ?? ''),
                                !empty($ev['merge_with']) ? 'já existe a empresa #' . (int)$ev['merge_with'] . ' com este CNPJ: aprovar mescla' : '',
                                'fonte: ' . ((string)$r['source'] === 'interno' ? 'cadastro do CRM' : ((string)$r['source'] === 'cnpja' ? 'CNPJá' : 'BrasilAPI + IA')),
                            ]))) ?></small>
                        </td>
                        <td class="is-num"><?= (int)$r['confidence'] ?>%</td>
                        <td class="is-actions">
                            <?= nc_san_op_form('aprovar', !empty($ev['merge_with']) || $r['status'] === 'duplicada' ? 'Aprovar e mesclar' : 'Aprovar', ['id' => (int)$r['id']], 'nc-btn-primary', '', true) ?>
                            <?= nc_san_op_form('rejeitar', 'Rejeitar', ['id' => (int)$r['id']], 'nc-btn-secondary', '', true) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <?php
}

/** CNPJs com problema: nome diverge, inválido, não encontrado. */
function nc_san_problems(PDO $pdo): void
{
    $rows = $pdo->query(
        "SELECT e.*, c.name, c.cnpj FROM company_enrich e JOIN companies c ON c.id = e.company_id
         WHERE e.step = 'validar' AND e.status IN ('divergente','invalido','nao_encontrado') ORDER BY e.status, e.id LIMIT 100"
    )->fetchAll();
    if (!$rows) {
        return;
    }
    ?>
    <section class="nc-card" aria-labelledby="san-prob">
        <h2 class="nc-card-title" id="san-prob">CNPJs para conferir <span class="nc-tab-count"><?= count($rows) ?></span></h2>
        <p class="nc-help">O CNPJ cadastrado não bate com o nome da empresa, é inválido ou não existe na consulta oficial. Abra a empresa e corrija; nada foi alterado automaticamente.</p>
        <div class="nc-table-wrap"><table class="nc-table">
            <thead><tr><th>Empresa no CRM</th><th>CNPJ cadastrado</th><th>Problema</th><th>Na consulta oficial</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $ev = json_decode((string)$r['evidence_json'], true);
                $ev = is_array($ev) ? $ev : [];
                [$label, $tone] = nc_san_status_label((string)$r['status']); ?>
                <tr>
                    <td><a href="<?= e(nc_url('cliente.php?id=' . (int)$r['company_id'])) ?>" target="_blank" rel="noopener"><?= e((string)$r['name']) ?></a></td>
                    <td><?= e(cnpj_is_valid((string)$r['cnpj']) ? format_cnpj((string)$r['cnpj']) : (string)$r['cnpj']) ?></td>
                    <td><?= nc_badge($label, $tone) ?><?php if ((string)($r['error'] ?? '') !== ''): ?><br><small class="nc-muted"><?= e((string)$r['error']) ?></small><?php endif; ?></td>
                    <td><?= e((string)($ev['razao_social'] ?? '—')) ?><?= isset($ev['similaridade']) ? '<br><small class="nc-muted">nome ' . (int)$ev['similaridade'] . '% parecido</small>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php
}

/** Duplicadas: seguras (mesmo CNPJ), parecidas (aprovação) e filiais (informativo). */
function nc_san_duplicates_section(PDO $pdo): void
{
    $d = nc_saneamento_duplicates($pdo);
    $names = static fn(array $list): string => e(implode(' · ', array_map(static fn($n) => nc_truncate((string)$n, 40), $list)));
    ?>
    <section class="nc-card" aria-labelledby="san-dup">
        <div class="nc-review-head">
            <div>
                <h2 class="nc-card-title" id="san-dup">Empresas duplicadas</h2>
                <p class="nc-help">Contatos e oportunidades vão para a empresa principal, a duplicada é apagada e a linha completa dela fica registrada.
                    Empresas com CNPJs válidos diferentes (matriz e filial) nunca são mescladas.</p>
            </div>
            <div class="nc-toolbar-actions">
                <?= nc_san_op_form('mesclar_seguras', 'Mesclar as ' . count($d['seguras']) . ' seguras (mesmo CNPJ)', [], 'nc-btn-primary',
                    'Mesclar em bloco todas as empresas com o mesmo CNPJ completo? A principal é a que tem CNPJ válido e mais vínculos.') ?>
            </div>
        </div>

        <h3 class="nc-section-title">Mesmo CNPJ (seguras): <?= count($d['seguras']) ?></h3>
        <?php if (!$d['seguras']): ?><p class="nc-muted">Nenhuma.</p><?php else: ?>
            <ul class="nc-cands">
                <?php foreach (array_slice($d['seguras'], 0, 30) as $g): ?>
                    <li><div><strong><?= e(format_cnpj($g['cnpj'])) ?></strong><br><small class="nc-muted"><?= $names($g['names']) ?></small></div></li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($d['seguras']) > 30): ?><p class="nc-help">Mostrando 30 de <?= count($d['seguras']) ?>.</p><?php endif; ?>
        <?php endif; ?>

        <h3 class="nc-section-title">Nomes iguais ou muito parecidos: <?= count($d['parecidas']) ?></h3>
        <?php if (!$d['parecidas']): ?><p class="nc-muted">Nenhuma.</p><?php else: ?>
            <div class="nc-table-wrap"><table class="nc-table">
                <thead><tr><th>Empresas</th><th>CNPJ</th><th>Motivo</th><th class="is-actions">Ação</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($d['parecidas'], 0, 50) as $g): ?>
                    <tr>
                        <td><?= $names($g['names']) ?></td>
                        <td><?= $g['cnpjs'] ? e(format_cnpj($g['cnpjs'][0])) : '<span class="nc-muted">nenhuma tem</span>' ?></td>
                        <td><?= e((string)$g['reason']) ?></td>
                        <td class="is-actions"><?= nc_san_op_form('mesclar', 'Mesclar', ['ids' => implode(',', $g['ids'])], 'nc-btn-primary',
                            'Mesclar estas duas empresas? A principal é a que tem CNPJ e mais vínculos.', true) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if (count($d['parecidas']) > 50): ?><p class="nc-help">Mostrando 50 de <?= count($d['parecidas']) ?>.</p><?php endif; ?>
        <?php endif; ?>

        <h3 class="nc-section-title">Matriz e filiais (mesmo CNPJ raiz): <?= count($d['filiais']) ?></h3>
        <p class="nc-help">Só informativo: são empresas diferentes e continuam separadas.</p>
    </section>
    <?php
}

/** BrasilAPI e n8n. */
function nc_san_settings_section(PDO $pdo, bool $isAdmin, string $back = ''): void
{
    $backField = $back !== '' ? '<input type="hidden" name="voltar" value="' . e($back) . '">' : '';
    $backFields = $back !== '' ? ['voltar' => $back] : [];
    $s = nc_san_settings($pdo);
    $token = nc_san_token($pdo);
    $openai = integration_get($pdo, 'openai');
    $cnpja = integration_get($pdo, 'cnpja');
    $api = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'seu-dominio') . nc_url('api/saneamento.php');
    ?>
    <section class="nc-card" aria-labelledby="san-cfg">
        <h2 class="nc-card-title" id="san-cfg">Integrações do Saneamento</h2>
        <ul class="nc-help">
            <li>BrasilAPI (consulta de CNPJ, sem chave): <strong><?= $s['active'] ? 'ativa' : 'desligada' ?></strong>.</li>
            <li>CNPJá (reserva quando a BrasilAPI falha): <strong><?= !empty($cnpja['is_active']) ? 'ativa' : 'desligada' ?></strong> — configure em <a href="/configuracoes.php#cnpja">Configurações</a>.</li>
            <li>OpenAI (descobrir CNPJ e sugerir vínculos): <strong><?= !empty($openai['is_active']) ? 'ativa' : 'desligada' ?></strong> — configure em <a href="/configuracoes.php#openai">Configurações</a>.</li>
        </ul>
        <?php if ($isAdmin): ?>
            <form method="post" action="<?= e(nc_url('acoes/saneamento-acao.php')) ?>" class="nc-form">
                <?= csrf_field() ?><input type="hidden" name="op" value="configurar"><?= $backField ?>
                <label class="nc-check"><input type="checkbox" name="ativa" value="1"<?= $s['active'] ? ' checked' : '' ?>> Consultar a BrasilAPI</label>
                <div class="nc-field"><label for="san-delay">Pausa entre consultas (ms)</label>
                    <input class="nc-input" id="san-delay" type="number" name="delay_ms" min="0" max="5000" value="<?= (int)$s['delay_ms'] ?>"></div>
                <div class="nc-field"><label for="san-batch">Empresas por passo</label>
                    <input class="nc-input" id="san-batch" type="number" name="batch" min="1" max="50" value="<?= (int)$s['batch'] ?>"></div>
                <div class="nc-form-actions"><button type="submit" class="nc-btn nc-btn-secondary">Salvar</button></div>
            </form>

            <h3 class="nc-section-title">n8n (agendamento automático)</h3>
            <p class="nc-help">O n8n só dispara o processamento e repete até a fila zerar; toda a lógica fica aqui. No n8n, use um nó HTTP Request
                <strong>POST</strong> em <code><?= e($api) ?></code> com o cabeçalho <code>X-Saneamento-Token: &lt;token&gt;</code> (ou <code>Authorization: Bearer &lt;token&gt;</code>) e corpo <code>{"acao":"processar"}</code>.
                A resposta traz <code>continuar</code>: repita enquanto for <code>true</code>.
                O fluxo pronto está em <code>novocrm/n8n/saneamento-workflow.json</code>.</p>
            <?php if ($token !== ''): ?>
                <div class="nc-field"><label for="san-token">Token</label><input class="nc-input" id="san-token" type="text" readonly value="<?= e($token) ?>" onfocus="this.select()"></div>
            <?php else: ?>
                <p class="nc-muted">Sem token: o endpoint do n8n está desligado.</p>
            <?php endif; ?>
            <div class="nc-form-actions">
                <?= nc_san_op_form('token_novo', $token !== '' ? 'Gerar novo token' : 'Gerar token', $backFields, 'nc-btn-secondary', $token !== '' ? 'Gerar um novo token invalida o anterior: o n8n precisa ser atualizado. Continuar?' : '') ?>
                <?php if ($token !== ''): ?><?= nc_san_op_form('token_desligar', 'Desligar o endpoint', $backFields, 'nc-btn-danger-outline', 'Desligar o acesso do n8n?') ?><?php endif; ?>
            </div>
        <?php else: ?>
            <p class="nc-help">Somente administradores alteram as integrações.</p>
        <?php endif; ?>
    </section>
    <?php
}
