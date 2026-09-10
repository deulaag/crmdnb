<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/calc.php';
require_once __DIR__ . '/../../includes/lead_service.php';
require_once __DIR__ . '/../../includes/task_service.php';
require_once __DIR__ . '/../../includes/crm_directory_service.php';

header('Content-Type: application/json; charset=utf-8');
exigirPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR);
$pdo=db();$acao=(string)($_POST['acao']??'');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'erro'=>'Método não permitido.']);exit;}
exigirCsrf();

function textoSeguroModal(string $valor):string{return htmlspecialchars($valor,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function colunasDiretorioOportunidade(PDO $pdo):bool{try{$s=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orcamentos' AND COLUMN_NAME IN ('company_id','contact_id')");return (int)$s->fetchColumn()===2;}catch(Throwable){return false;}}

try{
 if($acao==='create'){
  $cliente=trim((string)($_POST['company_name']??$_POST['cliente']??''));$valor=(float)($_POST['valor']??0);$linha=trim((string)($_POST['linha']??''));
  if($cliente===''||$valor<=0||$linha==='')throw new InvalidArgumentException('Empresa, linha e valor são obrigatórios.');
  $vendedores=filtrarVendedoresDaEquipe($pdo,obterVendedores($pdo));$vendedorId=trim((string)($_POST['vendedor_id']??''))?:null;
  if($vendedorId&&!in_array($vendedorId,array_column($vendedores,'id'),true)){http_response_code(403);echo json_encode(['ok'=>false,'erro'=>'Vendedor fora do seu escopo.']);exit;}
  $assignedTo=usuarioIdPorVendedor($pdo,$vendedorId)?:(int)usuarioLogado()['id'];$cargoId=cargoParaRegistroComercial($pdo,$assignedTo,!empty($_POST['cargo_id'])?(int)$_POST['cargo_id']:null);
  $suporteId=trim((string)($_POST['suporte_id']??''))?:null;$data=trim((string)($_POST['data']??''))?:hoje();$funil=funilDe($linha);$funis=funis();
  if(!isset($funis[$funil]))throw new InvalidArgumentException('Funil inválido para a linha informada.');
  $numero=trim((string)($_POST['numero']??''))?:gerarNumeroProposta($pdo,$vendedores,$vendedorId,$suporteId,$data);
  $pdo->beginTransaction();$companyId=null;$contactId=null;
  if(diretorioCrmDisponivel($pdo)&&colunasDiretorioOportunidade($pdo)){
   $dir=resolverEmpresaContatoCrm($pdo,['company_name'=>$cliente,'cnpj'=>(string)($_POST['cnpj']??''),'address'=>(string)($_POST['company_address']??''),'locality'=>(string)($_POST['company_locality']??''),'contact_name'=>(string)($_POST['contact_name']??''),'phone'=>(string)($_POST['contact_phone']??''),'email'=>(string)($_POST['contact_email']??''),'contact_role'=>(string)($_POST['contact_role']??'')]);
   $companyId=(int)($dir['company']['id']??0)?:null;$contactId=(int)($dir['contact']['id']??0)?:null;
   $stmt=$pdo->prepare('INSERT INTO orcamentos (numero,data,cliente,company_id,contact_id,vendedor_id,suporte_id,linha,tipo,funil,etapa,valor,comissao_dnb_estimada,status,origem,assigned_to_user_id,cargo_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
   $stmt->execute([$numero,$data,$cliente,$companyId,$contactId,$vendedorId,$suporteId,$linha,tipoDe($linha),$funil,$funis[$funil]['etapas'][0],$valor,(float)($_POST['comissao_dnb_estimada']??0),'Aberta','manual',$assignedTo,$cargoId]);
  }else{
   $stmt=$pdo->prepare('INSERT INTO orcamentos (numero,data,cliente,vendedor_id,suporte_id,linha,tipo,funil,etapa,valor,comissao_dnb_estimada,status,origem,assigned_to_user_id,cargo_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
   $stmt->execute([$numero,$data,$cliente,$vendedorId,$suporteId,$linha,tipoDe($linha),$funil,$funis[$funil]['etapas'][0],$valor,(float)($_POST['comissao_dnb_estimada']??0),'Aberta','manual',$assignedTo,$cargoId]);
  }
  $leadId=(int)$pdo->lastInsertId();registrarTimeline($pdo,$leadId,'created','Oportunidade criada manualmente para '.$cliente.'.');
  if($companyId)registrarTimeline($pdo,$leadId,'directory_link','Empresa e contato sincronizados com o diretório do CRM.');
  $taskTitle=trim((string)($_POST['task_title']??''));$taskDue=trim((string)($_POST['task_due_date']??''));$taskId=null;
  if($taskDue!==''){$taskId=criarTarefaOrcamento($pdo,$leadId,$assignedTo,$taskTitle?:'Acompanhar orçamento',$taskDue,(int)usuarioLogado()['id'],(string)($_POST['task_description']??''));registrarTimeline($pdo,$leadId,'task_created','Tarefa criada para '.$taskDue.': '.($taskTitle?:'Acompanhar orçamento').'.');}
  $pdo->commit();echo json_encode(['ok'=>true,'lead_id'=>$leadId,'numero'=>textoSeguroModal($numero),'task_id'=>$taskId,'company_id'=>$companyId,'contact_id'=>$contactId]);exit;
 }

 if($acao==='set_stage'){
  $leadId=(int)($_POST['id']??0);if(!$leadId||!podeAcessarLead($pdo,$leadId)){http_response_code(403);echo json_encode(['ok'=>false,'erro'=>'Oportunidade fora do seu escopo.']);exit;}
  $stmt=$pdo->prepare('SELECT * FROM orcamentos WHERE id=? LIMIT 1');$stmt->execute([$leadId]);$o=$stmt->fetch();$novaEtapa=trim((string)($_POST['etapa']??''));$funil=$o?funilDe((string)$o['linha']):'';$etapas=funis()[$funil]['etapas']??[];
  if(!$o||$o['status']!=='Aberta'||!in_array($novaEtapa,$etapas,true)){http_response_code(422);echo json_encode(['ok'=>false,'erro'=>'Etapa inválida para esta oportunidade.']);exit;}
  $pdo->beginTransaction();$pedido=null;$gerado=false;
  if($novaEtapa==='Fechamento'){
   $stmtPed=$pdo->prepare('SELECT id,numero FROM pedidos WHERE orcamento_id=? ORDER BY id DESC LIMIT 1');$stmtPed->execute([$leadId]);$pedido=$stmtPed->fetch()?:null;
   if(!$pedido){$numeroPedido='PV-'.((string)($o['numero']?:$o['id']));$modalidade=ehAgenciamento((string)$o['linha'])?'agenciada':'revenda';$pdo->prepare('INSERT INTO pedidos (numero,data,cliente,vendedor_id,suporte_id,linha,valor,modalidade,comissao_dnb,faturado,orcamento_id,assigned_to_user_id,cargo_id) VALUES (?,?,?,?,?,?,?,?,?,0,?,?,?)')->execute([$numeroPedido,hoje(),$o['cliente'],$o['vendedor_id'],$o['suporte_id'],$o['linha'],(float)$o['valor'],$modalidade,$modalidade==='agenciada'?(float)$o['comissao_dnb_estimada']:0,$leadId,$o['assigned_to_user_id'],$o['cargo_id']]);$pedido=['id'=>(int)$pdo->lastInsertId(),'numero'=>$numeroPedido];$gerado=true;}
   $pdo->prepare("UPDATE orcamentos SET etapa='Fechamento',status='Ganha',cad_pausada=1 WHERE id=?")->execute([$leadId]);registrarTimeline($pdo,$leadId,'funnel_update','Etapa atualizada para Fechamento. Pedido de venda '.($gerado?'gerado automaticamente':'já existente').'.');registrarTimeline($pdo,$leadId,'status_update','Status atualizado automaticamente para Ganha ao entrar em Fechamento.');
  }else{$pdo->prepare('UPDATE orcamentos SET etapa=? WHERE id=?')->execute([$novaEtapa,$leadId]);registrarTimeline($pdo,$leadId,'funnel_update','Etapa atualizada para '.$novaEtapa.'.');}
  $pdo->commit();if(is_array($pedido))$pedido['numero']=textoSeguroModal((string)($pedido['numero']??''));echo json_encode(['ok'=>true,'stage'=>$novaEtapa,'order_generated'=>$gerado,'order'=>$pedido]);exit;
 }

 if($acao==='complete_task'){concluirTarefa($pdo,(int)($_POST['task_id']??0));echo json_encode(['ok'=>true]);exit;}
 if($acao==='add_task'){
  $leadId=(int)($_POST['id']??0);if(!$leadId||!podeAcessarLead($pdo,$leadId)){http_response_code(403);echo json_encode(['ok'=>false,'erro'=>'Oportunidade fora do seu escopo.']);exit;}
  $stmt=$pdo->prepare('SELECT assigned_to_user_id FROM orcamentos WHERE id=?');$stmt->execute([$leadId]);$assigned=(int)$stmt->fetchColumn();if($assigned<=0)$assigned=(int)usuarioLogado()['id'];
  $taskId=criarTarefaOrcamento($pdo,$leadId,$assigned,trim((string)($_POST['task_title']??'')),trim((string)($_POST['task_due_date']??'')),(int)usuarioLogado()['id'],(string)($_POST['task_description']??''));registrarTimeline($pdo,$leadId,'task_created','Nova tarefa adicionada ao orçamento.');echo json_encode(['ok'=>true,'task_id'=>$taskId]);exit;
 }
 http_response_code(422);echo json_encode(['ok'=>false,'erro'=>'Ação inválida.']);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('api opportunities: '.$e->getMessage());http_response_code(422);echo json_encode(['ok'=>false,'erro'=>$e instanceof InvalidArgumentException||$e instanceof RuntimeException?$e->getMessage():'Não foi possível concluir a operação.']);}
