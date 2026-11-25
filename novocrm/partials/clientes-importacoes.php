<?php
declare(strict_types=1);

require_once __DIR__ . '/../_import_run.php';

/** Rótulo e tom do status de um lote de importação. */
function nc_import_status_badge(string $status): string
{
    return match ($status) {
        'confirmado' => nc_badge('Confirmado', 'success'),
        'falhou' => nc_badge('Falhou', 'danger'),
        'desfeito' => nc_badge('Desfeito'),
        'em_revisao' => nc_badge('Em revisão', 'warning'),
        'mapeado' => nc_badge('Mapeado', 'info'),
        default => nc_badge('Enviado', 'info'),
    };
}

/** Aba Importações: histórico dos lotes (admin e gestor) com as ações do lote (V3, 1.3). */
function nc_clientes_importacoes(PDO $pdo, array $user): void
{
    if (!nc_table_exists($pdo, 'import_batches')) {
        nc_empty(
            'Nenhuma importação ainda',
            'A tabela de lotes é criada pela migração 009 (aplique em /novocrm/migrations/run.php). Depois dela, o histórico aparece aqui.'
        );
        return;
    }

    $rows = $pdo->query(
        'SELECT b.id, b.import_type, b.file_name, b.status, b.counters_json, b.created_at, b.confirmed_at, u.name user_name
         FROM import_batches b LEFT JOIN users u ON u.id = b.created_by
         ORDER BY b.id DESC LIMIT 100'
    )->fetchAll();

    if (!$rows) {
        nc_empty('Nenhuma importação ainda', 'Os lotes enviados pela Central de Importação aparecem aqui, com contadores e relatório.');
        return;
    }
    $types = ['contatos' => 'Contatos', 'orcamentos' => 'Orçamentos e propostas'];
    ?>
    <div class="nc-table-wrap">
        <table class="nc-table">
            <thead>
                <tr>
                    <th>Lote</th><th>Data</th><th>Tipo</th><th>Arquivo</th><th>Usuário</th><th>Status</th>
                    <th class="is-num">Criados</th><th class="is-num">Atualizados</th><th class="is-num">Sem alteração</th><th class="is-num">Revisados</th><th class="is-num">Ignorados</th><th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $c = json_decode((string)($r['counters_json'] ?? ''), true);
                $c = is_array($c) ? $c : []; ?>
                <tr>
                    <td>#<?= (int)$r['id'] ?></td>
                    <td><?= e(nc_date((string)$r['created_at'])) ?></td>
                    <td><?= e($types[$r['import_type']] ?? (string)$r['import_type']) ?></td>
                    <td><?= e(nc_truncate((string)$r['file_name'], 40)) ?></td>
                    <td><?= e((string)($r['user_name'] ?? '—')) ?></td>
                    <td><?= nc_import_status_badge((string)$r['status']) ?></td>
                    <td class="is-num"><?= (int)($c['novo'] ?? 0) ?></td>
                    <td class="is-num"><?= (int)($c['atualizar'] ?? 0) ?></td>
                    <td class="is-num"><?= (int)($c['sem_alteracao'] ?? 0) ?></td>
                    <td class="is-num"><?= (int)($c['revisados'] ?? 0) ?></td>
                    <td class="is-num"><?= (int)($c['ignorar'] ?? 0) ?></td>
                    <td class="is-actions">
                        <?php $open = in_array($r['status'], ['enviado', 'mapeado', 'em_revisao'], true); ?>
                        <a class="nc-btn nc-btn-secondary nc-btn-sm" href="<?= e(nc_url('importar.php?lote=' . (int)$r['id'])) ?>"><?= $open ? 'Continuar' : 'Ver relatório' ?></a>
                        <?php if (!$open): ?><a class="nc-btn nc-btn-secondary nc-btn-sm" href="<?= e(nc_url('acoes/importar-csv.php?lote=' . (int)$r['id'] . '&tipo=erros')) ?>">Baixar erros (CSV)</a><?php endif; ?>
                        <?php if ($r['status'] === 'confirmado' && nc_import_undo_blocker(['status' => $r['status'], 'confirmed_at' => $r['confirmed_at']], $user) === ''): ?>
                            <a class="nc-btn nc-btn-danger-outline nc-btn-sm" href="<?= e(nc_url('importar.php?lote=' . (int)$r['id'])) ?>">Desfazer…</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
