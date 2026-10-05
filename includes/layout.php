<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

function page_header(string $title,string $active=''):void
{
    $user = current_user();
    $flash = pull_flash();
    $month = valid_month($_GET['mes'] ?? null);
    $security = security_settings(db());

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
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($title) ?> · DNB CRM</title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="stylesheet" href="assets/css/app.css?v=5">
</head>
<body
    class="source-body"
    data-session-timeout-ms="<?= (int)$security['inactivity_timeout_ms'] ?>"
    data-warning-before-timeout-ms="<?= (int)$security['warning_before_timeout_ms'] ?>"
    data-security-scope="<?= e(security_scope()) ?>"
    data-session-refresh-url="session_refresh.php"
    data-logout-url="logout.php"
>
    <header class="source-header">
        <div class="source-brand-block">
            <span class="source-logo-box"><img src="assets/dnb-logo.png" alt="DNB"></span>
            <div>
                <div class="source-brand-title">DNB · CRM Comercial</div>
                <div class="source-brand-subtitle">Políticas de comissão vigentes · metas derivadas por conversão · dados compartilhados entre a equipe</div>
            </div>
        </div>
        <div class="source-header-actions">
            <form method="get" class="source-month-form">
                <label>Mês de referência</label>
                <input type="month" name="mes" value="<?= e($month) ?>" onchange="this.form.submit()">
            </form>
            <div class="source-account">
                <strong><?= e($user['name'] ?? '') ?></strong>
                <?php if (($user['role'] ?? '') === 'admin'): ?><a href="usuarios.php">Usuários</a><?php endif; ?>
                <a href="logout.php">Sair</a>
            </div>
        </div>
    </header>

    <nav class="source-tabs">
        <?php foreach($menu as $key => [$label,$href]): ?>
            <a href="<?= e($href) ?>" class="source-tab <?= $active===$key?'active':'' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

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

    <main class="source-main">
        <?php if($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" data-autohide><?= e($flash['message']) ?></div><?php endif; ?>
<?php
}

function page_footer():void
{
?>
    </main>
    <script src="assets/js/app.js?v=5"></script>
</body>
</html>
<?php
}

function empty_state(string $title,string $text):void
{
?>
<div class="empty-state">
    <h3><?= e($title) ?></h3>
    <p><?= e($text) ?></p>
</div>
<?php
}
