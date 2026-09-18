<?php
declare(strict_types=1);

$root=dirname(__DIR__);
function rd9file(string $path):string{
    global $root;
    $v=file_get_contents($root.'/'.$path);
    if($v===false){fwrite(STDERR,"FALHA: {$path} ausente\n");exit(1);}
    return $v;
}
function rd9ok(bool $cond,string $msg):void{
    if(!$cond){fwrite(STDERR,"FALHA: {$msg}\n");exit(1);}
}

$migration=rd9file('sql/migrations/20260918_rd_phase9_outbound_sync.sql');
$service=rd9file('includes/rd_phase9_service.php');
$sync=rd9file('includes/rd_sync_service.php');
$phase2=rd9file('includes/crm_phase2_service.php');
$config=rd9file('configuracoes.php');
$panel=rd9file('includes/rd_station_sync_panel.php');
$cron=rd9file('api/internal/rdstation/sync.php');
$webhook=rd9file('api/webhooks/rdstation/index.php');
$api=rd9file('api/v1/rdstation-sync.php');
$integration=rd9file('includes/integration_config.php');
$empresa=rd9file('empresa.php');
$contato=rd9file('contato.php');
$negociacao=rd9file('negociacao.php');

foreach(['rd_outbound_field_rules','rd_outbound_queue'] as $table)
    rd9ok(str_contains($migration,'CREATE TABLE IF NOT EXISTS '.$table),'migração deve criar '.$table);
rd9ok(str_contains($migration,"source_of_truth ENUM('rd','dnb','manual_review')"),'saída deve manter propriedade campo a campo');
rd9ok(str_contains($migration,'dnb_id VARCHAR(24) NOT NULL'),'fila deve usar dnb_id como identidade local');
rd9ok(!preg_match('/ALTER TABLE\s+crm_(companies|contacts|negotiations).*rd_id/is',$migration),'Fase 9 não pode espalhar rd_id nas entidades');
rd9ok(str_contains($migration,"('negotiation','status','status','rd'"),'status deve começar sob domínio do RD');
rd9ok(str_contains($migration,"('negotiation','lost_reason','lost_reason_id','rd'"),'motivo de perda deve começar sob domínio do RD');
foreach(["('company','name','name','dnb'","('contact','email','emails','dnb'","('negotiation','title','name','dnb'","('negotiation','stage_name','stage_id','dnb'"] as $seed)
    rd9ok(str_contains($migration,$seed),'migração deve semear regra '.$seed);

rd9ok(str_contains($integration,"'DNB_RD_OUTBOUND_AUTO_ENABLED'"),'configuração local deve aceitar ativação DNB → RD');
rd9ok(str_contains($service,"integracaoConfigValor('DNB_RD_OUTBOUND_AUTO_ENABLED','0')==='1'"),'automação deve iniciar desligada');
rd9ok(str_contains($service,"provider='rd_crm'"),'IDs remotos devem ser lidos de crm_external_links');
rd9ok(str_contains($service,'rdSyncCanLinkLocal'),'vínculo externo deve preservar identidade 1:1');
rd9ok(str_contains($service,'rdSyncRegisterLink'),'ID remoto criado deve ser salvo no vínculo isolado');

foreach([
    "'https://api.rd.services/crm/v2/'.$resource",
    "'organizations'","'contacts'","'deals'"
] as $needle)rd9ok(str_contains($service,$needle),'saída deve usar endpoint CRM v2: '.$needle);
rd9ok(str_contains($service,"$creating?'POST':'PUT'"),'criação deve usar POST e atualização PUT');
rd9ok(str_contains($service,"if($creating)$data['status']='ongoing'"),'negociação nova deve sempre nascer ongoing no RD');
rd9ok(str_contains($service,"rdPhase9Truth($pdo,'negotiation','status')==='dnb'"),'status final DNB deve ser aplicado por PUT após criação');
rd9ok(str_contains($service,"'/deals/'.rawurlencode($external)"),'status pós-criação deve atualizar pelo ID remoto seguro');
rd9ok(str_contains($service,'total_price')&&str_contains($service,'stage_id')&&str_contains($service,'owner_id'),'negociação deve enviar valor etapa e responsável mapeados');
rd9ok(str_contains($service,"['email'=>(string)$local['email']]"),'Contato deve enviar e-mail estruturado');
rd9ok(str_contains($service,"['phone'=>(string)$local['phone'],'type'=>'work']"),'Contato deve enviar telefone estruturado');
rd9ok(str_contains($service,"['line'=>(string)$local['address']]"),'Empresa deve enviar endereço estruturado');

rd9ok(str_contains($service,'rdPhase9PreflightLink'),'antes de criar deve procurar vínculo/duplicidade');
rd9ok(str_contains($service,"'organizations','@'.$map['source_key']"),'Empresa deve poder prevenir duplicidade via CNPJ customizado');
rd9ok(str_contains($service,"'contacts','email:'"),'Contato deve prevenir duplicidade por e-mail');
rd9ok(str_contains($service,'Mais de uma Empresa do RD corresponde ao CNPJ local'),'CNPJ ambíguo deve bloquear');
rd9ok(str_contains($service,'Mais de um Contato do RD corresponde ao e-mail local'),'e-mail ambíguo deve bloquear');
rd9ok(str_contains($service,"review_status")&&str_contains($service,"needs_review"),'registro local em revisão não pode sair automaticamente');

rd9ok(str_contains($service,'RdPhase9DependencyException'),'dependências automáticas devem aguardar sem bloquear definitivamente');
rd9ok(str_contains($service,'attempts=GREATEST(attempts-1,0)'),'espera de dependência não deve consumir tentativas');
rd9ok(str_contains($service,"CASE entity WHEN 'company' THEN 1 WHEN 'contact' THEN 2 ELSE 3 END"),'fila deve processar Empresa → Contato → Negociação');
rd9ok(str_contains($service,'rdPhase9EnsureDependenciesQueued'),'Negociação/Contato devem enfileirar dependências');
rd9ok(str_contains($service,"status='blocked'"),'mapeamento ausente deve virar bloqueio visível');
rd9ok(str_contains($service,'rdPhase9RequeueItem'),'ADM deve poder reabrir bloqueio após corrigir mapeamento');
rd9ok(str_contains($service,"(int)$res['status']===429"),'rate limit deve ser tratado');

foreach(['empresa_criada_localmente','empresa_atualizada_localmente','contato_criado_localmente','contato_atualizado_localmente','negociacao_criada_localmente','negociacao_atualizada_localmente','contato_vinculado_a_negociacao'] as $reason)
    rd9ok(str_contains($phase2,$reason),'alteração local deve alimentar fila: '.$reason);
rd9ok(str_contains($phase2,"require_once __DIR__ . '/rd_phase9_service.php'"),'serviço CRM deve carregar hook da Fase 9');
rd9ok(str_contains($sync,'function rdSyncTruth'),'entrada RD deve consultar dono do campo');
foreach([
    "rdSyncTruth($pdo,'company','name')",
    "rdSyncTruth($pdo,'contact','email')",
    "rdSyncTruth($pdo,'negotiation','status')",
    "rdSyncTruth($pdo,'negotiation','stage_name')"
] as $truth)rd9ok(str_contains($sync,$truth),'entrada RD deve respeitar '.$truth);

rd9ok(str_contains($cron,'rdPhase9ProcessQueue($pdo,5,false)'),'cron deve processar fila outbound quando automática');
rd9ok(str_contains($webhook,"require_once __DIR__ . '/../../../includes/rd_phase9_service.php'"),'webhook inbound deve carregar propriedade da Fase 9');
rd9ok(str_contains($api,"require_once __DIR__ . '/../../includes/rd_phase9_service.php'"),'sync manual inbound deve carregar propriedade da Fase 9');

foreach(["\$acao === 'salvar_rd_outbound'","\$acao === 'processar_rd_outbound_now'","\$acao === 'enfileirar_rd_outbound'","\$acao === 'reabrir_rd_outbound'"] as $action)
    rd9ok(str_contains($config,$action),'Configurações deve ter ação '.$action);
foreach(['Fase 9 · DNB → RD CRM','Saída controlada para o RD CRM','Ativar envio automático DNB → RD','Processar saída agora','Quem prevalece','Fila de saída','Reabrir'] as $label)
    rd9ok(str_contains($panel,$label),'painel deve mostrar '.$label);
foreach([$empresa,$contato,$negociacao] as $entityPage)
    rd9ok(str_contains($entityPage,'Colocar na fila RD')&&str_contains($entityPage,"$acao==='send_rd'"),'detalhes CRM devem permitir envio manual ADM');

rd9ok(!str_contains($service,'INSERT INTO pedidos')&&!str_contains($service,'UPDATE pedidos')&&!str_contains($service,'DELETE FROM pedidos'),'Fase 9 não pode criar/alterar Pedido');
rd9ok(!str_contains($service,'INSERT INTO orcamentos')&&!str_contains($service,'UPDATE orcamentos'),'Fase 9 não pode fabricar/alterar Orçamento');

echo "CRM Fase 9 saída DNB para RD: OK\n";
