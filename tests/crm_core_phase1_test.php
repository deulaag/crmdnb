<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/includes/dnb_id_service.php';
require_once $root.'/includes/crm_core_service.php';

function coreOk(bool $ok,string $msg):void{if(!$ok){fwrite(STDERR,"FALHA: {$msg}\n");exit(1);}}
function coreFile(string $path):string{global $root;$v=file_get_contents($root.'/'.$path);if($v===false){fwrite(STDERR,"FALHA: {$path} ausente\n");exit(1);}return $v;}

coreOk(formatarDnbId('EMP',1)==='EMP-000001','Empresa deve usar EMP-000001');
coreOk(formatarDnbId('CON',42)==='CON-000042','Contato deve usar CON-000042');
coreOk(dnbIdEntidade('negotiation',184)==='NEG-000184','Negociação deve usar NEG');
coreOk(dnbIdEntidade('quote',813)==='ORC-000813','Orçamento deve usar ORC');
coreOk(dnbIdEntidade('order',7)==='PED-000007','Pedido deve usar PED');
coreOk(dnbIdEntidade('visit',9)==='VIS-000009','Visita deve usar VIS');

$contrato=contratoCrmCoreFase1();
coreOk(($contrato['company']['primary_key']??'')==='cnpj','CNPJ deve ser chave comercial principal da empresa');
coreOk(($contrato['company']['duplicates']??'')==='review','CNPJ duplicado deve ir para revisão');
coreOk(($contrato['contact']['primary_key']??'')==='email','e-mail deve ser identificador comercial principal do contato');
coreOk(($contrato['contact']['company_optional']??false)===true,'contato sem empresa deve ser permitido');
coreOk(($contrato['contact']['duplicates']??'')==='review','e-mail duplicado deve ir para revisão');
coreOk(($contrato['negotiation']['category']??'')==='single','categoria da negociação deve ser única');
coreOk(($contrato['negotiation']['contacts']??'')==='many','negociação deve aceitar vários contatos');
coreOk(($contrato['negotiation']['quotes']??'')==='many','negociação deve aceitar vários orçamentos');
coreOk(($contrato['negotiation']['orders']??'')==='many','negociação deve aceitar vários pedidos');
coreOk(($contrato['external_ids']['visible_to_user']??true)===false,'IDs externos devem ficar fora das entidades comerciais');

$sql=coreFile('sql/migrations/20260918_crm_core_entities_phase1.sql');
foreach(['crm_companies','crm_contacts','crm_negotiations','crm_negotiation_contacts','crm_external_links'] as $table){
    coreOk(str_contains($sql,$table),"migração deve conter {$table}");
}
coreOk(str_contains($sql,"CALL dnb_core_drop_index('crm_companies','uq_crm_companies_cnpj')"),'CNPJ não pode ser UNIQUE porque duplicidade exige conferência');
coreOk(str_contains($sql,'ALTER TABLE crm_contacts MODIFY company_id INT DEFAULT NULL'),'contato sem empresa deve ser suportado no banco');
coreOk(str_contains($sql,"review_status='needs_review'"),'duplicidades devem ser sinalizadas para revisão');
coreOk(str_contains($sql,"'principal','decisor','comprador','tecnico','financeiro','outro'"),'papéis dos contatos da negociação devem existir');
coreOk(str_contains($sql,"UPDATE crm_companies SET dnb_id=CONCAT('EMP-'"),'empresas legadas precisam receber dnb_id');
coreOk(str_contains($sql,"UPDATE crm_contacts SET dnb_id=CONCAT('CON-'"),'contatos legados precisam receber dnb_id');
coreOk(str_contains($sql,"UPDATE orcamentos SET dnb_id=CONCAT('ORC-'"),'orçamentos legados precisam receber dnb_id');
coreOk(str_contains($sql,"UPDATE pedidos SET dnb_id=CONCAT('PED-'"),'pedidos legados precisam receber dnb_id');
coreOk(str_contains($sql,"UPDATE visitas SET dnb_id=CONCAT('VIS-'"),'visitas legadas precisam receber dnb_id');
coreOk(str_contains($sql,'INSERT INTO crm_negotiations'),'orçamentos atuais devem virar negociações de transição');
coreOk(str_contains($sql,'UPDATE orcamentos o')&&str_contains($sql,'SET o.negotiation_id=n.id'),'orçamentos devem ser ligados à negociação criada');
coreOk(!preg_match('/\brd_id\b/i',$sql),'nenhuma entidade deve receber coluna rd_id');

$service=coreFile('includes/crm_core_service.php');
coreOk(str_contains($service,'function criarEmpresaCrmCore'),'serviço deve criar empresa');
coreOk(str_contains($service,'function criarContatoCrmCore'),'serviço deve criar contato');
coreOk(str_contains($service,'function criarNegociacaoCrmCore'),'serviço deve criar negociação');
coreOk(str_contains($service,'function vincularContatoNegociacaoCrm'),'serviço deve relacionar contatos e negociação');
coreOk(str_contains($service,'function registrarVinculoExternoCrm'),'vínculos externos devem ficar em tabela técnica');
coreOk(str_contains($service,'garantirNegociacaoParaOrcamento($pdo,$orcamentoId)'),'sincronização legado→negociação deve usar adaptador e não recursão');
coreOk(!str_contains($service,"function sincronizarNegociacaoDoOrcamento(PDO \$pdo, int \$orcamentoId): int\n{\n    if (\$orcamentoId<=0 || !crmCoreFase1Disponivel(\$pdo)) return 0;\n    \$negId=sincronizarNegociacaoDoOrcamento"),'sincronização não pode chamar a si mesma');

$legacy=coreFile('includes/crm_directory_service.php');
coreOk(str_contains($legacy,"require_once __DIR__ . '/dnb_id_service.php'"),'diretório atual deve participar do dnb_id');
coreOk(str_contains($legacy,"garantirDnbIdRegistro(\$pdo,'crm_companies'"),'empresa criada pelo fluxo atual deve ganhar dnb_id');
coreOk(str_contains($legacy,"garantirDnbIdRegistro(\$pdo,'crm_contacts'"),'contato criado pelo fluxo atual deve ganhar dnb_id');

$orc=coreFile('orcamentos.php');
coreOk(str_contains($orc,"require_once __DIR__ . '/includes/crm_core_service.php'"),'Orçamentos deve carregar compatibilidade da Fase 1');
coreOk(str_contains($orc,'garantirNegociacaoParaOrcamento'),'novos orçamentos devem ganhar negociação durante transição');

$api=coreFile('api/v1/opportunities.php');
coreOk(str_contains($api,'garantirNegociacaoParaOrcamento'),'API de oportunidades deve criar negociação de transição');
coreOk(str_contains($api,'sincronizarNegociacaoDoOrcamento'),'mudança de etapa deve refletir na negociação');

echo "CRM core Fase 1: OK\n";
