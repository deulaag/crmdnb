<?php
declare(strict_types=1);

/**
 * NOVOCRM — bootstrap da nova interface.
 *
 * Reutiliza includes/auth.php (sessão, banco, CSRF, funções de negócio) da raiz.
 * Nada aqui altera o CRM clássico.
 */

/** Enquanto false, somente role = admin acessa /novocrm. */
const NC_PUBLIC = false;

const NC_BASE = '/novocrm/';

// O bootstrap da raiz redireciona para "install.php" com caminho relativo, o que
// quebraria dentro de /novocrm. Antecipamos a checagem com caminho absoluto.
$ncRoot = dirname(__DIR__);
if (!is_file($ncRoot . '/storage/installed.lock') || !is_file($ncRoot . '/config/database.local.php')) {
    header('Location: /install.php');
    exit;
}
unset($ncRoot);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/_lib.php';

/** A requisição veio do htmx? */
function nc_is_htmx(): bool
{
    // Restauração de histórico (voltar/avançar) precisa da página completa, não de fragmento.
    return ($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true'
        && ($_SERVER['HTTP_HX_HISTORY_RESTORE_REQUEST'] ?? '') !== 'true';
}

/** URL absoluta de uma página do novo (ex.: nc_url('painel.php')). */
function nc_url(string $path = ''): string
{
    return NC_BASE . ltrim($path, '/');
}

/** URL de asset com versão (cache-busting pela data de modificação). */
function nc_asset(string $path): string
{
    $file = __DIR__ . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string)filemtime($file) : '0';
    return NC_BASE . 'assets/' . ltrim($path, '/') . '?v=' . $v;
}

/**
 * Exige usuário autenticado (e admin enquanto NC_PUBLIC = false).
 * Em requisições htmx não autenticadas, responde 401 + HX-Redirect em vez de
 * um 302 (que o XHR seguiria silenciosamente e injetaria a tela de login no fragmento).
 */
function nc_boot(): array
{
    $user = current_user();

    if (!$user) {
        if (nc_is_htmx()) {
            http_response_code(401);
            header('HX-Redirect: /' . security_login_url(current_request_target()));
            exit;
        }
        $user = require_auth(); // grava flash e redireciona para o login
    }

    if (!NC_PUBLIC && ($user['role'] ?? '') !== 'admin') {
        if (nc_is_htmx()) {
            http_response_code(403);
            header('HX-Redirect: /painel.php');
            exit;
        }
        redirect('/painel.php');
    }

    return $user;
}

/** Exige POST + CSRF (no corpo) para endpoints de ação. */
function nc_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Método não permitido.');
    }
    verify_csrf(); // lê somente $_POST['csrf_token']; 419 abre o modal de sessão expirada
}

/** Dispara um toast no cliente via cabeçalho HX-Trigger. */
function nc_hx_toast(string $type, string $message): void
{
    header('HX-Trigger: ' . json_encode(
        ['nc:toast' => ['type' => $type, 'message' => $message]],
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    ));
}

/** Valor curto para o cabeçalho de página (R$ 1,2 mi). */
function nc_money_short(float|int|string|null $value): string
{
    $v = (float)$value;
    $abs = abs($v);
    if ($abs >= 1_000_000) {
        return 'R$ ' . number_format($v / 1_000_000, 1, ',', '.') . ' mi';
    }
    if ($abs >= 1_000) {
        return 'R$ ' . number_format($v / 1_000, 1, ',', '.') . ' mil';
    }
    return money($v);
}

/** Iniciais para avatares (até 2 letras). Funciona com ou sem a extensão mbstring. */
function nc_initials(?string $name): string
{
    $parts = preg_split('/\s+/u', trim((string)$name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) {
        return '?';
    }
    $firstChar = static function (string $word): string {
        return preg_match('/^./us', $word, $m) ? $m[0] : '';
    };
    $letters = $firstChar($parts[0]) . (count($parts) > 1 ? $firstChar($parts[count($parts) - 1]) : '');
    return function_exists('mb_strtoupper') ? mb_strtoupper($letters) : strtoupper($letters);
}

/** Menu da sidebar. `native` = false → aponta para a versão clássica da raiz. */
function nc_menu(): array
{
    return [
        'painel'        => ['label' => 'Painel',            'icon' => 'home',     'href' => nc_url('painel.php'),        'native' => true],
        'oportunidades' => ['label' => 'Oportunidades',     'icon' => 'funnel',   'href' => nc_url('oportunidades.php'), 'native' => true],
        'clientes'      => ['label' => 'Clientes',          'icon' => 'building', 'href' => nc_url('clientes.php'),      'native' => true],
        'pedidos'       => ['label' => 'Pedidos',           'icon' => 'receipt',  'href' => nc_url('pedidos.php'),       'native' => true],
        'visitas'       => ['label' => 'Visitas & Agenda',  'icon' => 'calendar', 'href' => nc_url('visitas.php'),       'native' => true],
        'cadencia'      => ['label' => 'Cadência',          'icon' => 'repeat',   'href' => '/cadencia.php',             'native' => false],
        'comissoes'     => ['label' => 'Comissões',         'icon' => 'coins',    'href' => nc_url('comissoes.php'),     'native' => true],
        'configuracoes' => ['label' => 'Configurações',     'icon' => 'settings', 'href' => nc_url('configuracoes.php'), 'native' => true],
    ];
}

/** Ícones SVG de traço (24x24), decorativos. */
function nc_icon(string $name): string
{
    static $paths = [
        'home'     => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'funnel'   => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
        'building' => '<path d="M4 21V5l8-2v18"/><path d="M12 9h8v12"/><path d="M8 8h1M8 12h1M8 16h1M16 13h1M16 17h1"/><path d="M3 21h18"/>',
        'receipt'  => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'repeat'   => '<path d="M17 2l3 3-3 3"/><path d="M4 11V9a4 4 0 0 1 4-4h12"/><path d="M7 22l-3-3 3-3"/><path d="M20 13v2a4 4 0 0 1-4 4H4"/>',
        'coins'    => '<circle cx="9" cy="9" r="6"/><path d="M15.5 9.3A6 6 0 1 1 9.3 15.5"/><path d="M9 6.5v5M7.5 8h3"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h0a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5h0a1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v0a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'refresh'  => '<path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/><path d="M3 12A9 9 0 0 1 18.5 5.8L21 8"/><path d="M21 3v5h-5M3 21v-5h5"/>',
        'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'     => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'menu'     => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'panel'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
        'close'    => '<path d="M18 6 6 18M6 6l12 12"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
    ];
    $body = $paths[$name] ?? '';
    return '<svg class="nc-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" '
        . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $body . '</svg>';
}
