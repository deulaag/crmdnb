<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

function notification_meta(string $type): array
{
    return match ($type) {
        'success' => ['Sucesso', 'success'],
        'danger', 'error' => ['Não foi possível concluir', 'danger'],
        'warning' => ['Atenção', 'warning'],
        default => ['Informação', 'info'],
    };
}

function page_header(string $title,string $active=''):void
{
    $user = current_user();
    $flash = pull_flash();
    $month = valid_month($_GET['mes'] ?? null);
    $pdo = db();
    $security = security_settings($pdo);
    $portalLogoUrl = branding_logo_url($pdo, 'portal');

    $menu = [
        'painel' => ['Painel','painel.php'],
        'orcamentos' => ['Orçamentos','orcamentos.php'],
        'pedidos' => ['Pedidos','pedidos.php'],
        'visitas' => ['Visitas','visitas.php'],
        'cronograma' => ['Cronograma','cronograma.php'],
        'cadencia' => ['Cadência · Agente','cadencia.php'],
        'comissoes' => ['Comissões','comissoes.php'],
        'configuracoes' => ['Configurações','configuracoes.php'],
    ];

    [$flashTitle, $flashTone] = $flash ? notification_meta((string)($flash['type'] ?? 'info')) : ['', 'info'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#003060">
    <title><?= e($title) ?> · DNB CRM</title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Michroma&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=10">
</head>
<body
    class="source-body"
    data-session-timeout-ms="<?= (int)$security['inactivity_timeout_ms'] ?>"
    data-warning-before-timeout-ms="<?= (int)$security['warning_before_timeout_ms'] ?>"
    data-security-scope="<?= e(security_scope()) ?>"
    data-session-refresh-url="session_refresh.php"
    data-logout-url="logout.php"
>
    <a class="skip-link" href="#conteudo-principal">Pular para o conteúdo</a>

    <header class="source-header">
        <div class="source-brand-block">
            <a class="source-logo-box" href="painel.php" aria-label="Ir para o Painel">
                <img src="<?= e($portalLogoUrl) ?>" alt="DNB">
            </a>
            <div>
                <div class="source-brand-title">CRM Comercial</div>
                <div class="source-brand-subtitle">Gestão comercial · oportunidades · visitas · pedidos</div>
            </div>
        </div>

        <div class="source-header-actions">
            <form method="get" class="source-month-form">
                <label for="crm-reference-month">Mês de referência</label>
                <input id="crm-reference-month" type="month" name="mes" value="<?= e($month) ?>" onchange="this.form.submit()">
            </form>

            <div class="source-account">
                <div class="source-account-user">
                    <span class="source-account-avatar" aria-hidden="true"><?= e(strtoupper(substr((string)($user['name'] ?? 'U'), 0, 1))) ?></span>
                    <div>
                        <strong><?= e($user['name'] ?? '') ?></strong>
                        <small><?= e(ucfirst((string)($user['role'] ?? 'usuário'))) ?></small>
                    </div>
                </div>
                <?php if (($user['role'] ?? '') === 'admin'): ?><a href="usuarios.php">Usuários</a><?php endif; ?>
                <a href="logout.php">Sair</a>
            </div>
        </div>
    </header>

    <nav class="source-tabs" aria-label="Navegação principal">
        <?php foreach($menu as $key => [$label,$href]): ?>
            <a
                href="<?= e($href) ?>"
                class="source-tab <?= $active===$key?'active':'' ?>"
                <?= $active===$key?'aria-current="page"':'' ?>
            ><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($flash): ?>
        <div
            class="ui-modal-backdrop"
            data-ui-modal
            data-notification-modal
            data-modal-auto-open
            data-dismiss-backdrop="true"
            aria-hidden="true"
            hidden
        >
            <section
                class="ui-modal ui-modal-<?= e($flashTone) ?>"
                role="dialog"
                aria-modal="true"
                aria-labelledby="global-notification-title"
                aria-describedby="global-notification-message"
            >
                <div class="ui-modal-status" aria-hidden="true"></div>
                <div class="ui-modal-content">
                    <span class="eyebrow">NOTIFICAÇÃO</span>
                    <h2 id="global-notification-title"><?= e($flashTitle) ?></h2>
                    <p id="global-notification-message"><?= e($flash['message']) ?></p>
                </div>
                <div class="ui-modal-actions">
                    <button class="btn btn-primary" type="button" data-modal-close data-modal-primary>Entendi</button>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <div
        class="ui-modal-backdrop"
        data-ui-modal
        data-confirm-modal
        aria-hidden="true"
        hidden
    >
        <section
            class="ui-modal ui-modal-warning"
            role="dialog"
            aria-modal="true"
            aria-labelledby="confirm-modal-title"
            aria-describedby="confirm-modal-message"
        >
            <div class="ui-modal-status" aria-hidden="true"></div>
            <div class="ui-modal-content">
                <span class="eyebrow">CONFIRMAÇÃO</span>
                <h2 id="confirm-modal-title">Confirmar ação</h2>
                <p id="confirm-modal-message" data-confirm-message>Deseja continuar?</p>
            </div>
            <div class="ui-modal-actions">
                <button class="btn btn-secondary" type="button" data-confirm-cancel>Cancelar</button>
                <button class="btn btn-danger" type="button" data-confirm-action data-modal-primary>Confirmar</button>
            </div>
        </section>
    </div>

    <div class="session-modal-backdrop" data-session-modal hidden>
        <section class="session-modal" role="dialog" aria-modal="true" aria-labelledby="session-modal-title" aria-describedby="session-modal-description">
            <span class="eyebrow">SEGURANÇA DA SESSÃO</span>
            <h2 id="session-modal-title">Sua sessão está prestes a expirar</h2>
            <p id="session-modal-description">Por segurança, o CRM encerra sessões sem atividade.</p>
            <div class="session-countdown" aria-live="polite">
                Sua sessão expirará em <strong data-session-countdown>60s</strong>
            </div>
            <button class="btn btn-primary btn-block" type="button" data-session-continue>Continuar conectado</button>
        </section>
    </div>

    <main class="source-main" id="conteudo-principal" tabindex="-1">
<?php
}

function page_footer():void
{
?>
    </main>
    <script src="assets/js/ui.js?v=1"></script>
    <script src="assets/js/app.js?v=6"></script>
</body>
</html>
<?php
}

function empty_state(string $title,string $text):void
{
?>
<div class="empty-state">
    <div class="empty-state-mark" aria-hidden="true"></div>
    <h3><?= e($title) ?></h3>
    <p><?= e($text) ?></p>
</div>
<?php
}
