<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/calc.php';
exigirLogin();

$pdo = db();
$mes = $_GET['mes'] ?? mesAtual();
$vendedores = obterVendedores($pdo);
$funisArr = funis();
$cadArr = cadencias();

/** Situação de cadência de uma oportunidade (porte de statusCadencia() do JS). */
function statusCadencia(array $o, int $logCount, ?string $logUltima, array $cadArr): array
{
    $dias = (int) floor((time() - strtotime($o['data'])) / 86400);
    $cad = $cadArr[funilDe($o['linha'])] ?? $cadArr['pecas'];
    $n = $logCount;
    if ($n < count($cad['passos'])) {
        return [
            'dias' => $dias, 'devido' => $dias >= $cad['passos'][$n],
            'rotulo' => 'T' . ($n + 1) . ' · D+' . $cad['passos'][$n] . ' · ' . $cad['nomes'][$n],
            'intent' => $cad['nomes'][$n], 'breakup' => $n === count($cad['passos']) - 1 && empty($cad['nutricao']),
        ];
    }
    if (!empty($cad['nutricao'])) {
        $diasUlt = $logUltima ? (int) floor((time() - strtotime($logUltima)) / 86400) : $dias;
        return ['dias' => $dias, 'devido' => $diasUlt >= $cad['nutricao'], 'rotulo' => 'Nutrição mensal (a cada ' . $cad['nutricao'] . 'd)',
            'intent' => 'Nutrição: conteúdo técnico leve, manter relacionamento, sem pressão', 'breakup' => false];
    }
    return ['dias' => $dias, 'devido' => false, 'rotulo' => 'Cadência concluída', 'intent' => '', 'breakup' => false];
}

/** Gera assunto/corpo do e-mail de follow-up por TEMPLATE (sem IA). */
function gerarEmailTemplate(array $o, array $st, string $vendedorNome): array
{
    $funilLabel = funis()[funilDe($o['linha'])]['label'];
    $assunto = $st['breakup']
        ? "Encerrando o contato — {$o['cliente']}"
        : "Follow-up · proposta {$o['numero']} · {$o['cliente']}";

    if ($st['breakup']) {
        $corpo = "Olá,\n\nNão tivemos retorno sobre a proposta {$o['numero']} ({$funilLabel}, " . fmtBRL($o['valor']) . "). "
            . "Vou pausar o acompanhamento por aqui, mas fico à disposição sempre que fizer sentido retomar a conversa.\n\n"
            . "Qualquer novidade, é só chamar.\n\nAtenciosamente,\n{$vendedorNome} · DNB";
    } elseif (str_starts_with($st['intent'], 'Nutrição')) {
        $corpo = "Olá,\n\nPassando para compartilhar uma atualização técnica que pode ser útil para a operação de vocês, relacionada a {$o['linha']}. "
            . "Sem compromisso — só mantendo contato.\n\nSe fizer sentido conversar, estou à disposição.\n\nAtenciosamente,\n{$vendedorNome} · DNB";
    } else {
        $corpo = "Olá,\n\nEstou dando sequência à proposta {$o['numero']} ({$o['linha']}, " . fmtBRL($o['valor']) . "), enviada há {$st['dias']} dia(s). "
            . "Foco deste contato: {$st['intent']}.\n\nPodemos alinhar em uma conversa rápida de 15 minutos esta semana?\n\n"
            . "Fico à disposição.\n\nAtenciosamente,\n{$vendedorNome} · DNB";
    }
    return ['assunto' => $assunto, 'corpo' => $corpo];
}

function cadRedirect(string $msg = ''): void
{
    if ($msg !== '') $_SESSION['flash'] = $msg;
    $qs = !empty($_GET['mes']) ? ('?mes=' . urlencode($_GET['mes'])) : '';
    header('Location: /cadencia.php' . $qs);
    exit;
}

/** Busca contagem/última data de toques por orçamento. */
function mapaCadLog(PDO $pdo): array
{
    $rows = $pdo->query('SELECT orcamento_id, COUNT(*) c, MAX(data) ultima FROM orcamento_cadencia_log GROUP BY orcamento_id')->fetchAll();
    $map = [];
    foreach ($rows as $r) $map[$r['orcamento_id']] = ['c' => (int) $r['c'], 'ultima' => $r['ultima']];
    return $map;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'pausar') {
        $pdo->prepare('UPDATE orcamentos SET cad_pausada=? WHERE id=?')->execute([(int) $_POST['pausar'], (int) $_POST['id']]);
        cadRedirect();
    }

    if ($acao === 'gerar' || $acao === 'rodar_dia') {
        $logMap = mapaCadLog($pdo);
        if ($acao === 'gerar') {
            $stmt = $pdo->prepare('SELECT * FROM orcamentos WHERE id=?');
            $stmt->execute([(int) $_POST['id']]);
            $alvo = array_filter([$stmt->fetch()]);
        } else {
            $todas = $pdo->query("SELECT * FROM orcamentos WHERE status='Aberta' AND cad_pausada=0")->fetchAll();
            $alvo = array_values(array_filter($todas, function ($o) use ($logMap, $cadArr) {
                $lg = $logMap[$o['id']] ?? ['c' => 0, 'ultima' => null];
                return statusCadencia($o, $lg['c'], $lg['ultima'], $cadArr)['devido'];
            }));
        }
        if (!count($alvo)) cadRedirect($acao === 'rodar_dia' ? 'Cadência em dia — nenhum toque devido hoje.' : '');
        $previews = [];
        foreach ($alvo as $o) {
            $lg = $logMap[$o['id']] ?? ['c' => 0, 'ultima' => null];
            $st = statusCadencia($o, $lg['c'], $lg['ultima'], $cadArr);
            $vendedorNome = nomeVendedor($vendedores, $o['vendedor_id']);
            $previews[$o['id']] = gerarEmailTemplate($o, $st, $vendedorNome);
            $pdo->prepare('INSERT INTO orcamento_cadencia_log (orcamento_id, data, etapa) VALUES (?,?,?)')->execute([$o['id'], hoje(), $st['rotulo']]);
            $logMap[$o['id']] = ['c' => $lg['c'] + 1, 'ultima' => hoje()];
        }
        $_SESSION['cad_preview'] = $previews;
        cadRedirect($acao === 'rodar_dia' ? ('Concluído: ' . count($alvo) . ' e-mail(is) gerado(s) — copie e envie manualmente. Toques registrados no histórico.') : '');
    }
}

$msg = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$previews = $_SESSION['cad_preview'] ?? [];
unset($_SESSION['cad_preview']);

$logMap = mapaCadLog($pdo);
$orcAbertas = $pdo->query("SELECT * FROM orcamentos WHERE status='Aberta'")->fetchAll();
$ativas = array_values(array_filter($orcAbertas, fn($o) => empty($o['cad_pausada'])));
$pausadas = array_values(array_filter($orcAbertas, fn($o) => !empty($o['cad_pausada'])));
$comStatus = function (array $o) use ($logMap, $cadArr) {
    $lg = $logMap[$o['id']] ?? ['c' => 0, 'ultima' => null];
    return statusCadencia($o, $lg['c'], $lg['ultima'], $cadArr);
};
$devidas = array_values(array_filter($ativas, fn($o) => $comStatus($o)['devido']));
$emDia = array_values(array_filter($ativas, fn($o) => !$comStatus($o)['devido']));

function renderItemCad(array $o, array $st, array $vendedores, array $previews, bool $destaque): void
{
    $cls = $destaque ? 'border-amber-400 bg-amber-50' : 'border-slate-200';
    $qtdToques = 0; // preenchido abaixo via variável externa se necessário
    ?>
    <div class="border rounded-lg p-3 mb-3 <?= $cls ?>">
      <div class="flex items-center justify-between flex-wrap gap-2">
        <div>
          <span class="font-semibold" style="color:<?= NAVY ?>;"><?= e($o['cliente']) ?></span>
          <span class="text-xs text-slate-500 ml-2">nº <?= e($o['numero'] ?: 's/n') ?> · <?= e($o['linha']) ?> · <?= fmtBRL($o['valor']) ?> · <?= $st['dias'] ?> dias · <?= e(nomeVendedor($vendedores, $o['vendedor_id'])) ?></span>
          <div class="mt-1"><?= tag($st['rotulo'], $st['devido'] ? 'amber' : 'blue') ?></div>
        </div>
        <div class="flex gap-2 flex-wrap">
          <form method="post"><input type="hidden" name="acao" value="gerar"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="<?= BTN_S ?>">Gerar e-mail</button></form>
          <form method="post"><input type="hidden" name="acao" value="pausar"><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="pausar" value="<?= empty($o['cad_pausada']) ? 1 : 0 ?>"><button class="<?= BTN_S ?>"><?= empty($o['cad_pausada']) ? 'Pausar' : 'Retomar' ?></button></form>
        </div>
      </div>
      <?php if (isset($previews[$o['id']])): $p = $previews[$o['id']]; $domId = 'email-' . $o['id']; ?>
        <div class="mt-2 bg-white border border-slate-200 rounded-lg p-3 text-sm">
          <div class="font-bold text-slate-700 mb-1">Assunto: <?= e($p['assunto']) ?></div>
          <div id="<?= $domId ?>" class="whitespace-pre-wrap text-slate-600"><?= e($p['corpo']) ?></div>
          <button id="btn-<?= $domId ?>" type="button" class="<?= BTN_S ?> mt-2" onclick="dnbCopiar('<?= $domId ?>')">Copiar texto</button>
        </div>
      <?php endif; ?>
    </div>
    <?php
}

$tabAtual = 'cad';
include __DIR__ . '/includes/layout_header.php';
?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Cadência automática por funil</span>
    <form method="post" class="no-print"><input type="hidden" name="acao" value="rodar_dia">
      <button class="<?= BTN_P ?>" style="background:<?= NAVY ?>;">Rodar cadência do dia (<?= count($devidas) ?> devido<?= count($devidas) === 1 ? '' : 's' ?>)</button>
    </form>
  </div>
  <div class="p-4">
    <div class="text-xs text-slate-500">
      Ritmos: <b>Peças e Revenda</b> D+1 · D+3 · D+7 · break-up D+10 &nbsp;|&nbsp; <b>Serviços e Locação</b> D+2 · D+7 · D+14 · break-up D+21 &nbsp;|&nbsp; <b>Equipamentos</b> D+3 · D+10 · D+25 e nutrição mensal contínua.
      Os e-mails são gerados por modelo (sem IA externa) — revise e copie manualmente para o seu cliente de e-mail. Cada toque gerado fica registrado no histórico e alimenta o KPI de follow-up.
    </div>
    <?php if ($msg): ?><div class="text-sm font-semibold mt-2" style="color:<?= NAVY ?>;"><?= e($msg) ?></div><?php endif; ?>
  </div>
</div>

<?php if (count($devidas)): ?>
<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Toques devidos hoje (<?= count($devidas) ?>)</span></div>
  <div class="p-4"><?php foreach ($devidas as $o) renderItemCad($o, $comStatus($o), $vendedores, $previews, true); ?></div>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Em cadência, aguardando próximo toque (<?= count($emDia) ?>)</span></div>
  <div class="p-4">
    <?php foreach ($emDia as $o) renderItemCad($o, $comStatus($o), $vendedores, $previews, false); ?>
    <?php if (!count($emDia)): ?><div class="text-sm text-slate-400 py-3 text-center">Nenhuma.</div><?php endif; ?>
  </div>
</div>

<?php if (count($pausadas)): ?>
<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Pausadas (<?= count($pausadas) ?>)</span></div>
  <div class="p-4"><?php foreach ($pausadas as $o) renderItemCad($o, $comStatus($o), $vendedores, $previews, false); ?></div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
