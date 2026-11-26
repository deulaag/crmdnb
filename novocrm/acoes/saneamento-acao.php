<?php
declare(strict_types=1);

/**
 * Operações do Saneamento disparadas pelos botões da tela: aprovar/rejeitar sugestão de CNPJ, mesclar duplicadas,
 * reprocessar erros e configurar BrasilAPI/n8n (esta última só admin). Sempre POST + CSRF, volta para a tela com um aviso.
 */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../_saneamento.php';

$user = nc_boot();
nc_require_post();
if (!nc_is_manager($user)) {
    nc_fail('Ação não permitida.', 403);
}
@set_time_limit(120);
$pdo = db();
$uid = (int)$user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';

try {
    if (!nc_san_ready($pdo)) {
        throw new RuntimeException('Aplique a migração 011 antes de usar o Saneamento.');
    }
    ensure_company_cnpja_schema($pdo);
    $op = (string)($_POST['op'] ?? '');
    switch ($op) {
        case 'aprovar':
            $r = nc_san_approve($pdo, (int)($_POST['id'] ?? 0), $uid);
            flash($r['ok'] ? 'success' : 'danger', $r['message']);
            break;
        case 'rejeitar':
            nc_san_reject($pdo, (int)($_POST['id'] ?? 0), $uid);
            flash('success', 'Sugestão rejeitada.');
            break;
        case 'aprovar_todas':
            $r = nc_san_approve_all($pdo, $uid);
            audit($pdo, $uid, 'saneamento_aprovar_todas', 'company', null, ['approved' => $r['approved'], 'via' => 'novocrm']);
            flash($r['approved'] > 0 ? 'success' : 'warning', $r['approved'] > 0 ? $r['approved'] . ' CNPJ(s) gravados.' . ($r['left'] > 0 ? ' Faltam ' . $r['left'] . ': clique de novo.' : '') : 'Nenhuma sugestão de alta confiança sem mesclagem.');
            break;
        case 'mesclar':
            $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? '')))));
            $r = nc_san_merge_group($pdo, $ids, $uid);
            flash($r['ok'] ? 'success' : 'danger', $r['ok'] ? 'Empresas mescladas.' : $r['message']);
            break;
        case 'mesclar_seguras':
            $r = nc_san_merge_safe($pdo, $uid);
            $msg = $r['merged'] . ' empresa(s) duplicada(s) mescladas em ' . $r['groups'] . ' grupo(s).';
            if ($r['left'] > 0) {
                $msg .= ' Faltam ' . $r['left'] . ' grupo(s): clique de novo.';
            }
            if ($r['errors']) {
                $msg .= ' Avisos: ' . implode(' | ', $r['errors']);
            }
            flash($r['merged'] > 0 ? 'success' : 'warning', $r['merged'] > 0 ? $msg : 'Nenhum grupo seguro para mesclar. ' . $msg);
            break;
        case 'reprocessar':
            $n = $pdo->prepare("UPDATE company_enrich SET status = 'pendente', attempts = 0, error = NULL WHERE status IN ('erro','nao_encontrado','sem_resultado')");
            $n->execute();
            flash('success', $n->rowCount() . ' item(ns) voltaram para a fila.');
            break;
        case 'configurar':
            if (!$isAdmin) {
                throw new RuntimeException('Somente administradores alteram as integrações.');
            }
            nc_san_settings_save($pdo, ($_POST['ativa'] ?? '') === '1', (int)($_POST['delay_ms'] ?? 600), (int)($_POST['batch'] ?? 10), $uid);
            audit($pdo, $uid, 'configure', 'integration', null, ['provider' => 'brasilapi', 'via' => 'novocrm']);
            flash('success', 'Configuração salva.');
            break;
        case 'token_novo':
        case 'token_desligar':
            if (!$isAdmin) {
                throw new RuntimeException('Somente administradores alteram as integrações.');
            }
            if ($op === 'token_novo') {
                nc_san_token_rotate($pdo, $uid);
            } else {
                nc_san_token_disable($pdo, $uid);
            }
            audit($pdo, $uid, 'configure', 'integration', null, ['provider' => 'n8n', 'op' => $op, 'via' => 'novocrm']);
            flash('success', $op === 'token_novo' ? 'Novo token gerado. Atualize a credencial no n8n.' : 'Endpoint do n8n desligado.');
            break;
        default:
            throw new RuntimeException('Operação inválida.');
    }
} catch (Throwable $e) {
    flash('danger', $e->getMessage());
}
header('Location: ' . (($_POST['voltar'] ?? '') === 'config' ? nc_url('configuracoes.php') . '#integracoes' : nc_url('saneamento.php')));
exit;
