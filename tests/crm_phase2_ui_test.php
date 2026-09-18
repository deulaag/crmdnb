<?php
declare(strict_types=1);

$root=dirname(__DIR__);
function p2ok(bool $ok,string $msg):void{if(!$ok){fwrite(STDERR,"FALHA: {$msg}\n");exit(1);}}
function p2file(string $path):string{global $root;$v=file_get_contents($root.'/'.$path);if($v===false){fwrite(STDERR,"FALHA: {$path} ausente\n");exit(1);}return $v;}

$header=p2file('includes/layout_header.php');
foreach([
    "['empresas', 'Empresas', 'empresas.php'",
    "['contatos', 'Contatos', 'contatos.php'",
    "['neg', 'Negociações', 'negociacoes.php'",
] as $needle)p2ok(str_contains($header,$needle),"menu comercial ausente: {$needle}");
p2ok(!str_contains($header,"['orc', 'Orçamentos', 'orcamentos.php'"),'Orçamentos não pode permanecer isolado na lateral');
p2ok(str_contains($header,"\$k === 'neg' && \$tabAtual === 'orc'"),'Orçamentos deve manter Negociações selecionado');

foreach(['empresas.php','empresa.php','contatos.php','contato.php','negociacoes.php','negociacao.php'] as $page){
    $src=p2file($page);
    p2ok(str_contains($src,'exigirPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR)'),
        "{$page} precisa respeitar RBAC comercial");
    p2ok(str_contains($src,'crmPhase2Disponivel($pdo)'),"{$page} deve verificar a migração da Fase 2");
}

$empresa=p2file('empresa.php');
foreach(['Resumo','Contatos','Negociações','Orçamentos','Pedidos','Visitas','Histórico'] as $tab)
    p2ok(str_contains($empresa,"'{$tab}'")||str_contains($empresa,">{$tab}<"),"empresa deve conter aba {$tab}");
p2ok(str_contains($empresa,'timelineEmpresaCrmUi'),'empresa deve consolidar histórico');
p2ok(str_contains($empresa,'add_contact'),'empresa deve permitir cadastrar contato vinculado');

$contato=p2file('contato.php');
foreach(['Dados','Empresa','Negociações','Marketing','Visitas','Histórico'] as $tab)
    p2ok(str_contains($contato,"'{$tab}'")||str_contains($contato,">{$tab}<"),"contato deve conter aba {$tab}");
p2ok(str_contains($contato,'Contatos sem empresa'),'contato sem empresa deve permanecer explícito');
p2ok(str_contains($contato,'Nenhum dado externo é simulado'),'Marketing não deve inventar dados antes da integração');

$negList=p2file('negociacoes.php');
p2ok(str_contains($negList,"\$modo=(string)(\$_GET['modo']??'lista')"),'Negociações deve abrir em lista por padrão');
p2ok(str_contains($negList,"['lista','kanban']"),'Negociações deve oferecer lista e Kanban');
p2ok(str_contains($negList,'Leads / Pré-vendas')&&str_contains($negList,'Orçamentos'),'subnavegação deve ter Leads, Negociações e Orçamentos');
p2ok(str_contains($negList,'listarLeadsPreVendasCrmUi'),'Pré-vendas deve usar contatos ainda sem negociação');
p2ok(str_contains($negList,'criarNegociacaoCrmUi'),'nova negociação deve usar serviço seguro');
p2ok(str_contains($negList,"'contacts'=>\$contactId>0"),'negociação deve aceitar contato principal');

$neg=p2file('negociacao.php');
foreach(['Contatos da negociação','Orçamentos / propostas','Pedidos','Visitas'] as $rel)
    p2ok(str_contains($neg,$rel),"detalhe da negociação deve mostrar {$rel}");
p2ok(str_contains($neg,'vincularContatoNegociacaoCrmUi'),'negociação deve permitir múltiplos contatos');
p2ok(str_contains($neg,'vincularOrcamentoNegociacaoCrmUi'),'negociação deve permitir consolidar propostas');
p2ok(str_contains($neg,'Gerar Pedido a partir de'),'negociação ganha deve oferecer geração explícita de pedido');
p2ok(str_contains($neg,"order_status']==='pending'"),'pedido pendente deve aparecer na negociação');

$service=p2file('includes/crm_phase2_service.php');
p2ok(str_contains($service,'created_by_user_id IN'),'escopo de empresa/contato deve incluir criador');
p2ok(str_contains($service,'function crmEscopoEmpresa'),'empresa deve respeitar escopo da equipe');
p2ok(str_contains($service,'function crmEscopoContato'),'contato deve respeitar escopo da equipe');
p2ok(str_contains($service,'function crmEscopoNegociacao'),'negociação deve respeitar responsável/equipe');
p2ok(str_contains($service,'function vincularContatoNegociacaoCrmUi'),'vínculo de contato deve validar empresa');
p2ok(str_contains($service,"origin='legacy_orcamento'"),'proposta legada 1:1 deve poder ser consolidada com segurança');
p2ok(str_contains($service,'UPDATE pedidos SET negotiation_id=? WHERE negotiation_id=?'),'pedidos devem acompanhar consolidação da proposta');
p2ok(str_contains($service,'UPDATE visitas SET negotiation_id=? WHERE negotiation_id=?'),'visitas devem acompanhar consolidação da proposta');
p2ok(str_contains($service,"\$status==='won'?'pending':'none'"),'negociação ganha sem pedido deve permanecer pendente');

$migration=p2file('sql/migrations/20260918_crm_phase2_ui_ownership.sql');
p2ok(str_contains($migration,"dnb_p2_add_column('crm_companies','created_by_user_id'"),'empresa precisa registrar criador para RBAC');
p2ok(str_contains($migration,"dnb_p2_add_column('crm_contacts','created_by_user_id'"),'contato precisa registrar criador para RBAC');
p2ok(str_contains($migration,'Empresa sem CNPJ: conferir cadastro'),'empresa sem CNPJ precisa ir para revisão');

$quotes=p2file('orcamentos.php');
p2ok(str_contains($quotes,'negotiationContextId'),'Orçamentos deve aceitar contexto da negociação');
p2ok(str_contains($quotes,'vincularOrcamentoNegociacaoCrmUi'),'novo orçamento deve poder entrar em negociação existente');
p2ok(str_contains($quotes,'Leads / Pré-vendas')&&str_contains($quotes,'class="is-active" href="/orcamentos.php"'),'Orçamentos deve estar dentro da subnavegação de Negociações');

$api=p2file('api/v1/opportunities.php');
p2ok(str_contains($api,"\$_POST['negotiation_id']"),'API de criação deve receber negotiation_id');
p2ok(str_contains($api,'vincularOrcamentoNegociacaoCrmUi'),'API AJAX deve preservar o vínculo com negociação');

$js=p2file('assets/crm-phase2.js');
p2ok(str_contains($js,'data-modal-open'),'nova UI deve abrir modais por atributo');
p2ok(str_contains($js,'data-auto-open="1"'),'nova negociação a partir do lead deve abrir formulário automaticamente');
$css=p2file('assets/crm-phase2.css');
p2ok(str_contains($css,'.dnb-crm-subnav'),'subnavegação precisa de estilo');
p2ok(str_contains($css,'.dnb-neg-kanban'),'Kanban de negociações precisa ser responsivo');

echo "CRM Fase 2 UI e relacionamentos: OK\n";
