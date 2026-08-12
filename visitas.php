<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/calc.php';
exigirPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_VENDEDOR);

$pdo = db();
$mes = $_GET['mes'] ?? mesAtual();
$vendedores = filtrarVendedoresDaEquipe($pdo, obterVendedores($pdo));

function visRedirect(string $msg = ''): void
{
    if ($msg !== '') $_SESSION['flash'] = $msg;
    $qs = !empty($_GET['mes']) ? ('?mes=' . urlencode($_GET['mes'])) : '';
    header('Location: /visitas.php' . $qs);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'add') {
        $cliente = trim($_POST['cliente'] ?? '');
        $vendedorId = $_POST['vendedor_id'] ?: null;
        if ($cliente === '' || !$vendedorId) visRedirect('Informe cliente e responsável.');
        if (!in_array($vendedorId, array_column($vendedores, 'id'), true)) { http_response_code(403); exit; }
        $assignedTo = usuarioIdPorVendedor($pdo, $vendedorId) ?: (temPapel(PAPEL_VENDEDOR) ? (int) usuarioLogado()['id'] : null);
        $stmt = $pdo->prepare('INSERT INTO visitas (os,data,cliente,vendedor_id,tipo,contato,setor,resumo,oportunidade,assigned_to_user_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$_POST['os'] ?? '', $_POST['data'] ?: hoje(), $cliente, $vendedorId, $_POST['tipo'] === 'prospeccao' ? 'prospeccao' : 'visita', $_POST['contato'] ?? '', $_POST['setor'] ?? '', '', $_POST['oportunidade'] ?? '', $assignedTo]);
        visRedirect('Atividade registrada.');
    }

    if ($acao === 'importar_json') {
        try {
            $arr = json_decode($_POST['json_imp'] ?? '', true, 512, JSON_THROW_ON_ERROR);
            $lista = array_is_list($arr) ? $arr : [$arr];
            $n = 0;
            foreach ($lista as $v) {
                $cliente = trim($v['cliente'] ?? '');
                if ($cliente === '') continue;
                $nomeVend = mb_strtolower(trim($v['vendedor'] ?? ''));
                $vendId = null;
                foreach ($vendedores as $vv) {
                    $vn = mb_strtolower($vv['nome']);
                    if ($nomeVend !== '' && ($vn === $nomeVend || explode(' ', $vn)[0] === explode(' ', $nomeVend)[0])) { $vendId = $vv['id']; break; }
                }
                $tipo = str_starts_with(mb_strtolower($v['tipo'] ?? 'visita'), 'prosp') ? 'prospeccao' : 'visita';
                $assignedTo = usuarioIdPorVendedor($pdo, $vendId) ?: (temPapel(PAPEL_VENDEDOR) ? (int) usuarioLogado()['id'] : null);
                $pdo->prepare('INSERT INTO visitas (os,data,cliente,vendedor_id,tipo,contato,setor,resumo,oportunidade,assigned_to_user_id) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$v['os'] ?? '', $v['data'] ?? hoje(), $cliente, $vendId, $tipo, $v['contato'] ?? '', $v['setor'] ?? '', '', $v['oportunidade'] ?? ($v['comentario'] ?? ''), $assignedTo]);
                $n++;
            }
            visRedirect("$n visita(s) importada(s).");
        } catch (Throwable $e) {
            visRedirect('JSON inválido.');
        }
    }

    if ($acao === 'excluir') {
        if (!temPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR)) { http_response_code(403); exit; }
        if (!podeAcessarRegistroAtribuido($pdo, 'visitas', (int) $_POST['id'])) { http_response_code(403); exit; }
        $pdo->prepare('DELETE FROM visitas WHERE id=?')->execute([(int) $_POST['id']]);
        visRedirect();
    }
}

$msg = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
[$filtro, $params] = filtroEscopoAtribuicao($pdo);
$stmt = $pdo->prepare("SELECT * FROM visitas WHERE {$filtro} ORDER BY data DESC,id DESC");
$stmt->execute($params); $visitas = $stmt->fetchAll();

$tabAtual = 'vis';
include __DIR__ . '/includes/layout_header.php';
?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Registrar atividade comercial (visita / prospecção)</span></div>
  <div class="p-4">
    <div class="text-xs text-slate-500 mb-3">Prospecção de novo cliente é a atividade do canal interno (meta: 40/mês).</div>
    <form method="post">
      <input type="hidden" name="acao" value="add">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Tipo</label>
          <select name="tipo" class="<?= INPUT_CLS ?>"><option value="visita">Visita comercial</option><option value="prospeccao">Prospecção — novo cliente</option></select>
        </div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Nº OS / referência</label><input name="os" class="<?= INPUT_CLS ?>" placeholder="4391"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Data</label><input type="date" name="data" value="<?= e(hoje()) ?>" class="<?= INPUT_CLS ?>"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Responsável</label>
          <select name="vendedor_id" required class="<?= INPUT_CLS ?>"><option value="">—</option><?php foreach ($vendedores as $v): ?><option value="<?= e($v['id']) ?>"><?= e($v['nome']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-span-2"><label class="block text-xs font-semibold text-slate-600 mb-1">Cliente</label><input name="cliente" required class="<?= INPUT_CLS ?>"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Contato</label><input name="contato" class="<?= INPUT_CLS ?>"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Setor</label><input name="setor" class="<?= INPUT_CLS ?>"></div>
      </div>
      <div class="mt-3"><label class="block text-xs font-semibold text-slate-600 mb-1">Oportunidades identificadas / desfecho</label><textarea name="oportunidade" class="<?= INPUT_CLS ?> h-20"></textarea></div>
      <div class="mt-3 flex gap-2 items-center">
        <button class="<?= BTN_P ?>" style="background:<?= NAVY ?>;">Registrar</button>
        <?php if ($msg): ?><span class="text-xs text-slate-500"><?= e($msg) ?></span><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Importar visitas / relatórios (JSON)</span></div>
  <div class="p-4">
    <div class="text-xs text-slate-500 mb-2">Cole o JSON com as visitas (campos: os, data, tipo [visita|prospeccao], cliente, vendedor, contato, setor, oportunidade) — aceita lista. Use isto como ponte com sistemas externos de extração de PDF/OS.</div>
    <form method="post">
      <input type="hidden" name="acao" value="importar_json">
      <textarea name="json_imp" class="<?= INPUT_CLS ?> h-20 font-mono text-xs" placeholder='[{"os":"4391","data":"2026-07-15","tipo":"visita","cliente":"Cliente","vendedor":"Nome","oportunidade":"..."}]'></textarea>
      <button class="<?= BTN_P ?> mt-2" style="background:<?= NAVY2 ?>;">Importar visitas</button>
    </form>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Atividades registradas (<?= count($visitas) ?>)</span></div>
  <div class="p-4">
    <div class="overflow-x-auto">
      <table class="w-full text-sm min-w-[680px]">
        <thead><tr class="text-left text-xs text-slate-500 border-b border-slate-200">
          <th class="py-2 pr-2">Data</th><th class="pr-2">Tipo</th><th class="pr-2">OS</th><th class="pr-2">Cliente</th><th class="pr-2">Responsável</th><th class="pr-2">Oportunidade</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($visitas as $v): ?>
          <tr class="border-b border-slate-100 align-top">
            <td class="py-2 pr-2 text-slate-500"><?= e($v['data']) ?></td>
            <td class="pr-2"><?= $v['tipo'] === 'prospeccao' ? tag('Prospecção', 'blue') : tag('Visita') ?></td>
            <td class="pr-2 font-mono text-xs"><?= e($v['os']) ?></td>
            <td class="pr-2 font-semibold" style="color:<?= NAVY ?>;"><?= e($v['cliente']) ?><div class="text-xs font-normal text-slate-400"><?= e($v['contato']) ?></div></td>
            <td class="pr-2"><?= e(nomeVendedor($vendedores, $v['vendedor_id'])) ?></td>
            <td class="pr-2 text-xs text-slate-600 max-w-[280px]"><?= e($v['oportunidade'] ?: '—') ?></td>
            <td class="text-right"><?php if (temPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR)): ?><form method="post" class="inline" onsubmit="return confirm('Excluir esta atividade?');"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $v['id'] ?>"><button class="<?= BTN_S ?> dnb-button--danger" aria-label="Excluir atividade" title="Excluir atividade"><?= dnbIcon('trash', 'dnb-danger-icon') ?></button></form><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($visitas)): ?><tr><td colspan="7" class="py-6 text-center text-slate-400 text-sm">Nenhuma atividade registrada.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
