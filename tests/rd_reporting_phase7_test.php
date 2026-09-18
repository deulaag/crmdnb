<?php
declare(strict_types=1);

$root=dirname(__DIR__);
function rd7file(string $path):string{
    global $root;
    $v=file_get_contents($root.'/'.$path);
    if($v===false){fwrite(STDERR,"FALHA: {$path} ausente\n");exit(1);}
    return $v;
}
function rd7ok(bool $cond,string $msg):void{
    if(!$cond){fwrite(STDERR,"FALHA: {$msg}\n");exit(1);}
}

$service=rd7file('includes/rd_reporting_service.php');
$panel=rd7file('painel.php');
$auditService=rd7file('includes/audit_dashboard_service.php');
$auditPage=rd7file('auditoria-relatorios.php');
$pdf=rd7file('includes/audit_pdf_service.php');
$csvEndpoint=rd7file('auditoria-relatorios-csv.php');

rd7ok(str_contains($panel,"require_once __DIR__ . '/includes/rd_reporting_service.php'"),'Painel deve carregar apuração de Negociações');
rd7ok(str_contains($panel,'Negociações são acompanhadas separadamente de Propostas'),'Painel deve explicar separação de Negociação e Proposta');
rd7ok(str_contains($panel,'não aumenta o KPI “Propostas emitidas”'),'Negociação não pode inflar Propostas');
rd7ok(str_contains($panel,'não cria Pedido automaticamente'),'ganho RD não pode fabricar Pedido');
foreach(['Movimentadas','Criadas','Ganhas','Perdidas','Origem RD'] as $label)
    rd7ok(str_contains($panel,$label),'Painel deve mostrar KPI '.$label);
rd7ok(str_contains($panel,'won_without_order_count'),'Painel deve sinalizar ganho sem Pedido DNB');
rd7ok(str_contains($panel,'rdReportingByPipeline'),'Painel deve resumir os mesmos funis/etapas do núcleo CRM');

rd7ok(str_contains($service,'crm_negotiations'),'relatório deve usar Negociações do núcleo CRM');
rd7ok(str_contains($service,'filtroEscopoAtribuicao($pdo,\'n.assigned_to_user_id\')'),'Painel deve respeitar escopo de vendedor/supervisor');
rd7ok(str_contains($service,'business_updated_at'),'competência deve observar movimentação da Negociação');
rd7ok(str_contains($service,'closed_at'),'ganhos/perdas devem observar fechamento');
rd7ok(str_contains($service,'closedThisMonth'),'ganho não deve repetir em todos os meses após o fechamento');
rd7ok(str_contains($service,'orders_count'),'relatório deve distinguir ganho com/sem Pedido');
rd7ok(!str_contains($service,'INSERT INTO pedidos')&&!str_contains($service,'UPDATE pedidos'),'relatório não pode criar/alterar Pedido');
rd7ok(!str_contains($service,'INSERT INTO orcamentos'),'relatório não pode fabricar Proposta');

rd7ok(str_contains($auditService,"'rd_negotiations'=>\$rdNegotiations"),'Auditoria deve receber Negociações separadas');
rd7ok(str_contains($auditService,"'rd_pipeline'=>rdReportingByPipeline"),'Auditoria deve consolidar funis e etapas');
rd7ok(str_contains($auditPage,'Exportar PDF')&&str_contains($auditPage,'Exportar CSV'),'Auditoria deve exportar PDF e CSV');
rd7ok(str_contains($auditPage,'Ganhas sem Pedido'),'Auditoria deve exibir pendência comercial sem gerar Pedido');
rd7ok(str_contains($auditPage,'Negociações não são somadas ao KPI'),'Auditoria deve manter Propostas separadas');
rd7ok(str_contains($pdf,'NEGOCIAÇÕES · NÚCLEO CRM'),'PDF deve ter bloco próprio de Negociações');
rd7ok(str_contains($pdf,'$table(\'Negociações\''),'PDF deve ter tabela de Negociações');
rd7ok(str_contains($csvEndpoint,'exigirPapel(PAPEL_ADMINISTRADOR)'),'CSV deve ser exclusivo do ADM');
rd7ok(str_contains($csvEndpoint,'rdReportingCsv'),'endpoint CSV deve usar gerador dedicado');

require_once $root.'/includes/rd_reporting_service.php';
$sample=[
 'rotulo'=>'setembro/2026',
 'rd_negotiations'=>[
   'summary'=>['total_count'=>2,'total_value'=>4000,'created_count'=>1,'created_value'=>1500,'won_count'=>1,'won_value'=>2500,'lost_count'=>0,'lost_value'=>0,'won_without_order_count'=>1,'won_without_order_value'=>2500],
   'items'=>[
     ['dnb_id'=>'NEG-000001','title'=>'Negócio RD','company_name'=>'Cliente A','owner_name'=>'ADM','category'=>'Equipamentos','pipeline_name'=>'FUNIL DE VENDAS EQUIP. / TRATAMENTO AR','stage_name'=>'Proposta','status'=>'won','origin'=>'rd_crm','estimated_value'=>2500,'business_created_at'=>'2026-08-01 10:00:00','business_updated_at'=>'2026-09-10 10:00:00','closed_at'=>'2026-09-10 10:00:00','contacts_count'=>2,'quotes_count'=>0,'orders_count'=>0],
   ],
 ],
 'orcamentos'=>[['data'=>'2026-09-02','numero'=>'ORC-1','cliente'=>'Cliente B','_responsavel'=>'ADM','linha'=>'Peças','etapa'=>'Proposta','status'=>'Aberta','valor'=>900]],
 'pedidos'=>[],
 'visitas'=>[],
];
$csv=rdReportingCsv($sample);
rd7ok(str_starts_with($csv,"\xEF\xBB\xBF"),'CSV deve abrir no Excel com BOM UTF-8');
rd7ok(str_contains($csv,'NEGOCIAÇÕES'),'CSV deve separar Negociações');
rd7ok(str_contains($csv,'PROPOSTAS / ORÇAMENTOS'),'CSV deve separar Propostas');
rd7ok(str_contains($csv,'NEG-000001'),'CSV deve conter dnb_id da Negociação');
rd7ok(str_contains($csv,'Ganhas sem Pedido DNB'),'CSV deve explicitar ganho sem Pedido');

echo "CRM Fase 7 painel e relatórios RD: OK\n";
