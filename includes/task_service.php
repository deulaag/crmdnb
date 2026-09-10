<?php
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/notifications.php';

function tarefasDisponiveis(PDO $pdo): bool
{
    static $disponivel = null;
    if ($disponivel !== null) return $disponivel;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('crm_tasks','crm_task_settings')");
        return $disponivel = ((int) $stmt->fetchColumn() === 2);
    } catch (Throwable) { return $disponivel = false; }
}

function tarefasKanbanDisponivel(PDO $pdo): bool
{
    if (!tarefasDisponiveis($pdo)) return false;
    try {
        $stmt=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_tasks' AND COLUMN_NAME IN ('board_stage','description')");
        return (int)$stmt->fetchColumn()===2;
    } catch(Throwable){return false;}
}

function criarTarefaOrcamento(PDO $pdo, int $orcamentoId, int $responsavelId, string $titulo, string $dataLimite, ?int $criadorId = null, string $descricao=''): int
{
    if (!tarefasDisponiveis($pdo)) throw new RuntimeException('Instale a migração 20260909_crm_tasks.sql para habilitar tarefas.');
    $titulo=trim($titulo) ?: 'Acompanhar orçamento';
    $dt=DateTimeImmutable::createFromFormat('Y-m-d',$dataLimite);
    if(!$dt || $dt->format('Y-m-d')!==$dataLimite)throw new InvalidArgumentException('Informe uma data válida para a tarefa.');
    if(tarefasKanbanDisponivel($pdo)){
        $stmt=$pdo->prepare('INSERT INTO crm_tasks (orcamento_id,assigned_to_user_id,created_by_user_id,title,description,due_date,board_stage) VALUES (?,?,?,?,?,?,\'todo\')');
        $stmt->execute([$orcamentoId,$responsavelId,$criadorId,mb_substr($titulo,0,180),mb_substr(trim($descricao),0,500),$dataLimite]);
    }else{
        $stmt=$pdo->prepare('INSERT INTO crm_tasks (orcamento_id,assigned_to_user_id,created_by_user_id,title,due_date) VALUES (?,?,?,?,?)');
        $stmt->execute([$orcamentoId,$responsavelId,$criadorId,mb_substr($titulo,0,180),$dataLimite]);
    }
    return (int)$pdo->lastInsertId();
}

function listarTarefasPainel(PDO $pdo, ?array $usuario = null, int $limite = 120): array
{
    if(!tarefasDisponiveis($pdo))return[];
    $usuario=$usuario??usuarioLogado();[$filtro,$params]=filtroEscopoAtribuicao($pdo,'t.assigned_to_user_id',$usuario);$limite=max(10,min(300,$limite));
    $extra=tarefasKanbanDisponivel($pdo)?',t.board_stage,t.description':',\'todo\' board_stage,\'\' description';
    $stmt=$pdo->prepare("SELECT t.*{$extra},o.numero,o.cliente,o.linha,u.nome responsavel_nome FROM crm_tasks t JOIN orcamentos o ON o.id=t.orcamento_id JOIN usuarios u ON u.id=t.assigned_to_user_id WHERE {$filtro} AND t.status='pending' ORDER BY t.due_date,t.id LIMIT {$limite}");
    $stmt->execute($params);return $stmt->fetchAll();
}

function listarTarefasKanban(PDO $pdo, ?array $usuario=null, int $limite=250): array
{
    if(!tarefasDisponiveis($pdo))return[];
    $usuario=$usuario??usuarioLogado();[$filtro,$params]=filtroEscopoAtribuicao($pdo,'t.assigned_to_user_id',$usuario);$limite=max(20,min(500,$limite));
    $stage=tarefasKanbanDisponivel($pdo)?'t.board_stage':'\'todo\'';$desc=tarefasKanbanDisponivel($pdo)?'t.description':'\'\'';
    $stmt=$pdo->prepare("SELECT t.*,{$stage} board_stage,{$desc} description,o.numero,o.cliente,o.linha,u.nome responsavel_nome FROM crm_tasks t JOIN orcamentos o ON o.id=t.orcamento_id JOIN usuarios u ON u.id=t.assigned_to_user_id WHERE {$filtro} AND (t.status='pending' OR (t.status='done' AND t.completed_at>=DATE_SUB(NOW(),INTERVAL 7 DAY))) ORDER BY t.due_date,t.id LIMIT {$limite}");
    $stmt->execute($params);return $stmt->fetchAll();
}

function listarTarefasOrcamento(PDO $pdo,int $orcamentoId):array
{
    if(!tarefasDisponiveis($pdo))return[];
    $stage=tarefasKanbanDisponivel($pdo)?'t.board_stage':'\'todo\'';$desc=tarefasKanbanDisponivel($pdo)?'t.description':'\'\'';
    $stmt=$pdo->prepare("SELECT t.*,{$stage} board_stage,{$desc} description,u.nome responsavel_nome FROM crm_tasks t JOIN usuarios u ON u.id=t.assigned_to_user_id WHERE t.orcamento_id=? ORDER BY t.status='pending' DESC,t.due_date,t.id DESC");
    $stmt->execute([$orcamentoId]);return $stmt->fetchAll();
}

function podeAlterarTarefa(PDO $pdo,int $tarefaId):bool
{
    if(temPapel(PAPEL_ADMINISTRADOR))return true;
    [$filtro,$params]=filtroEscopoAtribuicao($pdo,'assigned_to_user_id');$params[]=$tarefaId;
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM crm_tasks WHERE {$filtro} AND id=?");$stmt->execute($params);return (int)$stmt->fetchColumn()>0;
}

function moverTarefaKanban(PDO $pdo,int $tarefaId,string $etapa):void
{
    if(!tarefasKanbanDisponivel($pdo))throw new RuntimeException('Execute a migração v3.6 para usar o Kanban de tarefas.');
    if(!podeAlterarTarefa($pdo,$tarefaId))throw new RuntimeException('Tarefa fora do seu escopo.');
    $permitidas=['todo','doing','waiting','done'];
    if(!in_array($etapa,$permitidas,true))throw new InvalidArgumentException('Etapa de tarefa inválida.');
    if($etapa==='done')$pdo->prepare("UPDATE crm_tasks SET board_stage='done',status='done',completed_at=COALESCE(completed_at,NOW()) WHERE id=?")->execute([$tarefaId]);
    else $pdo->prepare("UPDATE crm_tasks SET board_stage=?,status='pending',completed_at=NULL WHERE id=?")->execute([$etapa,$tarefaId]);
}

function concluirTarefa(PDO $pdo,int $tarefaId):void
{
    if(!tarefasDisponiveis($pdo))return;
    if(tarefasKanbanDisponivel($pdo)){moverTarefaKanban($pdo,$tarefaId,'done');return;}
    if(temPapel(PAPEL_ADMINISTRADOR)){$pdo->prepare("UPDATE crm_tasks SET status='done',completed_at=NOW() WHERE id=?")->execute([$tarefaId]);return;}
    [$filtro,$params]=filtroEscopoAtribuicao($pdo,'assigned_to_user_id');$params[]=$tarefaId;$pdo->prepare("UPDATE crm_tasks SET status='done',completed_at=NOW() WHERE {$filtro} AND id=?")->execute($params);
}

function configuracaoTarefas(PDO $pdo):array
{
    if(!tarefasDisponiveis($pdo))return['reminder_enabled'=>1,'reminder_days_before'=>1,'reminder_template'=>'Lembrete: a tarefa {tarefa} do orçamento {orcamento} para {cliente} vence em {dias} dia(s), em {data}.'];
    $row=$pdo->query('SELECT reminder_enabled,reminder_days_before,reminder_template FROM crm_task_settings WHERE id=1')->fetch();
    return $row?:['reminder_enabled'=>1,'reminder_days_before'=>1,'reminder_template'=>'Lembrete: a tarefa {tarefa} do orçamento {orcamento} para {cliente} vence em {dias} dia(s), em {data}.'];
}

function renderizarTemplateTarefa(string $template,array $dados):string
{
    return strtr($template,['{tarefa}'=>(string)($dados['title']??''),'{orcamento}'=>(string)($dados['numero']??''),'{cliente}'=>(string)($dados['cliente']??''),'{dias}'=>(string)($dados['dias']??''),'{data}'=>(string)($dados['due_date']??''),'{vendedor}'=>(string)($dados['responsavel_nome']??'')]);
}
