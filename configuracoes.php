<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/calc.php';
require_once __DIR__ . '/includes/seed.php';
exigirPapel(PAPEL_ADMINISTRADOR);

$pdo = db();
$mes = $_GET['mes'] ?? mesAtual();
$politicasArr = politicas();

function cfgRedirect(string $msg = ''): void
{
    if ($msg !== '') $_SESSION['flash'] = $msg;
    $qs = !empty($_GET['mes']) ? ('?mes=' . urlencode($_GET['mes'])) : '';
    header('Location: /configuracoes.php' . $qs);
    exit;
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
        $pdo->exec('UPDATE orcamentos o JOIN usuarios u ON u.vendedor_id=o.vendedor_id SET o.assigned_to_user_id=u.id WHERE o.assigned_to_user_id IS NULL');
        $pdo->exec('UPDATE pedidos p JOIN usuarios u ON u.vendedor_id=p.vendedor_id SET p.assigned_to_user_id=u.id WHERE p.assigned_to_user_id IS NULL');
        $pdo->exec('UPDATE visitas v JOIN usuarios u ON u.vendedor_id=v.vendedor_id SET v.assigned_to_user_id=u.id WHERE v.assigned_to_user_id IS NULL');
        cfgRedirect('Simulação de jul/2026 carregada.');
    }

    if ($acao === 'limpar_tudo') {
        limparTudo($pdo);
        cfgRedirect('Base zerada — equipe restaurada ao padrão.');
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

$tabAtual = 'cfg';
include __DIR__ . '/includes/layout_header.php';
?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Simulação com dados de demonstração</span></div>
  <div class="p-4">
    <div class="text-sm text-slate-600 mb-3">Carrega jul/2026 com as políticas reais em ação, para testar o sistema com dados de exemplo. <b>Substitui os dados comerciais atuais</b> (orçamentos, pedidos, visitas e apuração) e restaura a equipe ao padrão.</div>
    <?php if ($msg): ?><div class="text-xs font-semibold mb-2" style="color:<?= NAVY ?>;"><?= e($msg) ?></div><?php endif; ?>
    <div class="flex gap-2">
      <form method="post" onsubmit="return confirm('Isso substitui todos os dados comerciais atuais pela simulação. Continuar?');"><input type="hidden" name="acao" value="carregar_demo"><button class="<?= BTN_P ?>" style="background:<?= NAVY ?>;">Carregar simulação</button></form>
      <form method="post" onsubmit="return confirm('Isso apaga TODOS os orçamentos, pedidos, visitas e apuração, e restaura a equipe ao padrão. Continuar?');"><input type="hidden" name="acao" value="limpar_tudo"><button class="<?= BTN_S ?>">Limpar tudo (zerar base)</button></form>
    </div>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Apuração mensal · <?= e(rotuloMes($mes)) ?> (ocorrências operacionais)</span></div>
  <div class="p-4">
    <div class="text-xs text-slate-500 mb-2">Os KPIs de bonificação são apurados automaticamente pelos dados do CRM (veja o Painel). Aqui a diretoria lança apenas desconformidades documentais — cada ocorrência reduz a bonificação em 5%, cumulativa com as reduções automáticas, limitada a 100%.</div>
    <form method="post">
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

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Políticas por pessoa (parâmetros editáveis)</span></div>
  <div class="p-4">
    <?php foreach ($vendedores as $pes): ?>
      <div class="border border-slate-200 rounded-lg p-3 mb-3">
        <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
          <div><span class="font-semibold" style="color:<?= NAVY ?>;"><?= e($pes['nome']) ?></span><span class="text-xs text-slate-400 ml-2"><?= e($pes['email']) ?></span></div>
          <form method="post" class="flex gap-2 items-center flex-wrap">
            <input type="hidden" name="acao" value="trocar_politica">
            <input type="hidden" name="id" value="<?= e($pes['id']) ?>">
            <select name="politica" class="<?= INPUT_CLS ?> max-w-[280px]" onchange="this.form.submit()">
              <?php foreach ($politicasArr as $k => $p): ?><option value="<?= e($k) ?>" <?= $pes['politica'] === $k ? 'selected' : '' ?>><?= e($p['label']) ?></option><?php endforeach; ?>
            </select>
          </form>
        </div>

        <form method="post">
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
      </div>
    <?php endforeach; ?>
    <div class="text-xs text-slate-400">Alterar parâmetros aqui muda o cálculo do mês corrente e seguintes — registre alterações formais com aditivo à política.</div>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4"><div class="p-4 flex items-center justify-between gap-4 flex-wrap"><div><h2 class="dnb-section-title">Acesso e hierarquia</h2><p class="text-sm text-slate-500">Papéis, supervisores, vínculos comerciais e status são administrados em um módulo dedicado.</p></div><a href="/usuarios.php" class="<?= BTN_P ?> inline-flex">Gerenciar usuários</a></div></div>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
