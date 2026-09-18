<?php
/** @var PDO $pdo */
$rdSyncReady=rdSyncDisponivel($pdo);
$rdSyncJobs=$rdSyncReady?rdSyncRecentJobs($pdo,10):[];
$rdSyncIssues=$rdSyncReady?rdSyncPrerequisiteIssues($pdo):[];
$rdSelectedJobId=(int)($_GET['rd_job']??0);
if($rdSelectedJobId<=0&&$rdSyncJobs)$rdSelectedJobId=(int)$rdSyncJobs[0]['id'];
$rdSelectedJob=$rdSelectedJobId>0&&$rdSyncReady?rdSyncJob($pdo,$rdSelectedJobId):null;
$rdSelectedCounters=$rdSelectedJob['counters']??rdSyncCounterDefault();
$rdSelectedConflicts=$rdSelectedJob?rdSyncConflicts($pdo,(int)$rdSelectedJob['id'],30):[];
$rdPhase6Ready=rdPhase6Disponivel($pdo);
$rdPhase6Settings=$rdPhase6Ready?rdPhase6Settings($pdo):null;
$rdPhase6Stats=$rdPhase6Ready?rdPhase6Stats($pdo):['pending_events'=>0,'error_events'=>0,'pending_conflicts'=>0,'active_webhooks'=>0];
$rdPhase6Subscriptions=$rdPhase6Ready?$pdo->query("SELECT product,event_type,status,last_http_status,last_error,registered_at FROM rd_webhook_subscriptions ORDER BY product,event_type")->fetchAll():[];
$rdPhase6Checkpoint=$rdPhase6Ready?rdSyncStateGet($pdo,'crm_incremental_checkpoint',''):'';
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
        <button class="<?=BTN_P?>" <?=($rdSyncIssues?'disabled':'')?>>Iniciar primeira sincronização</button>
      </form>
    <?php endif;?>
  </div>

  <?php if(!$rdSyncReady):?>
    <div class="p-4"><div class="dnb-alert dnb-alert--warning">Instale <code>sql/migrations/20260918_rd_phase5_first_sync.sql</code> no MySQL para habilitar o processamento real e o Painel CMD.</div></div>
  <?php else:?>
    <?php if($rdSyncIssues):?><div class="p-4 pb-0"><div class="dnb-alert dnb-alert--warning"><strong>Antes de sincronizar:</strong> <?=e(implode(' ', $rdSyncIssues))?></div></div><?php endif;?>
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

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4 overflow-hidden">
  <div class="p-4 border-b border-slate-200 flex items-start justify-between gap-4 flex-wrap">
    <div>
      <span class="dnb-eyebrow">Fase 6 · automação contínua</span>
      <h2 class="dnb-section-title">Incremental + Webhooks RD Station</h2>
      <p class="text-sm text-slate-500 mt-1 max-w-3xl">O CRM recebe eventos do RD em uma fila idempotente e usa uma sincronização incremental por <code>updated_at</code> como rede de segurança. Exclusões remotas nunca apagam dados DNB silenciosamente.</p>
    </div>
    <?php if($rdPhase6Ready):?>
      <form method="post">
        <?=csrfInput()?><input type="hidden" name="acao" value="iniciar_rd_incremental">
        <button class="<?=BTN_S?>">Sincronizar alterações agora</button>
      </form>
    <?php endif;?>
  </div>

  <?php if(!$rdPhase6Ready):?>
    <div class="p-4"><div class="dnb-alert dnb-alert--warning">Instale <code>sql/migrations/20260918_rd_phase6_incremental_webhooks.sql</code> para habilitar webhooks, cron, incremental e resolução operacional de conflitos.</div></div>
  <?php else:?>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-px bg-slate-200">
      <div class="bg-white p-3"><small class="block text-slate-500">Webhooks ativos</small><strong class="text-xl" style="color:<?=NAVY?>;"><?= (int)$rdPhase6Stats['active_webhooks']?></strong></div>
      <div class="bg-white p-3"><small class="block text-slate-500">Eventos aguardando</small><strong class="text-xl" style="color:<?=NAVY?>;"><?= (int)$rdPhase6Stats['pending_events']?></strong></div>
      <div class="bg-white p-3"><small class="block text-slate-500">Eventos com erro</small><strong class="text-xl <?=((int)$rdPhase6Stats['error_events']>0)?'text-rose-700':''?>"><?= (int)$rdPhase6Stats['error_events']?></strong></div>
      <div class="bg-white p-3"><small class="block text-slate-500">Checkpoint CRM</small><strong class="block text-sm mt-1"><?=e($rdPhase6Checkpoint?:'ainda não definido')?></strong></div>
    </div>

    <div class="grid xl:grid-cols-2 gap-4 p-4">
      <form method="post" class="rounded-xl border border-slate-200 p-4">
        <?=csrfInput()?><input type="hidden" name="acao" value="salvar_rd_automation">
        <div class="flex items-center justify-between gap-3">
          <div><h3 class="font-semibold" style="color:<?=NAVY?>;">Sincronização recorrente</h3><p class="text-xs text-slate-500 mt-1">O cron processa a fila e cria uma carga incremental quando o intervalo vence.</p></div>
          <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="incremental_enabled" value="1" <?=!empty($rdPhase6Settings['enabled'])?'checked':''?>> Ativa</label>
        </div>
        <div class="grid sm:grid-cols-2 gap-3 mt-4">
          <label class="text-xs font-semibold text-slate-600">Intervalo (minutos)<input class="<?=INPUT_CLS?> mt-1" type="number" min="5" max="1440" name="incremental_minutes" value="<?= (int)$rdPhase6Settings['interval']?>"></label>
          <label class="text-xs font-semibold text-slate-600">Sobreposição de segurança (min)<input class="<?=INPUT_CLS?> mt-1" type="number" min="1" max="120" name="incremental_overlap_minutes" value="<?= (int)$rdPhase6Settings['overlap']?>"></label>
        </div>
        <button class="<?=BTN_P?> mt-4">Salvar automação</button>
      </form>

      <div class="rounded-xl border border-slate-200 p-4">
        <div class="flex items-start justify-between gap-3">
          <div><h3 class="font-semibold" style="color:<?=NAVY?>;">Webhooks</h3><p class="text-xs text-slate-500 mt-1">CRM: Empresa, Contato e Negociação (criar/atualizar/excluir). Marketing: conversão e oportunidade.</p></div>
          <form method="post"><?=csrfInput()?><input type="hidden" name="acao" value="registrar_rd_webhooks"><button class="<?=BTN_S?>">Registrar/atualizar</button></form>
        </div>
        <dl class="text-xs mt-4 space-y-2">
          <div><dt class="text-slate-500">Endpoint público</dt><dd class="font-mono break-all"><?=e((string)$rdPhase6Settings['webhook_url'])?></dd></div>
          <div><dt class="text-slate-500">Autenticação webhook</dt><dd><?=!empty($rdPhase6Settings['webhook_token'])?tag('segredo configurado','green'):tag('ausente','amber')?></dd></div>
          <div><dt class="text-slate-500">Token cron</dt><dd><?=!empty($rdPhase6Settings['cron_token'])?tag('segredo configurado','green'):tag('ausente','amber')?></dd></div>
        </dl>
        <?php if($rdPhase6Settings['cron_url']!==''):?>
          <label class="block text-xs font-semibold text-slate-600 mt-4">Comando sugerido para o Cron Job do cPanel</label>
          <textarea readonly class="<?=INPUT_CLS?> mt-1 font-mono text-xs min-h-[86px]" onclick="this.select()"><?=e(rdPhase6CronCommand())?></textarea>
          <p class="text-[11px] text-slate-500 mt-1">Sugestão: executar a cada 5 minutos. O próprio CRM respeita o intervalo configurado acima.</p>
        <?php endif;?>
      </div>
    </div>

    <div class="border-t border-slate-200">
      <div class="px-4 py-3 flex items-center justify-between"><strong class="text-sm" style="color:<?=NAVY?>;">Assinaturas conhecidas</strong><span class="text-xs text-slate-500"><?=count($rdPhase6Subscriptions)?> evento(s)</span></div>
      <div class="overflow-x-auto"><table class="w-full text-xs min-w-[760px]"><thead><tr class="text-left text-slate-500 border-t border-b"><th class="p-2">Produto</th><th class="p-2">Evento</th><th class="p-2">Status</th><th class="p-2">HTTP</th><th class="p-2">Registrado</th><th class="p-2">Último erro</th></tr></thead><tbody>
        <?php foreach($rdPhase6Subscriptions as $sub):?>
          <tr class="border-b border-slate-100"><td class="p-2"><?=e($sub['product'])?></td><td class="p-2 font-mono"><?=e($sub['event_type'])?></td><td class="p-2"><?=$sub['status']==='active'?tag('ativo','green'):tag($sub['status'],'amber')?></td><td class="p-2"><?=e((string)($sub['last_http_status']??'—'))?></td><td class="p-2"><?=e((string)($sub['registered_at']??'—'))?></td><td class="p-2 text-rose-700"><?=e((string)($sub['last_error']??''))?></td></tr>
        <?php endforeach;?>
        <?php if(!$rdPhase6Subscriptions):?><tr><td colspan="6" class="p-4 text-center text-slate-400">Nenhuma assinatura registrada pelo CRM ainda.</td></tr><?php endif;?>
      </tbody></table></div>
    </div>
  <?php endif;?>
</section>

<div class="grid xl:grid-cols-[1.45fr_.55fr] gap-4 mb-4">
  <section class="bg-slate-950 rounded-xl shadow-sm overflow-hidden" 
    data-rd-sync-console
    data-job-id="<?= (int)($rdSelectedJob['id']??0) ?>"
    data-job-status="<?= e((string)($rdSelectedJob['status']??'')) ?>"
    data-csrf="<?= e(csrfToken()) ?>">
    <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between gap-3 flex-wrap">
      <div><span class="text-xs uppercase tracking-wider text-slate-400">Painel CMD · sincronização</span><h2 class="font-bold text-white">Processamento RD → CRM DNB</h2></div>
      <div class="flex items-center gap-2"><span data-rd-sync-status class="text-xs text-slate-300"><?=e(strtoupper((string)($rdSelectedJob['status']??'aguardando')))?></span><?php if($rdSelectedJob&&$rdSelectedJob['status']==='error'):?><button type="button" data-rd-sync-resume class="rounded-md border border-amber-600 px-2.5 py-1 text-xs text-amber-200 hover:bg-slate-800">Retomar</button><?php endif;?><?php if($rdSelectedJob&&in_array($rdSelectedJob['status'],['queued','running','paused'],true)):?><button type="button" data-rd-sync-cancel class="rounded-md border border-slate-600 px-2.5 py-1 text-xs text-slate-200 hover:bg-slate-800">Cancelar</button><?php endif;?></div>
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
  <div class="overflow-x-auto"><table class="w-full text-sm min-w-[1180px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="p-2">Tipo</th><th class="p-2">Produto</th><th class="p-2">Entidade</th><th class="p-2">ID externo</th><th class="p-2">Candidatos DNB</th><th class="p-2">Criado</th><th class="p-2">Resolver</th></tr></thead><tbody data-rd-sync-conflict-table>
    <?php foreach($rdSelectedConflicts as $cf):
      $cand=json_decode((string)$cf['candidates_json'],true)?:[];
      $candidateIds=$rdPhase6Ready?rdPhase6ConflictCandidateIds($cf):[];
      $isField=str_starts_with((string)$cf['conflict_type'],'field_conflict_');
      $isDeleted=str_starts_with((string)$cf['conflict_type'],'remote_deleted_');
    ?>
      <tr class="border-b border-slate-100 align-top">
        <td class="p-2"><?=e($cf['conflict_type'])?></td><td class="p-2"><?=e($cf['product'])?></td><td class="p-2"><?=e($cf['external_entity'])?></td><td class="p-2 font-mono text-xs"><?=e($cf['external_id'])?></td>
        <td class="p-2 text-xs max-w-[320px] break-words"><?=e(implode(', ',array_map(fn($v)=>is_array($v)?json_encode($v,JSON_UNESCAPED_UNICODE):(string)$v,$cand)))?></td>
        <td class="p-2"><?=e($cf['created_at'])?></td>
        <td class="p-2 min-w-[280px]">
          <?php if(!$rdPhase6Ready):?><span class="text-xs text-slate-400">Instale a Fase 6.</span>
          <?php elseif($isField):?>
            <div class="flex gap-1 flex-wrap">
              <form method="post"><?=csrfInput()?><input type="hidden" name="acao" value="resolver_rd_conflict"><input type="hidden" name="conflict_id" value="<?=(int)$cf['id']?>"><input type="hidden" name="rd_job" value="<?=(int)$rdSelectedJob['id']?>"><input type="hidden" name="resolution_action" value="keep_dnb"><button class="<?=BTN_S?> !px-2 !py-1 text-xs">Manter DNB</button></form>
              <form method="post" onsubmit="return confirm('Aplicar o valor do RD neste campo?');"><?=csrfInput()?><input type="hidden" name="acao" value="resolver_rd_conflict"><input type="hidden" name="conflict_id" value="<?=(int)$cf['id']?>"><input type="hidden" name="rd_job" value="<?=(int)$rdSelectedJob['id']?>"><input type="hidden" name="resolution_action" value="use_rd"><button class="<?=BTN_P?> !px-2 !py-1 text-xs">Usar RD</button></form>
            </div>
          <?php elseif($isDeleted):$deletedId=$candidateIds[0]??'';?>
            <div class="flex gap-1 flex-wrap">
              <form method="post"><?=csrfInput()?><input type="hidden" name="acao" value="resolver_rd_conflict"><input type="hidden" name="conflict_id" value="<?=(int)$cf['id']?>"><input type="hidden" name="rd_job" value="<?=(int)$rdSelectedJob['id']?>"><input type="hidden" name="resolved_dnb_id" value="<?=e($deletedId)?>"><input type="hidden" name="resolution_action" value="keep_dnb"><button class="<?=BTN_S?> !px-2 !py-1 text-xs">Manter no DNB</button></form>
              <?php if(str_starts_with($deletedId,'EMP-')||str_starts_with($deletedId,'CON-')):?><form method="post" onsubmit="return confirm('Desativar este registro no CRM DNB? O histórico será preservado.');"><?=csrfInput()?><input type="hidden" name="acao" value="resolver_rd_conflict"><input type="hidden" name="conflict_id" value="<?=(int)$cf['id']?>"><input type="hidden" name="rd_job" value="<?=(int)$rdSelectedJob['id']?>"><input type="hidden" name="resolved_dnb_id" value="<?=e($deletedId)?>"><input type="hidden" name="resolution_action" value="deactivate_local"><button class="<?=BTN_S?> !px-2 !py-1 text-xs">Desativar DNB</button></form><?php endif;?>
            </div>
          <?php elseif($candidateIds):?>
            <form method="post" class="flex gap-1 items-center flex-wrap" onsubmit="return confirm('Vincular o ID externo a este registro DNB?');">
              <?=csrfInput()?><input type="hidden" name="acao" value="resolver_rd_conflict"><input type="hidden" name="conflict_id" value="<?=(int)$cf['id']?>"><input type="hidden" name="rd_job" value="<?=(int)$rdSelectedJob['id']?>"><input type="hidden" name="resolution_action" value="link">
              <select name="resolved_dnb_id" class="<?=INPUT_CLS?> !py-1 text-xs w-auto"><?php foreach($candidateIds as $cid):?><option value="<?=e($cid)?>"><?=e($cid)?></option><?php endforeach;?></select><button class="<?=BTN_P?> !px-2 !py-1 text-xs">Vincular</button>
            </form>
          <?php else:?>
            <form method="post"><?=csrfInput()?><input type="hidden" name="acao" value="resolver_rd_conflict"><input type="hidden" name="conflict_id" value="<?=(int)$cf['id']?>"><input type="hidden" name="rd_job" value="<?=(int)$rdSelectedJob['id']?>"><input type="hidden" name="resolution_action" value="ignore"><button class="<?=BTN_S?> !px-2 !py-1 text-xs">Ignorar</button></form>
          <?php endif;?>
        </td>
      </tr>
    <?php endforeach;?>
    <?php if(!$rdSelectedConflicts):?><tr><td colspan="7" class="p-5 text-center text-slate-400">Nenhum conflito pendente neste job.</td></tr><?php endif;?>
  </tbody></table></div>
</section>
<?php endif;?>
<?php endif;?>
