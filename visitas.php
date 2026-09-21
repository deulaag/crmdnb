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

$pdo=db();
$mes=$_GET['mes']??mesAtual();
$vendedores=filtrarVendedoresDaEquipe($pdo,obterVendedores($pdo));

function visRedirect(string $msg=''): void
{
    if($msg!=='')$_SESSION['flash']=$msg;
    $qs=!empty($_GET['mes'])?('?mes='.urlencode((string)$_GET['mes'])):'';
    header('Location: /visitas.php'.$qs);exit;
}

function encontrarVendedorVisita(array $vendedores,string $nome): ?string
{
    $nome=mb_strtolower(trim($nome));if($nome==='')return null;
    foreach($vendedores as $v){
        $vn=mb_strtolower(trim((string)$v['nome']));
        if($vn===$nome||explode(' ',$vn)[0]===explode(' ',$nome)[0])return (string)$v['id'];
    }
    return null;
}

function limparLeituraOsPendente(): void
{
    $pendente=$_SESSION['visita_pdf_prefill']??null;
    if(is_array($pendente)&&!empty($pendente['pdf_meta']['storage_name']))excluirPdfOsSeguro((string)$pendente['pdf_meta']['storage_name']);
    unset($_SESSION['visita_pdf_prefill']);
}

/** A visita e a oportunidade só são persistidas quando a ação add é confirmada pelo botão da tela. */
function registrarVisitaEAbrirOportunidade(PDO $pdo,array $vendedores,array $dados,array $template,?string $vendedorId,string $tipo,array $pdfMeta=[]): array
{
    if(!recursosVisitaOportunidadeDisponiveis($pdo))throw new RuntimeException('Execute sql/migrations/20260910_crm_directory_visits_taskboard.sql antes de registrar a visita.');
    if($pdfMeta&&!recursosPdfOsDisponiveis($pdo))throw new RuntimeException('Execute sql/migrations/20260910_os_pdf_taskboard_metrics.sql para anexar o PDF original à oportunidade.');

    $cliente=trim((string)($dados['cliente']??''));
    if($cliente==='')throw new InvalidArgumentException('Preencha NOME/CPNJ com a empresa/cliente antes de registrar.');
    if(!$vendedorId)throw new InvalidArgumentException('Selecione o responsável CRM antes de registrar.');
    if(!in_array($vendedorId,array_column($vendedores,'id'),true))throw new RuntimeException('Responsável fora do seu escopo.');
    $assignedTo=usuarioIdPorVendedor($pdo,$vendedorId)?:(int)usuarioLogado()['id'];
    $data=trim((string)($dados['data']??''))?:hoje();
    $tipo=$tipo==='prospeccao'?'prospeccao':'visita';
    $templateJson=templateOsJson($template);

    $pdo->beginTransaction();
    if(recursosPdfOsDisponiveis($pdo)){
        $stmt=$pdo->prepare('INSERT INTO visitas (os,data,cliente,vendedor_id,tipo,contato,setor,resumo,oportunidade,template_data_json,pdf_storage_name,pdf_original_name,pdf_sha256,assigned_to_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            (string)($dados['os']??''),$data,$cliente,$vendedorId,$tipo,(string)($dados['contato']??''),(string)($dados['setor']??''),
            (string)($dados['resumo']??''),(string)($dados['oportunidade']??''),$templateJson,
            ($pdfMeta['storage_name']??'')?:null,($pdfMeta['original_name']??'')?:null,($pdfMeta['sha256']??'')?:null,$assignedTo,
        ]);
    }else{
        $stmt=$pdo->prepare('INSERT INTO visitas (os,data,cliente,vendedor_id,tipo,contato,setor,resumo,oportunidade,template_data_json,assigned_to_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([(string)($dados['os']??''),$data,$cliente,$vendedorId,$tipo,(string)($dados['contato']??''),(string)($dados['setor']??''),(string)($dados['resumo']??''),(string)($dados['oportunidade']??''),$templateJson,$assignedTo]);
    }
    $visitId=(int)$pdo->lastInsertId();
    $leadId=criarOportunidadeDaVisita($pdo,$visitId,array_merge($dados,['data'=>$data]),$template,$vendedorId,$assignedTo,$pdfMeta);
    if($leadId<=0)throw new RuntimeException('Não foi possível abrir a oportunidade após a confirmação da visita.');
    $pdo->commit();
    return ['visit_id'=>$visitId,'lead_id'=>$leadId];
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    exigirCsrf();$acao=(string)($_POST['acao']??'');

    if($acao==='add'){
        $vendedorId=trim((string)($_POST['vendedor_id']??''))?:null;
        $pendente=$_SESSION['visita_pdf_prefill']??null;$base=[];$pdfMeta=[];
        $token=(string)($_POST['preview_token']??$_POST['pdf_token']??'');
        if($token!==''&&is_array($pendente)&&!empty($pendente['preview_token'])&&hash_equals((string)$pendente['preview_token'],$token)){
            $base=is_array($pendente['template']??null)?$pendente['template']:[];
            $pdfMeta=is_array($pendente['pdf_meta']??null)?$pendente['pdf_meta']:[];
        }
        $template=templateOsDoFormulario($_POST,$base);
        if(trim((string)($template['vendedor']??''))===''&&$vendedorId)$template['vendedor']=nomeVendedor($vendedores,$vendedorId);
        $dados=dadosBasicosDaOs($template);
        try{
            $res=registrarVisitaEAbrirOportunidade($pdo,$vendedores,$dados,$template,$vendedorId,(string)($_POST['tipo']??'visita'),$pdfMeta);
            unset($_SESSION['visita_pdf_prefill']);
            visRedirect('Visita registrada e oportunidade #'.$res['lead_id'].' aberta após sua confirmação'.($pdfMeta?' com o PDF original anexado.':'.'));
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();visRedirect($e->getMessage());}
    }

    if($acao==='importar_json'){
        $f=$_FILES['arquivo_json']??null;
        if(!$f||$f['error']===UPLOAD_ERR_NO_FILE)visRedirect('Selecione um arquivo JSON para ler.');
        if($f['error']!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name']))visRedirect('Falha no upload do arquivo JSON.');
        if($f['size']<=0||$f['size']>10*1024*1024)visRedirect('Arquivo JSON vazio ou maior que 10MB.');
        if(strtolower(pathinfo((string)$f['name'],PATHINFO_EXTENSION))!=='json')visRedirect('Selecione um arquivo com extensão .json.');
        try{$arr=json_decode((string)file_get_contents($f['tmp_name']),true,512,JSON_THROW_ON_ERROR);}catch(Throwable){visRedirect('Arquivo JSON inválido: verifique a formatação.');}
        if(!is_array($arr))visRedirect('O JSON deve conter uma Ordem de Serviço.');
        if(array_is_list($arr)){
            if(count($arr)!==1||!is_array($arr[0]??null))visRedirect('Para garantir conferência antes da criação, envie uma Ordem de Serviço por vez no JSON.');
            $arr=$arr[0];
        }
        limparLeituraOsPendente();
        $prefill=mapearVisitaDeJson($arr,(string)$f['name']);
        $prefill['preview_token']=bin2hex(random_bytes(24));$prefill['pdf_token']=$prefill['preview_token'];$prefill['pdf_meta']=[];
        $_SESSION['visita_pdf_prefill']=$prefill;
        visRedirect('JSON lido: '.(int)$prefill['campos_extraidos'].' de '.(int)$prefill['campos_template'].' campo(s) preenchido(s). Nenhuma visita ou oportunidade foi criada; confira a OS e clique em Registrar visita e abrir oportunidade.');
    }

    if($acao==='importar_pdf_preview'){
        $f=$_FILES['arquivo_pdf']??null;
        if(!$f||$f['error']===UPLOAD_ERR_NO_FILE)visRedirect('Selecione um arquivo PDF para ler.');
        if($f['error']!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name']))visRedirect('Falha no upload do PDF.');
        if($f['size']<=0||$f['size']>15*1024*1024)visRedirect('PDF vazio ou maior que 15MB.');
        if(strtolower(pathinfo((string)$f['name'],PATHINFO_EXTENSION))!=='pdf')visRedirect('Selecione um arquivo com extensão .pdf.');
        try{
            $documento=extrairConteudoPdfOs($f['tmp_name']);
            $prefill=mapearVisitaDeDocumentoPdf($documento,(string)$f['name']);
            limparLeituraOsPendente();
            $prefill['pdf_meta']=armazenarPdfOsUpload($f);
            $prefill['preview_token']=bin2hex(random_bytes(24));$prefill['pdf_token']=$prefill['preview_token'];
            $_SESSION['visita_pdf_prefill']=$prefill;
            visRedirect('PDF lido: '.(int)$prefill['campos_extraidos'].' de '.(int)$prefill['campos_template'].' campo(s) preenchido(s). Nenhuma visita ou oportunidade foi criada; confira todos os campos e clique em Registrar visita e abrir oportunidade.');
        }catch(Throwable $e){visRedirect($e->getMessage());}
    }

    if($acao==='cancel_preview'){
        limparLeituraOsPendente();visRedirect('Leitura descartada. Nenhum registro foi criado.');
    }

    if($acao==='excluir'){
        if(!temPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR)){http_response_code(403);exit;}
        if(!podeAcessarRegistroAtribuido($pdo,'visitas',(int)$_POST['id'])){http_response_code(403);exit;}
        $pdo->prepare('DELETE FROM visitas WHERE id=?')->execute([(int)$_POST['id']]);visRedirect();
    }
}

$msg=$_SESSION['flash']??'';unset($_SESSION['flash']);
$prefill=$_SESSION['visita_pdf_prefill']??null;
$templatePreview=is_array($prefill['template']??null)?$prefill['template']:templateOsVazio();
$prefillVendId=$prefill?encontrarVendedorVisita($vendedores,(string)($prefill['vendedor']??'')):'';
$eventosPreview=is_array($templatePreview['eventos_campo']??null)?$templatePreview['eventos_campo']:[];
if(!$eventosPreview){
    $row=['hora'=>(string)($templatePreview['evento_hora']??''),'evento'=>(string)($templatePreview['evento']??''),'tipo'=>(string)($templatePreview['evento_tipo']??'')];
    $eventosPreview=[array_filter($row,static fn($v)=>trim((string)$v)!=='')?$row:['hora'=>'','evento'=>'','tipo'=>'']];
}
$camposLongos=[
    'descricao_atividade','informacoes_cliente','fotos','relatorio_descritivo','compressores','equipamento_comentario',
    'fabricante','modelos','numero_serie','horimetro','registro_manutencao','pecas_originais','tipo_oleo',
    'informacao_oportunidade','oportunidade_comercial','oportunidade_comentario','relatorio_visita_tecnica','proximos_passos','assinatura_vendedor',
];
[$filtro,$params]=filtroEscopoAtribuicao($pdo);$stmt=$pdo->prepare("SELECT * FROM visitas WHERE {$filtro} ORDER BY data DESC,id DESC");$stmt->execute($params);$visitas=$stmt->fetchAll();
$tabAtual='vis';include __DIR__.'/includes/layout_header.php';
?>

<?php if($msg):?><div class="dnb-alert dnb-alert--success mb-4"><?=e($msg)?></div><?php endif;?>
<?php if(!recursosVisitaOportunidadeDisponiveis($pdo)):?><div class="dnb-alert dnb-alert--warning mb-4">Execute <code>sql/migrations/20260910_crm_directory_visits_taskboard.sql</code>. A leitura continua disponível, mas o botão de registrar precisa dessa estrutura para abrir a oportunidade.</div><?php endif;?>
<?php if(recursosVisitaOportunidadeDisponiveis($pdo)&&!recursosPdfOsDisponiveis($pdo)):?><div class="dnb-alert dnb-alert--warning mb-4">Execute <code>sql/migrations/20260910_os_pdf_taskboard_metrics.sql</code> para anexar o PDF original à oportunidade.</div><?php endif;?>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4 dnb-os-editor">
  <div class="dnb-os-editor__head">
    <div><span class="dnb-eyebrow">Template oficial completo</span><h2 class="dnb-section-title">Ordem de Serviço de Campo Simplificada</h2><p>Use <b>Ler PDF</b> ou <b>Ler JSON</b> apenas para preencher a ficha. <b>Nada é criado durante a leitura.</b> A visita e a oportunidade só são gravadas ao clicar em <b>Registrar visita e abrir oportunidade</b>.</p></div>
    <?php if($prefill):?><div class="dnb-os-extraction-badge"><strong><?= (int)($prefill['campos_extraidos']??0) ?> / <?= (int)($prefill['campos_template']??totalCamposTemplateOs()) ?></strong><span>campos preenchidos na leitura</span></div><?php endif;?>
  </div>

  <div class="p-4">
    <?php if($prefill):?>
      <div class="dnb-alert dnb-alert--warning mb-4"><strong><?=($prefill['fonte']??'')==='pdf'?'PDF':'JSON'?> carregado para conferência: <?=e((string)($prefill['pdf_meta']['original_name']??$prefill['arquivo_original']??''))?></strong><br>Revise cada seção abaixo. Campos vazios continuam visíveis para correção manual. Nenhuma oportunidade existe até a confirmação no botão final.</div>
    <?php endif;?>

    <form method="post" data-os-complete-form>
      <?=csrfInput()?>
      <input type="hidden" name="acao" value="add">
      <?php if($prefill):?><input type="hidden" name="preview_token" value="<?=e((string)($prefill['preview_token']??''))?>"><input type="hidden" name="pdf_token" value="<?=e((string)($prefill['pdf_token']??''))?>"><?php endif;?>

      <section class="dnb-os-form-group dnb-os-form-group--crm">
        <div class="dnb-os-form-group__title"><span>Controle do CRM</span><small>Dados necessários para atribuir a visita antes da confirmação</small></div>
        <div class="dnb-os-form-grid">
          <label><span>Tipo de atividade</span><select name="tipo" class="<?=INPUT_CLS?>"><option value="visita">Visita comercial</option><option value="prospeccao">Prospecção</option></select></label>
          <label><span>Responsável CRM</span><select name="vendedor_id" required class="<?=INPUT_CLS?>"><option value="">Selecione</option><?php foreach($vendedores as $v):?><option value="<?=e($v['id'])?>" <?=$prefillVendId===$v['id']?'selected':''?>><?=e($v['nome'])?></option><?php endforeach;?></select></label>
        </div>
      </section>

      <?php foreach(gruposTemplateOsSimplificada() as $grupo):?>
        <section class="dnb-os-form-group" data-os-section="<?=e($grupo['id'])?>">
          <div class="dnb-os-form-group__title"><span><?=e($grupo['titulo'])?></span><?php if($grupo['id']==='informacao_cliente'):?><small>ITENS VERIFICADOS</small><?php endif;?></div>
          <?php if(!empty($grupo['eventos'])):?>
            <div class="dnb-os-event-editor" data-os-events>
              <?php foreach($eventosPreview as $idx=>$ev):?>
                <div class="dnb-os-event-row" data-os-event-row>
                  <label><span>HORA</span><input class="<?=INPUT_CLS?>" name="eventos_campo[<?=$idx?>][hora]" value="<?=e((string)($ev['hora']??''))?>"></label>
                  <label class="dnb-os-event-row__event"><span>EVENTO</span><textarea class="<?=INPUT_CLS?>" name="eventos_campo[<?=$idx?>][evento]" rows="2"><?=e((string)($ev['evento']??''))?></textarea></label>
                  <label><span>TIPO</span><input class="<?=INPUT_CLS?>" name="eventos_campo[<?=$idx?>][tipo]" value="<?=e((string)($ev['tipo']??''))?>"></label>
                  <button type="button" class="<?=BTN_S?>" data-remove-os-event aria-label="Remover evento">Remover</button>
                </div>
              <?php endforeach;?>
            </div>
            <button type="button" class="<?=BTN_S?> mt-2" data-add-os-event>+ Adicionar evento</button>
          <?php else:?>
            <div class="dnb-os-form-grid">
              <?php foreach($grupo['campos'] as $key):
                if(in_array($key,['evento_hora','evento','evento_tipo'],true))continue;
                $label=camposTemplateOsSimplificada()[$key]??$key;$valor=(string)($templatePreview[$key]??'');$longo=in_array($key,$camposLongos,true);
              ?>
                <label class="<?=$longo?'dnb-os-field--wide':''?>" data-os-field="<?=e($key)?>"><span><?=e($label)?></span>
                  <?php if($longo):?><textarea class="<?=INPUT_CLS?>" name="os_fields[<?=e($key)?>]" rows="3"><?=e($valor)?></textarea><?php else:?><input class="<?=INPUT_CLS?>" name="os_fields[<?=e($key)?>]" value="<?=e($valor)?>"><?php endif;?>
                </label>
              <?php endforeach;?>
            </div>
          <?php endif;?>
        </section>
      <?php endforeach;?>

      <?php if($prefill&&!empty($templatePreview['_raw_text'])):?><details class="dnb-os-read-preview mt-4"><summary>Texto integral extraído do PDF</summary><pre class="dnb-os-raw-text"><?=e((string)$templatePreview['_raw_text'])?></pre></details><?php endif;?>

      <div class="dnb-os-editor__actions">
        <div><strong>Confirmação manual</strong><small>Somente este botão cria a visita e abre a oportunidade.</small></div>
        <button class="<?=BTN_P?>" type="submit">Registrar visita e abrir oportunidade</button>
      </div>
    </form>

    <?php if($prefill):?><form method="post" class="mt-2" onsubmit="return confirm('Descartar os dados lidos?');"><?=csrfInput()?><input type="hidden" name="acao" value="cancel_preview"><button class="<?=BTN_S?>">Descartar leitura</button></form><?php endif;?>
  </div>
</section>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm"><div class="p-4"><h2 class="dnb-section-title">Ler JSON da OS</h2><p class="text-xs text-slate-500 mb-3">O JSON usa exatamente os mesmos campos e seções do template do PDF. A leitura apenas preenche a ficha acima para conferência; não cria visita nem oportunidade.</p><form method="post" enctype="multipart/form-data"><?=csrfInput()?><input type="hidden" name="acao" value="importar_json"><input type="file" name="arquivo_json" accept=".json,application/json" required><button class="<?=BTN_P?> mt-3">Ler JSON</button></form></div></section>
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm"><div class="p-4"><h2 class="dnb-section-title">Ler PDF da OS</h2><p class="text-xs text-slate-500 mb-3">Extrai o conteúdo do PDF oficial por texto e coordenadas, preenche todas as seções acima e guarda o PDF original para anexar somente se você confirmar o registro.</p><form method="post" enctype="multipart/form-data"><?=csrfInput()?><input type="hidden" name="acao" value="importar_pdf_preview"><input type="file" name="arquivo_pdf" accept=".pdf,application/pdf" required><button class="<?=BTN_P?> mt-3">Ler PDF</button></form></div></section>
</div>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4"><div class="px-4 pt-4"><h2 class="dnb-section-title">Atividades registradas (<?=count($visitas)?>)</h2></div><div class="p-4 overflow-x-auto"><table class="w-full text-sm min-w-[760px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Data</th><th>Tipo</th><th>OS</th><th>Empresa</th><th>Responsável</th><th>Oportunidade</th><th></th></tr></thead><tbody><?php foreach($visitas as $v):?><tr class="border-b align-top"><td class="py-2"><?=e($v['data'])?></td><td><?=$v['tipo']==='prospeccao'?tag('Prospecção','blue'):tag('Visita')?></td><td><?=e($v['os'])?></td><td><strong><?=e($v['cliente'])?></strong><small class="block text-slate-400"><?=e($v['contato'])?></small></td><td><?=e(nomeVendedor($vendedores,$v['vendedor_id']))?></td><td class="max-w-[320px] text-xs"><?=e($v['oportunidade']?:'—')?></td><td><?php if(temPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR)):?><form method="post" onsubmit="return confirm('Excluir esta atividade?')"><?=csrfInput()?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?=(int)$v['id']?>"><button class="<?=BTN_S?>">Excluir</button></form><?php endif;?></td></tr><?php endforeach;?><?php if(!$visitas):?><tr><td colspan="7" class="py-6 text-center text-slate-400">Nenhuma atividade registrada.</td></tr><?php endif;?></tbody></table></div></section>

<script>
(function(){
  const host=document.querySelector('[data-os-events]');const add=document.querySelector('[data-add-os-event]');
  if(!host||!add)return;
  function renumber(){host.querySelectorAll('[data-os-event-row]').forEach((row,i)=>row.querySelectorAll('[name]').forEach(el=>{el.name=el.name.replace(/eventos_campo\[\d+\]/,'eventos_campo['+i+']');}));}
  add.addEventListener('click',()=>{const i=host.querySelectorAll('[data-os-event-row]').length;const row=document.createElement('div');row.className='dnb-os-event-row';row.dataset.osEventRow='1';row.innerHTML='<label><span>HORA</span><input class="<?=e(INPUT_CLS)?>" name="eventos_campo['+i+'][hora]"></label><label class="dnb-os-event-row__event"><span>EVENTO</span><textarea class="<?=e(INPUT_CLS)?>" name="eventos_campo['+i+'][evento]" rows="2"></textarea></label><label><span>TIPO</span><input class="<?=e(INPUT_CLS)?>" name="eventos_campo['+i+'][tipo]"></label><button type="button" class="<?=e(BTN_S)?>" data-remove-os-event>Remover</button>';host.appendChild(row);});
  host.addEventListener('click',e=>{const b=e.target.closest('[data-remove-os-event]');if(!b)return;const rows=host.querySelectorAll('[data-os-event-row]');if(rows.length===1){rows[0].querySelectorAll('input,textarea').forEach(x=>x.value='');return;}b.closest('[data-os-event-row]').remove();renumber();});
})();
</script>
<?php include __DIR__.'/includes/layout_footer.php';?>
