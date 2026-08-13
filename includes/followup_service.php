<?php
declare(strict_types=1);

/** Motor de cadência automática: regras, IA, WhatsApp, idempotência e auditoria. */
require_once __DIR__ . '/ai_client.php';
require_once __DIR__ . '/lead_service.php';
require_once __DIR__ . '/whatsapp_provider.php';
require_once __DIR__ . '/followup_flow_service.php';

const FOLLOWUP_MAX_MESSAGE_LENGTH = 4000;
const FOLLOWUP_MAX_RETRIES_AI = 2;
const FOLLOWUP_PROCESSING_TIMEOUT_MINUTES = 15;
const FOLLOWUP_SENDING_TIMEOUT_MINUTES = 30;

class FollowupServiceException extends RuntimeException {}

function followupTabelasDisponiveis(PDO $pdo): bool
{
    static $disponivel = null;
    if ($disponivel !== null) return $disponivel;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('pipeline_stages','followup_rules','followup_logs')");
        return $disponivel = ((int) $stmt->fetchColumn() === 3);
    } catch (Throwable) {
        return $disponivel = false;
    }
}

function followupChaveTentativa(int $leadId, int $ruleId, int $tentativa): string
{
    return hash('sha256', "followup-attempt:{$leadId}:{$ruleId}:{$tentativa}");
}

function followupChaveEvento(string $tipo, int $leadId, string $referencia): string
{
    return hash('sha256', "followup-event:{$tipo}:{$leadId}:{$referencia}");
}

function followupDeveInterromperPorEvento(string $direcao, ?int $leadId): bool
{
    return $leadId !== null && $leadId > 0 && strtolower($direcao) === 'incoming';
}

function followupStatusBloqueiaNovaTentativa(string $status): bool
{
    return in_array($status, ['sending','sent','blocked','delivery_unknown'], true);
}

function followupEtapaCompativel(array $lead, array $regra): bool
{
    return !isset($regra['pipeline_key'],$regra['stage_name'])
        || ((string)($lead['funil'] ?? '') === (string)$regra['pipeline_key']
            && (string)($lead['etapa'] ?? '') === (string)$regra['stage_name']);
}

/** @return array{eligible:bool,reason:string} */
function avaliarElegibilidadeFollowup(
    array $lead,
    array $regra,
    int $tentativasConsumidas,
    bool $humanLock,
    ?DateTimeImmutable $agora = null
): array {
    $agora = $agora ?? new DateTimeImmutable();
    if (($lead['status'] ?? '') !== 'Aberta') return ['eligible'=>false,'reason'=>'lead_closed'];
    if (!empty($lead['cad_pausada'])) return ['eligible'=>false,'reason'=>'cadence_paused'];
    if (empty($regra['is_active'])) return ['eligible'=>false,'reason'=>'rule_inactive'];
    if (!followupEtapaCompativel($lead,$regra)) return ['eligible'=>false,'reason'=>'stage_mismatch'];
    if ($humanLock) return ['eligible'=>false,'reason'=>'human_lock'];
    if ((int) ($lead['score'] ?? 0) < (int) ($regra['min_score'] ?? 0)) return ['eligible'=>false,'reason'=>'score_below_minimum'];
    if ($tentativasConsumidas >= (int) ($regra['max_attempts'] ?? 0)) return ['eligible'=>false,'reason'=>'attempt_limit'];
    try {
        $ultimaAtividade = new DateTimeImmutable((string) ($lead['last_activity_at'] ?? 'now'));
    } catch (Throwable) {
        return ['eligible'=>false,'reason'=>'invalid_activity_date'];
    }
    $horas = max(1, (int) ($regra['inactivity_hours'] ?? 1));
    if ($ultimaAtividade->modify("+{$horas} hours") > $agora) return ['eligible'=>false,'reason'=>'inactivity_not_reached'];
    return ['eligible'=>true,'reason'=>'eligible'];
}

/** @return array<string,mixed> */
function montarPayloadFollowup(array $lead, array $regra, int $tentativa, array $historico, array $solicitante): array
{
    return [
        'contract_version' => '1.0',
        'correlation_id' => bin2hex(random_bytes(16)),
        'source' => 'dnb-crm-followup-cpanel',
        'lead' => [
            'id' => (int) $lead['id'],
            'number' => (string) ($lead['numero'] ?? ''),
            'customer' => (string) ($lead['cliente'] ?? ''),
            'product_line' => (string) ($lead['linha'] ?? ''),
            'funnel' => (string) ($lead['funil'] ?? ''),
            'stage' => (string) ($lead['etapa'] ?? ''),
            'status' => (string) ($lead['status'] ?? ''),
            'value' => (float) ($lead['valor'] ?? 0),
            'score' => (int) ($lead['score'] ?? 0),
            'score_justification' => (string) ($lead['score_justification'] ?? ''),
            'notes' => (string) ($lead['obs'] ?? ''),
        ],
        'followup' => [
            'rule_id' => (int) $regra['id'],
            'pipeline_stage_id' => (int) $regra['pipeline_stage_id'],
            'attempt_number' => $tentativa,
            'inactivity_hours' => (int) $regra['inactivity_hours'],
            'min_score' => (int) $regra['min_score'],
            'max_attempts' => (int) $regra['max_attempts'],
            'channel' => 'whatsapp',
        ],
        'history' => array_values(array_slice($historico, -20)),
        'requested_by' => [
            'id' => (int) $solicitante['id'],
            'name' => (string) ($solicitante['nome'] ?? 'Sistema'),
            'role' => (string) ($solicitante['role'] ?? 'SISTEMA'),
            'roles' => array_values((array) ($solicitante['papeis'] ?? [])),
            'cargos' => array_values((array) ($solicitante['cargo_titles'] ?? [])),
        ],
    ];
}

/** @return array<int,array<string,mixed>> */
function listarRegrasFollowup(PDO $pdo): array
{
    if (!followupTabelasDisponiveis($pdo)) return [];
    return $pdo->query("SELECT fr.*,ps.pipeline_key,ps.stage_name,ps.stage_order
        FROM followup_rules fr JOIN pipeline_stages ps ON ps.id=fr.pipeline_stage_id
        ORDER BY FIELD(ps.pipeline_key,'pecas','servicos','equipamentos'),ps.stage_order,fr.id")->fetchAll();
}

function salvarRegraFollowup(PDO $pdo, int $regraId, int $inatividade, int $score, int $tentativas, bool $ativa): void
{
    if (!temPapel(PAPEL_ADMINISTRADOR)) throw new FollowupServiceException('Somente o Administrador pode alterar regras automáticas.');
    $inatividade = max(1, min(8760, $inatividade));
    $score = max(0, min(100, $score));
    $tentativas = max(1, min(20, $tentativas));
    $stmt = $pdo->prepare('UPDATE followup_rules SET inactivity_hours=?,min_score=?,max_attempts=?,is_active=?,updated_at=NOW() WHERE id=?');
    $stmt->execute([$inatividade, $score, $tentativas, $ativa ? 1 : 0, $regraId]);
    if ($stmt->rowCount() === 0) {
        $stmt = $pdo->prepare('SELECT id FROM followup_rules WHERE id=?');
        $stmt->execute([$regraId]);
        if (!$stmt->fetchColumn()) throw new FollowupServiceException('Regra de follow-up não encontrada.');
    }
}

/** @return array<int,array<string,mixed>> */
function listarCandidatosFollowup(PDO $pdo, int $limite = 20): array
{
    if (!followupTabelasDisponiveis($pdo)) throw new FollowupServiceException('Instale a migração 20260809_followup_engine.sql antes de executar o motor.');
    $limite = max(1, min(100, $limite));
    $sql = "SELECT o.*,COALESCE(ls.score,0) score,COALESCE(ls.justification,'') score_justification,
        ps.id pipeline_stage_id,fr.id rule_id,fr.inactivity_hours,fr.min_score,fr.max_attempts,fr.is_active,
        wc.id conversation_id,wc.instance_id,wc.assigned_to_user_id human_assignee_id,wc.ai_paused,wc.last_message_at,
        ct.phone,ct.display_name,wi.external_instance_id,wi.provider,
        (SELECT COUNT(*) FROM followup_logs fl WHERE fl.lead_id=o.id AND fl.rule_id=fr.id AND fl.status IN ('sent','delivery_unknown')) sent_attempts,
        GREATEST(COALESCE(o.criado_em,CONCAT(o.data,' 00:00:00')),COALESCE(wc.last_message_at,'1970-01-01 00:00:00'),
          COALESCE(wc.updated_at,'1970-01-01 00:00:00'),
          COALESCE((SELECT MAX(fl2.sent_at) FROM followup_logs fl2 WHERE fl2.lead_id=o.id AND fl2.status='sent'),'1970-01-01 00:00:00')) last_activity_at
        FROM orcamentos o
        JOIN pipeline_stages ps ON ps.pipeline_key=o.funil AND ps.stage_name=o.etapa AND ps.is_active=1
        JOIN followup_rules fr ON fr.pipeline_stage_id=ps.id AND fr.is_active=1
        LEFT JOIN lead_scores ls ON ls.lead_id=o.id
        JOIN whatsapp_conversations wc ON wc.id=(SELECT wc2.id FROM whatsapp_conversations wc2 WHERE wc2.lead_id=o.id AND wc2.status='open' ORDER BY wc2.last_message_at DESC,wc2.id DESC LIMIT 1)
        JOIN whatsapp_contacts ct ON ct.id=wc.contact_id JOIN whatsapp_instances wi ON wi.id=wc.instance_id AND wi.status='active'
        WHERE o.status='Aberta' AND o.cad_pausada=0 AND COALESCE(ls.score,0)>=fr.min_score
          AND wc.assigned_to_user_id IS NULL AND wc.ai_paused=0
          AND NOT EXISTS (SELECT 1 FROM followup_logs fl3 WHERE fl3.lead_id=o.id AND fl3.rule_id=fr.id AND fl3.status IN ('blocked','delivery_unknown'))
        HAVING sent_attempts<max_attempts AND last_activity_at<=DATE_SUB(NOW(),INTERVAL inactivity_hours HOUR)
        ORDER BY last_activity_at,o.id LIMIT {$limite}";
    return $pdo->query($sql)->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function historicoWhatsappFollowup(PDO $pdo, int $conversationId): array
{
    $stmt = $pdo->prepare("SELECT direction,sender_type,message_body,created_at FROM whatsapp_messages
        WHERE conversation_id=? AND message_body IS NOT NULL AND message_body<>'' ORDER BY id DESC LIMIT 20");
    $stmt->execute([$conversationId]);
    return array_reverse(array_map(static fn(array $m): array => [
        'direction'=>(string)$m['direction'],'sender_type'=>(string)$m['sender_type'],
        'message'=>mb_substr((string)$m['message_body'],0,2000),'created_at'=>(string)$m['created_at'],
    ], $stmt->fetchAll()));
}

/** @return array<string,mixed> */
function usuarioSistemaFollowup(PDO $pdo, ?int $ownerId): array
{
    if ($ownerId) {
        if ($usuario = buscarUsuarioComContextoCargos($pdo, $ownerId)) return $usuario;
    }
    $adminId = idsUsuariosComPapeis($pdo, [PAPEL_ADMINISTRADOR])[0] ?? 0;
    $usuario = $adminId ? buscarUsuarioComContextoCargos($pdo, $adminId) : null;
    if (!$usuario) throw new FollowupServiceException('Nenhum usuário ativo disponível para registrar o follow-up.');
    return $usuario;
}

/** @return array<string,mixed>|null */
function reservarTentativaFollowup(PDO $pdo, array $candidato): ?array
{
    $tentativa = ((int) $candidato['sent_attempts']) + 1;
    $chave = followupChaveTentativa((int)$candidato['id'],(int)$candidato['rule_id'],$tentativa);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM followup_logs WHERE attempt_key=? FOR UPDATE');
        $stmt->execute([$chave]); $log = $stmt->fetch();
        if ($log) {
            $status = (string) $log['status'];
            $minutos = max(0, (int) floor((time() - strtotime((string)$log['updated_at'])) / 60));
            if ($status === 'sending' && $minutos >= FOLLOWUP_SENDING_TIMEOUT_MINUTES) {
                $pdo->prepare("UPDATE followup_logs SET status='delivery_unknown',locked_at=NULL,error_message='Envio interrompido sem confirmação; revisão manual necessária.',updated_at=NOW() WHERE id=?")->execute([(int)$log['id']]);
                $pdo->prepare('UPDATE orcamentos SET cad_pausada=1 WHERE id=?')->execute([(int)$candidato['id']]);
                $pdo->commit();
                registrarTimeline($pdo,(int)$candidato['id'],'followup_delivery_unknown','Cadência pausada: o resultado do envio anterior precisa de revisão manual.');
                return null;
            }
            if (followupStatusBloqueiaNovaTentativa($status) || ($status === 'processing' && $minutos < FOLLOWUP_PROCESSING_TIMEOUT_MINUTES)) {
                $pdo->commit(); return null;
            }
            if ($status === 'ready' && $minutos < FOLLOWUP_PROCESSING_TIMEOUT_MINUTES) {
                $pdo->commit(); return null;
            }
            if ($status === 'ready') {
                $pdo->prepare("UPDATE followup_logs SET status='processing',message_sent=NULL,retry_count=retry_count+1,locked_at=NOW(),error_message=NULL,updated_at=NOW() WHERE id=?")
                    ->execute([(int)$log['id']]);
                $stmt->execute([$chave]); $log = $stmt->fetch();
                $pdo->commit(); return $log ?: null;
            }
            if ($status === 'failed' && (int)$log['retry_count'] >= FOLLOWUP_MAX_RETRIES_AI) {
                $pdo->prepare("UPDATE followup_logs SET status='blocked',error_message='Limite de retomadas após falha da IA atingido.',locked_at=NULL,updated_at=NOW() WHERE id=?")
                    ->execute([(int)$log['id']]);
                $pdo->prepare('UPDATE orcamentos SET cad_pausada=1 WHERE id=?')->execute([(int)$candidato['id']]);
                $pdo->commit();
                registrarTimeline($pdo,(int)$candidato['id'],'followup_failed','Cadência pausada após o limite de falhas consecutivas da IA.');
                return null;
            }
            $pdo->prepare("UPDATE followup_logs SET status='processing',retry_count=retry_count+1,locked_at=NOW(),error_message=NULL,updated_at=NOW() WHERE id=?")
                ->execute([(int)$log['id']]);
            $stmt->execute([$chave]); $log = $stmt->fetch();
            $pdo->commit(); return $log ?: null;
        }
        $correlationId = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("INSERT INTO followup_logs (lead_id,rule_id,conversation_id,attempt_number,status,attempt_key,idempotency_key,correlation_id,locked_at,created_at,updated_at)
            VALUES (?,?,?,?, 'processing',?,?,?,NOW(),NOW(),NOW())");
        $stmt->execute([(int)$candidato['id'],(int)$candidato['rule_id'],(int)$candidato['conversation_id'],$tentativa,$chave,$chave,$correlationId]);
        $id = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM followup_logs WHERE id=?'); $stmt->execute([$id]); $log = $stmt->fetch();
        $pdo->commit(); return $log ?: null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') return null;
        throw $e;
    }
}

function atualizarFalhaFollowup(PDO $pdo, int $logId, string $status, string $erro): void
{
    if (!in_array($status, ['failed','delivery_unknown'], true)) $status = 'failed';
    $stmt = $pdo->prepare("UPDATE followup_logs SET status=?,error_message=?,locked_at=NULL,updated_at=NOW() WHERE id=?");
    $stmt->execute([$status,mb_substr($erro,0,1000),$logId]);
}

function candidatoFollowupAindaValido(PDO $pdo, array $candidato, int $logId): bool
{
    $stmt = $pdo->prepare("SELECT o.status,o.cad_pausada,o.funil,o.etapa,wc.assigned_to_user_id,wc.ai_paused,
        fr.is_active,fr.min_score,COALESCE(ls.score,0) current_score,
        EXISTS(SELECT 1 FROM whatsapp_messages wm JOIN followup_logs fl ON fl.id=?
          WHERE wm.conversation_id=wc.id AND wm.direction='incoming' AND wm.created_at>=COALESCE(fl.locked_at,fl.created_at)) inbound_after_claim
        FROM orcamentos o JOIN whatsapp_conversations wc ON wc.id=?
        JOIN followup_rules fr ON fr.id=? LEFT JOIN lead_scores ls ON ls.lead_id=o.id WHERE o.id=? LIMIT 1");
    $stmt->execute([$logId,(int)$candidato['conversation_id'],(int)$candidato['rule_id'],(int)$candidato['id']]);
    $atual = $stmt->fetch();
    return $atual && $atual['status']==='Aberta' && empty($atual['cad_pausada'])
        && !empty($atual['is_active']) && (int)$atual['current_score'] >= (int)$atual['min_score']
        && followupEtapaCompativel($atual,['pipeline_key'=>$candidato['funil'],'stage_name'=>$candidato['etapa']])
        && empty($atual['assigned_to_user_id']) && empty($atual['ai_paused']) && empty($atual['inbound_after_claim']);
}

/** @return array{status:string,lead_id:int,log_id?:int,message?:string} */
function processarCandidatoFollowup(PDO $pdo, array $candidato): array
{
    $log = reservarTentativaFollowup($pdo,$candidato);
    if (!$log) return ['status'=>'skipped','lead_id'=>(int)$candidato['id']];
    $logId = (int) $log['id']; $tentativa = (int) $log['attempt_number'];
    $runId = iniciarExecucaoFluxoPr3($pdo,$candidato,$log);
    $mensagem = trim((string)($log['message_sent'] ?? ''));
    try {
        if ($mensagem === '') {
            registrarEventoFluxoPr3($pdo,$runId,'ai','ai','running',['attempt'=>$tentativa],null,null,null,'generation-start');
            $solicitante = usuarioSistemaFollowup($pdo,$candidato['assigned_to_user_id'] ? (int)$candidato['assigned_to_user_id'] : null);
            $regra = [
                'id'=>(int)$candidato['rule_id'],'pipeline_stage_id'=>(int)$candidato['pipeline_stage_id'],
                'inactivity_hours'=>(int)$candidato['inactivity_hours'],'min_score'=>(int)$candidato['min_score'],
                'max_attempts'=>(int)$candidato['max_attempts'],
            ];
            $payload = montarPayloadFollowup($candidato,$regra,$tentativa,historicoWhatsappFollowup($pdo,(int)$candidato['conversation_id']),$solicitante);
            $payload['correlation_id'] = (string) $log['correlation_id'];
            $resposta = gerarFollowupComIa($payload);
            if ($resposta['blocked'] || $resposta['escalated']) {
                $motivo = $resposta['reason'] ?: 'Mensagem bloqueada pelos guardrails da IA.';
                $pdo->prepare("UPDATE followup_logs SET status='blocked',error_message=?,locked_at=NULL,updated_at=NOW() WHERE id=?")
                    ->execute([mb_substr($motivo,0,1000),$logId]);
                $pdo->prepare('UPDATE orcamentos SET cad_pausada=1 WHERE id=?')->execute([(int)$candidato['id']]);
                registrarTimeline($pdo,(int)$candidato['id'],'followup_blocked','Follow-up automático pausado: '.$motivo);
                registrarEventoFluxoPr3($pdo,$runId,'ai','ai','blocked',null,['guardrail'=>'blocked'],'guardrail_blocked',null,'generation-blocked');
                executarAcoesPublicadasPr3($pdo,$candidato,$runId,'blocked');
                finalizarExecucaoFluxoPr3($pdo,$runId,'blocked');
                return ['status'=>'blocked','lead_id'=>(int)$candidato['id'],'log_id'=>$logId];
            }
            $mensagem = trim((string)$resposta['message']);
            if ($mensagem === '') throw new FollowupServiceException('A VPS não retornou uma mensagem de follow-up.');
            $mensagem = mb_substr($mensagem,0,FOLLOWUP_MAX_MESSAGE_LENGTH);
            $pdo->prepare("UPDATE followup_logs SET status='ready',message_sent=?,updated_at=NOW() WHERE id=? AND status='processing'")
                ->execute([$mensagem,$logId]);
            registrarEventoFluxoPr3($pdo,$runId,'ai','ai','success',null,['message_length'=>mb_strlen($mensagem),'guardrail'=>'approved'],null,null,'generation-success');
        } else {
            registrarEventoFluxoPr3($pdo,$runId,'ai','ai','success',null,['message_length'=>mb_strlen($mensagem),'reused'=>true],null,null,'generation-reused');
        }
    } catch (Throwable $e) {
        atualizarFalhaFollowup($pdo,$logId,'failed',$e->getMessage());
        registrarEventoFluxoPr3($pdo,$runId,'ai','ai','failed',null,'Falha ao gerar mensagem','ai_generation_failed',null,'generation-failed');
        executarAcoesPublicadasPr3($pdo,$candidato,$runId,'failed');
        finalizarExecucaoFluxoPr3($pdo,$runId,'failed');
        return ['status'=>'failed','lead_id'=>(int)$candidato['id'],'log_id'=>$logId,'message'=>$e->getMessage()];
    }

    if (!candidatoFollowupAindaValido($pdo,$candidato,$logId)) {
        $pdo->prepare("UPDATE followup_logs SET status='paused',message_sent=NULL,error_message='Lead respondeu, foi pausado ou recebeu Human Lock antes do envio.',locked_at=NULL,updated_at=NOW() WHERE id=? AND status='ready'")->execute([$logId]);
        registrarEventoFluxoPr3($pdo,$runId,'whatsapp','whatsapp','paused',null,'Envio cancelado por pausa, resposta ou Human Lock','human_lock_or_pause',null,'send-paused');
        finalizarExecucaoFluxoPr3($pdo,$runId,'paused');
        return ['status'=>'paused','lead_id'=>(int)$candidato['id'],'log_id'=>$logId];
    }
    registrarEventoFluxoPr3($pdo,$runId,'whatsapp','whatsapp','running',['attempt'=>$tentativa],null,null,null,'send-start');
    $stmt = $pdo->prepare("UPDATE followup_logs SET status='sending',locked_at=NOW(),updated_at=NOW() WHERE id=? AND status='ready'");
    $stmt->execute([$logId]);
    if ($stmt->rowCount() !== 1) {
        registrarEventoFluxoPr3($pdo,$runId,'whatsapp','whatsapp','skipped',null,'Registro não estava pronto para envio','send_state_changed',null,'send-skipped');
        finalizarExecucaoFluxoPr3($pdo,$runId,'paused');
        return ['status'=>'skipped','lead_id'=>(int)$candidato['id'],'log_id'=>$logId];
    }

    try {
        $provider = enviarWhatsappProvider($candidato,$candidato,$mensagem);
        $providerId = whatsappProviderMessageId($provider);
    } catch (Throwable $e) {
        atualizarFalhaFollowup($pdo,$logId,'delivery_unknown',$e->getMessage());
        $pdo->prepare('UPDATE orcamentos SET cad_pausada=1 WHERE id=?')->execute([(int)$candidato['id']]);
        registrarTimeline($pdo,(int)$candidato['id'],'followup_delivery_unknown','Cadência pausada porque o provedor não confirmou o resultado do envio.');
        registrarEventoFluxoPr3($pdo,$runId,'whatsapp','whatsapp','failed',null,'Provedor sem confirmação','provider_unconfirmed',null,'send-failed');
        executarAcoesPublicadasPr3($pdo,$candidato,$runId,'delivery_unknown');
        finalizarExecucaoFluxoPr3($pdo,$runId,'delivery_unknown');
        return ['status'=>'delivery_unknown','lead_id'=>(int)$candidato['id'],'log_id'=>$logId,'message'=>$e->getMessage()];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO whatsapp_messages (provider_message_id,instance_id,conversation_id,lead_id,direction,sender_type,message_body,status,created_at,processed_at)
            VALUES (?,?,?,?, 'outgoing','ai',?,'sent',NOW(),NOW()) ON DUPLICATE KEY UPDATE status='sent',processed_at=NOW()");
        $stmt->execute([$providerId,(int)$candidato['instance_id'],(int)$candidato['conversation_id'],(int)$candidato['id'],$mensagem]);
        $pdo->prepare('UPDATE whatsapp_conversations SET last_message_preview=?,last_message_at=NOW(),updated_at=NOW() WHERE id=?')
            ->execute([mb_substr($mensagem,0,500),(int)$candidato['conversation_id']]);
        $pdo->prepare('INSERT INTO orcamento_cadencia_log (orcamento_id,data,etapa) VALUES (?,CURDATE(),?)')
            ->execute([(int)$candidato['id'],'IA WhatsApp · tentativa '.$tentativa]);
        $pdo->prepare("UPDATE followup_logs SET status='sent',provider_message_id=?,sent_at=NOW(),locked_at=NULL,error_message=NULL,updated_at=NOW() WHERE id=? AND status='sending'")
            ->execute([$providerId,$logId]);
        registrarTimeline($pdo,(int)$candidato['id'],'followup_sent','Follow-up automático por WhatsApp enviado (tentativa '.$tentativa.' de '.(int)$candidato['max_attempts'].').');
        $pdo->commit();
        registrarEventoFluxoPr3($pdo,$runId,'whatsapp','whatsapp','success',null,['provider_confirmed'=>true,'attempt'=>$tentativa],null,null,'send-success');
        $acoesConcluidas = executarAcoesPublicadasPr3($pdo,$candidato,$runId,'sent');
        finalizarExecucaoFluxoPr3($pdo,$runId,$acoesConcluidas?'success':'failed');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        atualizarFalhaFollowup($pdo,$logId,'delivery_unknown','Provedor confirmou o envio, mas o CRM não concluiu o registro: '.$e->getMessage());
        $pdo->prepare('UPDATE orcamentos SET cad_pausada=1 WHERE id=?')->execute([(int)$candidato['id']]);
        registrarTimeline($pdo,(int)$candidato['id'],'followup_delivery_unknown','Cadência pausada: o provedor confirmou o envio, mas o registro local exige revisão.');
        registrarEventoFluxoPr3($pdo,$runId,'whatsapp','whatsapp','failed',null,'Confirmação recebida; registro local incompleto','local_commit_failed',null,'send-local-failed');
        executarAcoesPublicadasPr3($pdo,$candidato,$runId,'delivery_unknown');
        finalizarExecucaoFluxoPr3($pdo,$runId,'delivery_unknown');
        return ['status'=>'delivery_unknown','lead_id'=>(int)$candidato['id'],'log_id'=>$logId,'message'=>$e->getMessage()];
    }
    return ['status'=>'sent','lead_id'=>(int)$candidato['id'],'log_id'=>$logId];
}

/** @return array<string,int> */
function processarMotorFollowup(PDO $pdo, int $limite = 20): array
{
    $resultado = ['selected'=>0,'sent'=>0,'failed'=>0,'paused'=>0,'blocked'=>0,'delivery_unknown'=>0,'skipped'=>0];
    foreach (listarCandidatosFollowup($pdo,$limite) as $candidato) {
        $resultado['selected']++;
        try {
            $item = processarCandidatoFollowup($pdo,$candidato);
            $status = (string)$item['status'];
            if (isset($resultado[$status])) $resultado[$status]++;
            else $resultado['failed']++;
        } catch (Throwable $e) {
            $resultado['failed']++;
            error_log('DNB follow-up lead #'.(int)$candidato['id'].': '.$e->getMessage());
        }
    }
    return $resultado;
}

function interromperFollowupPorMensagemRecebida(PDO $pdo, int $leadId, string|int $messageRef, ?string $recebidaEm = null): bool
{
    if ($leadId <= 0 || !followupTabelasDisponiveis($pdo)) return false;
    $stmt = $pdo->prepare("SELECT fr.id rule_id FROM orcamentos o
        JOIN pipeline_stages ps ON ps.pipeline_key=o.funil AND ps.stage_name=o.etapa
        JOIN followup_rules fr ON fr.pipeline_stage_id=ps.id AND fr.is_active=1 WHERE o.id=? LIMIT 1");
    $stmt->execute([$leadId]); $ruleId = (int)$stmt->fetchColumn();
    if (!$ruleId) return false;
    $stmtPausa = $pdo->prepare("UPDATE orcamentos SET cad_pausada=1 WHERE id=? AND status='Aberta' AND cad_pausada=0");
    $stmtPausa->execute([$leadId]);
    $alterouPausa = $stmtPausa->rowCount() > 0;
    if (!$alterouPausa) return false;
    if ($ruleId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM followup_logs WHERE lead_id=? AND rule_id=? AND status IN ('sent','delivery_unknown')");
        $stmt->execute([$leadId,$ruleId]); $tentativa = ((int)$stmt->fetchColumn()) + 1;
        $chave = followupChaveEvento('incoming',$leadId,(string)$messageRef);
        $stmt = $pdo->prepare("INSERT IGNORE INTO followup_logs (lead_id,rule_id,attempt_number,status,idempotency_key,error_message,created_at,updated_at)
            VALUES (?,?,?,'paused',?,'Cadência interrompida por mensagem recebida do lead.',COALESCE(?,NOW()),NOW())");
        $stmt->execute([$leadId,$ruleId,$tentativa,$chave,$recebidaEm]);
    }
    registrarTimeline($pdo,$leadId,'followup_interrupted','Cadência automática interrompida imediatamente por mensagem recebida no WhatsApp.');
    return true;
}

/** @return array<string,int> */
function estatisticasFollowup(PDO $pdo): array
{
    $base = ['sent'=>0,'failed'=>0,'paused'=>0,'blocked'=>0,'delivery_unknown'=>0,'processing'=>0];
    if (!followupTabelasDisponiveis($pdo)) return $base;
    [$filtro,$params] = filtroEscopoAtribuicao($pdo,'o.assigned_to_user_id');
    $stmt = $pdo->prepare("SELECT fl.status,COUNT(*) total FROM followup_logs fl JOIN orcamentos o ON o.id=fl.lead_id WHERE {$filtro} GROUP BY fl.status");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) $base[(string)$r['status']] = (int)$r['total'];
    $base['processing'] = (int)($base['processing'] ?? 0) + (int)($base['ready'] ?? 0) + (int)($base['sending'] ?? 0);
    return $base;
}

/** @return array<int,array<string,mixed>> */
function listarLogsFollowup(PDO $pdo, int $limite = 50): array
{
    if (!followupTabelasDisponiveis($pdo)) return [];
    [$filtro,$params] = filtroEscopoAtribuicao($pdo,'o.assigned_to_user_id');
    $limite = max(1,min(200,$limite));
    $stmt = $pdo->prepare("SELECT fl.*,o.cliente,o.numero,ps.stage_name FROM followup_logs fl
        JOIN orcamentos o ON o.id=fl.lead_id JOIN followup_rules fr ON fr.id=fl.rule_id
        JOIN pipeline_stages ps ON ps.id=fr.pipeline_stage_id WHERE {$filtro} ORDER BY fl.created_at DESC,fl.id DESC LIMIT {$limite}");
    $stmt->execute($params); return $stmt->fetchAll();
}

/** @return array{available:bool,rule:?array,logs:array<int,array<string,mixed>>,sent_attempts:int} */
function resumoFollowupLead(PDO $pdo, int $leadId): array
{
    if (!followupTabelasDisponiveis($pdo)) return ['available'=>false,'rule'=>null,'logs'=>[],'sent_attempts'=>0];
    $stmt = $pdo->prepare("SELECT fr.*,ps.stage_name,ps.pipeline_key FROM orcamentos o
        LEFT JOIN pipeline_stages ps ON ps.pipeline_key=o.funil AND ps.stage_name=o.etapa
        LEFT JOIN followup_rules fr ON fr.pipeline_stage_id=ps.id WHERE o.id=? LIMIT 1");
    $stmt->execute([$leadId]); $rule = $stmt->fetch() ?: null;
    $stmt = $pdo->prepare("SELECT * FROM followup_logs WHERE lead_id=? ORDER BY created_at DESC,id DESC LIMIT 20");
    $stmt->execute([$leadId]); $logs = $stmt->fetchAll();
    $sent = count(array_filter($logs,static fn(array $l): bool => in_array($l['status'],['sent','delivery_unknown'],true)));
    return ['available'=>true,'rule'=>$rule,'logs'=>$logs,'sent_attempts'=>$sent];
}
