<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/calc.php';
require_once __DIR__ . '/includes/seed.php';
require_once __DIR__ . '/includes/whatsapp_provider.php';
require_once __DIR__ . '/includes/ai_client.php';
require_once __DIR__ . '/includes/rdstation_service.php';
exigirPapel(PAPEL_ADMINISTRADOR);

$pdo = db();
$usuarioAtualId = (int) (usuarioLogado()['id'] ?? 0);
$mes = $_GET['mes'] ?? mesAtual();
$politicasArr = politicas();

// Provedores aceitos na tabela de instâncias (o adaptador HTTP é genérico).
const PROVEDORES_WHATSAPP = ['generic' => 'Genérico', 'evolution' => 'Evolution API', 'zapi' => 'Z-API'];

function cfgRedirect(string $msg = '', string $aba = ''): void
{
    if ($msg !== '') $_SESSION['flash'] = $msg;
    $qs = !empty($_GET['mes']) ? ('?mes=' . urlencode($_GET['mes'])) : '';
    $hash = $aba !== '' ? ('#tab-' . $aba) : '';
    header('Location: /configuracoes.php' . $qs . $hash);
    exit;
}

function whatsappInstanciasDisponiveis(PDO $pdo): bool
{
    static $disponivel = null;
    if ($disponivel !== null) return $disponivel;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='whatsapp_instances'");
        return $disponivel = ((int) $stmt->fetchColumn() === 1);
    } catch (Throwable) {
        return $disponivel = false;
    }
}

/** Mostra apenas esquema e host de uma URL — o caminho pode conter identificadores da instância. */
function cfgUrlMascarada(string $url): string
{
    if ($url === '') return '—';
    $partes = parse_url($url);
    if ($partes === false || empty($partes['host'])) return 'valor inválido';
    return ($partes['scheme'] ?? 'https') . '://' . $partes['host'] . (isset($partes['path']) && $partes['path'] !== '/' ? '/…' : '');
}

function cfgTagSegredo(string $valor): string
{
    return $valor !== '' ? tag('definido', 'green') : tag('ausente', 'amber');
}

function sincronizarVinculosPessoasUsuarios(PDO $pdo): void
{
    $pdo->exec('UPDATE usuarios u JOIN vendedores v ON v.id=COALESCE(u.pessoa_id,u.vendedor_id) SET u.pessoa_id=v.id,u.vendedor_id=v.id');
    $pdo->exec('UPDATE orcamentos o JOIN usuarios u ON COALESCE(u.pessoa_id,u.vendedor_id)=o.vendedor_id SET o.assigned_to_user_id=u.id WHERE o.assigned_to_user_id IS NULL');
    $pdo->exec('UPDATE pedidos p JOIN usuarios u ON COALESCE(u.pessoa_id,u.vendedor_id)=p.vendedor_id SET p.assigned_to_user_id=u.id WHERE p.assigned_to_user_id IS NULL');
    $pdo->exec('UPDATE visitas v JOIN usuarios u ON COALESCE(u.pessoa_id,u.vendedor_id)=v.vendedor_id SET v.assigned_to_user_id=u.id WHERE v.assigned_to_user_id IS NULL');
}

// campos numéricos possíveis dentro de "pol" (união de todas as políticas)
const CAMPOS_POL = [
    'metaRevenda', 'metaAgenciadas', 'pctPecasServ', 'pctAgenciada', 'visitasMes', 'prospecMes',
    'convIR', 'convEquip', 'convDemais', 'bonifKpiPct', 'contatosMin', 'oportunidadesMin', 'contasNovasMin',
    'tetoBonusMes', 'pipelineMin', 'cotacoesMin', 'convMin', 'metaCompressores', 'metaAcessorios',
    'pctComDNB', 'pctMargem', 'metaPropriasAno', 'metaOrientadoAno', 'metaAgenciadasAno', 'tetoAgMes',
    'tetoAgAno', 'tetoPropriasAno', 'tetoOrientAno', 'tetoGlobalAno', 'metaPropostasMes',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'trocar_politica') {
        $id = $_POST['id']; $pid = $_POST['politica'];
        $preset = $politicasArr[$pid] ?? $politicasArr['sem_politica'];
        $pdo->prepare('UPDATE vendedores SET politica=?, pol=? WHERE id=?')->execute([$pid, json_encode($preset), $id]);
        cfgRedirect('Política atualizada.');
    }

    if ($acao === 'salvar_pessoa') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare('SELECT pol FROM vendedores WHERE id=?');
        $stmt->execute([$id]);
        $pol = json_decode($stmt->fetchColumn() ?: '{}', true) ?: [];
        foreach (CAMPOS_POL as $campo) {
            if (isset($_POST['pol'][$campo]) && $_POST['pol'][$campo] !== '') {
                $pol[$campo] = is_numeric($_POST['pol'][$campo]) ? (float) $_POST['pol'][$campo] : $_POST['pol'][$campo];
            }
        }
        $funcao = $_POST['funcao'] === 'suporte' ? 'suporte' : 'vendedor';
        $vinculoId = $_POST['vinculo_id'] ?: null;
        $orientadorId = $_POST['orientador_id'] ?: null;
        $dataContratacao = !empty($_POST['data_contratacao']) ? $_POST['data_contratacao'] . '-01' : null;
        $pdo->prepare('UPDATE vendedores SET funcao=?, vinculo_id=?, orientador_id=?, data_contratacao=?, pol=? WHERE id=?')
            ->execute([$funcao, $vinculoId, $orientadorId, $dataContratacao, json_encode($pol), $id]);
        sincronizarVinculosPessoasUsuarios($pdo);
        cfgRedirect('Parâmetros de ' . $id . ' salvos.');
    }

    if ($acao === 'salvar_apuracao') {
        $mesForm = $_POST['mes_apuracao'] ?? mesAtual();
        foreach (($_POST['ocorrencias'] ?? []) as $vid => $val) {
            if ($val === '') continue;
            $pdo->prepare('INSERT INTO apuracao (mes, vendedor_id, ocorrencias) VALUES (?,?,?) ON DUPLICATE KEY UPDATE ocorrencias=VALUES(ocorrencias)')
                ->execute([$mesForm, $vid, (int) $val]);
        }
        cfgRedirect('Apuração salva.');
    }

    if ($acao === 'carregar_demo') {
        carregarDadosDemo($pdo);
        sincronizarVinculosPessoasUsuarios($pdo);
        cfgRedirect('Simulação de jul/2026 carregada.');
    }

    if ($acao === 'limpar_tudo') {
        limparTudo($pdo);
        cfgRedirect('Base zerada — equipe restaurada ao padrão.');
    }

    if ($acao === 'salvar_instancia' && whatsappInstanciasDisponiveis($pdo)) {
        $externo = trim((string) ($_POST['external_instance_id'] ?? ''));
        $nome = trim((string) ($_POST['nome_instancia'] ?? ''));
        $providerEnviado = is_string($_POST['provider'] ?? null) ? $_POST['provider'] : '';
        $provider = array_key_exists($providerEnviado, PROVEDORES_WHATSAPP) ? $providerEnviado : 'generic';
        $status = ($_POST['status_instancia'] ?? '') === 'inactive' ? 'inactive' : 'active';
        if ($externo === '' || $nome === '') cfgRedirect('Informe o identificador externo e o nome da instância.', 'integracoes');
        $pdo->prepare('INSERT INTO whatsapp_instances (external_instance_id, name, provider, status) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), provider=VALUES(provider), status=VALUES(status)')
            ->execute([mb_substr($externo, 0, 120), mb_substr($nome, 0, 160), $provider, $status]);
        cfgRedirect('Instância de WhatsApp salva.', 'integracoes');
    }

    if ($acao === 'excluir_instancia' && whatsappInstanciasDisponiveis($pdo)) {
        // As FKs de contatos, conversas e mensagens são ON DELETE CASCADE: só removemos
        // instâncias sem histórico. Instâncias em uso devem ser desativadas, nunca apagadas.
        $idInst = (int) ($_POST['instancia_id'] ?? 0);
        $stmtUso = $pdo->prepare('SELECT (SELECT COUNT(*) FROM whatsapp_conversations WHERE instance_id=?) + (SELECT COUNT(*) FROM whatsapp_contacts WHERE instance_id=?)');
        $stmtUso->execute([$idInst, $idInst]);
        if ((int) $stmtUso->fetchColumn() > 0) {
            cfgRedirect('Instância com contatos ou conversas registrados não pode ser excluída — altere o status para inativa.', 'integracoes');
        }
        $pdo->prepare('DELETE FROM whatsapp_instances WHERE id=?')->execute([$idInst]);
        cfgRedirect('Instância removida.', 'integracoes');
    }

    // --- Integrações editáveis -------------------------------------------------
    // Campos de segredo chegam vazios quando o admin não quer trocá-los: nesse caso
    // preservamos o valor atual em vez de apagá-lo. Para limpar de fato, existe a
    // caixa "remover", que devolve a configuração ao arquivo local do servidor.
    if (in_array($acao, ['salvar_whatsapp', 'salvar_ia', 'salvar_rdstation'], true)) {
        $texto = static fn(string $campo): string => trim((string) ($_POST[$campo] ?? ''));
        $limpar = static fn(string $campo): bool => !empty($_POST['limpar'][$campo]);

        $gravar = function (string $servico, string $chave, string $campo, bool $segredo) use ($pdo, $texto, $limpar, $usuarioAtualId): void {
            if ($limpar($campo)) { salvarIntegracao($pdo, $servico, $chave, null, $segredo, $usuarioAtualId); return; }
            $valor = $texto($campo);
            if ($segredo && $valor === '') return; // em branco = manter o que já está gravado
            salvarIntegracao($pdo, $servico, $chave, $valor === '' ? null : $valor, $segredo, $usuarioAtualId);
        };

        try {
            if ($acao === 'salvar_whatsapp') {
                $gravar('whatsapp', 'send_url', 'wa_send_url', false);
                $gravar('whatsapp', 'instance_name', 'wa_instance_name', false);
                $gravar('whatsapp', 'timeout', 'wa_timeout', false);
                $gravar('whatsapp', 'api_token', 'wa_api_token', true);
                $gravar('whatsapp', 'webhook_token', 'wa_webhook_token', true);
                $gravar('whatsapp', 'cron_token', 'wa_cron_token', true);
                cfgRedirect('Configurações de WhatsApp salvas.', 'integracoes');
            }

            if ($acao === 'salvar_ia') {
                $gravar('ai_vps', 'endpoint', 'ia_endpoint', false);
                $gravar('ai_vps', 'modelo', 'ia_modelo', false);
                $gravar('ai_vps', 'timeout', 'ia_timeout', false);
                $gravar('ai_vps', 'token', 'ia_token', true);
                cfgRedirect('Configurações de IA salvas.', 'integracoes');
            }

            if ($acao === 'salvar_rdstation') {
                $fonte = (string) ($_POST['fonte'] ?? '');
                if (!rdFonteValida($fonte)) cfgRedirect('Fonte RD Station desconhecida.', 'integracoes');
                $sufixo = $fonte === 'rdstation_crm' ? 'crm' : 'mkt';
                $gravar($fonte, 'client_id', 'rd_client_id_' . $sufixo, false);
                $gravar($fonte, 'callback_url', 'rd_callback_' . $sufixo, false);
                $gravar($fonte, 'client_secret', 'rd_client_secret_' . $sufixo, true);
                $gravar($fonte, 'static_token', 'rd_static_token_' . $sufixo, true);
                cfgRedirect(rdFonte($fonte)['rotulo'] . ': configurações salvas.', 'integracoes');
            }
        } catch (SettingsServiceException $e) {
            cfgRedirect($e->getMessage(), 'integracoes');
        }
    }

    if ($acao === 'conectar_rdstation') {
        $fonte = (string) ($_POST['fonte'] ?? '');
        if (!rdFonteValida($fonte)) cfgRedirect('Fonte RD Station desconhecida.', 'integracoes');
        try {
            header('Location: ' . rdUrlAutorizacao($fonte));
            exit;
        } catch (Throwable $e) {
            cfgRedirect($e->getMessage(), 'integracoes');
        }
    }

    if ($acao === 'desconectar_rdstation') {
        $fonte = (string) ($_POST['fonte'] ?? '');
        if (!rdFonteValida($fonte)) cfgRedirect('Fonte RD Station desconhecida.', 'integracoes');
        foreach (['access_token', 'refresh_token', 'expires_at', 'connected_at'] as $chave) {
            salvarIntegracao($pdo, $fonte, $chave, null, false, $usuarioAtualId);
        }
        cfgRedirect(rdFonte($fonte)['rotulo'] . ': conexão removida.', 'integracoes');
    }

}

$msg = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$vendedores = obterVendedores($pdo);
$stmtAp = $pdo->prepare('SELECT vendedor_id, ocorrencias FROM apuracao WHERE mes = ?');
$stmtAp->execute([$mes]);
$apuracaoMes = [];
foreach ($stmtAp->fetchAll() as $a) $apuracaoMes[$a['vendedor_id']] = (int) $a['ocorrencias'];

function numField(array $pes, string $k, string $label): void
{
    $v = $pes['pol'][$k] ?? '';
    echo '<div><label class="block text-xs font-semibold text-slate-600 mb-1">' . e($label) . '</label>'
        . '<input type="number" step="0.01" name="pol[' . e($k) . ']" value="' . e((string) $v) . '" class="' . INPUT_CLS . '"></div>';
}

$instanciasDisponiveis = whatsappInstanciasDisponiveis($pdo);
$instancias = $instanciasDisponiveis
    ? $pdo->query('SELECT id, external_instance_id, name, provider, status FROM whatsapp_instances ORDER BY name')->fetchAll()
    : [];

// Nome propositalmente específico: layout_header.php declara um $abas próprio
// (o menu lateral) no mesmo escopo e sobrescreveria qualquer variável homônima.
$abasConfig = [
    'simulacao'   => 'Simulação & Demonstração',
    'apuracao'    => 'Apuração Mensal',
    'politicas'   => 'Políticas por Pessoa',
    'integracoes' => 'Integrações e Tokens',
    'acesso'      => 'Acesso e Suporte',
];

$tabAtual = 'cfg';
include __DIR__ . '/includes/layout_header.php';
?>

<?php if ($msg): ?><div class="dnb-alert dnb-alert--success"><?= e($msg) ?></div><?php endif; ?>

<div class="dnb-tabs" role="tablist" aria-label="Seções de configuração" data-dnb-tabs="dnb-cfg-tab">
  <?php foreach ($abasConfig as $chave => $rotulo): ?>
    <button type="button" role="tab" class="dnb-tabs__tab<?= $chave === 'simulacao' ? ' is-active' : '' ?>" id="tabbtn-<?= e($chave) ?>" aria-controls="tab-<?= e($chave) ?>" aria-selected="<?= $chave === 'simulacao' ? 'true' : 'false' ?>" tabindex="<?= $chave === 'simulacao' ? '0' : '-1' ?>" data-tab-target="<?= e($chave) ?>"><?= e($rotulo) ?></button>
  <?php endforeach; ?>
</div>

<section class="dnb-tabs__panel" role="tabpanel" id="tab-simulacao" aria-labelledby="tabbtn-simulacao" data-tab-panel="simulacao">

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Simulação com dados de demonstração</span></div>
  <div class="p-4">
    <div class="text-sm text-slate-600 mb-3">Carrega jul/2026 com as políticas reais em ação, para testar o sistema com dados de exemplo. <b>Substitui os dados comerciais atuais</b> (orçamentos, pedidos, visitas e apuração) e restaura a equipe ao padrão.</div>
    <div class="flex gap-2">
      <form method="post" onsubmit="return confirm('Isso substitui todos os dados comerciais atuais pela simulação. Continuar?');"><?= csrfInput() ?><input type="hidden" name="acao" value="carregar_demo"><button class="<?= BTN_P ?>" style="background:<?= NAVY ?>;">Carregar simulação</button></form>
      <form method="post" onsubmit="return confirm('Isso apaga TODOS os orçamentos, pedidos, visitas e apuração, e restaura a equipe ao padrão. Continuar?');"><?= csrfInput() ?><input type="hidden" name="acao" value="limpar_tudo"><button class="<?= BTN_S ?>">Limpar tudo (zerar base)</button></form>
    </div>
  </div>
</div>

</section>

<section class="dnb-tabs__panel" role="tabpanel" id="tab-apuracao" aria-labelledby="tabbtn-apuracao" data-tab-panel="apuracao">

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Apuração mensal · <?= e(rotuloMes($mes)) ?> (ocorrências operacionais)</span></div>
  <div class="p-4">
    <div class="text-xs text-slate-500 mb-2">Os KPIs de bonificação são apurados automaticamente pelos dados do CRM (veja o Painel). Aqui a diretoria lança apenas desconformidades documentais — cada ocorrência reduz a bonificação em 5%, cumulativa com as reduções automáticas, limitada a 100%.</div>

    <form method="get" class="flex items-end gap-2 flex-wrap mb-4 border-b border-slate-200 pb-4">
      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1" for="mes-apuracao">Mês de apuração</label>
        <input id="mes-apuracao" type="month" name="mes" value="<?= e($mes) ?>" class="<?= INPUT_CLS ?>">
      </div>
      <button class="<?= BTN_S ?>">Carregar mês</button>
      <span class="text-xs text-slate-500">Exibindo <b><?= e(rotuloMes($mes)) ?></b>.</span>
    </form>

    <form method="post">
      <?= csrfInput() ?>
      <input type="hidden" name="acao" value="salvar_apuracao">
      <input type="hidden" name="mes_apuracao" value="<?= e($mes) ?>">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-xs text-slate-500 border-b border-slate-200"><th class="py-2 pr-2">Pessoa</th><th class="pr-2">Ocorrências operacionais (−5% cada)</th></tr></thead>
        <tbody>
          <?php foreach ($vendedores as $v): if (!in_array($v['politica'], ['vendedor_ir', 'suporte_ir', 'vendedor_externo'], true)) continue; ?>
            <tr class="border-b border-slate-100">
              <td class="py-2 pr-2 font-semibold" style="color:<?= NAVY ?>;"><?= e($v['nome']) ?></td>
              <td class="pr-2"><input type="number" min="0" name="ocorrencias[<?= e($v['id']) ?>]" value="<?= e((string) ($apuracaoMes[$v['id']] ?? '')) ?>" class="<?= INPUT_CLS ?> max-w-[110px]"></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <button class="<?= BTN_P ?> mt-3" style="background:<?= NAVY ?>;">Salvar ocorrências</button>
    </form>
  </div>
</div>

</section>

<section class="dnb-tabs__panel" role="tabpanel" id="tab-politicas" aria-labelledby="tabbtn-politicas" data-tab-panel="politicas">

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Políticas por pessoa (parâmetros editáveis)</span></div>
  <div class="p-4">
    <?php foreach ($vendedores as $pes): ?>
      <details class="border border-slate-200 rounded-lg p-3 mb-3" open>
        <summary class="flex items-center justify-between flex-wrap gap-2 mb-2 cursor-pointer">
          <div><span class="font-semibold" style="color:<?= NAVY ?>;"><?= e($pes['nome']) ?></span><span class="text-xs text-slate-400 ml-2"><?= e($pes['email']) ?></span></div>
          <span class="text-xs text-slate-500"><?= e($politicasArr[$pes['politica']]['label'] ?? $pes['politica']) ?></span>
        </summary>
        <div class="flex items-center justify-end flex-wrap gap-2 mb-2">
          <form method="post" class="flex gap-2 items-center flex-wrap">
            <?= csrfInput() ?>
            <input type="hidden" name="acao" value="trocar_politica">
            <input type="hidden" name="id" value="<?= e($pes['id']) ?>">
            <select name="politica" class="<?= INPUT_CLS ?> max-w-[280px]" onchange="this.form.submit()">
              <?php foreach ($politicasArr as $k => $p): ?><option value="<?= e($k) ?>" <?= $pes['politica'] === $k ? 'selected' : '' ?>><?= e($p['label']) ?></option><?php endforeach; ?>
            </select>
          </form>
        </div>

        <form method="post">
          <?= csrfInput() ?>
          <input type="hidden" name="acao" value="salvar_pessoa">
          <input type="hidden" name="id" value="<?= e($pes['id']) ?>">

          <?php if ($pes['politica'] === 'consultor_senior'): ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
              <?php
                numField($pes, 'metaPropriasAno', 'Meta anual próprias (R$)');
                numField($pes, 'metaOrientadoAno', 'Meta anual orientado (R$)');
                numField($pes, 'metaAgenciadasAno', 'Meta anual agenciadas (R$)');
                numField($pes, 'metaPropostasMes', 'Meta propostas/mês (R$)');
                numField($pes, 'pctAgenciada', '% s/ comissão DNB (agenc.)');
                numField($pes, 'tetoAgMes', 'Teto agenc./mês (R$)');
                numField($pes, 'tetoGlobalAno', 'Teto global/ano (R$)');
                numField($pes, 'visitasMes', 'Visitas/mês');
              ?>
            </div>
          <?php else: ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
              <?php
                numField($pes, 'metaRevenda', $pes['politica'] === 'representante' ? 'Meta mensal (rev+agenc.) (R$)' : 'Meta revenda/mês (R$)');
                if ($pes['politica'] !== 'representante' && $pes['politica'] !== 'interno') numField($pes, 'metaAgenciadas', 'Meta agenciadas/mês (R$)');
                if ($pes['politica'] !== 'representante') numField($pes, 'pctPecasServ', '% peças/serviços');
                if ($pes['politica'] !== 'representante') numField($pes, 'pctAgenciada', '% s/ comissão DNB (agenc.)');

                if ($pes['politica'] === 'interno') {
                    numField($pes, 'tetoBonusMes', 'Teto bônus/mês (R$)');
                    numField($pes, 'prospecMes', 'Prospecções/mês');
                    numField($pes, 'cotacoesMin', 'Cotações mín./mês');
                    numField($pes, 'pipelineMin', 'Pipeline mínimo (R$)');
                } elseif ($pes['politica'] === 'representante') {
                    numField($pes, 'pctComDNB', '% s/ comissão DNB (30)');
                    numField($pes, 'pctMargem', '% s/ margem revenda (5)');
                    numField($pes, 'bonifKpiPct', 'Bonificação KPIs (%)');
                    numField($pes, 'visitasMes', 'Visitas/mês (KPI)');
                    numField($pes, 'cotacoesMin', 'Cotações mín./mês');
                    numField($pes, 'pipelineMin', 'Pipeline mínimo (R$)');
                } else {
                    numField($pes, 'visitasMes', 'Visitas/mês');
                    numField($pes, 'convIR', 'Conversão IR peças/serv (%)');
                    numField($pes, 'convEquip', 'Conversão equipamentos (%)');
                }
              ?>
            </div>
          <?php endif; ?>

          <div class="flex gap-3 mt-3 items-center flex-wrap">
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Função</label>
              <select name="funcao" class="<?= INPUT_CLS ?>"><option value="vendedor" <?= $pes['funcao'] === 'vendedor' ? 'selected' : '' ?>>vendedor</option><option value="suporte" <?= $pes['funcao'] === 'suporte' ? 'selected' : '' ?>>suporte</option></select>
            </div>
            <?php if ($pes['funcao'] === 'suporte'): ?>
              <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Suporte vinculado a</label>
                <select name="vinculo_id" class="<?= INPUT_CLS ?>">
                  <option value="">— sem vínculo (vendas próprias) —</option>
                  <?php foreach ($vendedores as $x): if ($x['funcao'] !== 'vendedor') continue; ?><option value="<?= e($x['id']) ?>" <?= $pes['vinculo_id'] === $x['id'] ? 'selected' : '' ?>><?= e($x['nome']) ?></option><?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Contratação (ativa rampa de 6 meses)</label>
              <input type="month" name="data_contratacao" value="<?= e($pes['data_contratacao'] ? substr($pes['data_contratacao'], 0, 7) : '') ?>" class="<?= INPUT_CLS ?>">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-600 mb-1">Orientado por (Consultor Sênior)</label>
              <select name="orientador_id" class="<?= INPUT_CLS ?>">
                <option value="">—</option>
                <?php foreach ($vendedores as $x): if ($x['politica'] !== 'consultor_senior' || $x['id'] === $pes['id']) continue; ?><option value="<?= e($x['id']) ?>" <?= $pes['orientador_id'] === $x['id'] ? 'selected' : '' ?>><?= e($x['nome']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="text-xs text-slate-400">Meta de propostas derivada: <b><?= fmtBRL(metaPropostasDe($pes)) ?></b>/mês</div>
          </div>
          <button class="<?= BTN_P ?> mt-3" style="background:<?= NAVY ?>;">Salvar alterações</button>
        </form>
      </details>
    <?php endforeach; ?>
    <div class="text-xs text-slate-400">Alterar parâmetros aqui muda o cálculo do mês corrente e seguintes — registre alterações formais com aditivo à política.</div>
  </div>
</div>

</section>

<section class="dnb-tabs__panel" role="tabpanel" id="tab-integracoes" aria-labelledby="tabbtn-integracoes" data-tab-panel="integracoes">

<?php
  $waUrl = whatsappEnv('DNB_WHATSAPP_SEND_URL');
  $waToken = whatsappEnv('DNB_WHATSAPP_API_TOKEN');
  $waWebhook = whatsappEnv('DNB_WHATSAPP_WEBHOOK_TOKEN');
  $waCron = whatsappEnv('DNB_WHATSAPP_CRON_TOKEN');
  $waOk = whatsappProviderConfigurado();
  $host = integracaoBaseUrl();
  $podeGravar = integracaoTabelaDisponivel($pdo);
  $podeSegredo = $podeGravar && configKeyDisponivel();

  /** Campo de segredo: nunca reimprime o valor, só indica se existe e de onde vem. */
  function campoSegredo(string $campo, string $rotulo, string $valorAtual, bool $noBanco, bool $habilitado): void
  {
      $id = 'f-' . $campo;
      echo '<div><label class="block text-xs font-semibold text-slate-600 mb-1" for="' . e($id) . '">' . e($rotulo) . '</label>'
        . '<div class="flex gap-2 items-center">'
        . '<input id="' . e($id) . '" type="password" name="' . e($campo) . '" value="" autocomplete="new-password" '
        . 'placeholder="' . ($valorAtual !== '' ? e(integracaoSegredoMascarado($valorAtual)) : 'não definido') . '" '
        . ($habilitado ? '' : 'disabled ') . 'class="' . INPUT_CLS . '">'
        . '<button type="button" class="' . BTN_S . ' whitespace-nowrap" data-toggle-secret="' . e($id) . '" aria-pressed="false">Mostrar</button>'
        . '</div>'
        . '<div class="flex items-center justify-between gap-2 mt-1">'
        . '<span class="text-xs text-slate-500">' . ($valorAtual === '' ? 'Ausente.' : ($noBanco ? 'Gravado nesta tela.' : 'Vindo do arquivo do servidor.')) . ' Em branco mantém o atual.</span>'
        . ($noBanco ? '<label class="text-xs text-slate-500 whitespace-nowrap"><input type="checkbox" name="limpar[' . e($campo) . ']" value="1"> remover</label>' : '')
        . '</div></div>';
  }

  /** Campo de texto comum, com indicação de origem do valor exibido. */
  function campoTexto(string $campo, string $rotulo, string $valorAtual, bool $noBanco, string $dica = '', string $tipo = 'text'): void
  {
      $id = 'f-' . $campo;
      echo '<div><label class="block text-xs font-semibold text-slate-600 mb-1" for="' . e($id) . '">' . e($rotulo) . '</label>'
        . '<input id="' . e($id) . '" type="' . e($tipo) . '" name="' . e($campo) . '" value="' . e($valorAtual) . '" class="' . INPUT_CLS . '">'
        . '<div class="flex items-center justify-between gap-2 mt-1">'
        . '<span class="text-xs text-slate-500">' . ($dica !== '' ? e($dica) : ($valorAtual === '' ? 'Ausente.' : ($noBanco ? 'Gravado nesta tela.' : 'Vindo do arquivo do servidor.'))) . '</span>'
        . ($noBanco ? '<label class="text-xs text-slate-500 whitespace-nowrap"><input type="checkbox" name="limpar[' . e($campo) . ']" value="1"> remover</label>' : '')
        . '</div></div>';
  }
?>

<?php if (!$podeGravar): ?>
  <div class="dnb-alert dnb-alert--warning">Instale <code>sql/migrations/20260810_system_integrations.sql</code> no phpMyAdmin para editar tokens e APIs por esta tela. Até lá, os valores continuam vindo de <code>whatsapp.local.php</code> e das variáveis de ambiente do cPanel.</div>
<?php elseif (!configKeyDisponivel()): ?>
  <div class="dnb-alert dnb-alert--warning">Defina <code>DNB_CONFIG_KEY</code> em <code>whatsapp.local.php</code> (ou nas variáveis de ambiente do cPanel) para gravar segredos. Sem ela os campos de token ficam bloqueados — nenhum segredo é gravado em texto puro no banco.</div>
<?php endif; ?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Conexão WhatsApp</span><?= $waOk ? tag('Provedor configurado', 'green') : tag('Provedor não configurado', 'amber') ?></div>
  <div class="p-4">
    <p class="text-sm text-slate-600">Evolution API, Z-API ou webhook genérico. O que for salvo aqui tem precedência sobre <code>whatsapp.local.php</code> e sobre as variáveis de ambiente; marcar <b>remover</b> devolve o campo ao valor do servidor.</p>
    <form method="post" class="mt-4">
      <?= csrfInput() ?>
      <input type="hidden" name="acao" value="salvar_whatsapp">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php
          campoTexto('wa_send_url', 'URL da API / instância', $waUrl, integracaoVemDoBanco('whatsapp', 'send_url'), '', 'url');
          campoTexto('wa_instance_name', 'Nome da instância', integracaoValor('whatsapp', 'instance_name'), integracaoVemDoBanco('whatsapp', 'instance_name'), 'Rótulo de referência desta conexão.');
          campoSegredo('wa_api_token', 'API Key / Secret Token', $waToken, integracaoVemDoBanco('whatsapp', 'api_token'), $podeSegredo);
          campoSegredo('wa_webhook_token', 'Token do webhook de entrada', $waWebhook, integracaoVemDoBanco('whatsapp', 'webhook_token'), $podeSegredo);
          campoSegredo('wa_cron_token', 'Token dos Cron Jobs', $waCron, integracaoVemDoBanco('whatsapp', 'cron_token'), $podeSegredo);
          campoTexto('wa_timeout', 'Timeout (segundos)', whatsappEnv('DNB_WHATSAPP_TIMEOUT', '30'), integracaoVemDoBanco('whatsapp', 'timeout'), '', 'number');
        ?>
      </div>
      <button class="<?= BTN_P ?> mt-4" style="background:<?= NAVY ?>;" <?= $podeGravar ? '' : 'disabled' ?>>Salvar configurações de WhatsApp</button>
    </form>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="px-4 pt-4"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Instâncias de WhatsApp</span></div>
  <div class="p-4">
    <?php if (!$instanciasDisponiveis): ?>
      <div class="dnb-alert dnb-alert--warning">Instale <code>sql/migrations/20260809_whatsapp_rbac.sql</code> no phpMyAdmin para administrar instâncias.</div>
    <?php else: ?>
      <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Instâncias de WhatsApp registradas">
        <table class="w-full text-sm min-w-[760px]">
          <thead><tr class="text-left text-slate-500"><th class="p-2">Identificador externo</th><th class="p-2">Nome</th><th class="p-2">Provedor</th><th class="p-2">Status</th><th class="p-2">Ação</th></tr></thead>
          <tbody>
            <?php foreach ($instancias as $inst): $fid = 'inst-' . (int) $inst['id']; ?>
              <tr class="border-t border-slate-200">
                <td class="p-2">
                  <form id="<?= $fid ?>" method="post"><?= csrfInput() ?><input type="hidden" name="acao" value="salvar_instancia"><input type="hidden" name="external_instance_id" value="<?= e($inst['external_instance_id']) ?>"></form>
                  <code class="text-xs"><?= e($inst['external_instance_id']) ?></code>
                </td>
                <td class="p-2"><label class="sr-only" for="nome-<?= (int) $inst['id'] ?>">Nome da instância</label><input form="<?= $fid ?>" id="nome-<?= (int) $inst['id'] ?>" type="text" maxlength="160" name="nome_instancia" value="<?= e($inst['name']) ?>" class="<?= INPUT_CLS ?>"></td>
                <td class="p-2"><label class="sr-only" for="prov-<?= (int) $inst['id'] ?>">Provedor</label><select form="<?= $fid ?>" id="prov-<?= (int) $inst['id'] ?>" name="provider" class="<?= INPUT_CLS ?>"><?php foreach (PROVEDORES_WHATSAPP as $pk => $pl): ?><option value="<?= e($pk) ?>" <?= $inst['provider'] === $pk ? 'selected' : '' ?>><?= e($pl) ?></option><?php endforeach; ?></select></td>
                <td class="p-2"><label class="sr-only" for="st-<?= (int) $inst['id'] ?>">Status</label><select form="<?= $fid ?>" id="st-<?= (int) $inst['id'] ?>" name="status_instancia" class="<?= INPUT_CLS ?>"><option value="active" <?= $inst['status'] === 'active' ? 'selected' : '' ?>>ativa</option><option value="inactive" <?= $inst['status'] === 'inactive' ? 'selected' : '' ?>>inativa</option></select></td>
                <td class="p-2 whitespace-nowrap">
                  <button form="<?= $fid ?>" class="<?= BTN_S ?>">Salvar</button>
                  <form method="post" class="inline" onsubmit="return confirm('Remover esta instância? Só é possível se ela não tiver contatos ou conversas registrados.');"><?= csrfInput() ?><input type="hidden" name="acao" value="excluir_instancia"><input type="hidden" name="instancia_id" value="<?= (int) $inst['id'] ?>"><button class="<?= BTN_S ?>">Excluir</button></form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$instancias): ?><tr><td colspan="5" class="p-4 text-center text-slate-400">Nenhuma instância registrada.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <form method="post" class="grid grid-cols-1 md:grid-cols-5 gap-3 mt-4 items-end border-t border-slate-200 pt-4">
        <?= csrfInput() ?>
        <input type="hidden" name="acao" value="salvar_instancia">
        <div><label class="block text-xs font-semibold text-slate-600 mb-1" for="nova-ext">Identificador externo</label><input id="nova-ext" type="text" maxlength="120" name="external_instance_id" required class="<?= INPUT_CLS ?>"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1" for="nova-nome">Nome</label><input id="nova-nome" type="text" maxlength="160" name="nome_instancia" required class="<?= INPUT_CLS ?>"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1" for="nova-prov">Provedor</label><select id="nova-prov" name="provider" class="<?= INPUT_CLS ?>"><?php foreach (PROVEDORES_WHATSAPP as $pk => $pl): ?><option value="<?= e($pk) ?>"><?= e($pl) ?></option><?php endforeach; ?></select></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1" for="nova-st">Status</label><select id="nova-st" name="status_instancia" class="<?= INPUT_CLS ?>"><option value="active">ativa</option><option value="inactive">inativa</option></select></div>
        <div><button class="<?= BTN_P ?> w-full" style="background:<?= NAVY ?>;">Adicionar instância</button></div>
      </form>
      <p class="text-xs text-slate-500 mt-3">O identificador externo é a chave da instância no provedor — salvar um identificador já existente atualiza o registro. A sincronização por QR Code é feita no painel do provedor.</p>
    <?php endif; ?>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Conexão IA &amp; RAG (VPS)</span><?= iaConfigurada() ? tag('VPS configurada', 'green') : tag('VPS não configurada', 'amber') ?></div>
  <div class="p-4">
    <p class="text-sm text-slate-600">O CRM chama a VPS por HTTPS com Bearer Token, nos contratos <code>/api/v1/ai/route-and-process</code> e <code>/api/v1/ai/generate-followup</code>. O modelo abaixo é <b>registro do que foi combinado com a VPS</b> — quem aplica o modelo, a temperatura e o índice RAG é ela. Limite de prompt: <?= (int) AI_MAX_PROMPT_LENGTH ?> caracteres.</p>
    <form method="post" class="mt-4">
      <?= csrfInput() ?>
      <input type="hidden" name="acao" value="salvar_ia">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php
          campoTexto('ia_endpoint', 'URL do endpoint da VPS', iaEndpoint(), integracaoVemDoBanco('ai_vps', 'endpoint'), 'Precisa ser HTTPS. Ex.: https://vps-ia.seu-dominio.com', 'url');
          campoSegredo('ia_token', 'Bearer Token / API Key da VPS', iaToken(), integracaoVemDoBanco('ai_vps', 'token'), $podeSegredo);
          campoTexto('ia_modelo', 'Modelo padrão de LLM', iaModeloPadrao(), integracaoVemDoBanco('ai_vps', 'modelo'), 'Ex.: gpt-4o, deepseek-r1, claude-sonnet-4-5. Informativo.');
          campoTexto('ia_timeout', 'Timeout (segundos)', (string) iaTimeout(), integracaoVemDoBanco('ai_vps', 'timeout'), 'Entre 5 e 180.', 'number');
        ?>
      </div>
      <button class="<?= BTN_P ?> mt-4" style="background:<?= NAVY ?>;" <?= $podeGravar ? '' : 'disabled' ?>>Salvar configurações de IA</button>
    </form>
  </div>
</div>

<?php foreach (RDSTATION_FONTES as $fonte => $meta):
  $sufixo = $fonte === 'rdstation_crm' ? 'crm' : 'mkt';
  $conectado = rdConectado($fonte);
  $expirado = $conectado && rdTokenExpirado($fonte);
?>
<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;"><?= e($meta['rotulo']) ?></span>
    <?= $conectado ? tag($expirado ? 'Conectado · token vencido' : 'Conectado', $expirado ? 'amber' : 'green') : tag('Não conectado', 'amber') ?>
  </div>
  <div class="p-4">
    <p class="text-sm text-slate-600">Fonte de coleta independente: os dados fluem da RD Station para o CRM. Cadastre esta URL de callback na aplicação criada no portal do desenvolvedor da RD.</p>

    <form method="post" class="mt-4">
      <?= csrfInput() ?>
      <input type="hidden" name="acao" value="salvar_rdstation">
      <input type="hidden" name="fonte" value="<?= e($fonte) ?>">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php
          campoTexto('rd_client_id_' . $sufixo, 'Client ID', rdClientId($fonte), integracaoVemDoBanco($fonte, 'client_id'));
          campoSegredo('rd_client_secret_' . $sufixo, 'Client Secret', rdClientSecret($fonte), integracaoVemDoBanco($fonte, 'client_secret'), $podeSegredo);
          campoTexto('rd_callback_' . $sufixo, 'URL de callback (OAuth Redirect)', rdCallbackUrl($fonte), integracaoVemDoBanco($fonte, 'callback_url'), 'Cole exatamente este valor no cadastro da aplicação na RD.', 'url');
          campoSegredo('rd_static_token_' . $sufixo, 'Token estático de instância (alternativa ao OAuth)', rdTokenEstatico($fonte), integracaoVemDoBanco($fonte, 'static_token'), $podeSegredo);
        ?>
      </div>
      <button class="<?= BTN_P ?> mt-4" style="background:<?= NAVY ?>;" <?= $podeGravar ? '' : 'disabled' ?>>Salvar <?= e($meta['rotulo']) ?></button>
    </form>

    <div class="flex gap-2 items-center flex-wrap mt-4 border-t border-slate-200 pt-4">
      <form method="post">
        <?= csrfInput() ?>
        <input type="hidden" name="acao" value="conectar_rdstation">
        <input type="hidden" name="fonte" value="<?= e($fonte) ?>">
        <button class="<?= BTN_P ?>" style="background:<?= NAVY ?>;" <?= rdPodeConectar($fonte) ? '' : 'disabled' ?>><?= $conectado ? 'Reconectar' : 'Conectar' ?> com <?= e($meta['rotulo']) ?></button>
      </form>
      <?php if ($conectado): ?>
        <form method="post" onsubmit="return confirm('Remover os tokens desta conexão? A coleta de dados para de funcionar até reconectar.');">
          <?= csrfInput() ?>
          <input type="hidden" name="acao" value="desconectar_rdstation">
          <input type="hidden" name="fonte" value="<?= e($fonte) ?>">
          <button class="<?= BTN_S ?>">Desconectar</button>
        </form>
      <?php endif; ?>
      <span class="text-xs text-slate-500">
        <?php if (!rdPodeConectar($fonte)): ?>
          Preencha Client ID, Client Secret e callback para habilitar a conexão.
        <?php elseif ($conectado): ?>
          Conectado em <?= e(integracaoValor($fonte, 'connected_at') ?: '—') ?>. Renovação automática por refresh token<?= rdExpiraEm($fonte) !== '' ? ', válido até ' . e(rdExpiraEm($fonte)) : '' ?>.
        <?php else: ?>
          Ao clicar, você é levado à RD Station para autorizar e volta para cá.
        <?php endif; ?>
      </span>
    </div>
  </div>
</div>
<?php endforeach; ?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="px-4 pt-4"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Webhooks e Cron Jobs</span></div>
  <div class="p-4">
    <p class="text-sm text-slate-600">Endereços que o ecossistema consome. Cada um exige o token indicado — os valores nunca são exibidos aqui.</p>
    <dl class="dnb-detail-list">
      <div><dt>Webhook de entrada do provedor</dt><dd><code class="text-xs"><?= e($host) ?>/api/webhooks/whatsapp/index.php</code><div class="text-xs text-slate-500 mt-1">Protegido por <code>DNB_WHATSAPP_WEBHOOK_TOKEN</code> · <?= cfgTagSegredo($waWebhook) ?></div></dd></div>
      <div><dt>Cron · fila de envio do WhatsApp</dt><dd><code class="text-xs"><?= e($host) ?>/api/internal/whatsapp/process_queue.php</code><div class="text-xs text-slate-500 mt-1">Protegido por <code>DNB_WHATSAPP_CRON_TOKEN</code> · <?= cfgTagSegredo($waCron) ?></div></dd></div>
      <div><dt>Cron · motor de follow-up</dt><dd><code class="text-xs"><?= e($host) ?>/api/internal/followup/run.php</code><div class="text-xs text-slate-500 mt-1">Protegido por <code>DNB_WHATSAPP_CRON_TOKEN</code> · regras editáveis em <a href="/cadencia.php" class="font-semibold">Cadência</a></div></dd></div>
      <?php foreach (RDSTATION_FONTES as $fonteCb => $metaCb): ?>
        <div><dt>Callback OAuth · <?= e($metaCb['rotulo']) ?></dt><dd><code class="text-xs"><?= e(rdCallbackUrl($fonteCb)) ?></code><div class="text-xs text-slate-500 mt-1">Cadastre no portal do desenvolvedor da RD Station · <?= rdConectado($fonteCb) ? tag('conectado', 'green') : tag('não conectado', 'amber') ?></div></dd></div>
      <?php endforeach; ?>
    </dl>
  </div>
</div>

</section>

<section class="dnb-tabs__panel" role="tabpanel" id="tab-acesso" aria-labelledby="tabbtn-acesso" data-tab-panel="acesso">

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4"><div class="p-4 flex items-center justify-between gap-4 flex-wrap"><div><h2 class="dnb-section-title">Acesso e hierarquia</h2><p class="text-sm text-slate-500">Papéis, supervisores, vínculos comerciais e status são administrados em um módulo dedicado.</p></div><a href="/usuarios.php" class="<?= BTN_P ?> inline-flex">Gerenciar usuários</a></div></div>

</section>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
