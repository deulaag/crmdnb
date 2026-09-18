<?php
declare(strict_types=1);

$root=dirname(__DIR__);
function rd5file(string $path):string{
    global $root;
    $v=file_get_contents($root.'/'.$path);
    if($v===false){fwrite(STDERR,"FALHA: {$path} ausente\n");exit(1);}
    return $v;
}
function rd5ok(bool $cond,string $msg):void{
    if(!$cond){fwrite(STDERR,"FALHA: {$msg}\n");exit(1);}
}

$migration=rd5file('sql/migrations/20260918_rd_phase5_first_sync.sql');
$service=rd5file('includes/rd_sync_service.php');
$api=rd5file('api/v1/rdstation-sync.php');
$panel=rd5file('includes/rd_station_sync_panel.php');
$admin=rd5file('includes/rd_station_admin_panel.php');
$config=rd5file('configuracoes.php');
$js=rd5file('assets/rd-sync.js');
$neg=rd5file('negociacao.php');

foreach(['rd_sync_jobs','rd_sync_logs','rd_sync_conflicts'] as $table)
    rd5ok(str_contains($migration,'CREATE TABLE IF NOT EXISTS '.$table),'migração deve criar '.$table);
rd5ok(str_contains($migration,"status ENUM('queued','running','paused','completed','error','cancelled')"),'job deve ter estados auditáveis');
rd5ok(str_contains($migration,"status ENUM('pending','resolved','ignored')"),'conflitos devem ter ciclo de revisão');
rd5ok(!str_contains($migration,'ALTER TABLE crm_companies ADD COLUMN rd_'),'não pode espalhar rd_id em Empresa');
rd5ok(!str_contains($migration,'ALTER TABLE crm_contacts ADD COLUMN rd_'),'não pode espalhar rd_id em Contato');
rd5ok(!str_contains($migration,'ALTER TABLE crm_negotiations ADD COLUMN rd_'),'não pode espalhar rd_id em Negociação');

rd5ok(str_contains($service,"modify('-6 months')"),'primeira carga de negociações deve limitar a seis meses');
rd5ok(str_contains($service,"created_at:>="),'filtro de negócios deve usar RDQL por created_at');
rd5ok(str_contains($service,"'https://api.rd.services/crm/v2/'"),'CRM deve usar API v2');
foreach(['organizations','contacts','deals'] as $resource)
    rd5ok(str_contains($service,"'".$resource."'"),'sync deve processar '.$resource);
rd5ok(str_contains($service,"https://api.rd.services/platform/segmentations/"),'Marketing deve enumerar contatos pela segmentação');
rd5ok(str_contains($service,"Todos os contatos da base de Leads"),'Marketing deve localizar segmentação padrão da base');
rd5ok(str_contains($service,"https://api.rd.services/platform/contacts/"),'Marketing deve detalhar cada contato');
rd5ok(str_contains($service,"marketing_contact_details"),'fluxo precisa ter segunda passagem para campos detalhados');
rd5ok(str_contains($service,'$limit=10'),'detalhamento Marketing deve usar lotes pequenos');
rd5ok(str_contains($service,"time()+60"),'rate limit deve aplicar espera de segurança');
rd5ok(str_contains($service,"GET_LOCK"),'processamento deve impedir concorrência do mesmo job');
rd5ok(str_contains($service,"RELEASE_LOCK"),'lock do job deve ser liberado');
rd5ok(str_contains($service,"rdSyncRetomar"),'job com falha deve poder retomar do ponto salvo');

rd5ok(str_contains($service,"rdSyncFindCompanyByCnpj"),'Empresa deve deduplicar por CNPJ');
rd5ok(str_contains($service,"rdSyncFindContactByEmail"),'Contato deve deduplicar por e-mail');
rd5ok(str_contains($service,"duplicate_cnpj"),'CNPJ ambíguo deve virar conflito');
rd5ok(str_contains($service,"duplicate_email"),'e-mail ambíguo deve virar conflito');
rd5ok(str_contains($service,"cnpj_change_collision"),'mudança de CNPJ não pode colidir silenciosamente');
rd5ok(str_contains($service,"email_change_collision"),'mudança de e-mail não pode colidir silenciosamente');
rd5ok(str_contains($service,"manual_review"),'campos em revisão manual devem gerar conflito');
rd5ok(str_contains($service,"provider='rd_marketing'")&&str_contains($service,"'rd_crm'"),'IDs externos devem ser separados por provedor');
rd5ok(str_contains($service,"registrarVinculoExternoCrm"),'identidade externa deve usar crm_external_links');
rd5ok(str_contains($service,"rdSyncTouchLink"),'last_sync_at deve ser atualizado em registros já vinculados');

rd5ok(str_contains($service,"selected_for_sync=1"),'Marketing deve respeitar seleção da Fase 4');
rd5ok(str_contains($service,"target_field='cnpj'"),'primeira sync deve exigir CNPJ mapeado para dedupe segura');
rd5ok(str_contains($service,"contact.name")&&str_contains($service,"contact.email"),'nome e e-mail do Marketing devem ser pré-requisitos');
rd5ok(str_contains($service,"rd_pipeline_mappings WHERE enabled=1"),'funis descobertos devem ser pré-requisito');
rd5ok(str_contains($service,"rdSyncOwnerLocalId"),'responsável RD deve usar mapa de usuários');
rd5ok(str_contains($service,"rdSyncPipelineNames"),'negociação deve usar funil/etapa mapeados');
rd5ok(str_contains($service,"vincularContatoNegociacaoCrm"),'contatos devem ser ligados às negociações');

rd5ok(str_contains($service,"'origin'=>'rd_crm'"),'negociação importada deve registrar origem RD');
rd5ok(str_contains($service,"'order_status'=>'none'"),'negociação histórica importada não pode criar pedido pendente automaticamente');
rd5ok(!str_contains($service,'INSERT INTO pedidos')&&!str_contains($service,'criarPedido'),'motor RD não pode criar Pedido');
rd5ok(str_contains($neg,'Negociação histórica ganha no RD Station'),'UI deve identificar ganhos históricos sem Pedido local');

rd5ok(str_contains($api,'exigirPapel(PAPEL_ADMINISTRADOR)'),'endpoint do sync deve ser exclusivo de ADM');
rd5ok(str_contains($api,'exigirCsrf()'),'ações do sync precisam de CSRF');
foreach(["\$action==='cancel'","\$action==='resume'","\$action==='step'"] as $action)
    rd5ok(str_contains($api,$action),'endpoint precisa suportar '.$action);

rd5ok(str_contains($config,"\$acao === 'iniciar_rd_first_sync'"),'Configurações deve iniciar a primeira sincronização');
rd5ok(str_contains($admin,"rd_station_sync_panel.php"),'painel RD deve incluir Fase 5');
foreach(['Painel CMD · sincronização','Iniciar primeira sincronização','Conflitos pendentes','Execuções'] as $text)
    rd5ok(str_contains($panel,$text),'painel deve exibir '.$text);
rd5ok(str_contains($panel,'data-rd-sync-console'),'painel deve expor estado ao processador visual');
rd5ok(str_contains($panel,'Antes de sincronizar'),'painel deve exibir pré-requisitos');

rd5ok(str_contains($js,"/api/v1/rdstation-sync.php"),'JS deve processar pelo endpoint protegido');
rd5ok(str_contains($js,"X-CSRF-Token"),'JS deve enviar CSRF');
rd5ok(str_contains($js,"createTextNode"),'logs remotos devem ser renderizados sem injetar HTML');
rd5ok(str_contains($js,"setTimeout(step"),'processamento deve ocorrer visualmente em passos');
rd5ok(str_contains($js,"post('resume')"),'Painel CMD deve retomar job com erro');

echo "CRM Fase 5 primeira sincronização RD: OK\n";
