<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

$user = require_admin();
$pdo = db();

if (($_GET['action'] ?? '') === 'connect') {
    $cfg = rd_crm_settings($pdo);
    if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') {
        flash('warning', 'Salve o Client ID e o Client Secret da RD Station CRM antes de conectar.');
        redirect('configuracoes.php#rd_crm');
    }

    $state = bin2hex(random_bytes(24));
    $_SESSION['rd_crm_oauth_state'] = $state;

    $authorizeUrl = 'https://accounts.rdstation.com/oauth/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $cfg['client_id'],
        'redirect_uri' => rd_crm_callback_url(),
        'state' => $state,
    ]);

    header('Location: ' . $authorizeUrl);
    exit;
}

try {
    if (!empty($_GET['error'])) {
        throw new RuntimeException('A RD Station recusou a autorização: ' . (string) ($_GET['error_description'] ?? $_GET['error']));
    }

    $code = trim((string) ($_GET['code'] ?? ''));
    if ($code === '') {
        throw new RuntimeException('Código de autorização da RD Station não recebido.');
    }

    $expectedState = (string) ($_SESSION['rd_crm_oauth_state'] ?? '');
    $receivedState = (string) ($_GET['state'] ?? '');
    unset($_SESSION['rd_crm_oauth_state']);
    if ($expectedState === '' || !hash_equals($expectedState, $receivedState)) {
        throw new RuntimeException('Falha de validação (state) no retorno da RD Station. Clique em “Conectar com RD Station” novamente.');
    }

    rd_crm_exchange_code($pdo, $code, (int) $user['id']);
    audit($pdo, (int) $user['id'], 'connect', 'integration', null, ['provider' => 'rd_crm']);
    flash('success', 'RD Station CRM conectada. Clique em “Sincronizar agora” para importar Empresas e Clientes.');
} catch (Throwable $e) {
    flash('danger', $e->getMessage());
}

redirect('configuracoes.php#rd_crm');
