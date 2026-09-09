<?php
/** Serviços de oportunidades (leads), scoring e linha do tempo. */
require_once __DIR__ . '/rbac.php';

function buscarLead(PDO $pdo, int $leadId): ?array
{
    $stmt = $pdo->prepare("SELECT o.*, COALESCE(v.nome,u.nome) assigned_to_name, u.email assigned_to_email,
        v.id assigned_to_pessoa_id,
        rc.title registro_cargo_title,rc.has_commission registro_cargo_comissionavel,
        (SELECT GROUP_CONCAT(c.title ORDER BY c.id SEPARATOR ', ') FROM user_cargos uc JOIN cargos c ON c.id=uc.cargo_id WHERE uc.user_id=u.id) assigned_to_cargos,
        ls.score lead_score, ls.justification score_justification, ls.updated_at score_updated_at
        FROM orcamentos o
        LEFT JOIN usuarios u ON u.id=o.assigned_to_user_id
        LEFT JOIN vendedores v ON v.id=COALESCE(u.pessoa_id,u.vendedor_id)
        LEFT JOIN cargos rc ON rc.id=o.cargo_id
        LEFT JOIN lead_scores ls ON ls.lead_id=o.id
        WHERE o.id=? LIMIT 1");
    $stmt->execute([$leadId]);
    return $stmt->fetch() ?: null;
}

function buscarLeadAutorizado(PDO $pdo, int $leadId): ?array
{
    if (!podeAcessarLead($pdo, $leadId)) return null;
    return buscarLead($pdo, $leadId);
}

function listarLeadsAcessiveis(PDO $pdo, bool $somenteAbertos = false): array
{
    $somenteSuporte = temPapel(PAPEL_SUPORTE) && !temPapel(PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_VENDEDOR);
    if ($somenteSuporte) {
        $status = $somenteAbertos ? " AND o.status='Aberta'" : '';
        $stmt = $pdo->query("SELECT DISTINCT o.*,u.nome assigned_to_name,ls.score lead_score,rc.title registro_cargo_title
            FROM orcamentos o JOIN support_cases sc ON sc.lead_id=o.id
            LEFT JOIN usuarios u ON u.id=o.assigned_to_user_id
            LEFT JOIN lead_scores ls ON ls.lead_id=o.id
            LEFT JOIN cargos rc ON rc.id=o.cargo_id
            WHERE 1=1{$status} ORDER BY o.data DESC,o.id DESC");
        return $stmt->fetchAll();
    }
    [$filtro, $params] = filtroEscopoAtribuicao($pdo, 'o.assigned_to_user_id');
    if (temPapel(PAPEL_SUPORTE) && !temPapel(PAPEL_ADMINISTRADOR)) {
        $filtro = '(' . $filtro . " OR EXISTS (SELECT 1 FROM support_cases sc WHERE sc.lead_id=o.id AND sc.status IN ('open','in_progress')))";
    }
    $status = $somenteAbertos ? " AND o.status='Aberta'" : '';
    $stmt = $pdo->prepare("SELECT o.*, u.nome assigned_to_name, ls.score lead_score,rc.title registro_cargo_title
        FROM orcamentos o
        LEFT JOIN usuarios u ON u.id=o.assigned_to_user_id
        LEFT JOIN lead_scores ls ON ls.lead_id=o.id
        LEFT JOIN cargos rc ON rc.id=o.cargo_id
        WHERE {$filtro}{$status} ORDER BY o.data DESC, o.id DESC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function timelineTemActorUserId(PDO $pdo): bool
{
    static $disponivel = null;
    if ($disponivel !== null) return $disponivel;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lead_timeline' AND COLUMN_NAME='actor_user_id'");
        return $disponivel = ((int) $stmt->fetchColumn() === 1);
    } catch (Throwable) {
        return $disponivel = false;
    }
}

function registrarTimeline(PDO $pdo, int $leadId, string $tipo, string $descricao, ?array $ator = null): void
{
    $ator = $ator ?? usuarioLogado();
    $contexto = $ator ? resumoTitulosCargos((array) ($ator['cargos'] ?? [])) : '';
    $nomeAtor = $ator ? ($ator['nome'] . ' (' . ($contexto !== 'Sem cargo' ? $contexto : ($ator['role'] ?? 'USUARIO')) . ')') : 'Sistema';
    if (timelineTemActorUserId($pdo)) {
        $stmt = $pdo->prepare('INSERT INTO lead_timeline (lead_id,event_type,description,actor,actor_user_id,created_at) VALUES (?,?,?,?,?,NOW())');
        $stmt->execute([$leadId, mb_substr($tipo, 0, 60), mb_substr($descricao, 0, 4000), mb_substr($nomeAtor, 0, 180), $ator ? (int) $ator['id'] : null]);
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO lead_timeline (lead_id,event_type,description,actor,created_at) VALUES (?,?,?,?,NOW())');
    $stmt->execute([$leadId, mb_substr($tipo, 0, 60), mb_substr($descricao, 0, 4000), mb_substr($nomeAtor, 0, 180)]);
}

function listarTimeline(PDO $pdo, int $leadId): array
{
    $stmt = $pdo->prepare('SELECT * FROM lead_timeline WHERE lead_id=? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$leadId]);
    return $stmt->fetchAll();
}

function salvarLeadScore(PDO $pdo, int $leadId, int $score, string $justificativa): void
{
    $score = max(0, min(100, $score));
    $stmt = $pdo->prepare('INSERT INTO lead_scores (lead_id,score,justification,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE score=VALUES(score), justification=VALUES(justification), updated_at=NOW()');
    $stmt->execute([$leadId, $score, mb_substr($justificativa, 0, 2000)]);
    registrarTimeline($pdo, $leadId, 'lead_score', "Score atualizado para {$score}/100: {$justificativa}", null);
}

function registrarInteracaoIa(PDO $pdo, int $leadId, int $usuarioId, string $consulta, string $status = 'processing'): int
{
    $stmt = $pdo->prepare('INSERT INTO ai_interactions (lead_id,requested_by_user_id,customer_query,status,created_at) VALUES (?,?,?,?,NOW())');
    $stmt->execute([$leadId, $usuarioId, mb_substr($consulta, 0, AI_MAX_PROMPT_LENGTH), $status]);
    return (int) $pdo->lastInsertId();
}

function concluirInteracaoIa(PDO $pdo, int $id, array $resposta): void
{
    $stmt = $pdo->prepare('UPDATE ai_interactions SET route_name=?,response_text=?,lead_score=?,score_justification=?,guardrail_status=?,guardrail_reason=?,escalated=?,status="completed",processed_at=NOW() WHERE id=?');
    $stmt->execute([
        $resposta['route'], $resposta['response'], $resposta['score'], $resposta['score_justification'],
        $resposta['guardrail_status'], $resposta['guardrail_reason'], $resposta['escalated'] ? 1 : 0, $id,
    ]);
}

function falharInteracaoIa(PDO $pdo, int $id, string $erro): void
{
    $stmt = $pdo->prepare('UPDATE ai_interactions SET status="failed",error_message=?,processed_at=NOW() WHERE id=?');
    $stmt->execute([mb_substr($erro, 0, 1000), $id]);
}
