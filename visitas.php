<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/calc.php';
require_once __DIR__ . '/includes/pdf_service.php';
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
    exigirCsrf();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'add') {
        $cliente = trim($_POST['cliente'] ?? '');
        $vendedorId = $_POST['vendedor_id'] ?: null;
        if ($cliente === '' || !$vendedorId) visRedirect('Informe cliente e responsável.');
        if (!in_array($vendedorId, array_column($vendedores, 'id'), true)) { http_response_code(403); exit; }
        $assignedTo = usuarioIdPorVendedor($pdo, $vendedorId) ?: (temPapel(PAPEL_VENDEDOR) ? (int) usuarioLogado()['id'] : null);
        $stmt = $pdo->prepare('INSERT INTO visitas (os,data,cliente,vendedor_id,tipo,contato,setor,resumo,oportunidade,assigned_to_user_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$_POST['os'] ?? '', $_POST['data'] ?: hoje(), $cliente, $vendedorId, $_POST['tipo'] === 'prospeccao' ? 'prospeccao' : 'visita', $_POST['contato'] ?? '', $_POST['setor'] ?? '', '', $_POST['oportunidade'] ?? '', $assignedTo]);
        unset($_SESSION['visita_pdf_prefill']);
        visRedirect('Atividade registrada.');
    }

    if ($acao === 'importar_json') {
        $f = $_FILES['arquivo_json'] ?? null;

        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
            visRedirect('Selecione um arquivo JSON para importar.');
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $mapErro = [
                UPLOAD_ERR_INI_SIZE => 'Arquivo excede o tamanho máximo permitido pelo servidor.',
                UPLOAD_ERR_FORM_SIZE => 'Arquivo excede o tamanho máximo permitido pelo formulário.',
                UPLOAD_ERR_PARTIAL => 'O upload foi interrompido. Tente novamente.',
                UPLOAD_ERR_NO_TMP_DIR => 'Falha interna do servidor ao processar o upload.',
                UPLOAD_ERR_CANT_WRITE => 'Falha interna do servidor ao salvar o arquivo.',
                UPLOAD_ERR_EXTENSION => 'Upload bloqueado por uma extensão do PHP.',
            ];
            visRedirect($mapErro[$f['error']] ?? 'Falha no upload do arquivo.');
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            visRedirect('Falha no upload do arquivo.');
        }
        if ($f['size'] <= 0) {
            visRedirect('O arquivo enviado está vazio.');
        }
        if ($f['size'] > 10 * 1024 * 1024) {
            visRedirect('Arquivo maior que o limite de 10MB.');
        }
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'json') {
            visRedirect('Selecione um arquivo com extensão .json.');
        }

        $conteudo = file_get_contents($f['tmp_name']);
        if ($conteudo === false || trim($conteudo) === '') {
            visRedirect('O arquivo enviado está vazio.');
        }

        try {
            $arr = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            visRedirect('Arquivo JSON inválido: verifique a formatação.');
        }
        if (!is_array($arr)) {
            visRedirect('O JSON deve ser um objeto ou uma lista de visitas.');
        }
        $lista = array_is_list($arr) ? $arr : [$arr];
        if (!$lista) {
            visRedirect('Nenhum item encontrado no arquivo.');
        }

        $n = 0;
        $ignorados = 0;
        foreach ($lista as $v) {
            if (!is_array($v)) { $ignorados++; continue; }
            $cliente = trim($v['cliente'] ?? '');
            if ($cliente === '') { $ignorados++; continue; }
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

        $msgFinal = "$n visita(s) importada(s).";
        if ($ignorados > 0) $msgFinal .= " $ignorados item(ns) ignorado(s) por falta de cliente.";
        visRedirect($msgFinal);
    }

    if ($acao === 'importar_pdf_preview') {
        $f = $_FILES['arquivo_pdf'] ?? null;

        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
            visRedirect('Selecione um arquivo PDF para importar.');
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $mapErro = [
                UPLOAD_ERR_INI_SIZE => 'Arquivo excede o tamanho máximo permitido pelo servidor.',
                UPLOAD_ERR_FORM_SIZE => 'Arquivo excede o tamanho máximo permitido pelo formulário.',
                UPLOAD_ERR_PARTIAL => 'O upload foi interrompido. Tente novamente.',
                UPLOAD_ERR_NO_TMP_DIR => 'Falha interna do servidor ao processar o upload.',
                UPLOAD_ERR_CANT_WRITE => 'Falha interna do servidor ao salvar o arquivo.',
                UPLOAD_ERR_EXTENSION => 'Upload bloqueado por uma extensão do PHP.',
            ];
            visRedirect($mapErro[$f['error']] ?? 'Falha no upload do arquivo.');
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            visRedirect('Falha no upload do arquivo.');
        }
        if ($f['size'] <= 0) {
            visRedirect('O arquivo enviado está vazio.');
        }
        if ($f['size'] > 10 * 1024 * 1024) {
            visRedirect('Arquivo maior que o limite de 10MB.');
        }
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'pdf') {
            visRedirect('Selecione um arquivo com extensão .pdf.');
        }

        try {
            $texto = extrairTextoPdf($f['tmp_name']);
            $_SESSION['visita_pdf_prefill'] = mapearVisitaDeTextoPdf($texto, $f['name']);
        } catch (Throwable $e) {
            visRedirect($e->getMessage());
        }

        visRedirect('Dados extraídos do PDF — confira e ajuste os campos abaixo antes de registrar.');
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
$pdfPrefill = $_SESSION['visita_pdf_prefill'] ?? null;
unset($_SESSION['visita_pdf_prefill']);
$pdfPrefillVendId = '';
if ($pdfPrefill && !empty($pdfPrefill['vendedor'])) {
    $nomePrefill = mb_strtolower(trim($pdfPrefill['vendedor']));
    foreach ($vendedores as $vv) {
        $vn = mb_strtolower($vv['nome']);
        if ($vn === $nomePrefill || explode(' ', $vn)[0] === explode(' ', $nomePrefill)[0]) { $pdfPrefillVendId = $vv['id']; break; }
    }
}
[$filtro, $params] = filtroEscopoAtribuicao($pdo);
$stmt = $pdo->prepare("SELECT * FROM visitas WHERE {$filtro} ORDER BY data DESC,id DESC");
$stmt->execute($params); $visitas = $stmt->fetchAll();

$tabAtual = 'vis';
include __DIR__ . '/includes/layout_header.php';
?>

<?php if ($msg): ?>
<div class="text-xs text-slate-600 mb-3 bg-slate-50 border border-slate-200 rounded-md px-3 py-2"><?= e($msg) ?></div>
<?php endif; ?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Registrar atividade comercial (visita / prospecção)</span></div>
  <div class="p-4">
    <div class="text-xs text-slate-500 mb-3">Prospecção de novo cliente é a atividade do canal interno (meta: 40/mês).</div>
    <?php if ($pdfPrefill): ?>
    <div class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2 mb-3">Dados extraídos do PDF — confira e ajuste os campos antes de registrar.</div>
    <?php endif; ?>
    <form method="post"><?= csrfInput() ?>
      <input type="hidden" name="acao" value="add">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Tipo</label>
          <select name="tipo" class="<?= INPUT_CLS ?>"><option value="visita">Visita comercial</option><option value="prospeccao">Prospecção — novo cliente</option></select>
        </div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Nº OS / referência</label><input name="os" value="<?= e($pdfPrefill['os'] ?? '') ?>" class="<?= INPUT_CLS ?>" placeholder="4391"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Data</label><input type="date" name="data" value="<?= e(($pdfPrefill['data'] ?? '') ?: hoje()) ?>" class="<?= INPUT_CLS ?>"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Responsável</label>
          <select name="vendedor_id" required class="<?= INPUT_CLS ?>"><option value="">—</option><?php foreach ($vendedores as $v): ?><option value="<?= e($v['id']) ?>" <?= $pdfPrefillVendId === $v['id'] ? 'selected' : '' ?>><?= e($v['nome']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-span-2"><label class="block text-xs font-semibold text-slate-600 mb-1">Cliente</label><input name="cliente" value="<?= e($pdfPrefill['cliente'] ?? '') ?>" required class="<?= INPUT_CLS ?>"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Contato</label><input name="contato" value="<?= e($pdfPrefill['contato'] ?? '') ?>" class="<?= INPUT_CLS ?>"></div>
        <div><label class="block text-xs font-semibold text-slate-600 mb-1">Setor</label><input name="setor" value="<?= e($pdfPrefill['setor'] ?? '') ?>" class="<?= INPUT_CLS ?>"></div>
      </div>
      <div class="mt-3"><label class="block text-xs font-semibold text-slate-600 mb-1">Oportunidades identificadas / desfecho</label><textarea name="oportunidade" class="<?= INPUT_CLS ?> h-20"><?= e($pdfPrefill['oportunidade'] ?? '') ?></textarea></div>
      <div class="mt-3 flex gap-2 items-center">
        <button class="<?= BTN_P ?>" style="background:<?= NAVY ?>;">Registrar</button>
      </div>
    </form>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Importar visitas / relatórios (JSON)</span></div>
  <div class="p-4">
    <div class="text-xs text-slate-500 mb-2">Selecione um arquivo .json com as visitas (campos: os, data, tipo [visita|prospeccao], cliente, vendedor, contato, setor, oportunidade) — aceita objeto único ou lista. Use isto como ponte com sistemas externos de extração de PDF/OS.</div>
    <form method="post" enctype="multipart/form-data"><?= csrfInput() ?>
      <input type="hidden" name="acao" value="importar_json">
      <input type="file" name="arquivo_json" accept=".json,application/json" required class="text-sm">
      <button class="<?= BTN_P ?> mt-2" style="background:<?= NAVY2 ?>;">Importar visitas</button>
    </form>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Importar visita de PDF (OS / relatório)</span></div>
  <div class="p-4">
    <div class="text-xs text-slate-500 mb-2">Selecione um relatório de visita em .pdf. O sistema extrai os dados e pré-preenche o formulário "Registrar atividade" acima para você conferir antes de salvar.</div>
    <form method="post" enctype="multipart/form-data"><?= csrfInput() ?>
      <input type="hidden" name="acao" value="importar_pdf_preview">
      <input type="file" name="arquivo_pdf" accept=".pdf,application/pdf" required class="text-sm">
      <button class="<?= BTN_P ?> mt-2" style="background:<?= NAVY2 ?>;">Extrair dados do PDF</button>
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
            <td class="text-right"><?php if (temPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR)): ?><form method="post" class="inline" onsubmit="return confirm('Excluir esta atividade?');"><?= csrfInput() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $v['id'] ?>"><button class="<?= BTN_S ?> dnb-button--danger" aria-label="Excluir atividade" title="Excluir atividade"><?= dnbIcon('trash', 'dnb-danger-icon') ?></button></form><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!count($visitas)): ?><tr><td colspan="7" class="py-6 text-center text-slate-400 text-sm">Nenhuma atividade registrada.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
