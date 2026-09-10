<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/rbac.php';
require_once __DIR__.'/../../includes/task_service.php';
require_once __DIR__.'/../../includes/profile_service.php';
header('Content-Type: application/json; charset=utf-8');
exigirPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR);
$pdo=db();
if(!tarefasDisponiveis($pdo)){http_response_code(503);echo json_encode(['ok'=>false,'erro'=>'Módulo de tarefas não instalado.']);exit;}
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
  $sort=trim((string)($_GET['period_sort']??''));
  $period=trim((string)($_GET['period']??'all'));$order=trim((string)($_GET['order']??'due'));
  if($sort==='newest'){$period='all';$order='newest';}
  elseif($sort==='oldest'){$period='all';$order='oldest';}
  elseif($sort==='7d'){$period='7d';$order='newest';}
  elseif($sort==='all'){$period='all';$order='due';}
  $filtros=[
    'q'=>(string)($_GET['q']??''),'funil'=>(string)($_GET['funil']??''),'stage'=>(string)($_GET['stage']??''),
    'period'=>$period,'order'=>$order,'mine'=>($_GET['mine']??'')==='1',
  ];
  $items=listarTarefasKanban($pdo,usuarioLogado(),$filtros);
  $out=[];
  foreach($items as $t){$out[]=[
    'id'=>(int)$t['id'],'orcamento_id'=>(int)$t['orcamento_id'],'title'=>(string)$t['title'],'description'=>(string)($t['description']??''),
    'due_date'=>(string)$t['due_date'],'created_at'=>(string)($t['created_at']??''),'completed_at'=>(string)($t['completed_at']??''),
    'status'=>(string)$t['status'],'stage'=>(string)($t['board_stage']??'todo'),'cliente'=>(string)$t['cliente'],'numero'=>(string)$t['numero'],
    'linha'=>(string)$t['linha'],'funil'=>(string)($t['funil']??''),'opportunity_stage'=>(string)($t['opportunity_stage']??''),
    'responsavel'=>nomeUsuarioComHumor($pdo,(int)$t['assigned_to_user_id'],(string)$t['responsavel_nome'])
  ];}
  echo json_encode(['ok'=>true,'csrf'=>csrfToken(),'kanban_available'=>tarefasKanbanDisponivel($pdo),'items'=>$out,'filters'=>$filtros],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'erro'=>'Método não permitido.']);exit;}
exigirCsrf();
try{
 $acao=(string)($_POST['acao']??'');$id=(int)($_POST['task_id']??0);
 if($acao==='set_stage'){moverTarefaKanban($pdo,$id,(string)($_POST['stage']??''));echo json_encode(['ok'=>true]);exit;}
 if($acao==='complete'){concluirTarefa($pdo,$id);echo json_encode(['ok'=>true]);exit;}
 throw new InvalidArgumentException('Ação inválida.');
}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'erro'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
