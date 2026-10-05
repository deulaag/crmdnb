<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$pdo = db();
$redirectTo = safe_internal_redirect(
    (string)($_GET['redirect_to'] ?? $_POST['redirect_to'] ?? 'painel.php'),
    'painel.php'
);

if (current_user()) {
    redirect($redirectTo);
}

$error = '';
$flash = pull_flash();
$turnstile = turnstile_settings($pdo);
$turnstileEnabled = !empty($turnstile['enabled']);
$turnstileSiteKey = (string)($turnstile['site_key'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = strtolower(trim((string)($_POST['sys_dnb_crm_user'] ?? '')));
    $password = (string)($_POST['sys_dnb_crm_pass'] ?? '');
    $remoteIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    try {
        if ($turnstileEnabled) {
            $token = (string)($_POST['cf-turnstile-response'] ?? '');
            if (!verify_turnstile($pdo, $token, $remoteIp)) {
                http_response_code(403);
                throw new RuntimeException('Não foi possível validar a verificação de segurança. Tente novamente.');
            }
        }

        if (!login_throttle_allowed($pdo, $email, $remoteIp)) {
            http_response_code(429);
            throw new RuntimeException('Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.');
        }

        $q = $pdo->prepare('SELECT * FROM users WHERE email=? AND active=1 LIMIT 1');
        $q->execute([$email]);
        $loginUser = $q->fetch();

        if ($loginUser && password_verify($password, (string)$loginUser['password_hash'])) {
            login_attempt_record($pdo, $email, $remoteIp, true);
            session_mark_authenticated((int)$loginUser['id']);
            $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([(int)$loginUser['id']]);
            audit($pdo, (int)$loginUser['id'], 'login', 'user', (int)$loginUser['id'], [
                'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
            ]);
            redirect($redirectTo);
        }

        login_attempt_record($pdo, $email, $remoteIp, false);
        http_response_code(403);
        $error = 'E-mail ou senha inválidos.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$hostLabel = preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'CRM DNB')) ?: 'CRM DNB';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Entrar · DNB CRM</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@300;400;700;900&family=Michroma&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=4">
    <?php if ($turnstileEnabled && $turnstileSiteKey !== ''): ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" defer></script>
        <script src="assets/js/auth.js?v=1" defer></script>
    <?php endif; ?>
</head>
<body class="auth-body">
    <div class="login-card">
        <div class="auth-brand">
            <img src="assets/dnb-logo.png" alt="DNB">
            <span>CRM Comercial</span>
        </div>

        <div class="login-panel">
            <span class="eyebrow">ACESSO RESTRITO</span>
            <h1>Bem-vindo ao CRM DNB</h1>
            <p>Entre com sua conta deste sistema.</p>

            <div class="auth-realm" aria-label="Origem do acesso">
                <strong>Origem protegida</strong>
                <span><?= e($hostLabel) ?></span>
            </div>

            <?php if($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <?php if($error): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if($turnstileEnabled && $turnstileSiteKey === ''): ?>
                <div class="alert alert-danger">O Turnstile está ativo, mas a chave pública não foi configurada. Procure o administrador.</div>
            <?php endif; ?>

            <form method="post" class="stack-form" autocomplete="off" data-auth-form>
                <?= csrf_field() ?>
                <input type="hidden" name="redirect_to" value="<?= e($redirectTo) ?>">

                <label>
                    <span>E-mail</span>
                    <input
                        id="sys_dnb_crm_user"
                        name="sys_dnb_crm_user"
                        type="email"
                        inputmode="email"
                        autocomplete="off"
                        autocapitalize="none"
                        spellcheck="false"
                        required
                        autofocus
                    >
                </label>

                <label>
                    <span>Senha</span>
                    <input
                        id="sys_dnb_crm_pass"
                        name="sys_dnb_crm_pass"
                        type="password"
                        autocomplete="new-password"
                        required
                    >
                </label>

                <?php if ($turnstileEnabled && $turnstileSiteKey !== ''): ?>
                    <div
                        class="turnstile-wrap"
                        data-turnstile-container
                        data-site-key="<?= e($turnstileSiteKey) ?>"
                        aria-label="Verificação de segurança"
                    ></div>
                    <div class="turnstile-feedback" data-turnstile-feedback aria-live="polite">Conclua a verificação de segurança.</div>
                <?php endif; ?>

                <button
                    class="btn btn-primary btn-block"
                    type="submit"
                    data-auth-submit
                    <?= $turnstileEnabled ? 'disabled' : '' ?>
                >Entrar</button>
            </form>
        </div>
    </div>
</body>
</html>
