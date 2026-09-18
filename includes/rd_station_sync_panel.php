<?php
/** @var PDO $pdo */
$rdSyncReady=rdSyncDisponivel($pdo);
$rdSyncJobs=$rdSyncReady?rdSyncRecentJobs($pdo,10):[];
$rdSelectedJobId=(int)($_GET['rd_job']??0);
if($rdSelectedJobId<=0&&$rdSyncJobs)$rdSelectedJobId=(int)$rdSyncJobs[0]['id'];
$rdSelectedJob=$rdSelectedJobId>0&&$rdSyncReady?rdSyncJob($pdo,$rdSelectedJobId):null;
$rdSelectedCounters=$rdSelectedJob['counters']??rdSyncCounterDefault();
$rdSelectedConflicts=$rdSelectedJob?rdSyncConflicts($pdo,(int)$rdSelectedJob['id'],30):[];
?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4 overflow-hidden">
  <div class="p-4 border-b border-slate-200 flex items-start justify-between gap-4 flex-wrap">
    <div>
      <span class="dnb-eyebrow">Fase 5 · primeira sincronização real</span>
      <h2 class="dnb-section-title">Empresas, Contatos e Negociações</h2>
      <p class="text-sm text-slate-500 mt-1 max-w-3xl">Processamento incremental em lotes: primeiro todos os contatos do Marketing, depois Empresas e Contatos do CRM e, por fim, Negociações criadas nos últimos 6 meses. IDs externos permanecem em <code>crm_external_links</code>.</p>
    </div>
    <?php if($rdSyncReady):?>
      <form method="post" onsubmit="return confirm('Iniciar a primeira sincronização real com o RD Station? O processo cria/atualiza Empresas, Contatos e Negociações, mas não cria Pedidos automaticamente.');">
        <?=csrfInput()?><input type="hidden" name="acao" value="iniciar_rd_first_sync">
        <button class="<?=BTN_P?>" <?=(!$rdMarketing['connected']||!$rdCrm['connected'])?'disabled':''?>>Iniciar primeira sincronização</button>
      </form>
    <?php endif;?>
  </div>

  <?php if(!$rdSyncReady):?>
    <div class="p-4"><div class="dnb-alert dnb-alert--warning">Instale <code>sql/migrations/20260918_rd_phase5_first_sync.sql</code> no MySQL para habilitar o processamento real e o Painel CMD.</div></div>
  <?php else:?>
    <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-px bg-slate-200">
      <div class="bg-white p-3"><small class="block text-slate-500">Empresas criadas</small><strong data-rd-counter="companies_created" class="text-xl" style="color:<?=NAVY?>;"><?= (int)$rdSelectedCounters['companies_created']?></strong></div>
      <div class="bg-white p-3"><small class="block text-slate-500">Contatos criados</small><strong data-rd-counter="contacts_created" class="text-xl" style="color:<?=NAVY?>;"><?= (int)$rdSelectedCounters['contacts_created']?></strong></div>
      <div class="bg-white p-3"><small class="block text-slate-500">Negociações criadas</small><strong data-rd-counter="negotiations_created" class="text-xl" style="color:<?=NAVY?>;"><?= (int)$rdSelectedCounters['negotiations_created']?></strong></div>
      <div class="bg-white p-3"><small class="block text-slate-500">Atualizações</small><strong data-rd-counter="updates" class="text-xl" style="color:<?=NAVY?>;"><?= (int)$rdSelectedCounters['companies_updated']+(int)$rdSelectedCounters['contacts_updated']+(int)$rdSelectedCounters['negotiations_updated']?></strong></div>
      <div class="bg-white p-3"><small class="block text-slate-500">Conflitos</small><strong data-rd-counter="conflicts" class="text-xl text-amber-700"><?= (int)$rdSelectedCounters['conflicts']?></strong></div>
    </div>
  <?php endif;?>
</section>

<?php if($rdSyncReady):?>
<div class="grid xl:grid-cols-[1.45fr_.55fr] gap-4 mb-4">
  <section class="bg-slate-950 rounded-xl shadow-sm overflow-hidden" 
    data-rd-sync-console
    data-job-id="<?= (int)($rdSelectedJob['id']??0) ?>"
    data-job-status="<?= e((string)($rdSelectedJob['status']??'')) ?>"
    data-csrf="<?= e(csrfToken()) ?>">
    <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between gap-3 flex-wrap">
      <div><span class="text-xs uppercase tracking-wider text-slate-400">Painel CMD · sincronização</span><h2 class="font-bold text-white">Processamento RD → CRM DNB</h2></div>
      <div class="flex items-center gap-2"><span data-rd-sync-status class="text-xs text-slate-300"><?=e(strtoupper((string)($rdSelectedJob['status']??'aguardando')))?></span><?php if($rdSelectedJob&&in_array($rdSelectedJob['status'],['queued','running','paused'],true)):?><button type="button" data-rd-sync-cancel class="rounded-md border border-slate-600 px-2.5 py-1 text-xs text-slate-200 hover:bg-slate-800">Cancelar</button><?php endif;?></div>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-px bg-slate-800 text-xs">
      <div class="bg-slate-950 p-3"><span class="text-slate-500">Etapa</span><strong data-rd-sync-stage class="block text-white mt-1"><?=e((string)($rdSelectedJob['stage']??'—'))?></strong></div>
      <div class="bg-slate-950 p-3"><span class="text-slate-500">Página</span><strong data-rd-sync-page class="block text-white mt-1"><?= (int)($rdSelectedJob['page_number']??0)?></strong></div>
      <div class="bg-slate-950 p-3"><span class="text-slate-500">Desde</span><strong class="block text-white mt-1"><?=e((string)($rdSelectedJob['since_at']??'—'))?></strong></div>
      <div class="bg-slate-950 p-3"><span class="text-slate-500">Conflitos pendentes</span><strong data-rd-sync-conflicts class="block text-amber-300 mt-1"><?=count($rdSelectedConflicts)?></strong></div>
    </div>
    <div data-rd-sync-log class="p-4 font-mono text-xs text-slate-300 overflow-auto min-h-[280px] max-h-[460px]">
      <?php if(!$rdSelectedJob):?><div class="text-slate-500">$ nenhuma sincronização iniciada...</div><?php else:?>
        <?php foreach(rdSyncLogs($pdo,(int)$rdSelectedJob['id'],0,120) as $log):?>
          <div class="py-1"><span class="text-slate-500">[<?=e($log['created_at'])?>]</span> <span class="<?=$log['level']==='error'?'text-rose-400':($log['level']==='warning'?'text-amber-300':($log['level']==='success'?'text-emerald-400':'text-sky-300'))?>"><?=e(strtoupper($log['level']))?></span> <span class="text-slate-500"><?=e($log['scope'])?></span> <?=e($log['message'])?></div>
        <?php endforeach;?>
      <?php endif;?>
    </div>
    <?php if($rdSelectedJob&&$rdSelectedJob['status']==='error'):?><div class="px-4 pb-4"><div class="rounded-lg bg-rose-950/50 border border-rose-900 p-3 text-xs text-rose-200"><?=e((string)$rdSelectedJob['last_error'])?></div></div><?php endif;?>
  </section>

  <section class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="p-4 border-b"><span class="dnb-eyebrow">Histórico</span><h2 class="dnb-section-title">Execuções</h2></div>
    <div class="divide-y divide-slate-100">
      <?php foreach($rdSyncJobs as $job):?>
        <a href="/configuracoes.php?rd_job=<?=(int)$job['id']?>#tab-rdstation" class="block p-3 hover:bg-slate-50 <?=((int)$job['id']===$rdSelectedJobId)?'bg-slate-50':''?>">
          <div class="flex justify-between gap-2"><strong>#<?=(int)$job['id']?> · <?=e($job['stage'])?></strong><span class="text-xs"><?=e(strtoupper($job['status']))?></span></div>
          <small class="block text-slate-500 mt-1"><?=e($job['created_at'])?> · conflitos: <?=(int)$job['pending_conflicts']?></small>
        </a>
      <?php endforeach;?>
      <?php if(!$rdSyncJobs):?><div class="p-5 text-sm text-slate-400">Nenhuma execução registrada.</div><?php endif;?>
    </div>
  </section>
</div>

<?php if($rdSelectedJob):?>
<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4 overflow-hidden">
  <div class="p-4 border-b"><span class="dnb-eyebrow">Revisão</span><h2 class="dnb-section-title">Conflitos pendentes</h2><p class="text-sm text-slate-500 mt-1">Duplicidade por CNPJ/e-mail e campos configurados como revisão manual nunca são resolvidos silenciosamente.</p></div>
  <div class="overflow-x-auto"><table class="w-full text-sm min-w-[940px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="p-2">Tipo</th><th class="p-2">Produto</th><th class="p-2">Entidade</th><th class="p-2">ID externo</th><th class="p-2">Candidatos DNB</th><th class="p-2">Criado</th></tr></thead><tbody data-rd-sync-conflict-table>
    <?php foreach($rdSelectedConflicts as $cf):$cand=json_decode((string)$cf['candidates_json'],true)?:[];?>
      <tr class="border-b border-slate-100"><td class="p-2"><?=e($cf['conflict_type'])?></td><td class="p-2"><?=e($cf['product'])?></td><td class="p-2"><?=e($cf['external_entity'])?></td><td class="p-2 font-mono text-xs"><?=e($cf['external_id'])?></td><td class="p-2 text-xs"><?=e(implode(', ',array_map(fn($v)=>is_array($v)?json_encode($v,JSON_UNESCAPED_UNICODE):(string)$v,$cand)))?></td><td class="p-2"><?=e($cf['created_at'])?></td></tr>
    <?php endforeach;?>
    <?php if(!$rdSelectedConflicts):?><tr><td colspan="6" class="p-5 text-center text-slate-400">Nenhum conflito pendente neste job.</td></tr><?php endif;?>
  </tbody></table></div>
</section>
<?php endif;?>
<?php endif;?>
