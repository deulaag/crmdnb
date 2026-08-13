<?php
declare(strict_types=1);

/** Fluxos PR3: grafo seguro, versões imutáveis, ações opcionais e auditoria. */
require_once __DIR__ . '/lead_service.php';
require_once __DIR__ . '/notifications.php';

class FollowupFlowException extends RuntimeException {}

/** @return array<string,string> */
function tiposNosFollowupPr3(): array
{
    return [
        'trigger' => 'Gatilho',
        'condition' => 'Condição',
        'ai' => 'Agente de IA',
        'whatsapp' => 'Enviar WhatsApp',
        'move_stage' => 'Mover etapa',
        'assign_owner' => 'Atribuir responsável',
        'human_handoff' => 'Transbordo humano',
        'notify_internal' => 'Notificação interna',
        'control' => 'Controle e parada',
    ];
}

function followupFluxoTabelasDisponiveis(PDO $pdo): bool
{
    static $cache = [];
    $chave = spl_object_id($pdo);
    if (array_key_exists($chave, $cache)) return $cache[$chave];
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('followup_flows','followup_flow_versions','followup_rule_actions','followup_flow_runs','followup_flow_events','followup_flow_audit_events')");
        return $cache[$chave] = ((int) $stmt->fetchColumn() === 6);
    } catch (Throwable) {
        return $cache[$chave] = false;
    }
}

/** Remove campos sensíveis antes de persistir ou exibir resumos de auditoria. */
function sanitizarAuditoriaFollowupPr3(mixed $valor, int $profundidade = 0): mixed
{
    if ($profundidade > 5) return '[limite]';
    if (is_array($valor)) {
        $limpo = [];
        foreach (array_slice($valor, 0, 50, true) as $chave => $item) {
            $nome = strtolower((string) $chave);
            if (preg_match('/token|secret|password|senha|authorization|api.?key|credential/', $nome)) {
                $limpo[$chave] = '[redigido]';
            } else {
                $limpo[$chave] = sanitizarAuditoriaFollowupPr3($item, $profundidade + 1);
            }
        }
        return $limpo;
    }
    if (is_string($valor)) {
        $valor = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+/i', 'Bearer [redigido]', $valor) ?? $valor;
        return mb_substr($valor, 0, 800);
    }
    return is_scalar($valor) || $valor === null ? $valor : get_debug_type($valor);
}

function resumoAuditoriaFollowupPr3(mixed $valor): string
{
    if (is_string($valor)) return mb_substr((string) sanitizarAuditoriaFollowupPr3($valor), 0, 1000);
    $json = json_encode(sanitizarAuditoriaFollowupPr3($valor), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return mb_substr($json === false ? '[resumo indisponível]' : $json, 0, 1000);
}

/** @return array<string,mixed> */
function construirGrafoFollowupPr3(array $regra, array $configuracao): array
{
    $nome = trim((string) ($configuracao['name'] ?? ''));
    if ($nome === '') $nome = ucfirst((string) ($regra['pipeline_key'] ?? 'Funil')) . ' · ' . (string) ($regra['stage_name'] ?? 'Etapa');
    $nodes = [
        ['id'=>'trigger','type'=>'trigger','label'=>'Entrada na etapa','position'=>['x'=>40,'y'=>210],'config'=>[]],
        ['id'=>'eligibility','type'=>'condition','label'=>'Elegibilidade','position'=>['x'=>300,'y'=>130],'config'=>[
            'inactivity_hours'=>(int)($regra['inactivity_hours'] ?? 24),
            'min_score'=>(int)($regra['min_score'] ?? 0),
        ]],
        ['id'=>'ai','type'=>'ai','label'=>'Gerar mensagem','position'=>['x'=>610,'y'=>210],'config'=>[]],
        ['id'=>'whatsapp','type'=>'whatsapp','label'=>'Enviar WhatsApp','position'=>['x'=>870,'y'=>210],'config'=>[]],
        ['id'=>'move_stage','type'=>'move_stage','label'=>'Mover etapa','position'=>['x'=>1130,'y'=>130],'config'=>[
            'active'=>!empty($configuracao['move_stage_active']),
            'target_stage'=>trim((string)($configuracao['move_stage_name'] ?? '')),
            'on_statuses'=>['sent'],
        ]],
        ['id'=>'assign_owner','type'=>'assign_owner','label'=>'Atribuir responsável','position'=>['x'=>1390,'y'=>210],'config'=>[
            'active'=>!empty($configuracao['assign_owner_active']),
            'mode'=>(string)($configuracao['assign_owner_mode'] ?? 'specific'),
            'user_id'=>(int)($configuracao['assign_owner_user_id'] ?? 0),
            'on_statuses'=>['sent'],
        ]],
        ['id'=>'human_handoff','type'=>'human_handoff','label'=>'Transbordo humano','position'=>['x'=>1650,'y'=>130],'config'=>[
            'active'=>!empty($configuracao['human_handoff_active']),
            'reason'=>trim((string)($configuracao['human_handoff_reason'] ?? 'A automação solicitou revisão humana.')),
            'priority'=>(string)($configuracao['human_handoff_priority'] ?? 'normal'),
            'recipient'=>(string)($configuracao['human_handoff_recipient'] ?? 'support'),
            'on_statuses'=>['blocked','failed','delivery_unknown'],
        ]],
        ['id'=>'notify_internal','type'=>'notify_internal','label'=>'Notificação interna','position'=>['x'=>1910,'y'=>210],'config'=>[
            'active'=>!empty($configuracao['notify_internal_active']),
            'user_id'=>(int)($configuracao['notify_internal_user_id'] ?? 0),
            'title'=>trim((string)($configuracao['notify_internal_title'] ?? 'Follow-up atualizado')),
            'message'=>trim((string)($configuracao['notify_internal_message'] ?? 'A execução do follow-up requer atenção.')),
            'severity'=>(string)($configuracao['notify_internal_severity'] ?? 'info'),
            'on_statuses'=>['sent','blocked','failed','delivery_unknown'],
        ]],
        ['id'=>'control','type'=>'control','label'=>'Limites e parada','position'=>['x'=>2170,'y'=>130],'config'=>[
            'max_attempts'=>(int)($regra['max_attempts'] ?? 3),
            'active'=>!empty($regra['is_active']),
        ]],
    ];
    $ordem = array_column($nodes, 'id');
    $edges = [];
    for ($i = 0; $i < count($ordem) - 1; $i++) {
        $edges[] = ['id'=>'edge-'.$ordem[$i].'-'.$ordem[$i+1], 'source'=>$ordem[$i], 'target'=>$ordem[$i+1]];
    }
    return [
        'contract_version'=>'3.0',
        'name'=>mb_substr($nome, 0, 180),
        'rule_id'=>(int)($regra['id'] ?? 0),
        'pipeline_key'=>(string)($regra['pipeline_key'] ?? ''),
        'stage_name'=>(string)($regra['stage_name'] ?? ''),
        'nodes'=>$nodes,
        'edges'=>$edges,
    ];
}

/** @return array{valid:bool,errors:array<int,string>} */
function validarGrafoFollowupPr3(array $grafo): array
{
    $erros = [];
    $nodes = is_array($grafo['nodes'] ?? null) ? $grafo['nodes'] : [];
    $edges = is_array($grafo['edges'] ?? null) ? $grafo['edges'] : [];
    if (!$nodes || count($nodes) > 60) $erros[] = 'O fluxo deve possuir entre 1 e 60 nós.';
    if (count($edges) > 120) $erros[] = 'O fluxo excede o limite de conexões.';
    $tipos = tiposNosFollowupPr3();
    $ids = [];
    $tiposUsados = [];
    foreach ($nodes as $node) {
        $id = (string)($node['id'] ?? '');
        $tipo = (string)($node['type'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_-]{0,79}$/', $id)) $erros[] = 'Existe um identificador de nó inválido.';
        if (isset($ids[$id])) $erros[] = "O nó {$id} está duplicado.";
        if (!isset($tipos[$tipo])) $erros[] = "O tipo de nó {$tipo} não é permitido.";
        $ids[$id] = true;
        $tiposUsados[$tipo] = ($tiposUsados[$tipo] ?? 0) + 1;
        $cfg = (array)($node['config']??[]);
        if ($tipo==='move_stage' && !empty($cfg['active']) && trim((string)($cfg['target_stage']??''))==='') $erros[]='O nó Mover etapa exige uma etapa de destino.';
        if ($tipo==='assign_owner' && !empty($cfg['active']) && !in_array($cfg['mode']??'specific',['specific','keep','supervisor','administrator'],true)) $erros[]='O modo de atribuição é inválido.';
        if ($tipo==='assign_owner' && !empty($cfg['active']) && ($cfg['mode']??'specific')==='specific' && (int)($cfg['user_id']??0)<=0) $erros[]='O nó Atribuir responsável exige um usuário.';
        if ($tipo==='human_handoff' && !empty($cfg['active']) && trim((string)($cfg['reason']??''))==='') $erros[]='O transbordo humano exige um motivo.';
        if ($tipo==='notify_internal' && !empty($cfg['active']) && ((int)($cfg['user_id']??0)<=0 || trim((string)($cfg['title']??''))==='' || trim((string)($cfg['message']??''))==='')) $erros[]='A notificação interna exige destinatário, título e mensagem.';
    }
    if (($tiposUsados['trigger'] ?? 0) !== 1) $erros[] = 'O fluxo deve possuir exatamente um gatilho.';
    if (($tiposUsados['control'] ?? 0) !== 1) $erros[] = 'O fluxo deve possuir exatamente um nó de controle.';
    $adj = array_fill_keys(array_keys($ids), []);
    $grau = array_fill_keys(array_keys($ids), 0);
    foreach ($edges as $edge) {
        $origem = (string)($edge['source'] ?? '');
        $destino = (string)($edge['target'] ?? '');
        if (!isset($ids[$origem], $ids[$destino]) || $origem === $destino) {
            $erros[] = 'Existe uma conexão inválida ou autorreferente.';
            continue;
        }
        $adj[$origem][] = $destino;
        $grau[$destino]++;
    }
    $fila = array_keys(array_filter($grau, static fn(int $n): bool => $n === 0));
    $visitados = 0;
    while ($fila) {
        $atual = array_shift($fila);
        $visitados++;
        foreach ($adj[$atual] as $destino) if (--$grau[$destino] === 0) $fila[] = $destino;
    }
    if ($nodes && $visitados !== count($nodes)) $erros[] = 'O fluxo não pode conter ciclos.';
    return ['valid'=>!$erros, 'errors'=>array_values(array_unique($erros))];
}

/** @return array<string,mixed> */
function configuracaoAcoesFollowupPr3(PDO $pdo, int $regraId): array
{
    $base = [
        'move_stage_active'=>false,'move_stage_name'=>'',
        'assign_owner_active'=>false,'assign_owner_mode'=>'specific','assign_owner_user_id'=>0,
        'human_handoff_active'=>false,'human_handoff_reason'=>'A automação solicitou revisão humana.','human_handoff_priority'=>'normal','human_handoff_recipient'=>'support',
        'notify_internal_active'=>false,'notify_internal_user_id'=>0,'notify_internal_title'=>'Follow-up atualizado','notify_internal_message'=>'A execução do follow-up requer atenção.','notify_internal_severity'=>'info',
        'name'=>'','flow_id'=>0,'flow_status'=>'draft',
    ];
    if (!followupFluxoTabelasDisponiveis($pdo)) return $base;
    $stmt = $pdo->prepare('SELECT id,name,status,draft_graph FROM followup_flows WHERE rule_id=? LIMIT 1');
    $stmt->execute([$regraId]);
    $fluxo = $stmt->fetch();
    if (!$fluxo) return $base;
    $base['name'] = (string)$fluxo['name'];
    $base['flow_id'] = (int)$fluxo['id'];
    $base['flow_status'] = (string)$fluxo['status'];
    try { $grafo = json_decode((string)$fluxo['draft_graph'], true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { return $base; }
    foreach ((array)($grafo['nodes'] ?? []) as $node) {
        $tipo = (string)($node['type'] ?? '');
        $cfg = (array)($node['config'] ?? []);
        if ($tipo === 'move_stage') { $base['move_stage_active']=!empty($cfg['active']); $base['move_stage_name']=(string)($cfg['target_stage']??''); }
        if ($tipo === 'assign_owner') { $base['assign_owner_active']=!empty($cfg['active']); $base['assign_owner_mode']=(string)($cfg['mode']??'specific'); $base['assign_owner_user_id']=(int)($cfg['user_id']??0); }
        if ($tipo === 'human_handoff') { $base['human_handoff_active']=!empty($cfg['active']); $base['human_handoff_reason']=(string)($cfg['reason']??$base['human_handoff_reason']); $base['human_handoff_priority']=(string)($cfg['priority']??'normal'); $base['human_handoff_recipient']=(string)($cfg['recipient']??'support'); }
        if ($tipo === 'notify_internal') { $base['notify_internal_active']=!empty($cfg['active']); $base['notify_internal_user_id']=(int)($cfg['user_id']??0); $base['notify_internal_title']=(string)($cfg['title']??$base['notify_internal_title']); $base['notify_internal_message']=(string)($cfg['message']??$base['notify_internal_message']); $base['notify_internal_severity']=(string)($cfg['severity']??'info'); }
    }
    return $base;
}

/** @return array<int,array<string,mixed>> */
function listarVersoesFluxoPr3(PDO $pdo, int $regraId): array
{
    if (!followupFluxoTabelasDisponiveis($pdo)) return [];
    $stmt = $pdo->prepare('SELECT fv.id,fv.version_number,fv.published_at,u.nome published_by FROM followup_flow_versions fv JOIN followup_flows ff ON ff.id=fv.flow_id LEFT JOIN usuarios u ON u.id=fv.published_by_user_id WHERE ff.rule_id=? ORDER BY fv.version_number DESC LIMIT 20');
    $stmt->execute([$regraId]);
    return $stmt->fetchAll();
}

function obterOuCriarFluxoPr3(PDO $pdo, int $regraId, string $nome, string $json, int $usuarioId): int
{
    $stmt = $pdo->prepare("INSERT INTO followup_flows (rule_id,name,status,draft_graph,created_by_user_id,updated_by_user_id,created_at,updated_at) VALUES (?,?,'draft',?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),draft_graph=VALUES(draft_graph),updated_by_user_id=VALUES(updated_by_user_id),updated_at=NOW()");
    $stmt->execute([$regraId,mb_substr($nome,0,180),$json,$usuarioId ?: null,$usuarioId ?: null]);
    $stmt = $pdo->prepare('SELECT id FROM followup_flows WHERE rule_id=?');
    $stmt->execute([$regraId]);
    $id = (int)$stmt->fetchColumn();
    if (!$id) throw new FollowupFlowException('Não foi possível preparar o fluxo.');
    return $id;
}

function salvarRascunhoFluxoPr3(PDO $pdo, int $regraId, array $grafo, int $usuarioId): int
{
    if (!temPapel(PAPEL_ADMINISTRADOR)) throw new FollowupFlowException('Somente o Administrador pode alterar fluxos.');
    if (!followupFluxoTabelasDisponiveis($pdo)) throw new FollowupFlowException('Instale a migração 20260812_followup_flows_pr3.sql.');
    $validacao = validarGrafoFollowupPr3($grafo);
    if (!$validacao['valid']) throw new FollowupFlowException(implode(' ', $validacao['errors']));
    $json = json_encode(sanitizarAuditoriaFollowupPr3($grafo), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $flowId=obterOuCriarFluxoPr3($pdo,$regraId,(string)($grafo['name']??'Fluxo'),$json,$usuarioId);
    $pdo->prepare("INSERT INTO followup_flow_audit_events (flow_id,event_type,summary,actor_user_id,created_at) VALUES (?,'draft_saved','Rascunho validado e salvo.',?,NOW())")->execute([$flowId,$usuarioId?:null]);
    return $flowId;
}

function sincronizarAcoesPublicadasPr3(PDO $pdo, int $regraId, array $grafo): void
{
    $pdo->prepare('UPDATE followup_rule_actions SET is_active=0,updated_at=NOW() WHERE rule_id=?')->execute([$regraId]);
    $ordem = 0;
    $permitidos = ['move_stage','assign_owner','human_handoff','notify_internal'];
    foreach ((array)$grafo['nodes'] as $node) {
        $tipo = (string)($node['type'] ?? '');
        if (!in_array($tipo,$permitidos,true)) continue;
        $ordem++;
        $cfg = (array)($node['config'] ?? []);
        $ativo = !empty($cfg['active']);
        $json = json_encode(sanitizarAuditoriaFollowupPr3($cfg), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare('INSERT INTO followup_rule_actions (rule_id,node_key,action_type,action_order,config_json,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE action_type=VALUES(action_type),action_order=VALUES(action_order),config_json=VALUES(config_json),is_active=VALUES(is_active),updated_at=NOW()');
        $stmt->execute([$regraId,(string)$node['id'],$tipo,$ordem,$json,$ativo?1:0]);
    }
}

function publicarFluxoPr3(PDO $pdo, int $regraId, array $grafo, int $usuarioId, string $evento='published'): int
{
    if (!temPapel(PAPEL_ADMINISTRADOR)) throw new FollowupFlowException('Somente o Administrador pode publicar fluxos.');
    if (!followupFluxoTabelasDisponiveis($pdo)) throw new FollowupFlowException('Instale a migração 20260812_followup_flows_pr3.sql.');
    $validacao = validarGrafoFollowupPr3($grafo);
    if (!$validacao['valid']) throw new FollowupFlowException(implode(' ', $validacao['errors']));
    $stmt = $pdo->prepare('SELECT ps.pipeline_key FROM followup_rules fr JOIN pipeline_stages ps ON ps.id=fr.pipeline_stage_id WHERE fr.id=? LIMIT 1');
    $stmt->execute([$regraId]); $pipelineKey = (string)$stmt->fetchColumn();
    if ($pipelineKey==='') throw new FollowupFlowException('A regra não está vinculada a um funil válido.');
    foreach((array)($grafo['nodes']??[]) as $node) {
        $tipo=(string)($node['type']??''); $cfg=(array)($node['config']??[]);
        if (empty($cfg['active'])) continue;
        if ($tipo==='move_stage') {
            $check=$pdo->prepare('SELECT COUNT(*) FROM pipeline_stages WHERE pipeline_key=? AND stage_name=? AND is_active=1');
            $check->execute([$pipelineKey,(string)($cfg['target_stage']??'')]);
            if (!(int)$check->fetchColumn()) throw new FollowupFlowException('A etapa de destino não pertence ao funil da regra.');
        }
        if (($tipo==='assign_owner' && ($cfg['mode']??'specific')==='specific') || $tipo==='notify_internal') {
            $check=$pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id=? AND status='active'");
            $check->execute([(int)($cfg['user_id']??0)]);
            if (!(int)$check->fetchColumn()) throw new FollowupFlowException('Um usuário configurado não está ativo.');
        }
    }
    $pdo->beginTransaction();
    try {
        $json = json_encode(sanitizarAuditoriaFollowupPr3($grafo), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $flowId = obterOuCriarFluxoPr3($pdo,$regraId,(string)($grafo['name']??'Fluxo'),$json,$usuarioId);
        $stmt = $pdo->prepare('SELECT id FROM followup_flows WHERE id=? FOR UPDATE');
        $stmt->execute([$flowId]);
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(version_number),0)+1 FROM followup_flow_versions WHERE flow_id=? FOR UPDATE');
        $stmt->execute([$flowId]); $versao = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare('INSERT INTO followup_flow_versions (flow_id,version_number,graph_json,published_by_user_id,published_at) VALUES (?,?,?,?,NOW())');
        $stmt->execute([$flowId,$versao,$json,$usuarioId?:null]);
        $versionId = (int)$pdo->lastInsertId();
        $horas=24; $score=0; $tentativas=3; $ativa=false;
        foreach((array)($grafo['nodes']??[]) as $node) {
            $cfg=(array)($node['config']??[]);
            if (($node['type']??'')==='condition') { $horas=(int)($cfg['inactivity_hours']??24); $score=(int)($cfg['min_score']??0); }
            if (($node['type']??'')==='control') { $tentativas=(int)($cfg['max_attempts']??3); $ativa=!empty($cfg['active']); }
        }
        $pdo->prepare('UPDATE followup_rules SET inactivity_hours=?,min_score=?,max_attempts=?,is_active=?,updated_at=NOW() WHERE id=?')->execute([max(1,min(8760,$horas)),max(0,min(100,$score)),max(1,min(20,$tentativas)),$ativa?1:0,$regraId]);
        $statusFluxo=$ativa?'active':'paused';
        $pdo->prepare('UPDATE followup_flows SET status=?,active_version_id=?,updated_by_user_id=?,updated_at=NOW() WHERE id=?')->execute([$statusFluxo,$versionId,$usuarioId?:null,$flowId]);
        sincronizarAcoesPublicadasPr3($pdo,$regraId,$grafo);
        $tipoEvento=$evento==='rollback'?'rollback':'published';
        $pdo->prepare('INSERT INTO followup_flow_audit_events (flow_id,flow_version_id,event_type,summary,actor_user_id,created_at) VALUES (?,?,?,?,?,NOW())')->execute([$flowId,$versionId,$tipoEvento,'Versão '.$versao.' publicada após validação do grafo.',$usuarioId?:null]);
        $pdo->commit();
        return $versao;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function republicarVersaoFluxoPr3(PDO $pdo, int $regraId, int $versionId, int $usuarioId): int
{
    if (!temPapel(PAPEL_ADMINISTRADOR)) throw new FollowupFlowException('Somente o Administrador pode republicar versões.');
    $stmt = $pdo->prepare('SELECT fv.graph_json FROM followup_flow_versions fv JOIN followup_flows ff ON ff.id=fv.flow_id WHERE fv.id=? AND ff.rule_id=? LIMIT 1');
    $stmt->execute([$versionId,$regraId]);
    $json = $stmt->fetchColumn();
    if (!$json) throw new FollowupFlowException('Versão não encontrada para este fluxo.');
    $grafo = json_decode((string)$json,true,512,JSON_THROW_ON_ERROR);
    return publicarFluxoPr3($pdo,$regraId,$grafo,$usuarioId,'rollback');
}

function alterarStatusFluxoPr3(PDO $pdo, int $regraId, string $acao, int $usuarioId): void
{
    if (!temPapel(PAPEL_ADMINISTRADOR)) throw new FollowupFlowException('Somente o Administrador pode pausar ou retomar fluxos.');
    if (!in_array($acao,['pause','resume'],true)) throw new FollowupFlowException('Ação operacional inválida.');
    $stmt=$pdo->prepare('SELECT id,active_version_id,status FROM followup_flows WHERE rule_id=? LIMIT 1');
    $stmt->execute([$regraId]); $fluxo=$stmt->fetch();
    if(!$fluxo) throw new FollowupFlowException('Salve e publique o fluxo antes desta ação.');
    if($acao==='resume' && empty($fluxo['active_version_id'])) throw new FollowupFlowException('O fluxo ainda não possui versão publicada.');
    $status=$acao==='pause'?'paused':'active';
    $evento=$acao==='pause'?'paused':'resumed';
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE followup_flows SET status=?,updated_by_user_id=?,updated_at=NOW() WHERE id=?')->execute([$status,$usuarioId?:null,(int)$fluxo['id']]);
        $pdo->prepare('UPDATE followup_rules SET is_active=?,updated_at=NOW() WHERE id=?')->execute([$status==='active'?1:0,$regraId]);
        $pdo->prepare('INSERT INTO followup_flow_audit_events (flow_id,flow_version_id,event_type,summary,actor_user_id,created_at) VALUES (?,?,?,?,?,NOW())')->execute([(int)$fluxo['id'],$fluxo['active_version_id']?:null,$evento,$acao==='pause'?'Novas entradas foram pausadas.':'Novas entradas foram retomadas.',$usuarioId?:null]);
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}

function registrarEventoFluxoPr3(PDO $pdo, ?int $runId, string $nodeKey, string $nodeType, string $status, mixed $entrada = null, mixed $saida = null, ?string $erro = null, ?int $duracaoMs = null, ?string $sufixo = null): bool
{
    if (!$runId || !followupFluxoTabelasDisponiveis($pdo)) return false;
    try {
        $status = in_array($status,['running','success','failed','skipped','blocked','paused'],true) ? $status : 'failed';
        $stmtMeta=$pdo->prepare('SELECT flow_version_id,lead_id FROM followup_flow_runs WHERE id=? LIMIT 1');
        $stmtMeta->execute([$runId]); $meta=$stmtMeta->fetch();
        if(!$meta) return false;
        $chave = hash('sha256','flow-event:'.$runId.':'.(int)$meta['flow_version_id'].':'.(int)$meta['lead_id'].':'.$nodeKey.':'.($sufixo??$status));
        $stmt = $pdo->prepare('INSERT IGNORE INTO followup_flow_events (run_id,node_key,node_type,status,input_summary,output_summary,error_code,duration_ms,idempotency_key,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
        $stmt->execute([$runId,mb_substr($nodeKey,0,80),mb_substr($nodeType,0,40),$status,$entrada===null?null:resumoAuditoriaFollowupPr3($entrada),$saida===null?null:resumoAuditoriaFollowupPr3($saida),$erro?mb_substr($erro,0,80):null,$duracaoMs,$chave]);
        return $stmt->rowCount() === 1;
    } catch (Throwable $e) {
        error_log('DNB flow audit event: '.$e->getMessage());
        return false;
    }
}

function iniciarExecucaoFluxoPr3(PDO $pdo, array $candidato, array $log): ?int
{
    if (!followupFluxoTabelasDisponiveis($pdo)) return null;
    try {
        $stmt = $pdo->prepare("SELECT id,active_version_id FROM followup_flows WHERE rule_id=? AND status='active' AND active_version_id IS NOT NULL LIMIT 1");
        $stmt->execute([(int)$candidato['rule_id']]); $fluxo = $stmt->fetch();
        if (!$fluxo) return null;
        $stmt = $pdo->prepare("INSERT IGNORE INTO followup_flow_runs (flow_id,flow_version_id,legacy_log_id,lead_id,rule_id,status,correlation_id,started_at,updated_at) VALUES (?,?,?,?,?,'running',?,NOW(),NOW())");
        $stmt->execute([(int)$fluxo['id'],(int)$fluxo['active_version_id'],(int)$log['id'],(int)$candidato['id'],(int)$candidato['rule_id'],(string)($log['correlation_id']??'')]);
        $stmt = $pdo->prepare('SELECT id FROM followup_flow_runs WHERE legacy_log_id=?');
        $stmt->execute([(int)$log['id']]); $runId = (int)$stmt->fetchColumn();
        if (!$runId) return null;
        registrarEventoFluxoPr3($pdo,$runId,'trigger','trigger','success',['lead_id'=>(int)$candidato['id']],['rule_id'=>(int)$candidato['rule_id']]);
        registrarEventoFluxoPr3($pdo,$runId,'eligibility','condition','success',['stage'=>(string)$candidato['etapa'],'score'=>(int)($candidato['score']??0)],['eligible'=>true]);
        return $runId;
    } catch (Throwable $e) {
        error_log('DNB flow run start: '.$e->getMessage());
        return null;
    }
}

function finalizarExecucaoFluxoPr3(PDO $pdo, ?int $runId, string $status): void
{
    if (!$runId || !followupFluxoTabelasDisponiveis($pdo)) return;
    try {
        $status = in_array($status,['success','failed','paused','blocked','delivery_unknown'],true) ? $status : 'failed';
        $pdo->prepare('UPDATE followup_flow_runs SET status=?,finished_at=COALESCE(finished_at,NOW()),updated_at=NOW() WHERE id=?')->execute([$status,$runId]);
        registrarEventoFluxoPr3($pdo,$runId,'control','control',$status==='success'?'success':($status==='paused'?'paused':($status==='blocked'?'blocked':'failed')),null,['run_status'=>$status]);
    } catch (Throwable $e) {
        error_log('DNB flow run finish: '.$e->getMessage());
    }
}

function executarAcaoFluxoPr3(PDO $pdo, array $acao, array $candidato, string $resultado): string
{
    $cfg = json_decode((string)$acao['config_json'],true,512,JSON_THROW_ON_ERROR);
    $leadId = (int)$candidato['id'];
    $tipo = (string)$acao['action_type'];
    $contextoAuditoria='execução #'.(int)($candidato['_run_id']??0).' · nó '.(string)($candidato['_node_key']??$tipo);
    if (!in_array($resultado,(array)($cfg['on_statuses']??[]),true)) return 'ignorada para este resultado';
    if ($tipo === 'move_stage') {
        $etapa = trim((string)($cfg['target_stage']??''));
        $stmt=$pdo->prepare('SELECT funil,etapa,status FROM orcamentos WHERE id=? FOR UPDATE');
        $stmt->execute([$leadId]); $leadAtual=$stmt->fetch();
        if(!$leadAtual||$leadAtual['status']!=='Aberta') throw new FollowupFlowException('Oportunidade indisponível para mudança de etapa.');
        if((string)$leadAtual['etapa']===$etapa) return 'Oportunidade já estava na etapa escolhida';
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pipeline_stages WHERE pipeline_key=? AND stage_name=? AND is_active=1');
        $stmt->execute([(string)$leadAtual['funil'],$etapa]);
        if ($etapa === '' || !(int)$stmt->fetchColumn()) throw new FollowupFlowException('Etapa de destino inválida.');
        $pdo->prepare("UPDATE orcamentos SET etapa=? WHERE id=? AND status='Aberta'")->execute([$etapa,$leadId]);
        registrarTimeline($pdo,$leadId,'followup_flow','Automação moveu a oportunidade de '.$leadAtual['etapa'].' para '.$etapa.' ('.$contextoAuditoria.').');
        return 'Etapa atualizada para '.$etapa;
    }
    if ($tipo === 'assign_owner') {
        $modo=(string)($cfg['mode']??'specific');
        $stmt=$pdo->prepare('SELECT assigned_to_user_id FROM orcamentos WHERE id=? FOR UPDATE');$stmt->execute([$leadId]);$usuarioAtual=(int)$stmt->fetchColumn();
        if($modo==='keep') return 'Responsável atual mantido';
        $usuarioId=(int)($cfg['user_id']??0);
        if($modo==='supervisor'&&$usuarioAtual){$stmt=$pdo->prepare("SELECT supervisor_id FROM usuarios WHERE id=? AND status='active'");$stmt->execute([$usuarioAtual]);$usuarioId=(int)$stmt->fetchColumn();}
        if($modo==='administrator')$usuarioId=(int)(idsUsuariosComPapeis($pdo,[PAPEL_ADMINISTRADOR])[0]??0);
        $stmt = $pdo->prepare("SELECT nome FROM usuarios WHERE id=? AND status='active' LIMIT 1");
        $stmt->execute([$usuarioId]); $nome = $stmt->fetchColumn();
        if (!$nome) throw new FollowupFlowException('Responsável configurado não está ativo.');
        if($usuarioId===$usuarioAtual) return 'Responsável já estava atribuído';
        $pdo->prepare('UPDATE orcamentos SET assigned_to_user_id=? WHERE id=?')->execute([$usuarioId,$leadId]);
        registrarTimeline($pdo,$leadId,'followup_flow','Automação alterou o responsável de #'.$usuarioAtual.' para '.$nome.' ('.$contextoAuditoria.').');
        criarNotificacao($pdo,$usuarioId,'followup_assignment','Nova oportunidade atribuída','O fluxo atribuiu o lead #'.$leadId.' a você.','/lead.php?id='.$leadId);
        return 'Responsável atualizado';
    }
    if ($tipo === 'human_handoff') {
        $prioridade=in_array($cfg['priority']??'normal',['low','normal','high','urgent'],true)?$cfg['priority']:'normal';
        $motivo = mb_substr('Prioridade '.$prioridade.'. '.trim((string)($cfg['reason']??'Revisão humana solicitada.')),0,1000);
        $stmt = $pdo->prepare("SELECT id FROM support_cases WHERE lead_id=? AND status IN ('open','in_progress') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$leadId]); $casoId = (int)$stmt->fetchColumn();
        if (!$casoId) {
            $stmt = $pdo->prepare("INSERT INTO support_cases (lead_id,ai_interaction_id,reason_blocked,customer_query,status,created_at,updated_at) VALUES (?,NULL,?,?,'open',NOW(),NOW())");
            $stmt->execute([$leadId,$motivo,'Transbordo criado pelo fluxo de follow-up.']);
            $casoId = (int)$pdo->lastInsertId();
        }
        if (!empty($candidato['conversation_id'])) $pdo->prepare('UPDATE whatsapp_conversations SET ai_paused=1,human_locked_at=COALESCE(human_locked_at,NOW()),updated_at=NOW() WHERE id=?')->execute([(int)$candidato['conversation_id']]);
        $destino=(string)($cfg['recipient']??'support'); $destinatarios=[]; $atual=(int)($candidato['assigned_to_user_id']??0);
        if($destino==='current'&&$atual)$destinatarios[]=$atual;
        if($destino==='supervisor'&&$atual){$stmt=$pdo->prepare("SELECT supervisor_id FROM usuarios WHERE id=? AND status='active'");$stmt->execute([$atual]);$super=(int)$stmt->fetchColumn();if($super)$destinatarios[]=$super;}
        if($destino==='administrator')$destinatarios=idsUsuariosComPapeis($pdo,[PAPEL_ADMINISTRADOR]);
        if($destino==='support')$destinatarios=idsUsuariosComPapeis($pdo,[PAPEL_SUPORTE]);
        foreach(array_unique($destinatarios) as $destinatario) criarNotificacao($pdo,(int)$destinatario,'ai_handoff','Atendimento humano solicitado',$motivo,'/casos.php?caso='.$casoId);
        registrarTimeline($pdo,$leadId,'followup_flow','Fluxo encaminhou o atendimento para revisão humana no caso #'.$casoId.' ('.$contextoAuditoria.').');
        return 'Caso humano #'.$casoId.' aberto ou reutilizado';
    }
    if ($tipo === 'notify_internal') {
        $usuarioId = (int)($cfg['user_id']??0);
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id=? AND status='active' LIMIT 1");
        $stmt->execute([$usuarioId]);
        if (!(int)$stmt->fetchColumn()) throw new FollowupFlowException('Destinatário da notificação não está ativo.');
        $severidade=in_array($cfg['severity']??'info',['info','warning','critical'],true)?$cfg['severity']:'info';
        $mensagem=strip_tags((string)($cfg['message']??'A execução do follow-up requer atenção.'));
        criarNotificacao($pdo,$usuarioId,'followup_flow_'.$severidade,mb_substr(strip_tags((string)($cfg['title']??'Follow-up atualizado')),0,160),mb_substr($mensagem.' Lead #'.$leadId.' · resultado '.$resultado.'.',0,1000),'/lead.php?id='.$leadId);
        return 'Notificação interna criada';
    }
    throw new FollowupFlowException('Tipo de ação não suportado.');
}

function executarAcoesPublicadasPr3(PDO $pdo, array $candidato, ?int $runId, string $resultado): bool
{
    if (!$runId || !followupFluxoTabelasDisponiveis($pdo)) return true;
    try {
        $stmt = $pdo->prepare('SELECT * FROM followup_rule_actions WHERE rule_id=? AND is_active=1 ORDER BY action_order,id');
        $stmt->execute([(int)$candidato['rule_id']]);
        $acoes = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('DNB flow actions load: '.$e->getMessage());
        return false;
    }
    $sucesso = true;
    foreach ($acoes as $acao) {
        $nodeKey = (string)$acao['node_key'];
        $tipo = (string)$acao['action_type'];
        $reservou = registrarEventoFluxoPr3($pdo,$runId,$nodeKey,$tipo,'running',['result'=>$resultado],null,null,null,'claim-'.$resultado);
        if (!$reservou) continue;
        $inicio = hrtime(true);
        try {
            $pdo->beginTransaction();
            $candidatoComContexto=$candidato;
            $candidatoComContexto['_run_id']=$runId;
            $candidatoComContexto['_node_key']=$nodeKey;
            $saida = executarAcaoFluxoPr3($pdo,$acao,$candidatoComContexto,$resultado);
            $pdo->commit();
            $status = str_starts_with($saida,'ignorada') ? 'skipped' : 'success';
            registrarEventoFluxoPr3($pdo,$runId,$nodeKey,$tipo,$status,null,$saida,null,(int)((hrtime(true)-$inicio)/1000000),$resultado.'-'.$status);
        } catch (Throwable $e) {
            if($pdo->inTransaction()) $pdo->rollBack();
            $sucesso = false;
            registrarEventoFluxoPr3($pdo,$runId,$nodeKey,$tipo,'failed',null,'Ação interrompida','action_failed',(int)((hrtime(true)-$inicio)/1000000),$resultado.'-failed');
            error_log('DNB flow action '.$tipo.' lead #'.(int)$candidato['id'].': '.$e->getMessage());
        }
    }
    return $sucesso;
}

/** @return array<int,array<string,mixed>> */
function listarExecucoesFluxoPr3(PDO $pdo, array $filtros = [], int $limite = 100): array
{
    if (!followupFluxoTabelasDisponiveis($pdo)) return [];
    [$escopo,$params] = escopoAuditoriaFluxoPr3($pdo);
    $where = [$escopo];
    if (!empty($filtros['status']) && in_array($filtros['status'],['running','success','failed','paused','blocked','delivery_unknown'],true)) { $where[]='r.status=?'; $params[]=$filtros['status']; }
    if (!empty($filtros['flow_id'])) { $where[]='r.flow_id=?'; $params[]=(int)$filtros['flow_id']; }
    if (!empty($filtros['date_from'])) { $where[]='r.started_at>=?'; $params[]=$filtros['date_from'].' 00:00:00'; }
    if (!empty($filtros['date_to'])) { $where[]='r.started_at<=?'; $params[]=$filtros['date_to'].' 23:59:59'; }
    if (!empty($filtros['version'])) { $where[]='fv.version_number=?'; $params[]=(int)$filtros['version']; }
    if (!empty($filtros['node_type']) && isset(tiposNosFollowupPr3()[$filtros['node_type']])) { $where[]='EXISTS (SELECT 1 FROM followup_flow_events fe_filter WHERE fe_filter.run_id=r.id AND fe_filter.node_type=?)'; $params[]=$filtros['node_type']; }
    if (!empty($filtros['funnel'])) { $where[]='o.funil=?'; $params[]=mb_substr((string)$filtros['funnel'],0,20); }
    if (!empty($filtros['stage'])) { $where[]='o.etapa=?'; $params[]=mb_substr((string)$filtros['stage'],0,80); }
    if (!empty($filtros['owner_id'])) { $where[]='o.assigned_to_user_id=?'; $params[]=(int)$filtros['owner_id']; }
    if (!empty($filtros['q'])) { $termo='%'.mb_substr(trim((string)$filtros['q']),0,80).'%'; $where[]='(o.cliente LIKE ? OR o.numero LIKE ?)'; $params[]=$termo; $params[]=$termo; }
    $limite = max(1,min(300,$limite));
    $pagina=max(1,(int)($filtros['page']??1)); $offset=($pagina-1)*$limite;
    $stmt = $pdo->prepare('SELECT r.*,ff.name flow_name,fv.version_number,o.cliente,o.numero,o.assigned_to_user_id,u.nome owner_name,TIMESTAMPDIFF(MICROSECOND,r.started_at,COALESCE(r.finished_at,NOW())) DIV 1000 duration_ms,(SELECT COUNT(*) FROM followup_flow_events fec WHERE fec.run_id=r.id) nodes_executed,(SELECT fe.node_type FROM followup_flow_events fe WHERE fe.run_id=r.id ORDER BY fe.id DESC LIMIT 1) last_node FROM followup_flow_runs r JOIN followup_flows ff ON ff.id=r.flow_id JOIN followup_flow_versions fv ON fv.id=r.flow_version_id JOIN orcamentos o ON o.id=r.lead_id LEFT JOIN usuarios u ON u.id=o.assigned_to_user_id WHERE '.implode(' AND ',$where).' ORDER BY r.started_at DESC,r.id DESC LIMIT '.$limite.' OFFSET '.$offset);
    $stmt->execute($params); return $stmt->fetchAll();
}

/** Escopo do Audit Log: administrador global, supervisor/equipe e suporte/casos. */
function escopoAuditoriaFluxoPr3(PDO $pdo): array
{
    if (temPapel(PAPEL_ADMINISTRADOR)) return ['1=1',[]];
    $condicoes=[]; $params=[];
    if (temPapel(PAPEL_SUPERVISOR)) {
        $ids=idsUsuariosDaEquipe($pdo);
        if($ids){$condicoes[]='o.assigned_to_user_id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$params=array_merge($params,$ids);}
    }
    if (temPapel(PAPEL_SUPORTE)) $condicoes[]="EXISTS (SELECT 1 FROM support_cases sc_scope WHERE sc_scope.lead_id=o.id AND sc_scope.status IN ('open','in_progress'))";
    return $condicoes?['('.implode(' OR ',$condicoes).')',$params]:['1=0',[]];
}

/** @return array<string,mixed>|null */
function detalharExecucaoFluxoPr3(PDO $pdo, int $runId): ?array
{
    if (!followupFluxoTabelasDisponiveis($pdo)) return null;
    $stmt = $pdo->prepare('SELECT r.*,ff.name flow_name,fv.version_number,fv.graph_json,o.cliente,o.numero FROM followup_flow_runs r JOIN followup_flows ff ON ff.id=r.flow_id JOIN followup_flow_versions fv ON fv.id=r.flow_version_id JOIN orcamentos o ON o.id=r.lead_id WHERE r.id=? LIMIT 1');
    $stmt->execute([$runId]); $run = $stmt->fetch();
    if (!$run || !podeAcessarLead($pdo,(int)$run['lead_id'])) return null;
    $stmt = $pdo->prepare('SELECT * FROM followup_flow_events WHERE run_id=? ORDER BY id');
    $stmt->execute([$runId]); $run['events']=$stmt->fetchAll();
    try { $run['graph']=json_decode((string)$run['graph_json'],true,512,JSON_THROW_ON_ERROR); } catch (Throwable) { $run['graph']=['nodes'=>[],'edges'=>[]]; }
    unset($run['graph_json']);
    return $run;
}

/** @return array<string,int> */
function estatisticasExecucoesFluxoPr3(PDO $pdo): array
{
    $base=['running'=>0,'success'=>0,'failed'=>0,'paused'=>0,'blocked'=>0,'delivery_unknown'=>0];
    if (!followupFluxoTabelasDisponiveis($pdo)) return $base;
    [$escopo,$params]=escopoAuditoriaFluxoPr3($pdo);
    $stmt=$pdo->prepare('SELECT r.status,COUNT(*) total FROM followup_flow_runs r JOIN orcamentos o ON o.id=r.lead_id WHERE '.$escopo.' AND r.started_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) GROUP BY r.status');
    $stmt->execute($params); foreach($stmt->fetchAll() as $row) $base[(string)$row['status']]=(int)$row['total'];
    return $base;
}
