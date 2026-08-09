<?php
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
exigirPapel(PAPEL_ADMINISTRADOR);

$pdo = db();
$mes = $_GET['mes'] ?? mesAtual();

function usuariosRedirect(string $mensagem): void
{
    $_SESSION['flash'] = $mensagem;
    header('Location: /usuarios.php');
    exit;
}

function validarDadosUsuario(PDO $pdo, array $dados, ?int $id = null): array
{
    $nome = trim((string) ($dados['nome'] ?? ''));
    $email = mb_strtolower(trim((string) ($dados['email'] ?? '')));
    $role = strtoupper((string) ($dados['role'] ?? PAPEL_VENDEDOR));
    $status = ($dados['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $supervisorId = $role === PAPEL_VENDEDOR && !empty($dados['supervisor_id']) ? (int) $dados['supervisor_id'] : null;
    $vendedorId = !empty($dados['vendedor_id']) ? (string) $dados['vendedor_id'] : null;
    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Informe nome e e-mail válido.');
    if (!in_array($role, PAPEIS_CRM, true)) throw new InvalidArgumentException('Papel de acesso inválido.');
    if ($supervisorId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id=? AND role='SUPERVISOR' AND status='active'");
        $stmt->execute([$supervisorId]);
        if (!(int) $stmt->fetchColumn()) throw new InvalidArgumentException('Selecione um supervisor ativo.');
    }
    if ($vendedorId) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE vendedor_id=? AND id<>?');
        $stmt->execute([$vendedorId, $id ?? 0]);
        if ((int) $stmt->fetchColumn() > 0) throw new InvalidArgumentException('Esta pessoa comercial já está vinculada a outro usuário.');
    }
    return compact('nome', 'email', 'role', 'status', 'supervisorId', 'vendedorId');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'criar') {
            $d = validarDadosUsuario($pdo, $_POST);
            $senha = (string) ($_POST['senha'] ?? '');
            if (strlen($senha) < 8) throw new InvalidArgumentException('A senha deve ter no mínimo 8 caracteres.');
            $stmt = $pdo->prepare('INSERT INTO usuarios (nome,email,senha_hash,role,supervisor_id,vendedor_id,status,ativo,criado_em,atualizado_em) VALUES (?,?,?,?,?,?,?, ?,NOW(),NOW())');
            $stmt->execute([$d['nome'], $d['email'], password_hash($senha, PASSWORD_DEFAULT), $d['role'], $d['supervisorId'], $d['vendedorId'], $d['status'], $d['status'] === 'active' ? 1 : 0]);
            usuariosRedirect('Usuário criado com sucesso.');
        }

        if ($acao === 'salvar') {
            $id = (int) ($_POST['id'] ?? 0);
            $d = validarDadosUsuario($pdo, $_POST, $id);
            if ($id === (int) usuarioLogado()['id'] && ($d['status'] !== 'active' || $d['role'] !== PAPEL_ADMINISTRADOR)) {
                throw new InvalidArgumentException('Você não pode desativar nem remover seu próprio papel de Administrador.');
            }
            $senha = (string) ($_POST['senha'] ?? '');
            if ($senha !== '' && strlen($senha) < 8) throw new InvalidArgumentException('A nova senha deve ter no mínimo 8 caracteres.');
            $sqlSenha = $senha !== '' ? ', senha_hash=?' : '';
            $params = [$d['nome'], $d['email'], $d['role'], $d['supervisorId'], $d['vendedorId'], $d['status'], $d['status'] === 'active' ? 1 : 0];
            if ($senha !== '') $params[] = password_hash($senha, PASSWORD_DEFAULT);
            $params[] = $id;
            $pdo->prepare("UPDATE usuarios SET nome=?,email=?,role=?,supervisor_id=?,vendedor_id=?,status=?,ativo=?,atualizado_em=NOW(){$sqlSenha} WHERE id=?")->execute($params);
            if ($d['role'] !== PAPEL_SUPERVISOR || $d['status'] !== 'active') {
                $pdo->prepare('UPDATE usuarios SET supervisor_id=NULL WHERE supervisor_id=?')->execute([$id]);
            }
            usuariosRedirect('Usuário atualizado.');
        }

        if ($acao === 'desativar') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) usuarioLogado()['id']) throw new InvalidArgumentException('Você não pode desativar a própria conta.');
            $stmt = $pdo->prepare("SELECT role FROM usuarios WHERE id=?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() === PAPEL_ADMINISTRADOR) {
                $ativos = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE role='ADMINISTRADOR' AND status='active'")->fetchColumn();
                if ($ativos <= 1) throw new InvalidArgumentException('O CRM precisa manter ao menos um Administrador ativo.');
            }
            $pdo->prepare("UPDATE usuarios SET status='inactive',ativo=0,atualizado_em=NOW() WHERE id=?")->execute([$id]);
            $pdo->prepare('UPDATE usuarios SET supervisor_id=NULL WHERE supervisor_id=?')->execute([$id]);
            usuariosRedirect('Usuário desativado. O histórico foi preservado.');
        }
    } catch (Throwable $e) {
        usuariosRedirect($e instanceof InvalidArgumentException ? $e->getMessage() : 'Não foi possível salvar. Verifique se o e-mail já está cadastrado.');
    }
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$usuarios = $pdo->query("SELECT u.*, s.nome supervisor_nome, v.nome vendedor_nome FROM usuarios u LEFT JOIN usuarios s ON s.id=u.supervisor_id LEFT JOIN vendedores v ON v.id=u.vendedor_id ORDER BY FIELD(u.status,'active','inactive'), u.nome")->fetchAll();
$supervisores = $pdo->query("SELECT id,nome FROM usuarios WHERE role='SUPERVISOR' AND status='active' ORDER BY nome")->fetchAll();
$vendedores = $pdo->query("SELECT id,nome,funcao FROM vendedores WHERE ativo=1 ORDER BY nome")->fetchAll();
$editando = null;
if (!empty($_GET['editar'])) {
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id=?');
    $stmt->execute([(int) $_GET['editar']]);
    $editando = $stmt->fetch() ?: null;
}

$tabAtual = 'usr';
include __DIR__ . '/includes/layout_header.php';
?>

<?php if ($flash): ?><div class="dnb-alert dnb-alert--success" role="status"><?= e($flash) ?></div><?php endif; ?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Estrutura de acesso e supervisão</span></div>
  <div class="p-4">
    <p class="text-sm text-slate-600 mb-4">Crie as contas do CRM, vincule vendedores à equipe de um supervisor e associe cada login à pessoa comercial correspondente.</p>
    <div class="overflow-x-auto">
      <table class="w-full text-sm min-w-[900px]">
        <thead><tr><th>Usuário</th><th>Papel</th><th>Supervisor</th><th>Pessoa comercial</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($usuarios as $u): ?>
          <tr>
            <td><strong><?= e($u['nome']) ?></strong><div class="text-xs text-slate-500"><?= e($u['email']) ?></div></td>
            <td><?= tag($u['role'], $u['role'] === PAPEL_ADMINISTRADOR ? 'blue' : 'slate') ?></td>
            <td><?= e($u['supervisor_nome'] ?: '—') ?></td>
            <td><?= e($u['vendedor_nome'] ?: '—') ?></td>
            <td><?= tag($u['status'] === 'active' ? 'Ativo' : 'Inativo', $u['status'] === 'active' ? 'green' : 'red') ?></td>
            <td class="text-right whitespace-nowrap">
              <a href="/usuarios.php?editar=<?= (int) $u['id'] ?>" class="<?= BTN_S ?> inline-block">Editar</a>
              <?php if ((int) $u['id'] !== (int) usuarioLogado()['id'] && $u['status'] === 'active'): ?>
              <form method="post" class="inline" onsubmit="return confirm('Desativar este usuário? O histórico será mantido.');"><?= csrfInput() ?><input type="hidden" name="acao" value="desativar"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="<?= BTN_S ?> dnb-button--danger">Desativar</button></form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;"><?= $editando ? 'Editar usuário' : 'Novo usuário' ?></span></div>
  <div class="p-4">
    <form method="post" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
      <?= csrfInput() ?><input type="hidden" name="acao" value="<?= $editando ? 'salvar' : 'criar' ?>"><?php if ($editando): ?><input type="hidden" name="id" value="<?= (int) $editando['id'] ?>"><?php endif; ?>
      <div><label class="block text-xs font-semibold mb-1">Nome</label><input name="nome" required class="<?= INPUT_CLS ?>" value="<?= e($editando['nome'] ?? '') ?>"></div>
      <div><label class="block text-xs font-semibold mb-1">E-mail</label><input type="email" name="email" required class="<?= INPUT_CLS ?>" value="<?= e($editando['email'] ?? '') ?>"></div>
      <div><label class="block text-xs font-semibold mb-1"><?= $editando ? 'Nova senha (opcional)' : 'Senha (mín. 8)' ?></label><input type="password" name="senha" <?= $editando ? '' : 'required' ?> minlength="8" class="<?= INPUT_CLS ?>"></div>
      <div><label class="block text-xs font-semibold mb-1">Papel</label><select name="role" class="<?= INPUT_CLS ?>"><?php foreach (PAPEIS_CRM as $papel): ?><option value="<?= e($papel) ?>" <?= ($editando['role'] ?? PAPEL_VENDEDOR) === $papel ? 'selected' : '' ?>><?= e($papel) ?></option><?php endforeach; ?></select></div>
      <div><label class="block text-xs font-semibold mb-1">Supervisor direto (para Vendedor)</label><select name="supervisor_id" class="<?= INPUT_CLS ?>"><option value="">— sem supervisor —</option><?php foreach ($supervisores as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) ($editando['supervisor_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option><?php endforeach; ?></select></div>
      <div><label class="block text-xs font-semibold mb-1">Pessoa comercial vinculada</label><select name="vendedor_id" class="<?= INPUT_CLS ?>"><option value="">— sem vínculo —</option><?php foreach ($vendedores as $v): ?><option value="<?= e($v['id']) ?>" <?= ($editando['vendedor_id'] ?? '') === $v['id'] ? 'selected' : '' ?>><?= e($v['nome']) ?> · <?= e($v['funcao']) ?></option><?php endforeach; ?></select></div>
      <div><label class="block text-xs font-semibold mb-1">Status</label><select name="status" class="<?= INPUT_CLS ?>"><option value="active" <?= ($editando['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Ativo</option><option value="inactive" <?= ($editando['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inativo</option></select></div>
      <div class="flex gap-2"><button class="<?= BTN_P ?>" type="submit"><?= $editando ? 'Salvar usuário' : 'Criar usuário' ?></button><?php if ($editando): ?><a href="/usuarios.php" class="<?= BTN_S ?> inline-flex items-center">Cancelar</a><?php endif; ?></div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
