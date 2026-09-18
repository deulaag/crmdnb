<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/calc.php';
require_once __DIR__ . '/includes/seed.php';
require_once __DIR__ . '/includes/whatsapp_provider.php';
require_once __DIR__ . '/includes/ai_client.php';
require_once __DIR__ . '/includes/rd_station_service.php';
require_once __DIR__ . '/includes/rd_discovery_service.php';
require_once __DIR__ . '/includes/rd_sync_service.php';
exigirPapel(PAPEL_ADMINISTRADOR);

$pdo = db();
$mes = $_GET['mes'] ?? mesAtual();
$politicasArr = politicas();

// Provedores aceitos na tabela de instâncias (o adaptador HTTP é genérico).
const PROVEDORES_WHATSAPP = ['generic' => 'Genérico', 'evolution' => 'Evolution API', 'zapi' => 'Z-API'];

function cfgRedirectTipo(string $msg = '', string $aba = '', string $tipo = 'success'): void
{
    if ($msg !== '') {
        $chave = $tipo === 'error' ? 'flash_error' : ($tipo === 'warning' ? 'flash_warning' : 'flash');
        $_SESSION[$chave] = $msg;
    }
    $qs = !empty($_GET['mes']) ? ('?mes=' . urlencode($_GET['mes'])) : '';
    $hash = $aba !== '' ? ('#tab-' . $aba) : '';
    header('Location: /configuracoes.php' . $qs . $hash);
    exit;
}

function cfgRedirect(string $msg = '', string $aba = ''): void
{
    cfgRedirectTipo($msg, $aba, 'success');
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

    if ($acao === 'salvar_rd_base') {
        try {
            rdSalvarPublicBaseUrl((string)($_POST['public_base_url'] ?? ''));
            cfgRedirect('URL pública do CRM salva. Use exatamente os callbacks exibidos no App Publisher do RD Station.', 'rdstation');
        } catch (Throwable $e) {
            cfgRedirectTipo($e->getMessage(), 'rdstation', 'error');
        }
    }

    if ($acao === 'salvar_rd_app') {
        $produto = strtolower(trim((string)($_POST['produto'] ?? '')));
        try {
            rdProdutoConfig($produto);
            rdSalvarCredenciais($produto, (string)($_POST['client_id'] ?? ''), (string)($_POST['client_secret'] ?? ''));
            cfgRedirect(((string)rdProdutoConfig($produto)['label']) . ' configurado. Agora clique em Conectar / autorizar.', 'rdstation');
        } catch (Throwable $e) {
            cfgRedirectTipo($e->getMessage(), 'rdstation', 'error');
        }
    }

    if ($acao === 'testar_rdstation') {
        $produto = strtolower(trim((string)($_POST['produto'] ?? '')));
        try {
            $resultado = rdTestarConexao($produto);
            cfgRedirectTipo($resultado['message'], 'rdstation', $resultado['ok'] ? 'success' : 'warning');
        } catch (Throwable $e) {
            cfgRedirectTipo($e->getMessage(), 'rdstation', 'error');
        }
    }

    if ($acao === 'renovar_rdstation') {
        $produto = strtolower(trim((string)($_POST['produto'] ?? '')));
        try {
            rdRenovarToken($produto);
            $resultado = rdTestarConexao($produto);
            cfgRedirectTipo('Token renovado. ' . $resultado['message'], 'rdstation', $resultado['ok'] ? 'success' : 'warning');
        } catch (Throwable $e) {
            cfgRedirectTipo('Não foi possível renovar o token: ' . $e->getMessage(), 'rdstation', 'error');
        }
    }

    if ($acao === 'desconectar_rdstation') {
        $produto = strtolower(trim((string)($_POST['produto'] ?? '')));
        try {
            $label = (string)rdProdutoConfig($produto)['label'];
            rdDesconectar($produto);
            cfgRedirect($label . ' desconectado. Client ID e Client Secret foram mantidos.', 'rdstation');
        } catch (Throwable $e) {
            cfgRedirectTipo($e->getMessage(), 'rdstation', 'error');
        }
    }

    if ($acao === 'descobrir_rdstation') {
        $produto = strtolower(trim((string)($_POST['produto'] ?? '')));
        try {
            if (!rdDiscoveryDisponivel($pdo)) throw new RuntimeException('Instale a migração da Fase 4 antes de descobrir a estrutura do RD.');
            $resultado = $produto === 'marketing' ? rdDiscoveryRunMarketing($pdo) : ($produto === 'crm' ? rdDiscoveryRunCrm($pdo) : throw new InvalidArgumentException('Produto RD inválido.'));
            $resumo = [];
            foreach (($resultado['summary'] ?? []) as $tipo => $qtd) $resumo[] = $tipo . ': ' . (int)$qtd;
            $mensagem = ($produto === 'marketing' ? 'RD Marketing' : 'RD CRM') . ' analisado. ' . (int)($resultado['total'] ?? 0) . ' metadado(s) encontrado(s).';
            if ($resumo) $mensagem .= ' ' . implode(' · ', $resumo);
            cfgRedirectTipo($mensagem, 'rdstation', (($resultado['status'] ?? 'success') === 'partial') ? 'warning' : 'success');
        } catch (Throwable $e) {
            cfgRedirectTipo('Descoberta RD: ' . $e->getMessage(), 'rdstation', 'error');
        }
    }

    if ($acao === 'salvar_rd_marketing_mapping') {
        try {
            if (!rdDiscoveryDisponivel($pdo)) throw new RuntimeException('Instale a migração da Fase 4.');
            rdSalvarSelecaoMarketing($pdo, (array)($_POST['selected_fields'] ?? []));
            $n = rdSalvarFieldMappings($pdo, (array)($_POST['field_mapping'] ?? []));
            cfgRedirect('Mapeamento do RD Marketing salvo. ' . $n . ' campo(s) com destino definido.', 'rdstation');
        } catch (Throwable $e) {
            cfgRedirectTipo($e->getMessage(), 'rdstation', 'error');
        }
    }

    if ($acao === 'salvar_rd_crm_fields') {
        try {
            if (!rdDiscoveryDisponivel($pdo)) throw new RuntimeException('Instale a migração da Fase 4.');
            $n = rdSalvarFieldMappings($pdo, (array)($_POST['field_mapping'] ?? []));
            cfgRedirect('Mapeamento de campos personalizados do RD CRM salvo. ' . $n . ' campo(s) configurado(s).', 'rdstation');
        } catch (Throwable $e) {
            cfgRedirectTipo($e->getMessage(), 'rdstation', 'error');
        }
    }

    if ($acao === 'salvar_rd_users') {
        try {
            if (!rdDiscoveryDisponivel($pdo)) throw new RuntimeException('Instale a migração da Fase 4.');
            $n = rdSalvarUserMappings($pdo, (array)($_POST['user_mapping'] ?? []));
            cfgRedirect('Mapeamento de usuários RD salvo. ' . $n . ' usuário(s) revisado(s).', 'rdstation');
        } catch (Throwable $e) {
            cfgRedirectTipo($e->getMessage(), 'rdstation', 'error');
        }
    }

    if ($acao === 'salvar_rd_pipelines') {
        try {
            if (!rdDiscoveryDisponivel($pdo)) throw new RuntimeException('Instale a migração da Fase 4.');
            $n = rdSalvarPipelineMappings($pdo, (array)($_POST['pipeline_mapping'] ?? []));
            cfgRedirect('Funis e etapas do RD CRM salvos. ' . $n . ' etapa(s) revisada(s).', 'rdstation');
        } catch (Throwable $e) {
            cfgRedirectTipo($e->getMessage(), 'rdstation', 'error');
        }
    }

    if ($acao === 'iniciar_rd_first_sync') {
        try {
            $job = rdSyncCriarJob($pdo, (int)(usuarioLogado()['id'] ?? 0));
            $_SESSION['flash'] = 'Sincronização criada. O Painel CMD vai processar os dados em lotes.';
            header('Location: /configuracoes.php?rd_job=' . (int)$job['id'] . '#tab-rdstation');
            exit;
        } catch (Throwable $e) {
            cfgRedirectTipo($e->getMessage(), 'rdstation', 'error');
        }
    }

}

$msg = $_SESSION['flash'] ?? '';
$msgErro = $_SESSION['flash_error'] ?? '';
$msgAviso = $_SESSION['flash_warning'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_error'], $_SESSION['flash_warning']);

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
    'rdstation'    => 'RD Station',
    'integracoes' => 'Integrações e Tokens',
    'acesso'      => 'Acesso e Suporte',
];

$tabAtual = 'cfg';
include __DIR__ . '/includes/layout_header.php';
?>

<?php if ($msg): ?><div class="dnb-alert dnb-alert--success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msgAviso): ?><div class="dnb-alert dnb-alert--warning"><?= e($msgAviso) ?></div><?php endif; ?>
<?php if ($msgErro): ?><div class="dnb-alert dnb-alert--error"><?= e($msgErro) ?></div><?php endif; ?>

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

<section class="dnb-tabs__panel" role="tabpanel" id="tab-rdstation" aria-labelledby="tabbtn-rdstation" data-tab-panel="rdstation">

<?php
  $rdBaseUrl = rdPublicBaseUrl();
  $rdMarketing = rdEstadoProduto('marketing');
  $rdCrm = rdEstadoProduto('crm');
  $rdProdutosTela = ['marketing' => $rdMarketing, 'crm' => $rdCrm];
?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2">
    <div><span class="dnb-eyebrow">Fase 3</span><h2 class="dnb-section-title">Aplicativos e OAuth do RD Station</h2></div>
    <?= ($rdMarketing['connected'] && $rdCrm['connected']) ? tag('2 produtos conectados','green') : tag('Configuração em andamento','amber') ?>
  </div>
  <div class="p-4">
    <p class="text-sm text-slate-600">RD Station Marketing e RD Station CRM usam <b>credenciais separadas</b>. Esta fase autentica os dois produtos e mantém os tokens no arquivo local protegido do servidor. Nenhuma sincronização completa de contatos, empresas ou negociações é executada aqui.</p>
    <form method="post" class="grid md:grid-cols-[1fr_auto] gap-3 items-end mt-4">
      <?= csrfInput() ?><input type="hidden" name="acao" value="salvar_rd_base">
      <label><span class="block text-xs font-semibold text-slate-600 mb-1">URL pública HTTPS do CRM</span><input type="url" name="public_base_url" required value="<?= e($rdBaseUrl) ?>" placeholder="https://crm.suaempresa.com.br" class="<?= INPUT_CLS ?>"></label>
      <button class="<?= BTN_P ?>">Salvar URL pública</button>
    </form>
    <p class="text-xs text-slate-500 mt-2">A URL pública é usada para montar os callbacks. Se o domínio do CRM mudar, atualize aqui e também no App Publisher antes de reconectar.</p>
  </div>
</div>

<div class="grid xl:grid-cols-2 gap-4 mb-4">
<?php foreach($rdProdutosTela as $produto => $rd): $rdCfg = rdProdutoConfig($produto); ?>
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-4 pt-4 flex items-start justify-between gap-3">
      <div><span class="dnb-eyebrow"><?= $produto === 'marketing' ? 'Aquisição e relacionamento' : 'Pipeline comercial' ?></span><h2 class="dnb-section-title"><?= e($rd['label']) ?></h2></div>
      <?php
        if($rd['connected']) echo tag('Conectado','green');
        elseif($rd['configured']) echo tag('Pronto para autorizar','blue');
        else echo tag('Aplicativo não configurado','amber');
      ?>
    </div>
    <div class="p-4">
      <form method="post" class="grid gap-3">
        <?= csrfInput() ?><input type="hidden" name="acao" value="salvar_rd_app"><input type="hidden" name="produto" value="<?= e($produto) ?>">
        <label><span class="block text-xs font-semibold text-slate-600 mb-1">Client ID</span><input name="client_id" required autocomplete="off" maxlength="255" value="<?= e(rdClientId($produto)) ?>" class="<?= INPUT_CLS ?>"></label>
        <label><span class="block text-xs font-semibold text-slate-600 mb-1">Client Secret</span><input type="password" name="client_secret" autocomplete="new-password" maxlength="1024" class="<?= INPUT_CLS ?>" placeholder="<?= rdClientSecret($produto) !== '' ? 'Definido — deixe em branco para manter' : 'Informe o Client Secret' ?>"></label>
        <button class="<?= BTN_S ?>">Salvar credenciais do aplicativo</button>
      </form>

      <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3">
        <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Callback para cadastrar no App Publisher</span>
        <code class="block text-xs mt-2 break-all select-all"><?= e($rd['callback_url']) ?></code>
      </div>

      <dl class="dnb-detail-list mt-3">
        <div><dt>Client Secret</dt><dd><?= cfgTagSegredo(rdClientSecret($produto)) ?></dd></div>
        <div><dt>Refresh token</dt><dd><?= $rd['refresh_available'] ? tag('armazenado','green') : tag('ausente','amber') ?></dd></div>
        <div><dt>Access token</dt><dd><?= $rd['access_available'] ? ($rd['access_expired'] ? tag('expirado · renovável','amber') : tag('ativo','green')) : tag('ausente','amber') ?></dd></div>
        <div><dt>Expiração access token</dt><dd><?= $rd['access_expires_at'] ? e(date('d/m/Y H:i', (int)$rd['access_expires_at'])) : '—' ?></dd></div>
        <div><dt>Última renovação</dt><dd><?= e($rd['last_refresh_at'] ?: '—') ?></dd></div>
        <div><dt>Último teste</dt><dd><?= e($rd['last_test_at'] ?: '—') ?><?= $rd['last_test_message'] ? '<small class="block mt-1">'.e($rd['last_test_message']).'</small>' : '' ?></dd></div>
      </dl>

      <div class="flex gap-2 flex-wrap mt-4">
        <?php if($rd['configured']): ?><a class="<?= BTN_P ?>" href="<?= e($rd['connect_path']) ?>"><?= $rd['connected'] ? 'Reconectar / reautorizar' : 'Conectar / autorizar' ?></a><?php endif; ?>
        <?php if($rd['connected']): ?>
          <form method="post"><?= csrfInput() ?><input type="hidden" name="acao" value="testar_rdstation"><input type="hidden" name="produto" value="<?= e($produto) ?>"><button class="<?= BTN_S ?>">Testar conexão</button></form>
          <form method="post"><?= csrfInput() ?><input type="hidden" name="acao" value="renovar_rdstation"><input type="hidden" name="produto" value="<?= e($produto) ?>"><button class="<?= BTN_S ?>">Renovar token agora</button></form>
          <form method="post" onsubmit="return confirm('Desconectar <?= e($rd['label']) ?>? Os tokens serão apagados, mas as credenciais do aplicativo serão mantidas.');"><?= csrfInput() ?><input type="hidden" name="acao" value="desconectar_rdstation"><input type="hidden" name="produto" value="<?= e($produto) ?>"><button class="<?= BTN_S ?>">Desconectar</button></form>
        <?php endif; ?>
      </div>

      <p class="text-xs text-slate-500 mt-3"><?php if($produto==='marketing'): ?>O teste usa a leitura dos campos de contatos. A Fase 4 usa essa mesma conexão para descobrir campos padrão e personalizados e permitir o mapeamento.<?php else: ?>O teste usa a listagem de usuários da API CRM v2. A Fase 4 descobre usuários, funis, etapas, campos personalizados, fontes, motivos de perda, segmentos e campanhas.<?php endif; ?></p>
    </div>
  </section>
<?php endforeach; ?>
</div>

<?php include __DIR__ . '/includes/rd_station_admin_panel.php'; ?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="p-4">
    <h2 class="dnb-section-title">Sequência de configuração no App Publisher</h2>
    <ol class="mt-3 text-sm text-slate-600 space-y-2 list-decimal pl-5">
      <li>Crie um aplicativo para <b>RD Station Marketing</b> e outro para <b>RD Station CRM</b>.</li>
      <li>Cadastre em cada aplicativo exatamente o callback mostrado acima.</li>
      <li>Copie Client ID e Client Secret para esta tela e salve.</li>
      <li>Clique em <b>Conectar / autorizar</b>, escolha a conta RD correta e aprove as permissões.</li>
      <li>Volte a esta tela e use <b>Testar conexão</b>. A Fase 3 termina com os dois produtos autenticados.</li>
    </ol>
    <div class="dnb-alert dnb-alert--info mt-4">Não use token legado do CRM nem API Key do Marketing para esta integração principal. O fluxo desta arquitetura é OAuth 2.0 porque precisaremos consultar dados e metadados nos dois produtos.</div>
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
  $host = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'seu-dominio.com.br');
?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Conexão WhatsApp</span><?= $waOk ? tag('Provedor configurado', 'green') : tag('Provedor não configurado', 'amber') ?></div>
  <div class="p-4">
    <p class="text-sm text-slate-600">Os segredos do provedor não ficam no banco nem no repositório: são constantes de <code>whatsapp.local.php</code> ou variáveis de ambiente do cPanel. Esta tela apenas confirma o que está definido e administra as instâncias registradas.</p>
    <dl class="dnb-detail-list">
      <div><dt>URL de envio (DNB_WHATSAPP_SEND_URL)</dt><dd><?= e(cfgUrlMascarada($waUrl)) ?></dd></div>
      <div><dt>Token da API (DNB_WHATSAPP_API_TOKEN)</dt><dd><?= cfgTagSegredo($waToken) ?></dd></div>
      <div><dt>Token do webhook de entrada (DNB_WHATSAPP_WEBHOOK_TOKEN)</dt><dd><?= cfgTagSegredo($waWebhook) ?></dd></div>
      <div><dt>Token do Cron Job (DNB_WHATSAPP_CRON_TOKEN)</dt><dd><?= cfgTagSegredo($waCron) ?></dd></div>
      <div><dt>Timeout de requisição</dt><dd><?= e(whatsappEnv('DNB_WHATSAPP_TIMEOUT', '30')) ?>s</dd></div>
    </dl>
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
    <p class="text-sm text-slate-600">O CRM só conversa com a VPS pelos contratos <code>/api/v1/ai/route-and-process</code> e <code>/api/v1/ai/generate-followup</code>, sempre por HTTPS com Bearer Token. <b>O modelo de LLM, a temperatura e o índice RAG são configurados na própria VPS</b> — o CRM não os envia.</p>
    <dl class="dnb-detail-list">
      <div><dt>Endpoint (DNB_AI_VPS_ENDPOINT)</dt><dd><?= e(cfgUrlMascarada(AI_VPS_ENDPOINT)) ?></dd></div>
      <div><dt>Bearer Token (DNB_AI_VPS_TOKEN)</dt><dd><?= cfgTagSegredo(AI_VPS_TOKEN) ?></dd></div>
      <div><dt>Timeout (DNB_AI_TIMEOUT)</dt><dd><?= (int) AI_REQUEST_TIMEOUT ?>s</dd></div>
      <div><dt>Limite de prompt</dt><dd><?= (int) AI_MAX_PROMPT_LENGTH ?> caracteres</dd></div>
    </dl>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="px-4 pt-4"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Webhooks e Cron Jobs</span></div>
  <div class="p-4">
    <p class="text-sm text-slate-600">Endereços que o ecossistema consome. Cada um exige o token indicado — os valores nunca são exibidos aqui.</p>
    <dl class="dnb-detail-list">
      <div><dt>Webhook de entrada do provedor</dt><dd><code class="text-xs"><?= e($host) ?>/api/webhooks/whatsapp/index.php</code><div class="text-xs text-slate-500 mt-1">Protegido por <code>DNB_WHATSAPP_WEBHOOK_TOKEN</code> · <?= cfgTagSegredo($waWebhook) ?></div></dd></div>
      <div><dt>Cron · fila de envio do WhatsApp</dt><dd><code class="text-xs"><?= e($host) ?>/api/internal/whatsapp/process_queue.php</code><div class="text-xs text-slate-500 mt-1">Protegido por <code>DNB_WHATSAPP_CRON_TOKEN</code> · <?= cfgTagSegredo($waCron) ?></div></dd></div>
      <div><dt>Cron · motor de follow-up</dt><dd><code class="text-xs"><?= e($host) ?>/api/internal/followup/run.php</code><div class="text-xs text-slate-500 mt-1">Protegido por <code>DNB_WHATSAPP_CRON_TOKEN</code> · regras editáveis em <a href="/cadencia.php" class="font-semibold">Cadência</a></div></dd></div>
    </dl>
  </div>
</div>

</section>

<section class="dnb-tabs__panel" role="tabpanel" id="tab-acesso" aria-labelledby="tabbtn-acesso" data-tab-panel="acesso">

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4"><div class="p-4 flex items-center justify-between gap-4 flex-wrap"><div><h2 class="dnb-section-title">Acesso e hierarquia</h2><p class="text-sm text-slate-500">Papéis, supervisores, vínculos comerciais e status são administrados em um módulo dedicado.</p></div><a href="/usuarios.php" class="<?= BTN_P ?> inline-flex">Gerenciar usuários</a></div></div>

</section>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
