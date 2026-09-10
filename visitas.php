<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/calc.php';
require_once __DIR__ . '/includes/pdf_service.php';
require_once __DIR__ . '/includes/os_template_service.php';
require_once __DIR__ . '/includes/os_pdf_storage.php';
require_once __DIR__ . '/includes/visit_opportunity_service.php';
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

function encontrarVendedorVisita(array $vendedores, string $nome): ?string
{
    $nome = mb_strtolower(trim($nome));
    if ($nome === '') return null;
    foreach ($vendedores as $v) {
        $vn = mb_strtolower(trim((string)$v['nome']));
        if ($vn === $nome || explode(' ', $vn)[0] === explode(' ', $nome)[0]) return (string)$v['id'];
    }
    return null;
}

/** @return array{visit_id:int,lead_id:int} */
function registrarVisitaEOpportunity(PDO $pdo, array $vendedores, array $dados, array $template, ?string $vendedorId, string $tipo, array $pdfMeta=[]): array
{
    if (!recursosVisitaOportunidadeDisponiveis($pdo)) throw new RuntimeException('Execute sql/migrations/20260910_crm_directory_visits_taskboard.sql antes de registrar visitas: toda visita deve gerar uma oportunidade aberta.');
    if ($pdfMeta && !recursosPdfOsDisponiveis($pdo)) throw new RuntimeException('Execute sql/migrations/20260910_os_pdf_taskboard_metrics.sql para anexar o PDF original à oportunidade.');

    $cliente = trim((string)($dados['cliente'] ?? ''));
    if ($cliente === '') throw new InvalidArgumentException('Informe a empresa/cliente da visita.');
    if ($vendedorId && !in_array($vendedorId, array_column($vendedores,'id'), true)) throw new RuntimeException('Responsável fora do seu escopo.');
    $assignedTo = usuarioIdPorVendedor($pdo,$vendedorId) ?: (int)usuarioLogado()['id'];
    $data = trim((string)($dados['data'] ?? '')) ?: hoje();
    $tipo = $tipo === 'prospeccao' ? 'prospeccao' : 'visita';
    $templateJson = templateOsJson($template);

    $pdo->beginTransaction();
    if (recursosPdfOsDisponiveis($pdo)) {
        $stmt=$pdo->prepare('INSERT INTO visitas (os,data,cliente,vendedor_id,tipo,contato,setor,resumo,oportunidade,template_data_json,pdf_storage_name,pdf_original_name,pdf_sha256,assigned_to_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            (string)($dados['os']??''),$data,$cliente,$vendedorId,$tipo,(string)($dados['contato']??''),(string)($dados['setor']??''),
            (string)($dados['resumo']??''),(string)($dados['oportunidade']??''),$templateJson,
            ($pdfMeta['storage_name']??'')?:null,($pdfMeta['original_name']??'')?:null,($pdfMeta['sha256']??'')?:null,$assignedTo,
        ]);
    } else {
        $stmt=$pdo->prepare('INSERT INTO visitas (os,data,cliente,vendedor_id,tipo,contato,setor,resumo,oportunidade,template_data_json,assigned_to_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            (string)($dados['os']??''),$data,$cliente,$vendedorId,$tipo,(string)($dados['contato']??''),(string)($dados['setor']??''),
            (string)($dados['resumo']??''),(string)($dados['oportunidade']??''),$templateJson,$assignedTo,
        ]);
    }
    $visitId=(int)$pdo->lastInsertId();
    $leadId=criarOportunidadeDaVisita($pdo,$visitId,array_merge($dados,['data'=>$data]),$template,$vendedorId,$assignedTo,$pdfMeta);
    if ($leadId <= 0) throw new RuntimeException('A visita foi lida, mas não foi possível criar a oportunidade automaticamente.');
    $pdo->commit();
    return ['visit_id'=>$visitId,'lead_id'=>$leadId];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'add') {
        $vendedorId = trim((string)($_POST['vendedor_id'] ?? '')) ?: null;
        $template = [];
        if (!empty($_POST['template_data_json'])) {
            try { $template = json_decode((string)$_POST['template_data_json'],true,512,JSON_THROW_ON_ERROR); } catch(Throwable) { $template=[]; }
        }
        if (!$template) {
            $template = normalizarTemplateOsJson([
                'os'=>$_POST['os']??'', 'data'=>$_POST['data']??'', 'cliente'=>$_POST['cliente']??'',
                'contato'=>$_POST['contato']??'', 'telefone'=>$_POST['telefone']??'', 'email'=>$_POST['email']??'',
                'setor'=>$_POST['setor']??'', 'oportunidade'=>$_POST['oportunidade']??'', 'vendedor'=>nomeVendedor($vendedores,$vendedorId),
            ]);
        }
        $dados = dadosBasicosDaOs($template);
        $dados = array_merge($dados,[
            'os'=>trim((string)($_POST['os']??$dados['os'])),'data'=>trim((string)($_POST['data']??$dados['data'])) ?: hoje(),
            'cliente'=>trim((string)($_POST['cliente']??$dados['cliente'])),'contato'=>trim((string)($_POST['contato']??$dados['contato'])),
            'telefone'=>trim((string)($_POST['telefone']??$dados['telefone'])),'email'=>trim((string)($_POST['email']??$dados['email'])),
            'setor'=>trim((string)($_POST['setor']??$dados['setor'])),'oportunidade'=>trim((string)($_POST['oportunidade']??$dados['oportunidade'])),
        ]);

        $pdfMeta=[];
        $pdfToken=(string)($_POST['pdf_token']??'');
        $pendente=$_SESSION['visita_pdf_prefill']??null;
        if($pdfToken!=='' && is_array($pendente) && !empty($pendente['pdf_token']) && hash_equals((string)$pendente['pdf_token'],$pdfToken)){
            $pdfMeta=(array)($pendente['pdf_meta']??[]);
        }
        try {
            $res=registrarVisitaEOpportunity($pdo,$vendedores,$dados,$template,$vendedorId,(string)($_POST['tipo']??'visita'),$pdfMeta);
            unset($_SESSION['visita_pdf_prefill']);
            visRedirect('Atividade registrada e oportunidade #'.$res['lead_id'].' aberta automaticamente'.($pdfMeta?' com o PDF original anexado.':'.'));
        } catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            visRedirect($e->getMessage());
        }
    }

    if ($acao === 'importar_json') {
        $f=$_FILES['arquivo_json']??null;
        if(!$f || $f['error']===UPLOAD_ERR_NO_FILE)visRedirect('Selecione um arquivo JSON para importar.');
        if($f['error']!==UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']))visRedirect('Falha no upload do arquivo JSON.');
        if($f['size']<=0 || $f['size']>10*1024*1024)visRedirect('Arquivo JSON vazio ou maior que 10MB.');
        if(strtolower(pathinfo($f['name'],PATHINFO_EXTENSION))!=='json')visRedirect('Selecione um arquivo com extensão .json.');
        try{$arr=json_decode((string)file_get_contents($f['tmp_name']),true,512,JSON_THROW_ON_ERROR);}catch(Throwable){visRedirect('Arquivo JSON inválido: verifique a formatação.');}
        if(!is_array($arr))visRedirect('O JSON deve ser um objeto ou uma lista de Ordens de Serviço.');
        $lista=array_is_list($arr)?$arr:[$arr];
        $n=0;$oportunidades=0;$ignorados=0;
        foreach($lista as $item){
            if(!is_array($item)){$ignorados++;continue;}
            $template=normalizarTemplateOsJson($item);
            $dados=dadosBasicosDaOs($template);
            $dados['cliente']=$dados['cliente'] ?: trim((string)($item['cliente']??''));
            $dados['data']=$dados['data'] ?: trim((string)($item['data']??'')) ?: hoje();
            $dados['contato']=$dados['contato'] ?: trim((string)($item['contato']??''));
            $dados['setor']=$dados['setor'] ?: trim((string)($item['setor']??''));
            $dados['oportunidade']=$dados['oportunidade'] ?: trim((string)($item['oportunidade']??$item['comentario']??''));
            if($dados['cliente']===''){$ignorados++;continue;}
            $vendId=encontrarVendedorVisita($vendedores,(string)($dados['vendedor']?:($item['vendedor']??'')));
            try{
                $res=registrarVisitaEOpportunity($pdo,$vendedores,$dados,$template,$vendId,str_starts_with(mb_strtolower((string)($item['tipo']??'visita')),'prosp')?'prospeccao':'visita');
                $n++;if($res['lead_id'])$oportunidades++;
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$ignorados++;error_log('visitas.import.json: '.$e->getMessage());}
        }
        $msg="$n visita(s) importada(s); $oportunidades oportunidade(s) aberta(s) automaticamente.";
        if($ignorados)$msg.=" $ignorados item(ns) ignorado(s).";
        visRedirect($msg);
    }

    if ($acao === 'importar_pdf_preview') {
        $f=$_FILES['arquivo_pdf']??null;
        if(!$f || $f['error']===UPLOAD_ERR_NO_FILE)visRedirect('Selecione um arquivo PDF para ler.');
        if($f['error']!==UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']))visRedirect('Falha no upload do PDF.');
        if($f['size']<=0 || $f['size']>15*1024*1024)visRedirect('PDF vazio ou maior que 15MB.');
        if(strtolower(pathinfo($f['name'],PATHINFO_EXTENSION))!=='pdf')visRedirect('Selecione um arquivo com extensão .pdf.');
        try{
            $texto=extrairTextoPdf($f['tmp_name']);
            $prefill=mapearVisitaDeTextoPdf($texto,$f['name']);
            $antigo=$_SESSION['visita_pdf_prefill']['pdf_meta']['storage_name']??null;
            if($antigo)excluirPdfOsSeguro((string)$antigo);
            $pdfMeta=armazenarPdfOsUpload($f);
            $prefill['pdf_token']=bin2hex(random_bytes(24));
            $prefill['pdf_meta']=$pdfMeta;
            $_SESSION['visita_pdf_prefill']=$prefill;
            visRedirect('PDF lido: '.(int)$prefill['campos_extraidos'].' campo(s)/evento(s) extraído(s). Confira e registre; o arquivo original será anexado à oportunidade.');
        }catch(Throwable $e){visRedirect($e->getMessage());}
    }

    if ($acao === 'excluir') {
        if(!temPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR)){http_response_code(403);exit;}
        if(!podeAcessarRegistroAtribuido($pdo,'visitas',(int)$_POST['id'])){http_response_code(403);exit;}
        $pdo->prepare('DELETE FROM visitas WHERE id=?')->execute([(int)$_POST['id']]);
        visRedirect();
    }
}

$msg=$_SESSION['flash']??'';unset($_SESSION['flash']);
$pdfPrefill=$_SESSION['visita_pdf_prefill']??null;
$pdfPrefillVendId=$pdfPrefill?encontrarVendedorVisita($vendedores,(string)($pdfPrefill['vendedor']??'')):'';
$templatePreview=is_array($pdfPrefill['template']??null)?$pdfPrefill['template']:[];
[$filtro,$params]=filtroEscopoAtribuicao($pdo);
$stmt=$pdo->prepare("SELECT * FROM visitas WHERE {$filtro} ORDER BY data DESC,id DESC");$stmt->execute($params);$visitas=$stmt->fetchAll();
$tabAtual='vis';include __DIR__.'/includes/layout_header.php';
?>
<?php if($msg):?><div class="dnb-alert dnb-alert--success mb-4"><?=e($msg)?></div><?php endif;?>
<?php if(!recursosVisitaOportunidadeDisponiveis($pdo)):?><div class="dnb-alert dnb-alert--warning mb-4">Execute <code>sql/migrations/20260910_crm_directory_visits_taskboard.sql</code> para habilitar Empresa/Contatos, OS completa e criação automática de oportunidades.</div><?php endif;?>
<?php if(recursosVisitaOportunidadeDisponiveis($pdo) && !recursosPdfOsDisponiveis($pdo)):?><div class="dnb-alert dnb-alert--warning mb-4">Execute <code>sql/migrations/20260910_os_pdf_taskboard_metrics.sql</code> para que o PDF original lido fique anexado à oportunidade.</div><?php endif;?>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="px-4 pt-4"><span class="dnb-eyebrow">Padrão oficial</span><h2 class="dnb-section-title">Ordem de Serviço de Campo Simplificada</h2><p class="text-sm text-slate-500">PDF e JSON usam o mesmo template. Toda visita registrada/importada gera uma oportunidade aberta com a OS completa.</p></div>
  <div class="p-4">
    <?php if($pdfPrefill):?>
      <div class="dnb-alert dnb-alert--warning mb-3"><strong>PDF lido: <?=e((string)($pdfPrefill['pdf_meta']['original_name']??$pdfPrefill['arquivo_original']??''))?></strong><br><?= (int)($pdfPrefill['campos_extraidos']??0) ?> campo(s)/evento(s) estruturado(s). O texto integral e o arquivo PDF serão preservados na oportunidade.</div>
    <?php endif;?>
    <form method="post"><?=csrfInput()?><input type="hidden" name="acao" value="add"><?php if($pdfPrefill):?><input type="hidden" name="template_data_json" value="<?=e((string)($pdfPrefill['template_json']??''))?>"><input type="hidden" name="pdf_token" value="<?=e((string)($pdfPrefill['pdf_token']??''))?>"><?php endif;?>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div><label class="block text-xs font-semibold mb-1">Tipo</label><select name="tipo" class="<?=INPUT_CLS?>"><option value="visita">Visita comercial</option><option value="prospeccao">Prospecção</option></select></div>
        <div><label class="block text-xs font-semibold mb-1">Nº OS</label><input name="os" value="<?=e((string)($pdfPrefill['os']??''))?>" class="<?=INPUT_CLS?>"></div>
        <div><label class="block text-xs font-semibold mb-1">Data</label><input type="date" name="data" value="<?=e((string)(($pdfPrefill['data']??'')?:hoje()))?>" class="<?=INPUT_CLS?>"></div>
        <div><label class="block text-xs font-semibold mb-1">Responsável</label><select name="vendedor_id" required class="<?=INPUT_CLS?>"><option value="">Selecione</option><?php foreach($vendedores as $v):?><option value="<?=e($v['id'])?>" <?=$pdfPrefillVendId===$v['id']?'selected':''?>><?=e($v['nome'])?></option><?php endforeach;?></select></div>
        <div class="md:col-span-2"><label class="block text-xs font-semibold mb-1">Empresa / cliente</label><input name="cliente" value="<?=e((string)($pdfPrefill['cliente']??''))?>" required class="<?=INPUT_CLS?>"></div>
        <div><label class="block text-xs font-semibold mb-1">Contato</label><input name="contato" value="<?=e((string)($pdfPrefill['contato']??''))?>" class="<?=INPUT_CLS?>"></div>
        <div><label class="block text-xs font-semibold mb-1">Setor</label><input name="setor" value="<?=e((string)($pdfPrefill['setor']??''))?>" class="<?=INPUT_CLS?>"></div>
        <div><label class="block text-xs font-semibold mb-1">Telefone</label><input name="telefone" value="<?=e((string)($pdfPrefill['telefone']??''))?>" class="<?=INPUT_CLS?>"></div>
        <div><label class="block text-xs font-semibold mb-1">E-mail</label><input type="email" name="email" value="<?=e((string)($pdfPrefill['email']??''))?>" class="<?=INPUT_CLS?>"></div>
        <div class="md:col-span-2"><label class="block text-xs font-semibold mb-1">Oportunidade / próximos passos</label><textarea name="oportunidade" class="<?=INPUT_CLS?> h-20"><?=e((string)($pdfPrefill['oportunidade']??''))?></textarea></div>
      </div>
      <button class="<?=BTN_P?> mt-3">Registrar visita e abrir oportunidade</button>
    </form>

    <?php if($templatePreview):?>
    <details class="dnb-os-read-preview mt-4">
      <summary>Ver tudo que foi extraído do PDF</summary>
      <div class="dnb-os-read-preview__grid">
      <?php foreach(camposTemplateOsSimplificada() as $key=>$label):$valor=trim((string)($templatePreview[$key]??''));if($valor==='')continue;?>
        <div><strong><?=e($label)?></strong><span><?=nl2br(e($valor))?></span></div>
      <?php endforeach;?>
      <?php if(!empty($templatePreview['eventos_campo'])&&is_array($templatePreview['eventos_campo'])):foreach($templatePreview['eventos_campo'] as $idx=>$ev):?>
        <div><strong>Evento em campo <?=($idx+1)?></strong><span><?=e((string)($ev['hora']??''))?> · <?=e((string)($ev['tipo']??''))?><br><?=nl2br(e((string)($ev['evento']??'')))?></span></div>
      <?php endforeach;endif;?>
      </div>
      <?php if(!empty($templatePreview['_raw_text'])):?><details class="mt-3"><summary>Texto integral extraído</summary><pre class="dnb-os-raw-text"><?=e((string)$templatePreview['_raw_text'])?></pre></details><?php endif;?>
    </details>
    <?php endif;?>
  </div>
</section>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
<section class="bg-white rounded-xl border border-slate-200 shadow-sm"><div class="p-4"><h2 class="dnb-section-title">Importar JSON da OS</h2><p class="text-xs text-slate-500 mb-3">Aceita o template completo e também o JSON simples antigo. Cada item importado gera sua oportunidade aberta usando a mesma estrutura do PDF.</p><form method="post" enctype="multipart/form-data"><?=csrfInput()?><input type="hidden" name="acao" value="importar_json"><input type="file" name="arquivo_json" accept=".json,application/json" required><button class="<?=BTN_P?> mt-3">Importar JSON</button></form></div></section>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm"><div class="p-4"><h2 class="dnb-section-title">Ler PDF da OS</h2><p class="text-xs text-slate-500 mb-3">Lê o PDF completo conforme o template oficial, extrai os campos e preserva o arquivo original para anexá-lo à oportunidade.</p><form method="post" enctype="multipart/form-data"><?=csrfInput()?><input type="hidden" name="acao" value="importar_pdf_preview"><input type="file" name="arquivo_pdf" accept=".pdf,application/pdf" required><button class="<?=BTN_P?> mt-3">Ler PDF</button></form></div></section>
</div>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4"><div class="px-4 pt-4"><h2 class="dnb-section-title">Atividades registradas (<?=count($visitas)?>)</h2></div><div class="p-4 overflow-x-auto"><table class="w-full text-sm min-w-[760px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Data</th><th>Tipo</th><th>OS</th><th>Empresa</th><th>Responsável</th><th>Oportunidade</th><th></th></tr></thead><tbody><?php foreach($visitas as $v):?><tr class="border-b align-top"><td class="py-2"><?=e($v['data'])?></td><td><?=$v['tipo']==='prospeccao'?tag('Prospecção','blue'):tag('Visita')?></td><td><?=e($v['os'])?></td><td><strong><?=e($v['cliente'])?></strong><small class="block text-slate-400"><?=e($v['contato'])?></small></td><td><?=e(nomeVendedor($vendedores,$v['vendedor_id']))?></td><td class="max-w-[320px] text-xs"><?=e($v['oportunidade']?:'—')?></td><td><?php if(temPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR)):?><form method="post" onsubmit="return confirm('Excluir esta atividade?')"><?=csrfInput()?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?=(int)$v['id']?>"><button class="<?=BTN_S?>">Excluir</button></form><?php endif;?></td></tr><?php endforeach;?><?php if(!$visitas):?><tr><td colspan="7" class="py-6 text-center text-slate-400">Nenhuma atividade registrada.</td></tr><?php endif;?></tbody></table></div></section>
<?php include __DIR__.'/includes/layout_footer.php';?>
