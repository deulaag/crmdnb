<?php
/** Notificações internas para transbordos e eventos críticos. */
require_once __DIR__ . '/rbac.php';

function criarNotificacao(PDO $pdo, int $usuarioId, string $tipo, string $titulo, string $mensagem, string $link): void
{
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id,type,title,message,link,created_at) VALUES (?,?,?,?,?,NOW())');
    $stmt->execute([$usuarioId, mb_substr($tipo, 0, 50), mb_substr($titulo, 0, 160), mb_substr($mensagem, 0, 1000), mb_substr($link, 0, 255)]);
}

function notificarTransbordo(PDO $pdo, int $casoId, int $leadId, string $cliente, ?int $responsavelId): void
{
    $destinatarios = [];
    foreach (idsUsuariosComPapeis($pdo, [PAPEL_ADMINISTRADOR,PAPEL_SUPERVISOR,PAPEL_SUPORTE]) as $usuarioId) {
        $destinatarios[$usuarioId] = true;
    }
    if ($responsavelId) {
        $stmt = $pdo->prepare("SELECT supervisor_id FROM usuarios WHERE id=? AND status='active'");
        $stmt->execute([$responsavelId]);
        $supervisorId = (int) $stmt->fetchColumn();
        if ($supervisorId) $destinatarios[$supervisorId] = true;
    }
    foreach (array_keys($destinatarios) as $usuarioId) {
        criarNotificacao($pdo, $usuarioId, 'ai_handoff', 'Atendimento transbordado pela IA', "O lead #{$leadId} · {$cliente} precisa de atendimento humano.", '/casos.php?caso=' . $casoId);
    }
}

function listarNotificacoes(PDO $pdo, int $usuarioId, int $limite = 8): array
{
    $limite = max(1, min(30, $limite));
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY read_at IS NULL DESC, created_at DESC LIMIT {$limite}");
    $stmt->execute([$usuarioId]);
    return $stmt->fetchAll();
}

function contarNotificacoesNaoLidas(PDO $pdo, int $usuarioId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL');
    $stmt->execute([$usuarioId]);
    return (int) $stmt->fetchColumn();
}
