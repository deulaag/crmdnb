<?php
declare(strict_types=1);
define('DNB_TESTING',true);
require_once __DIR__ . '/../includes/followup_service.php';

function confirmarPayloadFollowup(bool $condicao,string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR,"FALHA: {$mensagem}\n"); exit(1); }
}

$lead = ['id'=>42,'numero'=>'ORC-42','cliente'=>'Cliente Teste','linha'=>'Peças IR','funil'=>'pecas','etapa'=>'Follow-up','status'=>'Aberta','valor'=>1500,'score'=>80,'score_justification'=>'Teste','obs'=>''];
$regra = ['id'=>7,'pipeline_stage_id'=>3,'inactivity_hours'=>24,'min_score'=>60,'max_attempts'=>3];
$payload = montarPayloadFollowup($lead,$regra,2,[['direction'=>'incoming','message'=>'Pode atualizar a proposta?','created_at'=>'2026-08-08 10:00:00']],['id'=>5,'nome'=>'Vendedor','role'=>'VENDEDOR']);
validarPayloadFollowupIa($payload);
confirmarPayloadFollowup($payload['followup']['attempt_number']===2,'Tentativa deve constar no contrato.');
confirmarPayloadFollowup($payload['followup']['channel']==='whatsapp','Canal deve ser WhatsApp.');
confirmarPayloadFollowup(count($payload['history'])===1,'Histórico contextual deve ser preservado.');

echo "Follow-up payload: OK\n";
