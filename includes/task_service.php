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
    } catch (Throwable) {
        return $disponivel = false;
    }
}

function criarTarefaOrcamento(PDO $pdo, int $orcamentoId, int $responsavelId, string $titulo, string $dataLimite, ?int $criadorId = null): int
{
    if (!tarefasDisponiveis($pdo)) throw new RuntimeException('Instale a migração 20260909_crm_tasks.sql para habilitar tarefas.');
    $titulo = trim($titulo);
    if ($titulo === '') $titulo = 'Acompanhar orçamento';
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dataLimite);
    if (!$dt || $dt->format('Y-m-d') !== $dataLimite) throw new InvalidArgumentException('Informe uma data válida para a tarefa.');
    $stmt = $pdo->prepare('INSERT INTO crm_tasks (orcamento_id,assigned_to_user_id,created_by_user_id,title,due_date) VALUES (?,?,?,?,?)');
    $stmt->execute([$orcamentoId, $responsavelId, $criadorId, mb_substr($titulo, 0, 180), $dataLimite]);
    return (int) $pdo->lastInsertId();
}

function listarTarefasPainel(PDO $pdo, ?array $usuario = null, int $limite = 120): array
{
    if (!tarefasDisponiveis($pdo)) return [];
    $usuario = $usuario ?? usuarioLogado();
    [$filtro, $params] = filtroEscopoAtribuicao($pdo, 't.assigned_to_user_id', $usuario);
    $limite = max(10, min(300, $limite));
    $stmt = $pdo->prepare("SELECT t.*,o.numero,o.cliente,o.linha,u.nome responsavel_nome
        FROM crm_tasks t
        JOIN orcamentos o ON o.id=t.orcamento_id
        JOIN usuarios u ON u.id=t.assigned_to_user_id
        WHERE {$filtro} AND t.status='pending'
        ORDER BY t.due_date ASC,t.id ASC LIMIT {$limite}");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function listarTarefasOrcamento(PDO $pdo, int $orcamentoId): array
{
    if (!tarefasDisponiveis($pdo)) return [];
    $stmt = $pdo->prepare("SELECT t.*,u.nome responsavel_nome FROM crm_tasks t JOIN usuarios u ON u.id=t.assigned_to_user_id WHERE t.orcamento_id=? ORDER BY t.status='pending' DESC,t.due_date ASC,t.id DESC");
    $stmt->execute([$orcamentoId]);
    return $stmt->fetchAll();
}

function concluirTarefa(PDO $pdo, int $tarefaId): void
{
    if (!tarefasDisponiveis($pdo)) return;
    if (temPapel(PAPEL_ADMINISTRADOR)) {
        $stmt = $pdo->prepare("UPDATE crm_tasks SET status='done',completed_at=NOW() WHERE id=?");
        $stmt->execute([$tarefaId]);
        return;
    }
    [$filtro, $params] = filtroEscopoAtribuicao($pdo, 'assigned_to_user_id');
    $params[] = $tarefaId;
    $stmt = $pdo->prepare("UPDATE crm_tasks SET status='done',completed_at=NOW() WHERE {$filtro} AND id=?");
    $stmt->execute($params);
}

function configuracaoTarefas(PDO $pdo): array
{
    if (!tarefasDisponiveis($pdo)) return ['reminder_enabled'=>1,'reminder_days_before'=>1,'reminder_template'=>'Lembrete: a tarefa {tarefa} do orçamento {orcamento} para {cliente} vence em {dias} dia(s), em {data}.'];
    $row = $pdo->query('SELECT reminder_enabled,reminder_days_before,reminder_template FROM crm_task_settings WHERE id=1')->fetch();
    return $row ?: ['reminder_enabled'=>1,'reminder_days_before'=>1,'reminder_template'=>'Lembrete: a tarefa {tarefa} do orçamento {orcamento} para {cliente} vence em {dias} dia(s), em {data}.'];
}

function renderizarTemplateTarefa(string $template, array $dados): string
{
    return strtr($template, [
        '{tarefa}' => (string) ($dados['title'] ?? ''),
        '{orcamento}' => (string) ($dados['numero'] ?? ''),
        '{cliente}' => (string) ($dados['cliente'] ?? ''),
        '{dias}' => (string) ($dados['dias'] ?? ''),
        '{data}' => (string) ($dados['due_date'] ?? ''),
        '{vendedor}' => (string) ($dados['responsavel_nome'] ?? ''),
    ]);
}
