<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/crm_phase2_service.php';
exigirPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR);

$pdo=db();$mes=$_GET['mes']??mesAtual();
$erro='';$msg=$_SESSION['flash']??'';unset($_SESSION['flash']);
$disponivel=crmPhase2Disponivel($pdo);

if($_SERVER['REQUEST_METHOD']==='POST'){
    exigirCsrf();
    try{
        if(!$disponivel)throw new RuntimeException('Instale sql/migrations/20260918_crm_phase2_ui_ownership.sql.');
        if(($_POST['acao']??'')==='add'){
            $empresa=criarEmpresaCrmUi($pdo,[
                'name'=>$_POST['name']??'','trade_name'=>$_POST['trade_name']??'','cnpj'=>$_POST['cnpj']??'',
                'email'=>$_POST['primary_email']??'','phone'=>$_POST['phone']??'','website'=>$_POST['website']??'',
                'address'=>$_POST['address']??'','locality'=>$_POST['locality']??'','source'=>'manual'
            ],!empty($_POST['permitir_duplicidade']));
            header('Location: /empresa.php?id='.(int)$empresa['id'].'&criada=1');exit;
        }
    }catch(Throwable $e){$erro=$e->getMessage();}
}
$filtros=['q'=>trim((string)($_GET['q']??'')),'review'=>(string)($_GET['review']??''),'status'=>(string)($_GET['status']??'active')];
$empresas=$disponivel?listarEmpresasCrmUi($pdo,$filtros):[];
$tabAtual='empresas';
include __DIR__.'/includes/layout_header.php';
?>
<?php if(!$disponivel): ?>
<div class="dnb-alert dnb-alert--warning">A Fase 2 precisa da migração <code>sql/migrations/20260918_crm_phase2_ui_ownership.sql</code>.</div>
<?php else: ?>
<?php if($erro): ?><div class="dnb-alert dnb-alert--error"><?= e($erro) ?></div><?php endif; ?>
<?php if($msg): ?><div class="dnb-alert dnb-alert--success"><?= e($msg) ?></div><?php endif; ?>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="px-4 pt-4 flex items-start justify-between gap-3 flex-wrap">
    <div><span class="dnb-eyebrow">Diretório comercial</span><h2 class="dnb-section-title">Empresas</h2><p class="text-sm text-slate-500 mt-1">CNPJ é a referência principal; duplicidades ficam em conferência e nunca são mescladas silenciosamente.</p></div>
    <button type="button" class="<?= BTN_P ?>" data-modal-open="modal-nova-empresa">Nova empresa</button>
  </div>
  <form method="get" class="p-4 grid md:grid-cols-[1fr_180px_180px_auto] gap-2">
    <input type="hidden" name="mes" value="<?= e($mes) ?>">
    <input type="search" name="q" value="<?= e($filtros['q']) ?>" placeholder="Nome, CNPJ ou DNB ID" class="<?= INPUT_CLS ?>">
    <select name="review" class="<?= INPUT_CLS ?>"><option value="">Todas as revisões</option><option value="needs_review" <?= $filtros['review']==='needs_review'?'selected':'' ?>>Precisa conferir</option></select>
    <select name="status" class="<?= INPUT_CLS ?>"><option value="active" <?= $filtros['status']!=='inactive'?'selected':'' ?>>Ativas</option><option value="inactive" <?= $filtros['status']==='inactive'?'selected':'' ?>>Inativas</option></select>
    <button class="<?= BTN_S ?>">Filtrar</button>
  </form>
</section>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm">
  <div class="px-4 pt-4 flex items-center justify-between"><h2 class="dnb-section-title">Cadastro consolidado</h2><span class="text-xs text-slate-500"><?= count($empresas) ?> empresa(s)</span></div>
  <div class="p-4 overflow-x-auto">
    <table class="w-full min-w-[900px] text-sm">
      <thead><tr class="text-left text-xs text-slate-500 border-b"><th class="py-2">Empresa</th><th>DNB ID</th><th>CNPJ</th><th>Contatos</th><th>Negociações</th><th>Pipeline aberto</th><th>Revisão</th><th></th></tr></thead>
      <tbody>
      <?php foreach($empresas as $c): ?>
        <tr class="border-b border-slate-100">
          <td class="py-3"><a class="font-bold" style="color:<?= NAVY ?>" href="/empresa.php?id=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a><?php if(!empty($c['trade_name'])): ?><div class="text-xs text-slate-500"><?= e($c['trade_name']) ?></div><?php endif; ?></td>
          <td><span class="font-mono text-xs"><?= e($c['dnb_id']??'') ?></span></td>
          <td><?= e($c['cnpj']?:'—') ?></td>
          <td><?= (int)$c['contacts_count'] ?></td>
          <td><?= (int)$c['negotiations_count'] ?></td>
          <td><?= fmtBRL($c['open_value']??0) ?></td>
          <td><?= ($c['review_status']??'ok')==='needs_review'?tag('Conferir','amber'):tag('OK','green') ?></td>
          <td class="text-right"><a class="<?= BTN_S ?>" href="/empresa.php?id=<?= (int)$c['id'] ?>">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$empresas): ?><tr><td colspan="8" class="py-8 text-center text-slate-400">Nenhuma empresa encontrada no seu escopo.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<div class="dnb-modal-backdrop hidden" data-modal="modal-nova-empresa" aria-hidden="true">
  <div class="dnb-modal dnb-modal--wide" role="dialog" aria-modal="true" aria-labelledby="nova-empresa-title">
    <div class="dnb-modal__header"><div><span class="dnb-eyebrow">Novo cadastro</span><h2 id="nova-empresa-title">Empresa</h2></div><button class="dnb-icon-button" type="button" data-modal-close aria-label="Fechar">×</button></div>
    <form method="post" class="dnb-modal__body"><?= csrfInput() ?><input type="hidden" name="acao" value="add">
      <div class="grid md:grid-cols-2 gap-3">
        <label><span class="text-xs font-semibold">Razão social *</span><input name="name" required maxlength="200" class="<?= INPUT_CLS ?>"></label>
        <label><span class="text-xs font-semibold">Nome fantasia</span><input name="trade_name" maxlength="200" class="<?= INPUT_CLS ?>"></label>
        <label><span class="text-xs font-semibold">CNPJ</span><input name="cnpj" maxlength="18" class="<?= INPUT_CLS ?>"></label>
        <label><span class="text-xs font-semibold">E-mail principal</span><input type="email" name="primary_email" maxlength="190" class="<?= INPUT_CLS ?>"></label>
        <label><span class="text-xs font-semibold">Telefone</span><input name="phone" maxlength="40" class="<?= INPUT_CLS ?>"></label>
        <label><span class="text-xs font-semibold">Website</span><input name="website" maxlength="255" class="<?= INPUT_CLS ?>"></label>
        <label class="md:col-span-2"><span class="text-xs font-semibold">Endereço</span><input name="address" maxlength="255" class="<?= INPUT_CLS ?>"></label>
        <label class="md:col-span-2"><span class="text-xs font-semibold">Localidade</span><input name="locality" maxlength="180" class="<?= INPUT_CLS ?>"></label>
      </div>
      <label class="mt-3 flex gap-2 items-start text-sm"><input type="checkbox" name="permitir_duplicidade" value="1"><span>Se o CNPJ já existir, criar mesmo assim e marcar para conferência.</span></label>
      <div class="dnb-modal__footer"><button type="button" class="<?= BTN_S ?>" data-modal-close>Cancelar</button><button class="<?= BTN_P ?>">Criar empresa</button></div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__.'/includes/layout_footer.php'; ?>
