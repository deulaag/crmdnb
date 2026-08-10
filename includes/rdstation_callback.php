<?php
declare(strict_types=1);
/**
 * Corpo compartilhado dos callbacks OAuth da RD Station.
 * Cada fonte tem seu próprio diretório público, mas o tratamento é idêntico:
 * confere o state, troca o code por tokens e devolve o admin para a aba de
 * Integrações com o resultado em flash.
 *
 * Espera $rdFonteEsperada definida pelo arquivo que o inclui.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/rdstation_service.php';

exigirPapel(PAPEL_ADMINISTRADOR);

$destino = '/configuracoes.php#tab-integracoes';

function rdConcluir(string $mensagem, string $destino): never
{
    $_SESSION['flash'] = $mensagem;
    header('Location: ' . $destino);
    exit;
}

$erro = trim((string) ($_GET['error_description'] ?? $_GET['error'] ?? ''));
if ($erro !== '') {
    rdConcluir('RD Station recusou a autorização: ' . mb_substr($erro, 0, 200), $destino);
}

$code = trim((string) ($_GET['code'] ?? ''));
if ($code === '') {
    rdConcluir('A RD Station não devolveu o código de autorização.', $destino);
}

try {
    $fonte = rdValidarState((string) ($_GET['state'] ?? ''));
    if ($fonte !== $rdFonteEsperada) {
        throw new RdStationException('O callback recebido não corresponde a esta fonte.');
    }
    $usuario = usuarioLogado();
    rdTrocarCodePorToken(db(), $fonte, $code, $usuario ? (int) $usuario['id'] : null);
    rdConcluir(rdFonte($fonte)['rotulo'] . ' conectado com sucesso.', $destino);
} catch (Throwable $e) {
    rdConcluir('Falha ao conectar com a RD Station: ' . mb_substr($e->getMessage(), 0, 200), $destino);
}
