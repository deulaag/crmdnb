<?php
declare(strict_types=1);

$root=dirname(__DIR__);
function rd4file(string $path):string{
    global $root;
    $v=file_get_contents($root.'/'.$path);
    if($v===false){fwrite(STDERR,"FALHA: {$path} ausente\n");exit(1);}
    return $v;
}
function rd4ok(bool $cond,string $msg):void{
    if(!$cond){fwrite(STDERR,"FALHA: {$msg}\n");exit(1);}
}

$migration=rd4file('sql/migrations/20260918_rd_phase4_discovery_mapping.sql');
$service=rd4file('includes/rd_discovery_service.php');
$panel=rd4file('includes/rd_station_admin_panel.php');
$config=rd4file('configuracoes.php');
$list=rd4file('negociacoes.php');
$detail=rd4file('negociacao.php');
$js=rd4file('assets/crm-phase2.js');

foreach(['rd_discovery_runs','rd_discovery_items','rd_field_mappings','rd_user_mappings','rd_pipeline_mappings'] as $table)
    rd4ok(str_contains($migration,'CREATE TABLE IF NOT EXISTS '.$table),'migração deve criar '.$table);
rd4ok(str_contains($migration,"source_of_truth ENUM('rd','dnb','manual_review')"),'fonte oficial deve ser configurável campo a campo');
rd4ok(str_contains($migration,'rd_user_external_id'),'IDs externos de usuários devem ficar isolados no módulo RD');
rd4ok(str_contains($migration,'rd_pipeline_external_id')&&str_contains($migration,'rd_stage_external_id'),'IDs externos de funis/etapas devem ficar isolados');
rd4ok(!str_contains($migration,'ALTER TABLE crm_negotiations ADD COLUMN rd_'),'Fase 4 não pode espalhar rd_id na entidade Negociação');
rd4ok(!str_contains($migration,'ALTER TABLE crm_contacts ADD COLUMN rd_'),'Fase 4 não pode espalhar rd_id em Contatos');
rd4ok(!str_contains($migration,'ALTER TABLE crm_companies ADD COLUMN rd_'),'Fase 4 não pode espalhar rd_id em Empresas');

rd4ok(str_contains($service,"'https://api.rd.services/platform/contacts/fields'"),'Marketing deve descobrir campos da conta');
foreach(['users?page[size]=100','pipelines?page[size]=100','custom_fields?page[size]=100','sources?page[size]=100','lost_reasons?page[size]=100','segments?page[size]=100','campaigns?page[size]=100'] as $endpoint)
    rd4ok(str_contains($service,$endpoint),'descoberta CRM deve consultar '.$endpoint);
rd4ok(str_contains($service,"'/stages?page[size]=100&sort[order]=asc'"),'descoberta deve ler etapas de cada funil');
rd4ok(str_contains($service,"$res['json']['links']['next']"),'paginação CRM deve seguir links.next');
rd4ok(str_contains($service,"strtolower((string)($parts['host']??''))!=='api.rd.services'"),'paginação deve bloquear host externo');
rd4ok(str_contains($service,'rdDiscoveryRefreshDerivedMappings'),'descoberta deve preparar mapas derivados');
rd4ok(str_contains($service,"LOWER(TRIM(u.email))=LOWER(TRIM(m.rd_user_email))"),'usuários devem sugerir vínculo por e-mail exato');
rd4ok(str_contains($service,'function rdPipelineCatalogo'),'funis habilitados devem virar catálogo local');
rd4ok(str_contains($service,'rdSalvarSelecaoMarketing'),'campos Marketing devem ser selecionáveis');
rd4ok(str_contains($service,'rdSalvarFieldMappings'),'campos devem ser mapeáveis');
rd4ok(str_contains($service,"['rd','dnb','manual_review']"),'mapeamento deve preservar fonte oficial por campo');
rd4ok(!str_contains($service,'INSERT INTO crm_contacts')&&!str_contains($service,'INSERT INTO crm_companies')&&!str_contains($service,'INSERT INTO crm_negotiations'),'descoberta não deve importar registros comerciais');

foreach(['descobrir_rdstation','salvar_rd_marketing_mapping','salvar_rd_crm_fields','salvar_rd_users','salvar_rd_pipelines'] as $action)
    rd4ok(str_contains($config,"$acao === '".$action."'"),'Configurações deve tratar '.$action);
rd4ok(str_contains($config,"include __DIR__ . '/includes/rd_station_admin_panel.php'"),'aba RD deve incluir o painel da Fase 4');

foreach(['Ler estrutura do Marketing','Ler estrutura do CRM','Campos de Contatos e Pré-vendas','Usuários / responsáveis','Funis e etapas reais da conta','Campos personalizados','Painel CMD · descoberta'] as $text)
    rd4ok(str_contains($panel,$text),'painel RD deve exibir '.$text);
rd4ok(str_contains($panel,'RD Station prevalece')&&str_contains($panel,'CRM DNB prevalece')&&str_contains($panel,'Revisar conflito'),'UI deve permitir fonte oficial campo a campo');
rd4ok(str_contains($panel,'inativo no RD'),'usuários RD inativos devem permanecer visíveis');
rd4ok(str_contains($panel,'IDs externos permanecem isolados'),'UI deve explicar fronteira de IDs externos');

rd4ok(str_contains($list,"require_once __DIR__ . '/includes/rd_discovery_service.php'"),'nova Negociação deve carregar catálogo RD');
rd4ok(str_contains($list,'rdPipelineCatalogo($pdo)'),'lista de Negociações deve incluir funis descobertos');
rd4ok(str_contains($list,'data-rd-pipeline')&&str_contains($list,'data-rd-stage'),'nova Negociação deve usar funil/etapa relacionados');
rd4ok(str_contains($detail,'rdPipelineCatalogo($pdo)'),'edição de Negociação deve usar catálogo RD');
rd4ok(str_contains($detail,'· legado'),'edição deve preservar funil/etapa legado fora do catálogo');
rd4ok(str_contains($js,'initRdPipelineSelects')&&str_contains($js,'option.dataset.pipeline'),'UI deve filtrar etapas pelo funil');

echo "CRM Fase 4 RD discovery e mapping: OK\n";
