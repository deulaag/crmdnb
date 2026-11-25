<?php
declare(strict_types=1);

require_once __DIR__ . '/_app.php';
require_once __DIR__ . '/partials/kpi.php';

/**
 * Abre a página: <head>, sidebar, barra superior e <main>.
 *
 * @param string   $title   Título da aba/página
 * @param string   $active  Chave do item ativo em nc_menu()
 * @param string[] $crumbs  Trilha após "DNB" (ex.: ['Oportunidades'])
 */
function nc_header(string $title, string $active = '', array $crumbs = []): void
{
    $user = current_user() ?: [];
    $pdo = db();
    $security = security_settings($pdo);
    $logoUrl = branding_logo_url($pdo, 'portal');
    if (!str_starts_with($logoUrl, '/')) {
        $logoUrl = '/' . ltrim($logoUrl, '/'); // branding_logo_url() devolve caminho relativo à raiz
    }
    $menu = nc_menu();
    $crumbs = $crumbs ?: [$menu[$active]['label'] ?? $title];
    $isAdmin = ($user['role'] ?? '') === 'admin';
    ?>
<!doctype html>
<html lang="pt-BR" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#003060">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($title) ?> · DNB CRM</title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="htmx-config" content='{"allowEval":false,"allowScriptTags":false,"selfRequestsOnly":true,"historyCacheSize":0,"defaultSwapStyle":"innerHTML"}'>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&family=Michroma&display=swap" rel="stylesheet">
    <script src="<?= e(nc_asset('js/theme-init.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(nc_asset('css/novocrm.css')) ?>">
</head>
<body
    data-session-timeout-ms="<?= (int)$security['inactivity_timeout_ms'] ?>"
    data-warning-before-timeout-ms="<?= (int)$security['warning_before_timeout_ms'] ?>"
    data-security-scope="<?= e(security_scope()) ?>"
    data-session-refresh-url="/session_refresh.php"
    data-logout-url="/logout.php"
>
<a class="nc-skip" href="#nc-main">Pular para o conteúdo</a>

<div class="nc-shell">
    <aside class="nc-sidebar" id="nc-sidebar" aria-label="Menu principal">
        <div class="nc-sidebar-top">
            <a class="nc-brand" href="<?= e(nc_url('painel.php')) ?>" aria-label="DNB CRM — ir para o Painel">
                <img src="<?= e($logoUrl) ?>" alt="DNB" height="32">
            </a>
            <button type="button" class="nc-icon-btn nc-sidebar-collapse" data-nc-sidebar-toggle
                    aria-label="Recolher menu" aria-controls="nc-sidebar" title="Recolher menu ( [ )">
                <?= nc_icon('panel') ?>
            </button>
        </div>

        <nav class="nc-nav">
            <ul>
                <?php foreach ($menu as $key => $item): ?>
                    <li>
                        <a class="nc-nav-item<?= $active === $key ? ' is-active' : '' ?>"
                           href="<?= e($item['href']) ?>"
                           <?= $active === $key ? 'aria-current="page"' : '' ?>
                           title="<?= e($item['label']) ?>">
                            <?= nc_icon($item['icon']) ?>
                            <span class="nc-nav-label"><?= e($item['label']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <div class="nc-sidebar-foot">
            <div class="nc-user">
                <span class="nc-avatar" aria-hidden="true"><?= e(nc_initials($user['name'] ?? '')) ?></span>
                <span class="nc-user-text">
                    <strong><?= e($user['name'] ?? '') ?></strong>
                    <small><?= e(ucfirst((string)($user['role'] ?? ''))) ?></small>
                </span>
            </div>
            <a class="nc-nav-item nc-nav-small" href="/logout.php" title="Sair">
                <?= nc_icon('logout') ?><span class="nc-nav-label">Sair</span>
            </a>
        </div>
    </aside>
    <div class="nc-scrim" data-nc-sidebar-close hidden></div>

    <div class="nc-content">
        <header class="nc-topbar">
            <button type="button" class="nc-icon-btn nc-menu-btn" data-nc-sidebar-open
                    aria-label="Abrir menu" aria-controls="nc-sidebar">
                <?= nc_icon('menu') ?>
            </button>

            <nav class="nc-crumbs" aria-label="Trilha de navegação">
                <span>DNB</span>
                <?php foreach ($crumbs as $i => $crumb): ?>
                    <span class="nc-crumb-sep" aria-hidden="true">/</span>
                    <span<?= $i === array_key_last($crumbs) ? ' aria-current="page" class="is-current"' : '' ?>><?= e($crumb) ?></span>
                <?php endforeach; ?>
            </nav>

            <div class="nc-topbar-actions">
                <button type="button" class="nc-search-btn" data-nc-search-open aria-haspopup="dialog">
                    <?= nc_icon('search') ?>
                    <span class="nc-search-label">Buscar</span>
                    <kbd class="nc-kbd" data-nc-kbd>Ctrl K</kbd>
                </button>
                <button type="button" class="nc-icon-btn" data-nc-refresh aria-label="Atualizar página" title="Atualizar">
                    <?= nc_icon('refresh') ?>
                </button>
                <button type="button" class="nc-icon-btn" data-nc-theme-toggle aria-label="Alternar tema claro/escuro" title="Alternar tema">
                    <span class="nc-theme-sun"><?= nc_icon('sun') ?></span>
                    <span class="nc-theme-moon"><?= nc_icon('moon') ?></span>
                </button>
            </div>
        </header>

        <main class="nc-main" id="nc-main" tabindex="-1">
    <?php
}

/** Cabeçalho de página: contexto pequeno, título grande e botão primário opcional. */
function nc_page_header(string $context, string $title, string $actionHtml = '', string $subtitle = ''): void
{
    ?>
    <div class="nc-page-header">
        <div>
            <?php if ($context !== ''): ?><p class="nc-page-context"><?= e($context) ?></p><?php endif; ?>
            <h1 class="nc-page-title"><?= e($title) ?></h1>
            <?php if ($subtitle !== ''): ?><p class="nc-page-sub"><?= e($subtitle) ?></p><?php endif; ?>
        </div>
        <?php if ($actionHtml !== ''): ?><div class="nc-page-actions"><?= $actionHtml /* HTML já escapado pelo chamador */ ?></div><?php endif; ?>
    </div>
    <?php
}

/** Estado vazio com texto e (opcional) botão de ação. $actionHtml já deve vir escapado. */
function nc_empty(string $title, string $text, string $actionHtml = ''): void
{
    ?>
    <div class="nc-empty">
        <div class="nc-empty-mark" aria-hidden="true"></div>
        <h2><?= e($title) ?></h2>
        <p><?= e($text) ?></p>
        <?php if ($actionHtml !== ''): ?><div class="nc-empty-action"><?= $actionHtml ?></div><?php endif; ?>
    </div>
    <?php
}

/** Fecha <main> e renderiza modal, drawer, toasts, busca ⌘K, aviso de sessão e scripts. */
function nc_footer(): void
{
    $flash = pull_flash();
    ?>
        </main>
    </div>
</div>

<?php if ($flash): ?>
    <div hidden data-nc-flash data-type="<?= e((string)($flash['type'] ?? 'info')) ?>" data-message="<?= e((string)($flash['message'] ?? '')) ?>"></div>
<?php endif; ?>

<div class="nc-toasts" data-nc-toasts role="region" aria-label="Notificações" aria-live="polite"></div>

<!-- Drawer (fichas e formulários carregados via htmx) -->
<div class="nc-drawer-backdrop" data-nc-drawer-close hidden></div>
<aside class="nc-drawer" id="nc-drawer" data-nc-drawer role="dialog" aria-modal="true" aria-labelledby="nc-drawer-title" aria-hidden="true" hidden>
    <header class="nc-drawer-head">
        <h2 id="nc-drawer-title" data-nc-drawer-title>Detalhes</h2>
        <button type="button" class="nc-icon-btn" data-nc-drawer-close aria-label="Fechar painel"><?= nc_icon('close') ?></button>
    </header>
    <div class="nc-drawer-body" id="nc-drawer-body" data-nc-drawer-body></div>
</aside>

<!-- Modal de notificação / erro -->
<div class="nc-modal-backdrop" data-nc-modal hidden>
    <section class="nc-modal" role="dialog" aria-modal="true" aria-labelledby="nc-modal-title" aria-describedby="nc-modal-message" data-tone="info">
        <p class="nc-modal-kicker" data-nc-modal-kicker>Informação</p>
        <h2 id="nc-modal-title" data-nc-modal-title>Aviso</h2>
        <p id="nc-modal-message" data-nc-modal-message></p>
        <div class="nc-field nc-modal-field" data-nc-modal-field hidden>
            <label for="nc-modal-input" data-nc-modal-field-label>Motivo</label>
            <textarea class="nc-input" id="nc-modal-input" rows="4" maxlength="3000" data-nc-modal-input></textarea>
            <small class="nc-field-error" data-nc-modal-field-error role="alert" hidden></small>
        </div>
        <div class="nc-modal-actions">
            <button type="button" class="nc-btn nc-btn-secondary" data-nc-modal-cancel hidden>Cancelar</button>
            <button type="button" class="nc-btn nc-btn-primary" data-nc-modal-ok>Entendi</button>
        </div>
    </section>
</div>

<!-- Paleta de busca ⌘K / Ctrl+K -->
<div class="nc-modal-backdrop nc-search-backdrop" data-nc-search hidden>
    <section class="nc-search" role="dialog" aria-modal="true" aria-label="Busca global">
        <div class="nc-search-field">
            <?= nc_icon('search') ?>
            <input type="search" name="q" autocomplete="off" spellcheck="false"
                   placeholder="Buscar clientes, oportunidades, pedidos, visitas…"
                   aria-label="Buscar" data-nc-search-input
                   hx-get="<?= e(nc_url('acoes/busca.php')) ?>"
                   hx-trigger="input changed delay:250ms, search"
                   hx-target="#nc-search-results"
                   hx-swap="innerHTML">
            <kbd class="nc-kbd">Esc</kbd>
        </div>
        <div class="nc-search-results" id="nc-search-results" role="listbox" aria-label="Resultados" aria-live="polite">
            <p class="nc-search-hint">Digite para buscar. Use ↑ ↓ para navegar e Enter para abrir.</p>
        </div>
    </section>
</div>

<!-- Aviso de expiração de sessão (mesmo comportamento da raiz) -->
<div class="nc-modal-backdrop" data-nc-session-modal hidden>
    <section class="nc-modal" role="dialog" aria-modal="true" aria-labelledby="nc-session-title" aria-describedby="nc-session-desc" data-tone="warning">
        <p class="nc-modal-kicker">Segurança da sessão</p>
        <h2 id="nc-session-title">Sua sessão está prestes a expirar</h2>
        <p id="nc-session-desc">Por segurança, o CRM encerra sessões sem atividade. Sua sessão expirará em
            <strong data-nc-session-countdown>60s</strong>.</p>
        <div class="nc-modal-actions">
            <button type="button" class="nc-btn nc-btn-primary" data-nc-session-continue>Continuar conectado</button>
        </div>
    </section>
</div>

<script src="<?= e(nc_asset('vendor/htmx.min.js')) ?>"></script>
<script src="<?= e(nc_asset('vendor/Sortable.min.js')) ?>"></script>
<script src="<?= e(nc_asset('js/novocrm.js')) ?>"></script>
</body>
</html>
    <?php
}
