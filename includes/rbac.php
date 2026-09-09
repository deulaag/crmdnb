<?php
/**
 * Controle de acesso hierárquico do DNB CRM.
 * O ADMINISTRADOR enxerga tudo; o SUPERVISOR enxerga sua equipe; o VENDEDOR,
 * somente registros atribuídos a ele; o SUPORTE atua nos casos transbordados.
 */
require_once __DIR__ . '/auth.php';

const PAPEL_ADMINISTRADOR = 'ADMINISTRADOR';
const PAPEL_SUPERVISOR = 'SUPERVISOR';
const PAPEL_VENDEDOR = 'VENDEDOR';
const PAPEL_SUPORTE = 'SUPORTE';
const PAPEIS_CRM = [PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_VENDEDOR, PAPEL_SUPORTE];

/** @return string[] */
function papeisAtuais(?array $usuario = null): array
{
    $usuario = $usuario ?? usuarioLogado();
    if (!$usuario) return [];
    $papeis = $usuario['papeis'] ?? [strtoupper((string) ($usuario['role'] ?? PAPEL_VENDEDOR))];
    return array_values(array_intersect(PAPEIS_CRM, array_values(array_unique(array_map('strtoupper', $papeis)))));
}

function papelAtual(): string
{
    return papelPrincipalDosPapeis(papeisAtuais());
}

function temPapel(string ...$papeis): bool
{
    return (bool) array_intersect(papeisAtuais(), array_map('strtoupper', $papeis));
}

/** Vendedor operacional puro: não acumula papel administrativo ou de supervisão. */
function ehOperadorVendedor(?array $usuario = null): bool
{
    $papeis = papeisAtuais($usuario);
    return in_array(PAPEL_VENDEDOR, $papeis, true)
        && !in_array(PAPEL_ADMINISTRADOR, $papeis, true)
        && !in_array(PAPEL_SUPERVISOR, $papeis, true);
}

function negarAcessoModulo(): void
{
    http_response_code(403);
    require_once __DIR__ . '/helpers.php';
    $titulo = 'Acesso não autorizado';
    $mensagem = 'Seu perfil não possui permissão para acessar este módulo.';
    include __DIR__ . '/forbidden.php';
    exit;
}

/**
 * Regras sensíveis que não podem depender apenas de esconder botões na UI.
 * São avaliadas em toda página que chama exigirPapel().
 */
function aplicarRestricoesAdministrativasDaRequisicao(): void
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));

    // Auditoria de fluxos é exclusiva do administrador.
    if ($script === 'auditoria.php' && !temPapel(PAPEL_ADMINISTRADOR)) {
        negarAcessoModulo();
    }

    // Importações e sincronizações em massa alteram dados de vários vendedores.
    if ($script === 'orcamentos.php' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $acao = (string) ($_POST['acao'] ?? '');
        if (in_array($acao, ['importar_json', 'importar_rdstation_csv'], true) && !temPapel(PAPEL_ADMINISTRADOR)) {
            negarAcessoModulo();
        }
    }
}

function exigirPapel(string ...$papeis): void
{
    exigirLogin();
    aplicarRestricoesAdministrativasDaRequisicao();
    if (!temPapel(...$papeis)) negarAcessoModulo();
}

function idsUsuariosDaEquipe(PDO $pdo, ?array $usuario = null): array
{
    $usuario = $usuario ?? usuarioLogado();
    if (!$usuario) return [];
    $papeis = papeisAtuais($usuario);
    if (in_array(PAPEL_ADMINISTRADOR, $papeis, true)) return [];
    if (in_array(PAPEL_SUPERVISOR, $papeis, true)) {
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE status='active' AND (id=? OR supervisor_id=?)");
        $stmt->execute([(int) $usuario['id'], (int) $usuario['id']]);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }
    return [(int) $usuario['id']];
}

/** Retorna SQL e parâmetros para uma coluna assigned_to_user_id. */
function filtroEscopoAtribuicao(PDO $pdo, string $coluna = 'assigned_to_user_id', ?array $usuario = null): array
{
    $usuario = $usuario ?? usuarioLogado();
    if (!$usuario || in_array(PAPEL_ADMINISTRADOR, papeisAtuais($usuario), true)) {
        return ['1=1', []];
    }
    $ids = idsUsuariosDaEquipe($pdo, $usuario);
    if (!$ids) return ['1=0', []];
    return [$coluna . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

function idsVendedoresDaEquipe(PDO $pdo, ?array $usuario = null): array
{
    $usuario = $usuario ?? usuarioLogado();
    if (!$usuario) return [];
    if (in_array(PAPEL_ADMINISTRADOR, papeisAtuais($usuario), true)) {
        return array_column($pdo->query("SELECT id FROM vendedores WHERE ativo=1")->fetchAll(), 'id');
    }
    $ids = idsUsuariosDaEquipe($pdo, $usuario);
    if (!$ids) return [];
    $stmt = $pdo->prepare('SELECT COALESCE(pessoa_id,vendedor_id) vendedor_id FROM usuarios WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND COALESCE(pessoa_id,vendedor_id) IS NOT NULL');
    $stmt->execute($ids);
    return array_values(array_filter(array_column($stmt->fetchAll(), 'vendedor_id')));
}

function filtrarVendedoresDaEquipe(PDO $pdo, array $vendedores, ?array $usuario = null): array
{
    $usuario = $usuario ?? usuarioLogado();
    if (!$usuario || in_array(PAPEL_ADMINISTRADOR, papeisAtuais($usuario), true)) return $vendedores;
    $permitidos = array_flip(idsVendedoresDaEquipe($pdo, $usuario));
    return array_values(array_filter($vendedores, fn(array $v): bool => isset($permitidos[$v['id']])));
}

function usuarioIdPorVendedor(PDO $pdo, ?string $vendedorId): ?int
{
    if (!$vendedorId) return null;
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE (pessoa_id=? OR vendedor_id=?) AND status='active' ORDER BY pessoa_id IS NOT NULL DESC,id LIMIT 1");
    $stmt->execute([$vendedorId, $vendedorId]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

function podeAcessarRegistroAtribuido(PDO $pdo, string $tabela, int $id): bool
{
    if (!in_array($tabela, ['orcamentos','pedidos','visitas'], true)) return false;
    if (temPapel(PAPEL_ADMINISTRADOR)) return true;
    [$filtro, $params] = filtroEscopoAtribuicao($pdo, 'assigned_to_user_id');
    $params[] = $id;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$tabela} WHERE {$filtro} AND id=?");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function podeAcessarLead(PDO $pdo, int $leadId, ?array $usuario = null): bool
{
    $usuario = $usuario ?? usuarioLogado();
    if (!$usuario) return false;
    $papeis = papeisAtuais($usuario);
    if (in_array(PAPEL_ADMINISTRADOR, $papeis, true)) return true;

    [$filtro, $params] = filtroEscopoAtribuicao($pdo, 'assigned_to_user_id', $usuario);
    $params[] = $leadId;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orcamentos WHERE {$filtro} AND id=?");
    $stmt->execute($params);
    if ((int) $stmt->fetchColumn() > 0) return true;

    if (in_array(PAPEL_SUPORTE, $papeis, true)) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM support_cases WHERE lead_id=?');
        $stmt->execute([$leadId]);
        return (int) $stmt->fetchColumn() > 0;
    }
    return false;
}

function podeReatribuirLead(): bool
{
    return temPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR);
}

function podeGerenciarCasos(): bool
{
    return temPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPORTE);
}

/**
 * Regra pura usada também pelos testes do Live Chat.
 *
 * @param int[] $idsEquipeSupervisor
 */
function papelPodeVisualizarConversaWhatsapp(
    string $papel,
    int $usuarioId,
    ?int $responsavelLeadId,
    array $idsEquipeSupervisor = [],
    bool $suportePossuiCasoAberto = false,
    bool $leadAtivo = true
): bool {
    $papel = strtoupper($papel);
    if ($papel === PAPEL_ADMINISTRADOR) return true;
    if (!$responsavelLeadId) return false;
    if ($papel === PAPEL_SUPERVISOR) return in_array($responsavelLeadId, array_map('intval', $idsEquipeSupervisor), true);
    if ($papel === PAPEL_VENDEDOR) return $leadAtivo && $responsavelLeadId === $usuarioId;
    if ($papel === PAPEL_SUPORTE) return $suportePossuiCasoAberto;
    return false;
}

/**
 * Regra multi-cargo: basta um dos grupos permitir a conversa.
 * @param string[] $papeis @param int[] $idsEquipeSupervisor
 */
function papeisPodemVisualizarConversaWhatsapp(
    array $papeis,
    int $usuarioId,
    ?int $responsavelLeadId,
    array $idsEquipeSupervisor = [],
    bool $suportePossuiCasoAberto = false,
    bool $leadAtivo = true
): bool {
    foreach ($papeis as $papel) {
        if (papelPodeVisualizarConversaWhatsapp((string) $papel, $usuarioId, $responsavelLeadId, $idsEquipeSupervisor, $suportePossuiCasoAberto, $leadAtivo)) return true;
    }
    return false;
}

/** Retorna SQL e parâmetros para o escopo de uma conversa WhatsApp. */
function filtroEscopoWhatsapp(PDO $pdo, string $alias = 'wc', ?array $usuario = null): array
{
    $usuario = $usuario ?? usuarioLogado();
    if (!$usuario) return ['1=0', []];
    $papeis = papeisAtuais($usuario);
    if (in_array(PAPEL_ADMINISTRADOR, $papeis, true)) return ['1=1', []];
    $condicoes = [];
    $params = [];
    if (in_array(PAPEL_SUPERVISOR, $papeis, true)) {
        $ids = idsUsuariosDaEquipe($pdo, $usuario);
        if ($ids) {
            $condicoes[] = "EXISTS (SELECT 1 FROM orcamentos dnb_wo WHERE dnb_wo.id={$alias}.lead_id AND dnb_wo.assigned_to_user_id IN (" . implode(',', array_fill(0, count($ids), '?')) . '))';
            $params = array_merge($params, $ids);
        }
    }
    if (in_array(PAPEL_VENDEDOR, $papeis, true)) {
        $condicoes[] = "EXISTS (SELECT 1 FROM orcamentos dnb_wo WHERE dnb_wo.id={$alias}.lead_id AND dnb_wo.assigned_to_user_id=? AND dnb_wo.status='Aberta')";
        $params[] = (int) $usuario['id'];
    }
    if (in_array(PAPEL_SUPORTE, $papeis, true)) {
        $condicoes[] = "EXISTS (SELECT 1 FROM support_cases dnb_sc WHERE dnb_sc.lead_id={$alias}.lead_id AND dnb_sc.status IN ('open','in_progress'))";
    }
    return $condicoes ? ['(' . implode(' OR ', $condicoes) . ')', $params] : ['1=0', []];
}

function podeAcessarConversaWhatsapp(PDO $pdo, int $conversationId, ?array $usuario = null): bool
{
    [$filtro, $params] = filtroEscopoWhatsapp($pdo, 'wc', $usuario);
    $params[] = $conversationId;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_conversations wc WHERE {$filtro} AND wc.id=?");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function paginaInicialPorPapel(): string
{
    return temPapel(PAPEL_SUPORTE) && !temPapel(PAPEL_ADMINISTRADOR, PAPEL_SUPERVISOR, PAPEL_VENDEDOR) ? '/casos.php' : '/painel.php';
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrfInput(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function exigirCsrf(): void
{
    $recebido = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($recebido === '' || !hash_equals(csrfToken(), $recebido)) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'erro' => 'Sessão expirada. Recarregue a página e tente novamente.']);
        exit;
    }
}
