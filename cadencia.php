<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/calc.php';
require_once __DIR__ . '/includes/followup_service.php';
exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_VENDEDOR);

$pdo = db();
$mes = $_GET['mes'] ?? mesAtual();
$vendedores = filtrarVendedoresDaEquipe($pdo, obterVendedores($pdo));
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
    exigirCsrf();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar_regra') {
        salvarRegraFollowup(
            $pdo,
            (int) ($_POST['regra_id'] ?? 0),
            (int) ($_POST['inactivity_hours'] ?? 24),
            (int) ($_POST['min_score'] ?? 0),
            (int) ($_POST['max_attempts'] ?? 3),
            !empty($_POST['is_active'])
        );
        cadRedirect('Regra automática atualizada.');
    }

    if ($acao === 'salvar_fluxo_pr3') {
        if (!temPapel(PAPEL_ADMINISTRADOR)) { http_response_code(403); exit; }
        if (!followupFluxoTabelasDisponiveis($pdo)) cadRedirect('Instale a migração 20260812_followup_flows_pr3.sql antes de salvar os novos nós.');
        $regraId = (int)($_POST['regra_id']??0);
        $modo = (string)($_POST['modo_fluxo']??'draft');
        try {
            if ($modo === 'rollback') {
                $versao = republicarVersaoFluxoPr3($pdo,$regraId,(int)($_POST['version_id']??0),(int)usuarioLogado()['id']);
                cadRedirect('Versão anterior republicada com segurança como versão '.$versao.'.');
            }
            if (in_array($modo,['pause','resume'],true)) {
                alterarStatusFluxoPr3($pdo,$regraId,$modo,(int)usuarioLogado()['id']);
                cadRedirect($modo==='pause'?'Fluxo pausado para novas entradas.':'Fluxo retomado com a última versão publicada.');
            }
            $stmt = $pdo->prepare('SELECT fr.*,ps.pipeline_key,ps.stage_name FROM followup_rules fr JOIN pipeline_stages ps ON ps.id=fr.pipeline_stage_id WHERE fr.id=? LIMIT 1');
            $stmt->execute([$regraId]); $regraAtual = $stmt->fetch();
            if (!$regraAtual) throw new FollowupFlowException('Regra de follow-up não encontrada.');
            $regraAtual['inactivity_hours']=max(1,min(8760,(int)($_POST['inactivity_hours']??24)));
            $regraAtual['min_score']=max(0,min(100,(int)($_POST['min_score']??0)));
            $regraAtual['max_attempts']=max(1,min(20,(int)($_POST['max_attempts']??3)));
            $regraAtual['is_active']=!empty($_POST['is_active'])?1:0;
            $config = [
                'name'=>trim((string)($_POST['flow_name']??'')),
                'move_stage_active'=>!empty($_POST['move_stage_active']),
                'move_stage_name'=>(string)($_POST['move_stage_name']??''),
                'assign_owner_active'=>!empty($_POST['assign_owner_active']),
                'assign_owner_mode'=>(string)($_POST['assign_owner_mode']??'specific'),
                'assign_owner_user_id'=>(int)($_POST['assign_owner_user_id']??0),
                'human_handoff_active'=>!empty($_POST['human_handoff_active']),
                'human_handoff_reason'=>(string)($_POST['human_handoff_reason']??''),
                'human_handoff_priority'=>(string)($_POST['human_handoff_priority']??'normal'),
                'human_handoff_recipient'=>(string)($_POST['human_handoff_recipient']??'support'),
                'notify_internal_active'=>!empty($_POST['notify_internal_active']),
                'notify_internal_user_id'=>(int)($_POST['notify_internal_user_id']??0),
                'notify_internal_title'=>(string)($_POST['notify_internal_title']??''),
                'notify_internal_message'=>(string)($_POST['notify_internal_message']??''),
                'notify_internal_severity'=>(string)($_POST['notify_internal_severity']??'info'),
            ];
            $grafo = construirGrafoFollowupPr3($regraAtual,$config);
            salvarRascunhoFluxoPr3($pdo,$regraId,$grafo,(int)usuarioLogado()['id']);
            if ($modo === 'publish') {
                $versao = publicarFluxoPr3($pdo,$regraId,$grafo,(int)usuarioLogado()['id']);
                cadRedirect('Fluxo validado e publicado como versão '.$versao.'.');
            }
            cadRedirect('Rascunho do fluxo salvo. A versão ativa continua sem alteração.');
        } catch (Throwable $e) {
            cadRedirect('Não foi possível atualizar o fluxo: '.$e->getMessage());
        }
    }

    if ($acao === 'pausar') {
        if (!podeAcessarLead($pdo, (int) $_POST['id'])) { http_response_code(403); exit; }
        $pdo->prepare('UPDATE orcamentos SET cad_pausada=? WHERE id=?')->execute([(int) $_POST['pausar'], (int) $_POST['id']]);
        cadRedirect();
    }

    if ($acao === 'gerar' || $acao === 'rodar_dia') {
        $logMap = mapaCadLog($pdo);
        if ($acao === 'gerar') {
            if (!podeAcessarLead($pdo, (int) $_POST['id'])) { http_response_code(403); exit; }
            $stmt = $pdo->prepare('SELECT * FROM orcamentos WHERE id=?');
            $stmt->execute([(int) $_POST['id']]);
            $alvo = array_filter([$stmt->fetch()]);
        } else {
            [$filtro, $params] = filtroEscopoAtribuicao($pdo);
            $stmt = $pdo->prepare("SELECT * FROM orcamentos WHERE status='Aberta' AND cad_pausada=0 AND {$filtro}");
            $stmt->execute($params); $todas = $stmt->fetchAll();
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
[$filtro, $params] = filtroEscopoAtribuicao($pdo);
$stmt = $pdo->prepare("SELECT * FROM orcamentos WHERE status='Aberta' AND {$filtro}");
$stmt->execute($params); $orcAbertas = $stmt->fetchAll();
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
          <form method="post"><?= csrfInput() ?><input type="hidden" name="acao" value="gerar"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="<?= BTN_S ?>">Gerar e-mail</button></form>
          <form method="post"><?= csrfInput() ?><input type="hidden" name="acao" value="pausar"><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="pausar" value="<?= empty($o['cad_pausada']) ? 1 : 0 ?>"><button class="<?= BTN_S ?>"><?= empty($o['cad_pausada']) ? 'Pausar' : 'Retomar' ?></button></form>
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

<?php
$followupDisponivel = followupTabelasDisponiveis($pdo);
$followupFluxoDisponivel = $followupDisponivel && followupFluxoTabelasDisponiveis($pdo);
$regrasFollowup = $followupDisponivel ? listarRegrasFollowup($pdo) : [];
$statsFollowup = estatisticasFollowup($pdo);
$statsFluxos = $followupFluxoDisponivel ? estatisticasExecucoesFluxoPr3($pdo) : [];
$logsFollowup = listarLogsFollowup($pdo, 30);
$usuariosFluxo = [];
if ($followupFluxoDisponivel && temPapel(PAPEL_ADMINISTRADOR)) {
    $usuariosFluxo = $pdo->query("SELECT id,nome FROM usuarios WHERE status='active' ORDER BY nome,id")->fetchAll();
}
$rotulosFollowup = [
    'sent'=>['Enviados','green'],'failed'=>['Falhas de IA','red'],'paused'=>['Pausados','amber'],
    'blocked'=>['Bloqueados','red'],'delivery_unknown'=>['Revisão manual','amber'],'processing'=>['Processando','blue'],
    'ready'=>['Pronto','blue'],'sending'=>['Enviando','blue'],
];
?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-start justify-between px-4 pt-4 flex-wrap gap-3">
    <div><span class="dnb-eyebrow">Motor v3.2</span><h2 class="dnb-section-title">Follow-up automático com IA e WhatsApp</h2></div>
    <?= tag($followupDisponivel ? 'Migração instalada' : 'Migração pendente', $followupDisponivel ? 'green' : 'amber') ?>
  </div>
  <div class="p-4">
    <p class="text-sm text-slate-600">O Cron Job do cPanel seleciona leads inativos por etapa e score, pede uma mensagem contextual à VPS e envia pelo WhatsApp. Resposta do lead, Human Lock, pausa manual e limite de tentativas impedem novos envios.</p>
    <?php if (!$followupDisponivel): ?><div class="dnb-alert dnb-alert--warning mt-3">Instale <code>sql/migrations/20260809_followup_engine.sql</code> no phpMyAdmin antes de ativar o motor.</div><?php endif; ?>
    <?php if ($followupDisponivel): ?>
      <div class="grid grid-cols-2 md:grid-cols-6 gap-2 mt-4">
        <?php foreach (['sent','failed','paused','blocked','delivery_unknown','processing'] as $status): [$rotulo,$cor] = $rotulosFollowup[$status]; ?>
          <div class="border border-slate-200 rounded-lg p-3"><span class="text-xs text-slate-500"><?= e($rotulo) ?></span><div class="text-xl font-bold mt-1" style="color:<?= NAVY ?>;"><?= (int)($statsFollowup[$status] ?? 0) ?></div><div class="mt-1"><?= tag($status,$cor) ?></div></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($followupFluxoDisponivel): ?>
<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-start justify-between px-4 pt-4 gap-3 flex-wrap"><div><span class="dnb-eyebrow">Painel operacional PR3 · últimas 24 horas</span><h2 class="dnb-section-title">Saúde dos fluxos publicados</h2></div><a href="/auditoria.php" class="<?= BTN_S ?>">Abrir auditoria visual</a></div>
  <div class="dnb-operation-kpis p-4">
    <?php foreach (['running'=>['Em execução','blue'],'success'=>['Sucesso','green'],'failed'=>['Falhas','red'],'paused'=>['Pausados','amber'],'blocked'=>['Bloqueados','red'],'delivery_unknown'=>['Revisão manual','amber']] as $status=>$meta): ?>
      <div class="dnb-operation-kpi"><span><?= e($meta[0]) ?></span><strong><?= (int)($statsFluxos[$status]??0) ?></strong><?= tag($status,$meta[1]) ?></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($followupDisponivel && temPapel(PAPEL_ADMINISTRADOR)): ?>
<div class="dnb-followup-builder-card mb-4">
  <div class="dnb-followup-builder-card__header">
    <div>
      <span class="dnb-eyebrow">Editor visual</span>
      <h2 class="dnb-section-title">Fluxos automáticos por etapa</h2>
      <p>Edite os mesmos parâmetros do motor atual em um canvas visual. Arraste os blocos; as conexões acompanham a ordem real de execução.</p>
    </div>
    <div class="flex gap-2 flex-wrap"><?= tag(count($regrasFollowup) . ' fluxo' . (count($regrasFollowup) === 1 ? '' : 's'), 'blue') ?><?= tag($followupFluxoDisponivel?'PR3 instalado':'PR3 pendente',$followupFluxoDisponivel?'green':'amber') ?></div>
  </div>

  <?php if (!$followupFluxoDisponivel): ?><div class="dnb-alert dnb-alert--warning m-4">O canvas atual continua disponível. Instale <code>sql/migrations/20260812_followup_flows_pr3.sql</code> para habilitar os quatro novos nós, versões e auditoria.</div><?php endif; ?>

  <?php if ($regrasFollowup): ?>
    <div class="dnb-flow-builder" data-followup-builder>
      <div class="dnb-flow-toolbar" role="group" aria-label="Controles do editor de fluxos">
        <label class="dnb-flow-selector">
          <span>Fluxo</span>
          <select data-flow-selector>
            <?php foreach ($regrasFollowup as $indice => $regra):
                $rotuloRegra = ucfirst((string) $regra['pipeline_key']) . ' · ' . (string) $regra['stage_name'];
            ?>
              <option value="<?= (int) $regra['id'] ?>"<?= $indice === 0 ? ' selected' : '' ?>>
                <?= e($rotuloRegra . (!empty($regra['is_active']) ? ' · ativo' : ' · inativo')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="dnb-flow-toolbar__summary">
          <span class="dnb-flow-toolbar__dot" aria-hidden="true"></span>
          <strong data-flow-current-label></strong>
          <span>O motor PHP continua sendo a fonte de verdade.</span>
        </div>
        <div class="dnb-flow-toolbar__actions">
          <button type="button" class="dnb-flow-tool" data-flow-zoom-out aria-label="Diminuir zoom">−</button>
          <output data-flow-zoom aria-live="polite">100%</output>
          <button type="button" class="dnb-flow-tool" data-flow-zoom-in aria-label="Aumentar zoom">+</button>
          <button type="button" class="dnb-flow-tool dnb-flow-tool--text" data-flow-fit>Ajustar</button>
          <button type="button" class="dnb-flow-tool dnb-flow-tool--text" data-flow-reset>Restaurar posições</button>
        </div>
      </div>

      <div class="dnb-flow-viewport" data-flow-viewport tabindex="0" role="region" aria-label="Canvas visual do fluxo de follow-up">
        <div class="dnb-flow-stage" data-flow-stage>
          <?php foreach ($regrasFollowup as $indice => $regra):
              $regraId = (int) $regra['id'];
              $rotuloRegra = ucfirst((string) $regra['pipeline_key']) . ' · ' . (string) $regra['stage_name'];
              $ativa = !empty($regra['is_active']);
              $formId = 'fluxo-regra-' . $regraId;
              $configPr3 = $followupFluxoDisponivel ? configuracaoAcoesFollowupPr3($pdo,$regraId) : [];
              $versoesPr3 = $followupFluxoDisponivel ? listarVersoesFluxoPr3($pdo,$regraId) : [];
          ?>
            <form
              id="<?= e($formId) ?>"
              method="post"
              class="dnb-flow-canvas"
              data-flow
              data-rule-id="<?= $regraId ?>"
              data-rule-label="<?= e($rotuloRegra) ?>"
              data-canvas-width="2450"
              data-canvas-height="680"
              aria-label="Fluxo <?= e($rotuloRegra) ?>"
              <?= $indice === 0 ? '' : 'hidden' ?>
            >
              <?= csrfInput() ?>
              <input type="hidden" name="acao" value="<?= $followupFluxoDisponivel?'salvar_fluxo_pr3':'salvar_regra' ?>">
              <input type="hidden" name="regra_id" value="<?= $regraId ?>">
              <?php if ($followupFluxoDisponivel): ?><input type="hidden" name="flow_name" value="<?= e($configPr3['name']?:$rotuloRegra) ?>"><?php endif; ?>
              <svg class="dnb-flow-edges" data-flow-edges aria-hidden="true"></svg>

              <article class="dnb-flow-node dnb-flow-node--trigger" data-flow-node="trigger" data-flow-next="eligibility" data-default-x="40" data-default-y="190" style="left:40px;top:190px;" aria-labelledby="flow-<?= $regraId ?>-trigger">
                <div class="dnb-flow-node__header" data-flow-drag>
                  <span class="dnb-flow-node__kind">Gatilho</span>
                  <span class="dnb-flow-node__grip" aria-hidden="true">⠿</span>
                </div>
                <div class="dnb-flow-node__body">
                  <span class="dnb-flow-node__icon"><?= dnbIcon('dashboard') ?></span>
                  <h3 id="flow-<?= $regraId ?>-trigger"><?= e(ucfirst((string) $regra['pipeline_key'])) ?></h3>
                  <strong><?= e((string) $regra['stage_name']) ?></strong>
                  <p>Inicia quando o lead está nesta etapa e permanece com status aberto.</p>
                </div>
                <span class="dnb-flow-port dnb-flow-port--output" aria-hidden="true"></span>
              </article>

              <article class="dnb-flow-node dnb-flow-node--condition" data-flow-node="eligibility" data-flow-next="ai" data-default-x="300" data-default-y="120" style="left:300px;top:120px;" aria-labelledby="flow-<?= $regraId ?>-eligibility">
                <span class="dnb-flow-port dnb-flow-port--input" aria-hidden="true"></span>
                <div class="dnb-flow-node__header" data-flow-drag>
                  <span class="dnb-flow-node__kind">Condição</span>
                  <span class="dnb-flow-node__grip" aria-hidden="true">⠿</span>
                </div>
                <div class="dnb-flow-node__body">
                  <span class="dnb-flow-node__icon"><?= dnbIcon('cadence') ?></span>
                  <h3 id="flow-<?= $regraId ?>-eligibility">Elegibilidade</h3>
                  <div class="dnb-flow-fields">
                    <label for="flow-<?= $regraId ?>-hours">
                      <span>Inatividade</span>
                      <span class="dnb-flow-input"><input id="flow-<?= $regraId ?>-hours" type="number" min="1" max="8760" name="inactivity_hours" value="<?= (int) $regra['inactivity_hours'] ?>"><small>horas</small></span>
                    </label>
                    <label for="flow-<?= $regraId ?>-score">
                      <span>Score mínimo</span>
                      <span class="dnb-flow-input"><input id="flow-<?= $regraId ?>-score" type="number" min="0" max="100" name="min_score" value="<?= (int) $regra['min_score'] ?>"><small>/100</small></span>
                    </label>
                  </div>
                  <p>As duas condições precisam ser verdadeiras para o motor avançar.</p>
                </div>
                <span class="dnb-flow-port dnb-flow-port--output" aria-hidden="true"></span>
              </article>

              <article class="dnb-flow-node dnb-flow-node--ai" data-flow-node="ai" data-flow-next="whatsapp" data-default-x="610" data-default-y="190" style="left:610px;top:190px;" aria-labelledby="flow-<?= $regraId ?>-ai">
                <span class="dnb-flow-port dnb-flow-port--input" aria-hidden="true"></span>
                <div class="dnb-flow-node__header" data-flow-drag>
                  <span class="dnb-flow-node__kind">Agente de IA</span>
                  <span class="dnb-flow-node__grip" aria-hidden="true">⠿</span>
                </div>
                <div class="dnb-flow-node__body">
                  <span class="dnb-flow-node__icon"><?= dnbIcon('ai') ?></span>
                  <h3 id="flow-<?= $regraId ?>-ai">Gerar mensagem</h3>
                  <strong>Contexto + histórico</strong>
                  <p>A VPS prepara a mensagem e aplica os guardrails atuais antes do envio.</p>
                </div>
                <span class="dnb-flow-port dnb-flow-port--output" aria-hidden="true"></span>
              </article>

              <article class="dnb-flow-node dnb-flow-node--channel" data-flow-node="whatsapp" data-flow-next="<?= $followupFluxoDisponivel?'move_stage':'control' ?>" data-default-x="870" data-default-y="210" style="left:870px;top:210px;" aria-labelledby="flow-<?= $regraId ?>-whatsapp">
                <span class="dnb-flow-port dnb-flow-port--input" aria-hidden="true"></span>
                <div class="dnb-flow-node__header" data-flow-drag>
                  <span class="dnb-flow-node__kind">Canal</span>
                  <span class="dnb-flow-node__grip" aria-hidden="true">⠿</span>
                </div>
                <div class="dnb-flow-node__body">
                  <span class="dnb-flow-node__icon"><?= dnbIcon('whatsapp') ?></span>
                  <h3 id="flow-<?= $regraId ?>-whatsapp">Enviar WhatsApp</h3>
                  <strong>Conversa aberta</strong>
                  <p>Usa a instância ativa já vinculada ao lead e registra o resultado.</p>
                </div>
                <span class="dnb-flow-port dnb-flow-port--output" aria-hidden="true"></span>
              </article>

              <?php if ($followupFluxoDisponivel): ?>
              <article class="dnb-flow-node dnb-flow-node--action" data-flow-node="move_stage" data-flow-next="assign_owner" data-default-x="1130" data-default-y="130" style="left:1130px;top:130px;" aria-labelledby="flow-<?= $regraId ?>-move-stage">
                <span class="dnb-flow-port dnb-flow-port--input" aria-hidden="true"></span><div class="dnb-flow-node__header" data-flow-drag><span class="dnb-flow-node__kind">Ação CRM</span><span class="dnb-flow-node__grip" aria-hidden="true">⠿</span></div>
                <div class="dnb-flow-node__body"><span class="dnb-flow-node__icon"><?= dnbIcon('cadence') ?></span><h3 id="flow-<?= $regraId ?>-move-stage">Mover etapa</h3><label class="dnb-flow-switch"><input type="checkbox" name="move_stage_active" value="1" <?= !empty($configPr3['move_stage_active'])?'checked':'' ?>><span class="dnb-flow-switch__track" aria-hidden="true"></span><span>Executar após envio</span></label><label class="dnb-flow-attempts"><span>Etapa de destino</span><select name="move_stage_name"><option value="">Selecione</option><?php foreach($funisArr[$regra['pipeline_key']]['etapas']??[] as $etapa): ?><option value="<?= e($etapa) ?>" <?= ($configPr3['move_stage_name']??'')===$etapa?'selected':'' ?>><?= e($etapa) ?></option><?php endforeach; ?></select></label><p>Só avança leads abertos e valida a etapa no funil atual.</p></div><span class="dnb-flow-port dnb-flow-port--output" aria-hidden="true"></span>
              </article>

              <article class="dnb-flow-node dnb-flow-node--action" data-flow-node="assign_owner" data-flow-next="human_handoff" data-default-x="1390" data-default-y="210" style="left:1390px;top:210px;" aria-labelledby="flow-<?= $regraId ?>-assign-owner">
                <span class="dnb-flow-port dnb-flow-port--input" aria-hidden="true"></span><div class="dnb-flow-node__header" data-flow-drag><span class="dnb-flow-node__kind">Ação CRM</span><span class="dnb-flow-node__grip" aria-hidden="true">⠿</span></div>
                <div class="dnb-flow-node__body"><span class="dnb-flow-node__icon"><?= dnbIcon('users') ?></span><h3 id="flow-<?= $regraId ?>-assign-owner">Atribuir responsável</h3><label class="dnb-flow-switch"><input type="checkbox" name="assign_owner_active" value="1" <?= !empty($configPr3['assign_owner_active'])?'checked':'' ?>><span class="dnb-flow-switch__track" aria-hidden="true"></span><span>Executar após envio</span></label><label class="dnb-flow-attempts"><span>Modo</span><select name="assign_owner_mode"><option value="specific" <?= ($configPr3['assign_owner_mode']??'specific')==='specific'?'selected':'' ?>>Usuário específico</option><option value="keep" <?= ($configPr3['assign_owner_mode']??'')==='keep'?'selected':'' ?>>Manter atual</option><option value="supervisor" <?= ($configPr3['assign_owner_mode']??'')==='supervisor'?'selected':'' ?>>Supervisor atual</option><option value="administrator" <?= ($configPr3['assign_owner_mode']??'')==='administrator'?'selected':'' ?>>Administrador</option></select></label><label class="dnb-flow-attempts"><span>Usuário específico</span><select name="assign_owner_user_id"><option value="0">Selecione</option><?php foreach($usuariosFluxo as $usuarioFluxo): ?><option value="<?= (int)$usuarioFluxo['id'] ?>" <?= (int)($configPr3['assign_owner_user_id']??0)===(int)$usuarioFluxo['id']?'selected':'' ?>><?= e($usuarioFluxo['nome']) ?></option><?php endforeach; ?></select></label><p>Resolve novamente o usuário ativo no servidor e notifica trocas efetivas.</p></div><span class="dnb-flow-port dnb-flow-port--output" aria-hidden="true"></span>
              </article>

              <article class="dnb-flow-node dnb-flow-node--handoff" data-flow-node="human_handoff" data-flow-next="notify_internal" data-default-x="1650" data-default-y="130" style="left:1650px;top:130px;" aria-labelledby="flow-<?= $regraId ?>-handoff">
                <span class="dnb-flow-port dnb-flow-port--input" aria-hidden="true"></span><div class="dnb-flow-node__header" data-flow-drag><span class="dnb-flow-node__kind">Segurança</span><span class="dnb-flow-node__grip" aria-hidden="true">⠿</span></div>
                <div class="dnb-flow-node__body"><span class="dnb-flow-node__icon"><?= dnbIcon('cases') ?></span><h3 id="flow-<?= $regraId ?>-handoff">Transbordo humano</h3><label class="dnb-flow-switch"><input type="checkbox" name="human_handoff_active" value="1" <?= !empty($configPr3['human_handoff_active'])?'checked':'' ?>><span class="dnb-flow-switch__track" aria-hidden="true"></span><span>Em bloqueio ou falha</span></label><label class="dnb-flow-attempts"><span>Prioridade</span><select name="human_handoff_priority"><option value="low" <?= ($configPr3['human_handoff_priority']??'')==='low'?'selected':'' ?>>Baixa</option><option value="normal" <?= ($configPr3['human_handoff_priority']??'normal')==='normal'?'selected':'' ?>>Normal</option><option value="high" <?= ($configPr3['human_handoff_priority']??'')==='high'?'selected':'' ?>>Alta</option><option value="urgent" <?= ($configPr3['human_handoff_priority']??'')==='urgent'?'selected':'' ?>>Urgente</option></select></label><label class="dnb-flow-attempts"><span>Destinatário</span><select name="human_handoff_recipient"><option value="current" <?= ($configPr3['human_handoff_recipient']??'')==='current'?'selected':'' ?>>Responsável atual</option><option value="supervisor" <?= ($configPr3['human_handoff_recipient']??'')==='supervisor'?'selected':'' ?>>Supervisor</option><option value="support" <?= ($configPr3['human_handoff_recipient']??'support')==='support'?'selected':'' ?>>Suporte</option><option value="administrator" <?= ($configPr3['human_handoff_recipient']??'')==='administrator'?'selected':'' ?>>Administrador</option></select></label><label class="dnb-flow-attempts"><span>Motivo para a equipe</span><input name="human_handoff_reason" maxlength="500" value="<?= e($configPr3['human_handoff_reason']??'') ?>"></label><p>Pausa a IA, cria ou reutiliza um caso e mantém o Human Lock.</p></div><span class="dnb-flow-port dnb-flow-port--output" aria-hidden="true"></span>
              </article>

              <article class="dnb-flow-node dnb-flow-node--notification" data-flow-node="notify_internal" data-flow-next="control" data-default-x="1910" data-default-y="210" style="left:1910px;top:210px;" aria-labelledby="flow-<?= $regraId ?>-notification">
                <span class="dnb-flow-port dnb-flow-port--input" aria-hidden="true"></span><div class="dnb-flow-node__header" data-flow-drag><span class="dnb-flow-node__kind">Operação</span><span class="dnb-flow-node__grip" aria-hidden="true">⠿</span></div>
                <div class="dnb-flow-node__body"><span class="dnb-flow-node__icon"><?= dnbIcon('bell') ?></span><h3 id="flow-<?= $regraId ?>-notification">Notificação interna</h3><label class="dnb-flow-switch"><input type="checkbox" name="notify_internal_active" value="1" <?= !empty($configPr3['notify_internal_active'])?'checked':'' ?>><span class="dnb-flow-switch__track" aria-hidden="true"></span><span>Notificar resultado</span></label><label class="dnb-flow-attempts"><span>Destinatário</span><select name="notify_internal_user_id"><option value="0">Selecione</option><?php foreach($usuariosFluxo as $usuarioFluxo): ?><option value="<?= (int)$usuarioFluxo['id'] ?>" <?= (int)($configPr3['notify_internal_user_id']??0)===(int)$usuarioFluxo['id']?'selected':'' ?>><?= e($usuarioFluxo['nome']) ?></option><?php endforeach; ?></select></label><label class="dnb-flow-attempts"><span>Severidade</span><select name="notify_internal_severity"><option value="info" <?= ($configPr3['notify_internal_severity']??'info')==='info'?'selected':'' ?>>Informação</option><option value="warning" <?= ($configPr3['notify_internal_severity']??'')==='warning'?'selected':'' ?>>Atenção</option><option value="critical" <?= ($configPr3['notify_internal_severity']??'')==='critical'?'selected':'' ?>>Crítica</option></select></label><label class="dnb-flow-attempts"><span>Título</span><input name="notify_internal_title" maxlength="160" value="<?= e($configPr3['notify_internal_title']??'') ?>"></label><label class="dnb-flow-attempts"><span>Mensagem</span><input name="notify_internal_message" maxlength="500" value="<?= e($configPr3['notify_internal_message']??'') ?>"></label></div><span class="dnb-flow-port dnb-flow-port--output" aria-hidden="true"></span>
              </article>
              <?php endif; ?>

              <article class="dnb-flow-node dnb-flow-node--control" data-flow-node="control" data-default-x="<?= $followupFluxoDisponivel?'2170':'1130' ?>" data-default-y="130" style="left:<?= $followupFluxoDisponivel?'2170':'1130' ?>px;top:130px;" aria-labelledby="flow-<?= $regraId ?>-control">
                <span class="dnb-flow-port dnb-flow-port--input" aria-hidden="true"></span>
                <div class="dnb-flow-node__header" data-flow-drag>
                  <span class="dnb-flow-node__kind">Controle</span>
                  <span class="dnb-flow-node__grip" aria-hidden="true">⠿</span>
                </div>
                <div class="dnb-flow-node__body">
                  <span class="dnb-flow-node__icon"><?= dnbIcon('check-circle') ?></span>
                  <h3 id="flow-<?= $regraId ?>-control">Limites e parada</h3>
                  <label class="dnb-flow-attempts" for="flow-<?= $regraId ?>-attempts">
                    <span>Máximo de tentativas</span>
                    <input id="flow-<?= $regraId ?>-attempts" type="number" min="1" max="20" name="max_attempts" value="<?= (int) $regra['max_attempts'] ?>">
                  </label>
                  <label class="dnb-flow-switch" for="flow-<?= $regraId ?>-active">
                    <input id="flow-<?= $regraId ?>-active" type="checkbox" name="is_active" value="1" <?= $ativa ? 'checked' : '' ?>>
                    <span class="dnb-flow-switch__track" aria-hidden="true"></span>
                    <span data-flow-active-label><?= $ativa ? 'Fluxo ativo' : 'Fluxo inativo' ?></span>
                  </label>
                  <p>Resposta do lead, pausa, Human Lock, bloqueio ou limite encerram novas tentativas.</p>
                  <?php if ($followupFluxoDisponivel): ?>
                    <div class="dnb-flow-publish-actions"><button type="submit" class="<?= BTN_S ?>" name="modo_fluxo" value="draft">Salvar rascunho</button><button type="submit" class="<?= BTN_P ?>" name="modo_fluxo" value="publish">Publicar versão</button></div>
                    <?php if(!empty($configPr3['flow_id'])): ?><button type="submit" class="<?= BTN_S ?> dnb-flow-node__save" name="modo_fluxo" value="<?= ($configPr3['flow_status']??'draft')==='active'?'pause':'resume' ?>" onclick="return confirm('Confirmar esta ação operacional no fluxo?');"><?= ($configPr3['flow_status']??'draft')==='active'?'Pausar novas entradas':'Retomar fluxo publicado' ?></button><?php endif; ?>
                    <?php if($versoesPr3): ?><label class="dnb-flow-attempts"><span>Restaurar versão</span><select name="version_id"><?php foreach($versoesPr3 as $versaoPr3): ?><option value="<?= (int)$versaoPr3['id'] ?>">v<?= (int)$versaoPr3['version_number'] ?> · <?= e($versaoPr3['published_at']) ?></option><?php endforeach; ?></select></label><button type="submit" class="<?= BTN_S ?> dnb-flow-node__save" name="modo_fluxo" value="rollback" onclick="return confirm('Republicar esta versão como uma nova versão ativa?');">Republicar versão</button><?php endif; ?>
                  <?php else: ?><button type="submit" class="<?= BTN_P ?> dnb-flow-node__save">Salvar fluxo</button><?php endif; ?>
                </div>
              </article>
            </form>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="dnb-flow-legend">
        <span><i class="dnb-flow-legend__line" aria-hidden="true"></i> conexão executada pelo motor</span>
        <span><i class="dnb-flow-legend__node" aria-hidden="true"></i> bloco arrastável</span>
        <span>Rascunhos não alteram a versão ativa; publicação e rollback ficam registrados.</span>
      </div>

      <details class="dnb-flow-fallback" data-flow-fallback open>
        <summary>Editar os mesmos parâmetros em tabela</summary>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Regras automáticas de follow-up em tabela">
          <table class="w-full text-sm min-w-[900px]"><thead><tr class="text-left text-slate-500"><th class="p-2">Funil / etapa</th><th class="p-2">Inatividade</th><th class="p-2">Score mínimo</th><th class="p-2">Máx. tentativas</th><th class="p-2">Ativa</th><th class="p-2">Ação</th></tr></thead><tbody>
            <?php foreach ($regrasFollowup as $regra): $formId='tabela-regra-'.(int)$regra['id']; ?><tr class="border-t border-slate-200">
              <td class="p-2"><form id="<?= $formId ?>" method="post"><?= csrfInput() ?><input type="hidden" name="acao" value="salvar_regra"><input type="hidden" name="regra_id" value="<?= (int)$regra['id'] ?>"></form><strong><?= e(ucfirst($regra['pipeline_key'])) ?></strong><div class="text-xs text-slate-500"><?= e($regra['stage_name']) ?></div></td>
              <td class="p-2"><label class="sr-only" for="tabela-horas-<?= (int)$regra['id'] ?>">Horas de inatividade</label><input form="<?= $formId ?>" id="tabela-horas-<?= (int)$regra['id'] ?>" type="number" min="1" max="8760" name="inactivity_hours" value="<?= (int)$regra['inactivity_hours'] ?>" class="<?= INPUT_CLS ?> w-28"> h</td>
              <td class="p-2"><label class="sr-only" for="tabela-score-<?= (int)$regra['id'] ?>">Score mínimo</label><input form="<?= $formId ?>" id="tabela-score-<?= (int)$regra['id'] ?>" type="number" min="0" max="100" name="min_score" value="<?= (int)$regra['min_score'] ?>" class="<?= INPUT_CLS ?> w-24"></td>
              <td class="p-2"><label class="sr-only" for="tabela-tent-<?= (int)$regra['id'] ?>">Máximo de tentativas</label><input form="<?= $formId ?>" id="tabela-tent-<?= (int)$regra['id'] ?>" type="number" min="1" max="20" name="max_attempts" value="<?= (int)$regra['max_attempts'] ?>" class="<?= INPUT_CLS ?> w-24"></td>
              <td class="p-2"><label class="inline-flex items-center gap-2"><input form="<?= $formId ?>" type="checkbox" name="is_active" value="1" <?= !empty($regra['is_active'])?'checked':'' ?>><span><?= !empty($regra['is_active'])?'Ativa':'Inativa' ?></span></label></td>
              <td class="p-2"><button form="<?= $formId ?>" class="<?= BTN_S ?>">Salvar</button></td>
            </tr><?php endforeach; ?>
          </tbody></table>
        </div>
      </details>
      <p class="dnb-flow-builder__note">As regras continuam instaladas desativadas por padrão. Ative somente depois de validar a VPS, o provedor de WhatsApp e o Cron Job.</p>
    </div>
  <?php else: ?>
    <div class="dnb-alert dnb-alert--warning">Nenhuma regra foi encontrada. Reaplique a migração do motor de follow-up para criar as regras por etapa.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($followupDisponivel): ?>
<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="px-4 pt-4"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Operação recente</span></div>
  <div class="p-4 overflow-x-auto" tabindex="0" role="region" aria-label="Histórico do motor de follow-up">
    <table class="w-full text-sm min-w-[760px]"><thead><tr class="text-left text-slate-500"><th class="p-2">Data</th><th class="p-2">Lead</th><th class="p-2">Etapa</th><th class="p-2">Tentativa</th><th class="p-2">Status</th><th class="p-2">Detalhe</th></tr></thead><tbody>
      <?php foreach ($logsFollowup as $log): $meta=$rotulosFollowup[$log['status']]??[$log['status'],'slate']; $detalhe=temPapel(PAPEL_ADMINISTRADOR)&&$log['error_message']?$log['error_message']:mb_substr((string)$log['message_sent'],0,140); ?><tr class="border-t border-slate-200"><td class="p-2 whitespace-nowrap"><?= e($log['sent_at'] ?: $log['created_at']) ?></td><td class="p-2"><a href="/lead.php?id=<?= (int)$log['lead_id'] ?>" class="font-semibold"><?= e($log['cliente']) ?></a><div class="text-xs text-slate-500"><?= e($log['numero']) ?></div></td><td class="p-2"><?= e($log['stage_name']) ?></td><td class="p-2"><?= (int)$log['attempt_number'] ?></td><td class="p-2"><?= tag($meta[0],$meta[1]) ?></td><td class="p-2 text-xs text-slate-500 max-w-sm"><?= e($detalhe) ?></td></tr><?php endforeach; ?>
      <?php if (!$logsFollowup): ?><tr><td colspan="6" class="p-4 text-center text-slate-400">Nenhuma execução registrada.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Cadência automática por funil</span>
    <form method="post" class="no-print"><?= csrfInput() ?><input type="hidden" name="acao" value="rodar_dia">
      <button class="<?= BTN_P ?>" style="background:<?= NAVY ?>;">Rodar cadência do dia (<?= count($devidas) ?> devido<?= count($devidas) === 1 ? '' : 's' ?>)</button>
    </form>
  </div>
  <div class="p-4">
    <div class="text-xs text-slate-500">
      Ritmos: <b>Peças e Revenda</b> D+1 · D+3 · D+7 · break-up D+10 &nbsp;|&nbsp; <b>Serviços e Locação</b> D+2 · D+7 · D+14 · break-up D+21 &nbsp;|&nbsp; <b>Equipamentos</b> D+3 · D+10 · D+25 e nutrição mensal contínua.
      Esta área mantém a cadência manual por e-mail da versão anterior. Os e-mails são gerados por modelo, revisados e copiados manualmente; o motor v3.2 acima opera separadamente por IA e WhatsApp.
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
