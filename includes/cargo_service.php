<?php
declare(strict_types=1);

/** Gestão centralizada de cargos, grupos de acesso e elegibilidade de comissão. */

const CARGO_ADMINISTRADOR = 'ADMINISTRADOR';
const CARGO_SUPERVISOR = 'SUPERVISOR';
const CARGO_REPRESENTANTE = 'Representante Comercial Autônomo';
const CARGO_VENDEDOR_INTERNO_SENIOR = 'Vendedor Interno Sênior';
const CARGO_VENDEDOR_EXTERNO = 'Vendedor Externo';
const CARGO_VENDEDOR_JR = 'Vendedor JR';
const CARGO_CONSULTOR_SENIOR = 'Consultor Comercial Sênior';
const CARGO_SUPORTE_VENDAS_JR = 'Suporte em Vendas JR';

const TITULOS_CARGOS_SISTEMA = [
    CARGO_ADMINISTRADOR,
    CARGO_SUPERVISOR,
    CARGO_REPRESENTANTE,
    CARGO_VENDEDOR_INTERNO_SENIOR,
    CARGO_VENDEDOR_EXTERNO,
    CARGO_VENDEDOR_JR,
    CARGO_CONSULTOR_SENIOR,
    CARGO_SUPORTE_VENDAS_JR,
];

const TITULOS_CARGOS_COMERCIAIS = [
    CARGO_REPRESENTANTE,
    CARGO_VENDEDOR_INTERNO_SENIOR,
    CARGO_VENDEDOR_EXTERNO,
    CARGO_VENDEDOR_JR,
    CARGO_CONSULTOR_SENIOR,
];

/** @param string[] $titulos @return string[] */
function papeisPorTitulosCargos(array $titulos): array
{
    $papeis = [];
    if (in_array(CARGO_ADMINISTRADOR, $titulos, true)) $papeis[] = 'ADMINISTRADOR';
    if (in_array(CARGO_SUPERVISOR, $titulos, true)) $papeis[] = 'SUPERVISOR';
    if (array_intersect(TITULOS_CARGOS_COMERCIAIS, $titulos)) $papeis[] = 'VENDEDOR';
    if (in_array(CARGO_SUPORTE_VENDAS_JR, $titulos, true)) $papeis[] = 'SUPORTE';
    return array_values(array_unique($papeis));
}

/** @param string[] $papeis */
function papelPrincipalDosPapeis(array $papeis): string
{
    foreach (['ADMINISTRADOR', 'SUPERVISOR', 'SUPORTE', 'VENDEDOR'] as $papel) {
        if (in_array($papel, $papeis, true)) return $papel;
    }
    return 'VENDEDOR';
}

/** @param array<int,array<string,mixed>> $cargos */
function resumoTitulosCargos(array $cargos): string
{
    $titulos = array_values(array_filter(array_map(static fn(array $cargo): string => trim((string) ($cargo['title'] ?? '')), $cargos)));
    return $titulos ? implode(', ', $titulos) : 'Sem cargo';
}

/** @return array<int,array<string,mixed>> */
function listarCargos(PDO $pdo): array
{
    return $pdo->query('SELECT id,title,has_commission,created_at FROM cargos ORDER BY id')->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function cargosDoUsuario(PDO $pdo, int $usuarioId): array
{
    $stmt = $pdo->prepare('SELECT c.id,c.title,c.has_commission,c.created_at FROM cargos c JOIN user_cargos uc ON uc.cargo_id=c.id WHERE uc.user_id=? ORDER BY c.id');
    $stmt->execute([$usuarioId]);
    return $stmt->fetchAll();
}

/** @param array<string,mixed> $usuario @return array<string,mixed> */
function carregarContextoCargosUsuario(PDO $pdo, array $usuario): array
{
    $cargos = cargosDoUsuario($pdo, (int) $usuario['id']);
    $titulos = array_column($cargos, 'title');
    $papeis = papeisPorTitulosCargos($titulos);
    if (!$papeis) {
        $legado = strtoupper((string) ($usuario['role'] ?? 'VENDEDOR'));
        $papeis = in_array($legado, ['ADMINISTRADOR','SUPERVISOR','VENDEDOR','SUPORTE'], true) ? [$legado] : ['VENDEDOR'];
    }
    $pessoaId = $usuario['pessoa_id'] ?? $usuario['vendedor_id'] ?? null;
    $usuario['pessoa_id'] = $pessoaId ?: null;
    $usuario['vendedor_id'] = $pessoaId ?: ($usuario['vendedor_id'] ?? null);
    $usuario['cargos'] = $cargos;
    $usuario['cargo_ids'] = array_map('intval', array_column($cargos, 'id'));
    $usuario['cargo_titles'] = $titulos;
    $usuario['papeis'] = $papeis;
    $usuario['role'] = papelPrincipalDosPapeis($papeis);
    return $usuario;
}

/** @return array<string,mixed>|null */
function buscarUsuarioComContextoCargos(PDO $pdo, int $usuarioId, bool $somenteAtivo = true): ?array
{
    $sql = 'SELECT * FROM usuarios WHERE id=?';
    if ($somenteAtivo) $sql .= " AND status='active'";
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql); $stmt->execute([$usuarioId]);
    $usuario = $stmt->fetch();
    return $usuario ? carregarContextoCargosUsuario($pdo, $usuario) : null;
}

/** @param array<string,mixed> $usuario */
function usuarioContextoTemPapel(array $usuario, string $papel): bool
{
    $papeis = $usuario['papeis'] ?? [strtoupper((string) ($usuario['role'] ?? 'VENDEDOR'))];
    return in_array(strtoupper($papel), array_map('strtoupper', $papeis), true);
}

/** @param int[] $cargoIds */
function sincronizarCargosUsuario(PDO $pdo, int $usuarioId, array $cargoIds): void
{
    $cargoIds = array_values(array_unique(array_filter(array_map('intval', $cargoIds), static fn(int $id): bool => $id > 0)));
    if (!$cargoIds) throw new InvalidArgumentException('Selecione ao menos um cargo.');
    $marks = implode(',', array_fill(0, count($cargoIds), '?'));
    $stmt = $pdo->prepare("SELECT id,title FROM cargos WHERE id IN ({$marks})");
    $stmt->execute($cargoIds);
    $cargos = $stmt->fetchAll();
    if (count($cargos) !== count($cargoIds)) throw new InvalidArgumentException('Um dos cargos selecionados é inválido.');
    $pdo->prepare('DELETE FROM user_cargos WHERE user_id=?')->execute([$usuarioId]);
    $stmt = $pdo->prepare('INSERT INTO user_cargos (user_id,cargo_id,created_at) VALUES (?,?,NOW())');
    foreach ($cargoIds as $cargoId) $stmt->execute([$usuarioId, $cargoId]);
    $papel = papelPrincipalDosPapeis(papeisPorTitulosCargos(array_column($cargos, 'title')));
    $pdo->prepare('UPDATE usuarios SET role=?,atualizado_em=NOW() WHERE id=?')->execute([$papel, $usuarioId]);
}

function usuarioPossuiCargo(PDO $pdo, int $usuarioId, string $titulo): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_cargos uc JOIN cargos c ON c.id=uc.cargo_id WHERE uc.user_id=? AND c.title=?');
    $stmt->execute([$usuarioId, $titulo]);
    return (int) $stmt->fetchColumn() > 0;
}

/** @return int[] */
function idsUsuariosComPapeis(PDO $pdo, array $papeis, bool $somenteAtivos = true): array
{
    $titulos = [];
    foreach (array_map('strtoupper', $papeis) as $papel) {
        if ($papel === 'ADMINISTRADOR') $titulos[] = CARGO_ADMINISTRADOR;
        elseif ($papel === 'SUPERVISOR') $titulos[] = CARGO_SUPERVISOR;
        elseif ($papel === 'SUPORTE') $titulos[] = CARGO_SUPORTE_VENDAS_JR;
        elseif ($papel === 'VENDEDOR') $titulos = array_merge($titulos, TITULOS_CARGOS_COMERCIAIS);
    }
    $titulos = array_values(array_unique($titulos));
    if (!$titulos) return [];
    $marks = implode(',', array_fill(0, count($titulos), '?'));
    $sql = "SELECT DISTINCT u.id FROM usuarios u JOIN user_cargos uc ON uc.user_id=u.id JOIN cargos c ON c.id=uc.cargo_id WHERE c.title IN ({$marks})";
    if ($somenteAtivos) $sql .= " AND u.status='active'";
    $stmt = $pdo->prepare($sql . ' ORDER BY u.id');
    $stmt->execute($titulos);
    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}

/** @return array<int,array<string,mixed>> */
function listarUsuariosComerciais(PDO $pdo, ?array $idsPermitidos = null): array
{
    $params = TITULOS_CARGOS_COMERCIAIS;
    $marksCargo = implode(',', array_fill(0, count($params), '?'));
    $sql = "SELECT u.id,u.nome,u.email,u.pessoa_id,u.vendedor_id,GROUP_CONCAT(DISTINCT c.title ORDER BY c.id SEPARATOR ', ') cargos
        FROM usuarios u JOIN user_cargos uc ON uc.user_id=u.id JOIN cargos c ON c.id=uc.cargo_id
        WHERE u.status='active' AND c.title IN ({$marksCargo})";
    if ($idsPermitidos !== null) {
        $idsPermitidos = array_values(array_unique(array_map('intval', $idsPermitidos)));
        if (!$idsPermitidos) return [];
        $sql .= ' AND u.id IN (' . implode(',', array_fill(0, count($idsPermitidos), '?')) . ')';
        $params = array_merge($params, $idsPermitidos);
    }
    $stmt = $pdo->prepare($sql . ' GROUP BY u.id,u.nome,u.email,u.pessoa_id,u.vendedor_id ORDER BY u.nome');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function cargosComissionaveisDoUsuario(PDO $pdo, int $usuarioId): array
{
    if (usuarioPossuiCargo($pdo, $usuarioId, CARGO_ADMINISTRADOR)) return [];
    $stmt = $pdo->prepare('SELECT c.id,c.title,c.has_commission FROM cargos c JOIN user_cargos uc ON uc.cargo_id=c.id WHERE uc.user_id=? AND c.has_commission=1 ORDER BY c.id');
    $stmt->execute([$usuarioId]);
    return $stmt->fetchAll();
}

function cargoParaRegistroComercial(PDO $pdo, ?int $usuarioId, ?int $cargoSolicitado = null): ?int
{
    if (!$usuarioId) return null;
    if (usuarioPossuiCargo($pdo, $usuarioId, CARGO_ADMINISTRADOR)) {
        $stmt = $pdo->prepare('SELECT c.id FROM cargos c JOIN user_cargos uc ON uc.cargo_id=c.id WHERE uc.user_id=? AND c.title=? LIMIT 1');
        $stmt->execute([$usuarioId, CARGO_ADMINISTRADOR]);
        return ($id = (int) $stmt->fetchColumn()) > 0 ? $id : null;
    }
    $cargos = cargosComissionaveisDoUsuario($pdo, $usuarioId);
    if ($cargoSolicitado && in_array($cargoSolicitado, array_map('intval', array_column($cargos, 'id')), true)) return $cargoSolicitado;
    return $cargos ? (int) $cargos[0]['id'] : null;
}

/** @param array<string,mixed> $registro */
function registroComissionavelPorCargo(array $registro): bool
{
    $titulo = trim((string) ($registro['cargo_title'] ?? ''));
    return $titulo !== '' && (int) ($registro['cargo_has_commission'] ?? 0) === 1
        && strtoupper($titulo) !== CARGO_ADMINISTRADOR;
}

/** @param array<int,array<string,mixed>> $pessoas @return array<int,array<string,mixed>> */
function filtrarPessoasElegiveisComissao(PDO $pdo, array $pessoas): array
{
    $stmt = $pdo->query("SELECT DISTINCT COALESCE(u.pessoa_id,u.vendedor_id) pessoa_id
        FROM usuarios u JOIN user_cargos uc ON uc.user_id=u.id JOIN cargos c ON c.id=uc.cargo_id
        WHERE u.status='active' AND c.has_commission=1
          AND NOT EXISTS (SELECT 1 FROM user_cargos uca JOIN cargos ca ON ca.id=uca.cargo_id WHERE uca.user_id=u.id AND ca.title='ADMINISTRADOR')");
    $permitidas = array_flip(array_filter(array_column($stmt->fetchAll(), 'pessoa_id')));
    return array_values(array_filter($pessoas, static fn(array $pessoa): bool => isset($permitidas[$pessoa['id']])));
}

function gerarPessoaId(PDO $pdo): string
{
    do {
        $id = 'PES-' . strtoupper(bin2hex(random_bytes(6)));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM vendedores WHERE id=?');
        $stmt->execute([$id]);
    } while ((int) $stmt->fetchColumn() > 0);
    return $id;
}
