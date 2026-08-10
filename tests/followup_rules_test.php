<?php
declare(strict_types=1);
define('DNB_TESTING',true);
require_once __DIR__ . '/../includes/followup_service.php';

function confirmarRegraFollowup(bool $condicao,string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR,"FALHA: {$mensagem}\n"); exit(1); }
}

$agora = new DateTimeImmutable('2026-08-09 12:00:00');
$lead = ['status'=>'Aberta','cad_pausada'=>0,'score'=>70,'last_activity_at'=>'2026-08-08 11:00:00','funil'=>'pecas','etapa'=>'Follow-up'];
$regra = ['is_active'=>1,'min_score'=>60,'max_attempts'=>3,'inactivity_hours'=>24,'pipeline_key'=>'pecas','stage_name'=>'Follow-up'];
confirmarRegraFollowup(avaliarElegibilidadeFollowup($lead,$regra,0,false,$agora)['eligible'],'Lead inativo e com score suficiente deve entrar.');
confirmarRegraFollowup(avaliarElegibilidadeFollowup($lead,$regra,3,false,$agora)['reason']==='attempt_limit','Limite de tentativas deve bloquear.');
$lead['last_activity_at']='2026-08-09 11:30:00';
confirmarRegraFollowup(avaliarElegibilidadeFollowup($lead,$regra,0,false,$agora)['reason']==='inactivity_not_reached','Janela de inatividade deve ser respeitada.');
$lead['last_activity_at']='2026-08-08 11:00:00';
$lead['score']=40;
confirmarRegraFollowup(avaliarElegibilidadeFollowup($lead,$regra,0,false,$agora)['reason']==='score_below_minimum','Score mínimo deve ser respeitado.');
$lead['score']=70;
confirmarRegraFollowup(avaliarElegibilidadeFollowup($lead,$regra,0,true,$agora)['reason']==='human_lock','Human Lock deve interromper o motor.');
$lead['etapa']='Fechamento';
confirmarRegraFollowup(avaliarElegibilidadeFollowup($lead,$regra,0,false,$agora)['reason']==='stage_mismatch','Regra deve corresponder à etapa atual do funil.');
$lead['etapa']='Follow-up'; $regra['is_active']=0;
confirmarRegraFollowup(avaliarElegibilidadeFollowup($lead,$regra,0,false,$agora)['reason']==='rule_inactive','Regra inativa não deve selecionar leads.');

echo "Follow-up rules: OK\n";
