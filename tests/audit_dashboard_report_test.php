<?php
declare(strict_types=1);

$root=dirname(__DIR__);
function auditOk(bool $ok,string $msg):void{if(!$ok){fwrite(STDERR,"FALHA: {$msg}\n");exit(1);}}
function auditFile(string $path):string{global $root;$v=file_get_contents($root.'/'.$path);auditOk($v!==false,"arquivo {$path} deve existir");return (string)$v;}

$page=auditFile('auditoria-relatorios.php');
$pdfEndpoint=auditFile('auditoria-relatorios-pdf.php');
$csvEndpoint=auditFile('auditoria-relatorios-csv.php');
$service=auditFile('includes/audit_dashboard_service.php');
$header=auditFile('includes/layout_header.php');
$auditLog=auditFile('auditoria.php');

auditOk(str_contains($page,'exigirPapel(PAPEL_ADMINISTRADOR)'), 'relatório deve ser exclusivo do ADM');
auditOk(str_contains($pdfEndpoint,'exigirPapel(PAPEL_ADMINISTRADOR)'), 'PDF deve ser exclusivo do ADM');
auditOk(str_contains($csvEndpoint,'exigirPapel(PAPEL_ADMINISTRADOR)'), 'CSV deve ser exclusivo do ADM');
auditOk(str_contains($page,'Exportar PDF')&&str_contains($page,'Exportar CSV'), 'cada competência deve ter exportação PDF e CSV');
auditOk(str_contains($page,'foreach($relatorios as $m=>$r)'), 'meses devem ser renderizados separadamente');
auditOk(str_contains($service,'calcularMes($pdo,$mes,$vendedores)'), 'auditoria deve usar o mesmo motor do Painel');
auditOk(str_contains($service,'mesesAuditoriaPainel'), 'deve existir histórico mensal contínuo');
foreach(['Negociações','Oportunidades / propostas','Pedidos faturados','Visitas e prospecções','Apuração por colaborador'] as $secao)auditOk(str_contains($page,$secao),"deve exibir seção {$secao}");
auditOk(str_contains($header,"'rotulo' => 'Auditoria'"), 'sidebar deve ter grupo Auditoria');
auditOk(str_contains($header,"'Relatório do Painel'"), 'sidebar deve ter relatório do painel');
auditOk(str_contains($header,"'Log de Fluxos'"), 'sidebar deve manter log de fluxos dentro de Auditoria');
auditOk(str_contains($header,"[PAPEL_ADMINISTRADOR]"), 'itens da Auditoria devem ser exclusivos do ADM');

require_once $root.'/includes/audit_pdf_service.php';
$sample=[
 'mes'=>'2026-09','rotulo'=>'setembro/2026',
 'resumo'=>['propostas'=>1000,'metaPropostas'=>2000,'revenda'=>500,'metaRevenda'=>1000,'agenciadas'=>250,'metaAgenciadas'=>500,'atividade'=>3,'metaAtividade'=>10,'ganhos'=>45],
 'contagens'=>['propostas'=>1,'revenda'=>1,'agenciadas'=>0,'atividade'=>1],
 'orcamentos'=>[['id'=>1,'data'=>'2026-09-01','numero'=>'ORC-1','cliente'=>'Cliente Teste','_responsavel'=>'ADM','linha'=>'Equipamentos','etapa'=>'Proposta','status'=>'Aberta','valor'=>1000]],
 'pedidos'=>[['id'=>1,'data'=>'2026-09-02','data_fat'=>'2026-09-03','numero'=>'PED-1','cliente'=>'Cliente Teste','_responsavel'=>'ADM','modalidade'=>'revenda','linha'=>'Equipamentos','valor'=>500]],
 'visitas'=>[['data'=>'2026-09-04','os'=>'OS-1','cliente'=>'Cliente Teste','_responsavel'=>'ADM','tipo'=>'visita','oportunidade'=>'Teste']],
 'pessoas'=>[['nome'=>'ADM','propostas'=>1000,'meta_propostas'=>2000,'revenda'=>500,'meta_revenda'=>1000,'agenciadas'=>250,'meta_agenciadas'=>500,'visitas'=>1,'prospeccoes'=>2,'meta_atividade'=>10,'ganhos'=>45]],
 'rd_negotiations'=>[
   'summary'=>['total_count'=>2,'total_value'=>4000,'created_count'=>1,'created_value'=>1500,'won_count'=>1,'won_value'=>2500,'lost_count'=>0,'lost_value'=>0,'rd_count'=>2,'rd_value'=>4000,'won_without_order_count'=>1,'won_without_order_value'=>2500],
   'items'=>[
     ['dnb_id'=>'NEG-000001','title'=>'Negócio RD','company_name'=>'Cliente Teste','owner_name'=>'ADM','category'=>'Equipamentos','pipeline_name'=>'FUNIL DE VENDAS EQUIP. / TRATAMENTO AR','stage_name'=>'Proposta','status'=>'won','origin'=>'rd_crm','estimated_value'=>2500],
     ['dnb_id'=>'NEG-000002','title'=>'Outro negócio','company_name'=>'Cliente 2','owner_name'=>'ADM','category'=>'Peças','pipeline_name'=>'VENDAS (PEÇAS)','stage_name'=>'Qualificação','status'=>'open','origin'=>'rd_crm','estimated_value'=>1500],
   ],
 ],
 'rd_pipeline'=>[['pipeline'=>'FUNIL DE VENDAS EQUIP. / TRATAMENTO AR','stage'=>'Proposta','count'=>1,'open'=>0,'won'=>1,'lost'=>0,'value'=>2500]],
];
$bytes=gerarPdfAuditoriaPainel($sample);
auditOk(str_starts_with($bytes,'%PDF-1.4'), 'exportação deve gerar PDF real');
auditOk(str_contains($bytes,'xref')&&str_contains($bytes,'%%EOF'), 'PDF deve ter estrutura válida básica');
auditOk(strlen($bytes)>3000, 'PDF estilizado não pode ser vazio');

echo "Auditoria relatório mensal: OK\n";
