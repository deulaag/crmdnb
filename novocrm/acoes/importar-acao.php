<?php
declare(strict_types=1);

/**
 * Ações do lote (POST + CSRF, com redirecionamento de volta): aba, remapear, modo (Manter CRM / Usar planilha),
 * linha_padrao, confirmar, desfazer e reabrir.
 *
 * "confirmar" é a Etapa 4: revalida cada linha contra o banco atual e grava tudo em UMA transação.
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../_import_run.php';

$user = nc_boot();
nc_require_post();
if (!nc_is_manager($user)) {
    nc_fail('Somente administradores e gestores importam planilhas.', 403);
}
@set_time_limit(300);
$pdo = db();
$id = (int)($_POST['lote'] ?? 0);
$batch = nc_import_batch($pdo, $id);
if (!$batch) {
    nc_fail('Lote não encontrado.', 404);
}
$back = nc_url('importar.php?lote=' . $id);
$op = (string)($_POST['op'] ?? '');

try {
    switch ($op) {
        case 'aba':
            if ($batch['status'] !== 'enviado') {
                break;
            }
            $sheet = (string)($_POST['aba'] ?? '');
            if (!in_array($sheet, nc_sheet_names(nc_import_file_path($batch)), true)) {
                throw new RuntimeException('Escolha uma das abas da lista.');
            }
            nc_import_batch_update($pdo, $id, ['sheet_name' => $sheet]);
            break;

        case 'remapear':
            if (!in_array($batch['status'], ['mapeado', 'em_revisao'], true)) {
                throw new RuntimeException('Este lote não pode mais ser remapeado.');
            }
            nc_import_batch_update($pdo, $id, ['status' => 'enviado']);
            break;

        case 'reabrir':
            if ($batch['status'] !== 'falhou') {
                throw new RuntimeException('Só lotes que falharam podem voltar à conferência.');
            }
            nc_import_batch_update($pdo, $id, ['status' => 'em_revisao', 'error_message' => null]);
            break;

        case 'modo':
            nc_import_set_mode($pdo, $batch, (int)($_POST['linha_id'] ?? 0), (string)($_POST['modo'] ?? ''));
            break;

        case 'linha_padrao':
            if ($batch['status'] !== 'em_revisao') {
                throw new RuntimeException('Este lote não está em conferência.');
            }
            nc_import_set_default_line($pdo, $batch, (int)($_POST['linha'] ?? 0));
            flash('success', 'Linha padrão do lote definida.');
            break;

        case 'confirmar':
            $r = nc_import_confirm($pdo, $batch, (int)$user['id']);
            if (!$r['ok']) {
                throw new RuntimeException($r['message']);
            }
            $c = $r['counts'];
            flash('success', 'Importação concluída: ' . ($batch['import_type'] === 'contatos'
                ? $c['empresas_criadas'] . ' empresa(s) e ' . $c['contatos_criados'] . ' contato(s) criados; ' . ($c['empresas_atualizadas'] + $c['contatos_atualizados']) . ' atualizado(s).'
                : $c['orcamentos_criados'] . ' orçamento(s) criado(s); ' . $c['orcamentos_atualizados'] . ' atualizado(s)' . ($c['mantidos_crm'] > 0 ? '; ' . $c['mantidos_crm'] . ' mantido(s) como no CRM.' : '.')));
            break;

        case 'desfazer':
            $why = nc_import_undo_blocker($batch, $user);
            if ($why !== '') {
                throw new RuntimeException($why);
            }
            $u = nc_import_undo($pdo, $batch, (int)$user['id']);
            flash($u['skipped'] ? 'warning' : 'success', 'Importação desfeita: ' . $u['done'] . ' registro(s). '
                . ($u['skipped'] ? count($u['skipped']) . ' não puderam ser desfeitos porque foram alterados depois (veja o relatório).' : ''));
            break;

        default:
            throw new RuntimeException('Ação desconhecida.');
    }
} catch (Throwable $e) {
    flash('danger', $e->getMessage());
}
redirect($back);
