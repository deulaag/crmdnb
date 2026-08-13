<?php
declare(strict_types=1);
define('DNB_TESTING',true);
require_once __DIR__ . '/../includes/followup_flow_service.php';

function confirmarFluxoPr3(bool $condicao,string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR,"FALHA: {$mensagem}\n"); exit(1); }
}

$regra=['id'=>7,'pipeline_key'=>'pecas','stage_name'=>'Follow-up','inactivity_hours'=>24,'min_score'=>35,'max_attempts'=>3,'is_active'=>1];
$grafo=construirGrafoFollowupPr3($regra,[
    'name'=>'Peças · recuperação','move_stage_active'=>true,'move_stage_name'=>'Fechamento',
    'assign_owner_active'=>true,'assign_owner_user_id'=>12,
    'human_handoff_active'=>true,'human_handoff_reason'=>'Revisar bloqueio',
    'notify_internal_active'=>true,'notify_internal_user_id'=>4,'notify_internal_title'=>'Fluxo concluído',
]);
$validacao=validarGrafoFollowupPr3($grafo);
confirmarFluxoPr3($validacao['valid'],'grafo padrão PR3 deve ser válido');
confirmarFluxoPr3(count($grafo['nodes'])===9,'grafo deve conter os cinco nós existentes e quatro novos');
confirmarFluxoPr3(count($grafo['edges'])===8,'grafo deve conectar os nove nós em sequência');

$tipos=array_column($grafo['nodes'],'type');
foreach(['move_stage','assign_owner','human_handoff','notify_internal'] as $tipo) confirmarFluxoPr3(in_array($tipo,$tipos,true),'tipo operacional '.$tipo.' deve existir');

$ciclico=$grafo;
$ciclico['edges'][7]['target']='trigger';
confirmarFluxoPr3(!validarGrafoFollowupPr3($ciclico)['valid'],'ciclos devem ser recusados antes da publicação');

$invalido=$grafo;
$invalido['nodes'][]=['id'=>'shell','type'=>'execute_shell','config'=>[]];
confirmarFluxoPr3(!validarGrafoFollowupPr3($invalido)['valid'],'tipos de nó não permitidos devem ser recusados');

$sanitizado=sanitizarAuditoriaFollowupPr3(['authorization'=>'Bearer segredo','api_key'=>'abc','safe'=>'Bearer outro']);
confirmarFluxoPr3($sanitizado['authorization']==='[redigido]'&&$sanitizado['api_key']==='[redigido]','campos sensíveis devem ser redigidos');
confirmarFluxoPr3($sanitizado['safe']==='Bearer [redigido]','tokens em texto também devem ser redigidos');

echo "Follow-up flow PR3: OK\n";

