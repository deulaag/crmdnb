<?php
declare(strict_types=1);

/**
 * Endpoint do n8n para o Saneamento. Sem sessão: o acesso é por token (gerado em Saneamento > Integrações), enviado no cabeçalho
 * "Authorization: Bearer <token>" ou "X-Saneamento-Token: <token>" (alguns servidores PHP-CGI descartam o Authorization).
 * Nunca na URL, para o token não ir parar nos logs.
 *
 *   {"acao":"status"}                     -> contagens da base e da fila
 *   {"acao":"processar","limite":10}      -> processa UM passo da fila e diz se deve continuar
 *
 * A lógica é a mesma da tela (nc_san_step). O n8n só agenda e repete até "continuar" ser false.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../_lib.php';
require_once __DIR__ . '/../_saneamento.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$reply = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$pdo = db();
$expected = nc_san_token($pdo);
$sent = '';
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
        if (strtolower((string)$k) === 'authorization') {
            $auth = (string)$v;
        }
    }
}
if (preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
    $sent = trim($m[1]);
}
if ($sent === '') {
    $sent = trim((string)($_SERVER['HTTP_X_SANEAMENTO_TOKEN'] ?? ''));
}
if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
    $reply(403, ['ok' => false, 'message' => 'Acesso não autorizado.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $reply(405, ['ok' => false, 'message' => 'Use POST.']);
}

$body = json_decode((string)file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];
$acao = (string)($body['acao'] ?? $_POST['acao'] ?? 'processar');

try {
    if (!nc_san_ready($pdo)) {
        $reply(409, ['ok' => false, 'message' => 'Aplique a migração 011 do NOVOCRM antes de usar o Saneamento.']);
    }
    ensure_company_cnpja_schema($pdo);
    if ($acao === 'status') {
        $reply(200, ['ok' => true] + nc_san_stats($pdo));
    }
    if ($acao !== 'processar') {
        $reply(400, ['ok' => false, 'message' => 'Ação inválida. Use "status" ou "processar".']);
    }
    @set_time_limit(120);
    $r = nc_san_step($pdo, 0, (int)($body['limite'] ?? 0));
    $reply(200, ['ok' => $r['fatal'] === '', 'continuar' => $r['fatal'] === '' && !$r['throttled'] && $r['processed'] > 0 && $r['remaining'] > 0,
        'restantes' => $r['remaining'], 'processadas' => $r['processed'], 'limite_atingido' => $r['throttled'],
        'erro' => $r['fatal'], 'resultado' => array_diff_key($r, ['fatal' => 1, 'throttled' => 1, 'remaining' => 1, 'processed' => 1])]);
} catch (Throwable $e) {
    $reply(500, ['ok' => false, 'continuar' => false, 'message' => $e->getMessage()]);
}
