<?php
/** @var PDO $pdo */
$rdDiscoveryReady = rdDiscoveryDisponivel($pdo);
if (!$rdDiscoveryReady):
?>
<div class="dnb-alert dnb-alert--warning mb-4">
  A Fase 4 precisa da migração <code>sql/migrations/20260918_rd_phase4_discovery_mapping.sql</code>.
  Depois de aplicá-la no MySQL, esta área passa a armazenar a estrutura descoberta no RD sem importar contatos, empresas ou negociações.
</div>
<?php
return;
endif;

$rdMarketingFields = rdDiscoveryItems($pdo,'marketing','contact_field');
$rdCrmUsers = rdUserMappings($pdo);
$rdPipelines = rdPipelineMappings($pdo);
$rdCrmCustomFields = rdDiscoveryItems($pdo,'crm','custom_field');
$rdSources = rdDiscoveryItems($pdo,'crm','source');
$rdLostReasons = rdDiscoveryItems($pdo,'crm','lost_reason');
$rdSegments = rdDiscoveryItems($pdo,'crm','segment');
$rdCampaigns = rdDiscoveryItems($pdo,'crm','campaign');
$rdRuns = rdDiscoveryRecentRuns($pdo,12);
$rdMappings = rdFieldMappingsIndex($pdo);
$rdTargets = rdLocalTargetFields();
$rdLocalUsers = $pdo->query("SELECT id,nome,email FROM usuarios WHERE ativo=1 ORDER BY nome,email")->fetchAll();

if (!function_exists('rdAdminSuggestedTarget')) {
    function rdAdminSuggestedTarget(string $product,string $sourceEntity,string $sourceKey): string
    {
        $sourceKey=strtolower(trim($sourceKey));
        if($product==='marketing'){
            return match($sourceKey){
                'email'=>'contact.email',
                'name'=>'contact.name',
                'job_title'=>'contact.role_title',
                'personal_phone','mobile_phone'=>'contact.phone',
                'company_name'=>'company.name',
                'company_site'=>'company.website',
                'company_address'=>'company.address',
                default=>'',
            };
        }
        if(in_array($sourceEntity,['organization','company'],true) && str_contains($sourceKey,'cnpj')) return 'company.cnpj';
        return '';
    }
}
if (!function_exists('rdAdminTargetOptions')) {
    /** @param array<string,array<string,string>> $targets */
    function rdAdminTargetOptions(array $targets,string $selected): void
    {
        echo '<option value="">— não mapear —</option>';
        $labels=['contact'=>'Contato','company'=>'Empresa','negotiation'=>'Negociação'];
        foreach($targets as $entity=>$fields){
            echo '<optgroup label="'.e($labels[$entity]??$entity).'">';
            foreach($fields as $field=>$label){
                $value=$entity.'.'.$field;
                echo '<option value="'.e($value).'" '.($selected===$value?'selected':'').'>'.e($label).'</option>';
            }
            echo '</optgroup>';
        }
    }
}
?>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4 overflow-hidden">
  <div class="p-4 flex items-start justify-between gap-4 flex-wrap">
    <div>
      <span class="dnb-eyebrow">Fase 4 · leitura estrutural</span>
      <h2 class="dnb-section-title">Descobrir estrutura real da conta RD</h2>
      <p class="text-sm text-slate-500 mt-1 max-w-3xl">A descoberta consulta somente metadados. Ela não cria nem altera Empresa, Contato, Negociação, Orçamento ou Pedido no CRM DNB.</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <form method="post">
        <?=csrfInput()?><input type="hidden" name="acao" value="descobrir_rdstation"><input type="hidden" name="produto" value="marketing">
        <button class="<?=BTN_P?>" <?=empty($rdMarketing['connected'])?'disabled':''?>>Ler estrutura do Marketing</button>
      </form>
      <form method="post">
        <?=csrfInput()?><input type="hidden" name="acao" value="descobrir_rdstation"><input type="hidden" name="produto" value="crm">
        <button class="<?=BTN_P?>" <?=empty($rdCrm['connected'])?'disabled':''?>>Ler estrutura do CRM</button>
      </form>
    </div>
  </div>
  <div class="grid md:grid-cols-4 xl:grid-cols-8 gap-px bg-slate-200 border-t border-slate-200">
    <?php
      $rdCounters=[
        ['Marketing · campos',count($rdMarketingFields)],
        ['CRM · usuários',count($rdCrmUsers)],
        ['Funis/etapas',count($rdPipelines)],
        ['Campos CRM',count($rdCrmCustomFields)],
        ['Fontes',count($rdSources)],
        ['Perdas',count($rdLostReasons)],
        ['Segmentos',count($rdSegments)],
        ['Campanhas',count($rdCampaigns)],
      ];
      foreach($rdCounters as [$label,$value]):
    ?>
      <div class="bg-white p-3"><small class="block text-slate-500"><?=e($label)?></small><strong class="text-lg" style="color:<?=NAVY?>;"><?=$value?></strong></div>
    <?php endforeach;?>
  </div>
</section>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4 overflow-hidden">
  <div class="p-4 border-b border-slate-200">
    <span class="dnb-eyebrow">RD Station Marketing</span>
    <h2 class="dnb-section-title">Campos de Contatos e Pré-vendas</h2>
    <p class="text-sm text-slate-500 mt-1">Selecione somente os campos que deverão entrar na futura sincronização. Campos padrão e personalizados permanecem identificados pelo <code>api_identifier</code> do RD.</p>
  </div>
  <?php if(!$rdMarketingFields):?>
    <div class="p-6 text-center text-sm text-slate-400">Nenhum campo descoberto ainda. Conecte o Marketing e clique em <b>Ler estrutura do Marketing</b>.</div>
  <?php else:?>
  <form method="post">
    <?=csrfInput()?><input type="hidden" name="acao" value="salvar_rd_marketing_mapping">
    <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Mapeamento de campos do RD Marketing">
      <table class="w-full text-sm min-w-[1180px]">
        <thead><tr class="text-left text-xs text-slate-500 border-b border-slate-200">
          <th class="p-2">Usar</th><th class="p-2">Campo RD</th><th class="p-2">Identificador API</th><th class="p-2">Tipo</th><th class="p-2">Destino no CRM DNB</th><th class="p-2">Fonte em conflito</th>
        </tr></thead>
        <tbody>
        <?php foreach($rdMarketingFields as $field):
          $sourceKey=(string)($field['api_identifier']?:$field['external_id']);
          $mapKey='marketing|contact|'.$sourceKey;
          $map=$rdMappings[$mapKey]??null;
          $target=$map?($map['target_entity'].'.'.$map['target_field']):rdAdminSuggestedTarget('marketing','contact',$sourceKey);
          $truth=(string)($map['source_of_truth']??'rd');
          $idx='m'.(int)$field['id'];
        ?>
          <tr class="border-b border-slate-100 align-top">
            <td class="p-2"><input type="checkbox" name="selected_fields[]" value="<?=(int)$field['id']?>" <?=!empty($field['selected_for_sync'])?'checked':''?> aria-label="Usar <?=e($field['name'])?>"></td>
            <td class="p-2"><strong><?=e($field['name']?:$sourceKey)?></strong><small class="block text-slate-400"><?=$field['is_custom']?'personalizado':'padrão'?></small></td>
            <td class="p-2"><code class="text-xs"><?=e($sourceKey)?></code></td>
            <td class="p-2"><?=e($field['data_type']?:'—')?></td>
            <td class="p-2">
              <input type="hidden" name="field_mapping[<?=$idx?>][product]" value="marketing">
              <input type="hidden" name="field_mapping[<?=$idx?>][source_entity]" value="contact">
              <input type="hidden" name="field_mapping[<?=$idx?>][source_key]" value="<?=e($sourceKey)?>">
              <input type="hidden" name="field_mapping[<?=$idx?>][source_label]" value="<?=e($field['name'])?>">
              <select name="field_mapping[<?=$idx?>][target]" class="<?=INPUT_CLS?>"><?php rdAdminTargetOptions($rdTargets,$target);?></select>
            </td>
            <td class="p-2"><select name="field_mapping[<?=$idx?>][source_of_truth]" class="<?=INPUT_CLS?>">
              <option value="rd" <?=$truth==='rd'?'selected':''?>>RD Station prevalece</option>
              <option value="dnb" <?=$truth==='dnb'?'selected':''?>>CRM DNB prevalece</option>
              <option value="manual_review" <?=$truth==='manual_review'?'selected':''?>>Revisar conflito</option>
            </select></td>
          </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    </div>
    <div class="p-4 flex items-center justify-between gap-3 flex-wrap"><p class="text-xs text-slate-500">A propriedade dos dados fica configurada campo a campo. Nada é fundido silenciosamente.</p><button class="<?=BTN_P?>">Salvar seleção e mapeamento do Marketing</button></div>
  </form>
  <?php endif;?>
</section>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4 overflow-hidden">
  <div class="p-4 border-b border-slate-200">
    <span class="dnb-eyebrow">RD Station CRM</span>
    <h2 class="dnb-section-title">Usuários / responsáveis</h2>
    <p class="text-sm text-slate-500 mt-1">O vínculo por e-mail é sugerido automaticamente quando existe correspondência exata. Usuários inativos no RD continuam visíveis para preservar histórico.</p>
  </div>
  <?php if(!$rdCrmUsers):?>
    <div class="p-6 text-center text-sm text-slate-400">Nenhum usuário descoberto ainda.</div>
  <?php else:?>
  <form method="post">
    <?=csrfInput()?><input type="hidden" name="acao" value="salvar_rd_users">
    <div class="overflow-x-auto"><table class="w-full text-sm min-w-[820px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="p-2">Usar</th><th class="p-2">Usuário RD</th><th class="p-2">Status RD</th><th class="p-2">Usuário no CRM DNB</th></tr></thead><tbody>
      <?php foreach($rdCrmUsers as $u):?>
      <tr class="border-b border-slate-100">
        <td class="p-2"><input type="checkbox" name="user_mapping[<?=e($u['rd_user_external_id'])?>][enabled]" value="1" <?=!empty($u['enabled'])?'checked':''?>></td>
        <td class="p-2"><strong><?=e($u['rd_user_name']?:'Sem nome')?></strong><small class="block text-slate-500"><?=e($u['rd_user_email']?:'sem e-mail')?></small></td>
        <td class="p-2"><?=!empty($u['rd_user_active'])?tag('ativo','green'):tag('inativo no RD','slate')?></td>
        <td class="p-2"><select name="user_mapping[<?=e($u['rd_user_external_id'])?>][local_user_id]" class="<?=INPUT_CLS?>"><option value="0">— não vinculado —</option><?php foreach($rdLocalUsers as $lu):?><option value="<?=(int)$lu['id']?>" <?=(int)$u['local_user_id']===(int)$lu['id']?'selected':''?>><?=e($lu['nome'])?> · <?=e($lu['email'])?></option><?php endforeach;?></select></td>
      </tr>
      <?php endforeach;?>
    </tbody></table></div>
    <div class="p-4 text-right"><button class="<?=BTN_P?>">Salvar responsáveis</button></div>
  </form>
  <?php endif;?>
</section>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4 overflow-hidden">
  <div class="p-4 border-b border-slate-200">
    <span class="dnb-eyebrow">RD Station CRM</span>
    <h2 class="dnb-section-title">Funis e etapas reais da conta</h2>
    <p class="text-sm text-slate-500 mt-1">Os nomes são trazidos diretamente do RD. Por padrão, todos os funis e etapas descobertos ficam habilitados e entram no catálogo da futura sincronização.</p>
  </div>
  <?php if(!$rdPipelines):?>
    <div class="p-6 text-center text-sm text-slate-400">Nenhum funil/etapa descoberto ainda.</div>
  <?php else:?>
  <form method="post">
    <?=csrfInput()?><input type="hidden" name="acao" value="salvar_rd_pipelines">
    <div class="overflow-x-auto"><table class="w-full text-sm min-w-[900px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="p-2">Usar</th><th class="p-2">Funil local</th><th class="p-2">Etapa local</th><th class="p-2">Ordem</th></tr></thead><tbody>
      <?php $lastPipe=null; foreach($rdPipelines as $p):?>
      <tr class="border-b border-slate-100 <?=$lastPipe!==$p['pipeline_name']?'bg-slate-50':''?>">
        <td class="p-2"><input type="checkbox" name="pipeline_mapping[<?=(int)$p['id']?>][enabled]" value="1" <?=!empty($p['enabled'])?'checked':''?>></td>
        <td class="p-2"><input name="pipeline_mapping[<?=(int)$p['id']?>][pipeline_name]" value="<?=e($p['pipeline_name'])?>" maxlength="160" class="<?=INPUT_CLS?>"></td>
        <td class="p-2"><input name="pipeline_mapping[<?=(int)$p['id']?>][stage_name]" value="<?=e($p['stage_name'])?>" maxlength="160" class="<?=INPUT_CLS?>"></td>
        <td class="p-2"><?= (int)$p['sort_order']?></td>
      </tr>
      <?php $lastPipe=$p['pipeline_name']; endforeach;?>
    </tbody></table></div>
    <div class="p-4 flex justify-between items-center gap-3 flex-wrap"><p class="text-xs text-slate-500">Os IDs externos permanecem isolados nas tabelas de integração. O restante do CRM continua usando <code>dnb_id</code> e os nomes locais.</p><button class="<?=BTN_P?>">Salvar funis e etapas</button></div>
  </form>
  <?php endif;?>
</section>

<section class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4 overflow-hidden">
  <div class="p-4 border-b border-slate-200">
    <span class="dnb-eyebrow">RD Station CRM</span>
    <h2 class="dnb-section-title">Campos personalizados</h2>
    <p class="text-sm text-slate-500 mt-1">Mapeamento disponível para campos de Contato, Empresa e Negociação. Campos de Produto são mantidos no inventário, mas não entram no núcleo comercial desta fase.</p>
  </div>
  <?php
    $rdSupportedCustom=array_values(array_filter($rdCrmCustomFields,fn($f)=>in_array(strtolower((string)$f['entity_type']),['contact','organization','deal'],true)));
  ?>
  <?php if(!$rdSupportedCustom):?>
    <div class="p-6 text-center text-sm text-slate-400">Nenhum campo personalizado suportado foi descoberto.</div>
  <?php else:?>
  <form method="post">
    <?=csrfInput()?><input type="hidden" name="acao" value="salvar_rd_crm_fields">
    <div class="overflow-x-auto"><table class="w-full text-sm min-w-[1120px]"><thead><tr class="text-left text-xs text-slate-500 border-b"><th class="p-2">Entidade RD</th><th class="p-2">Campo</th><th class="p-2">Slug/API</th><th class="p-2">Destino DNB</th><th class="p-2">Fonte em conflito</th></tr></thead><tbody>
      <?php foreach($rdSupportedCustom as $field):
        $sourceEntity=strtolower((string)$field['entity_type']);
        $sourceKey=(string)($field['api_identifier']?:$field['external_id']);
        $mapKey='crm|'.$sourceEntity.'|'.$sourceKey;
        $map=$rdMappings[$mapKey]??null;
        $target=$map?($map['target_entity'].'.'.$map['target_field']):rdAdminSuggestedTarget('crm',$sourceEntity,$sourceKey);
        $truth=(string)($map['source_of_truth']??'rd');
        $idx='c'.(int)$field['id'];
      ?>
      <tr class="border-b border-slate-100">
        <td class="p-2"><?=e($sourceEntity)?></td>
        <td class="p-2"><strong><?=e($field['name']?:$sourceKey)?></strong><small class="block text-slate-400"><?=e($field['data_type']?:'—')?></small></td>
        <td class="p-2"><code class="text-xs"><?=e($sourceKey)?></code></td>
        <td class="p-2">
          <input type="hidden" name="field_mapping[<?=$idx?>][product]" value="crm">
          <input type="hidden" name="field_mapping[<?=$idx?>][source_entity]" value="<?=e($sourceEntity)?>">
          <input type="hidden" name="field_mapping[<?=$idx?>][source_key]" value="<?=e($sourceKey)?>">
          <input type="hidden" name="field_mapping[<?=$idx?>][source_label]" value="<?=e($field['name'])?>">
          <select name="field_mapping[<?=$idx?>][target]" class="<?=INPUT_CLS?>"><?php rdAdminTargetOptions($rdTargets,$target);?></select>
        </td>
        <td class="p-2"><select name="field_mapping[<?=$idx?>][source_of_truth]" class="<?=INPUT_CLS?>"><option value="rd" <?=$truth==='rd'?'selected':''?>>RD Station prevalece</option><option value="dnb" <?=$truth==='dnb'?'selected':''?>>CRM DNB prevalece</option><option value="manual_review" <?=$truth==='manual_review'?'selected':''?>>Revisar conflito</option></select></td>
      </tr>
      <?php endforeach;?>
    </tbody></table></div>
    <div class="p-4 text-right"><button class="<?=BTN_P?>">Salvar mapeamento de campos CRM</button></div>
  </form>
  <?php endif;?>
</section>

<div class="grid xl:grid-cols-2 gap-4 mb-4">
  <?php
    $rdReferenceGroups=[
      ['Fontes',$rdSources],
      ['Motivos de perda',$rdLostReasons],
      ['Segmentos de empresa',$rdSegments],
      ['Campanhas',$rdCampaigns],
    ];
    foreach($rdReferenceGroups as [$title,$items]):
  ?>
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4">
    <div class="flex items-center justify-between"><h2 class="dnb-section-title"><?=e($title)?></h2><?=tag((string)count($items),'blue')?></div>
    <div class="flex flex-wrap gap-2 mt-3"><?php foreach($items as $item):?><span class="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs"><?=e($item['name']?:$item['external_id'])?></span><?php endforeach;?><?php if(!$items):?><span class="text-sm text-slate-400">Ainda não descoberto.</span><?php endif;?></div>
  </section>
  <?php endforeach;?>
</div>

<section class="bg-slate-950 rounded-xl shadow-sm mb-4 overflow-hidden dnb-rd-console">
  <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between gap-3"><div><span class="text-xs uppercase tracking-wider text-slate-400">Painel CMD · descoberta</span><h2 class="font-bold text-white">Execuções estruturais do RD</h2></div><span class="text-xs text-slate-400">somente metadados</span></div>
  <div class="p-4 font-mono text-xs text-slate-300 overflow-x-auto">
    <?php if(!$rdRuns):?><div class="text-slate-500">$ aguardando primeira descoberta...</div><?php endif;?>
    <?php foreach($rdRuns as $run):
      $sum=json_decode((string)$run['summary_json'],true)?:[];
      $pairs=[];foreach($sum as $k=>$v)$pairs[]=$k.'='.(int)$v;
    ?>
      <div class="py-1 whitespace-nowrap"><span class="text-slate-500">[<?=e($run['started_at'])?>]</span> <span class="<?=$run['status']==='success'?'text-emerald-400':($run['status']==='partial'?'text-amber-300':'text-rose-400')?>"><?=e(strtoupper($run['status']))?></span> <?=e($run['product'])?> · total=<?=(int)$run['total_items']?><?= $pairs?' · '.e(implode(' ', $pairs)):'' ?><?= $run['error_message']?' · '.e($run['error_message']):'' ?></div>
    <?php endforeach;?>
  </div>
</section>
