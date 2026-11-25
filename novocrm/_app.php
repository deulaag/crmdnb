<?php
declare(strict_types=1);

/**
 * NOVOCRM — bootstrap da nova interface.
 *
 * Reutiliza includes/auth.php (sessão, banco, CSRF, funções de negócio) da raiz.
 * Nada aqui altera o CRM da raiz.
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
require_once __DIR__ . '/partials/icons.php';

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

/**
 * Menu da sidebar. Todos os itens são páginas nativas do /novocrm (nenhum aponta para a raiz).
 * Páginas ainda não entregues não aparecem aqui.
 */
function nc_menu(): array
{
    return [
        'painel'        => ['label' => 'Painel',              'icon' => 'home',     'href' => nc_url('painel.php')],
        'oportunidades' => ['label' => 'Oportunidades',       'icon' => 'funnel',   'href' => nc_url('oportunidades.php')],
        'clientes'      => ['label' => 'Clientes',            'icon' => 'building', 'href' => nc_url('clientes.php')],
        'pedidos'       => ['label' => 'Pedidos',             'icon' => 'receipt',  'href' => nc_url('pedidos.php')],
        'visitas'       => ['label' => 'Visitas & Agenda',    'icon' => 'calendar', 'href' => nc_url('visitas.php')],
        'cadencia'      => ['label' => 'Cadência',            'icon' => 'repeat',   'href' => nc_url('cadencia.php')],
        'equipe'        => ['label' => 'Equipe & Comissões',  'icon' => 'users',    'href' => nc_url('equipe.php')],
        'configuracoes' => ['label' => 'Configurações',       'icon' => 'settings', 'href' => nc_url('configuracoes.php')],
    ];
}

