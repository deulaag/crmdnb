<?php
declare(strict_types=1);

/** Fragmento: campos de parâmetros da política escolhida (troca do select no drawer). */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/comissao.php';
require_once __DIR__ . '/../partials/equipe.php';

$user = nc_boot();
if (!nc_is_manager($user)) {
    nc_fail('Ação não permitida.', 403);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

nc_rep_param_fields((string)($_GET['policy_key'] ?? ''), []);
