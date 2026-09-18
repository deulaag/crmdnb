<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/rd_station_service.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$resultados = [];
$falhou = false;
foreach (DNB_RD_PRODUCTS as $produto) {
    try {
        $estado = rdEstadoProduto($produto);
        if (!$estado['connected']) {
            $resultados[$produto] = ['ok' => true, 'skipped' => true, 'message' => 'não conectado'];
            continue;
        }
        rdObterAccessToken($produto);
        $resultados[$produto] = ['ok' => true, 'skipped' => false, 'message' => 'token válido/renovado'];
    } catch (Throwable $e) {
        $falhou = true;
        $resultados[$produto] = ['ok' => false, 'message' => mb_substr($e->getMessage(), 0, 220)];
    }
}
echo json_encode(['ok' => !$falhou, 'rdstation' => $resultados], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($falhou ? 1 : 0);
