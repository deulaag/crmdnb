<?php
declare(strict_types=1);

/**
 * Fluxo OAuth compartilhado das integrações RD Station (CRM e Marketing).
 * Espera $rdProvider definido pelo arquivo de callback que inclui este fluxo.
 */

require_once __DIR__ . '/layout.php';

if (!isset($rdProvider) || !in_array($rdProvider, ['rd_crm', 'rd_marketing'], true)) {
    http_response_code(400);
    exit('Integração inválida.');
}

$user = require_admin();
$pdo = db();
$meta = rd_provider_meta($rdProvider);
$sessionKey = $rdProvider . '_oauth';

if (($_GET['action'] ?? '') === 'connect') {
    $cfg = rd_settings($pdo, $rdProvider);
    if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') {
        flash('warning', 'Salve o Client ID e o Client Secret da ' . $meta['label'] . ' antes de conectar.');
        redirect('configuracoes.php#' . $rdProvider);
    }

    $state = bin2hex(random_bytes(24));
    $_SESSION[$sessionKey] = ['state' => $state, 'started_at' => time()];

    header('Location: ' . rd_authorize_url($pdo, $rdProvider, $state));
    exit;
}

try {
    if (!empty($_GET['error'])) {
        throw new RuntimeException('A ' . $meta['label'] . ' recusou a autorização: ' . (string) ($_GET['error_description'] ?? $_GET['error']));
    }

    $code = trim((string) ($_GET['code'] ?? ''));
    if ($code === '') {
        throw new RuntimeException('Código de autorização da ' . $meta['label'] . ' não recebido.');
    }

    $pending = $_SESSION[$sessionKey] ?? null;
    unset($_SESSION[$sessionKey]);
    $expectedState = is_array($pending) ? (string) ($pending['state'] ?? '') : '';
    $startedAt = is_array($pending) ? (int) ($pending['started_at'] ?? 0) : 0;
    $receivedState = (string) ($_GET['state'] ?? '');

    // O retorno só é aceito se esta sessão iniciou a conexão há menos de 15 minutos.
    // Quando a RD devolve o parâmetro state, ele também precisa conferir.
    $validPending = $expectedState !== '' && $startedAt > time() - 900;
    $validState = $receivedState === '' || hash_equals($expectedState, $receivedState);
    if (!$validPending || !$validState) {
        throw new RuntimeException('Falha de validação no retorno da ' . $meta['label'] . '. Clique em “Conectar com RD Station” novamente.');
    }

    rd_exchange_code($pdo, $rdProvider, $code, (int) $user['id']);
    audit($pdo, (int) $user['id'], 'connect', 'integration', null, ['provider' => $rdProvider]);
    flash('success', $meta['label'] . ' conectada. Clique em “Sincronizar agora” para importar os dados.');
} catch (Throwable $e) {
    flash('danger', $e->getMessage());
}

redirect('configuracoes.php#' . $rdProvider);
