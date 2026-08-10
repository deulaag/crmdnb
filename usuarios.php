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

/** @param string[] $titulos */
function politicaLegadaPorCargos(array $titulos, string $atual = 'sem_politica'): string
{
    if (in_array(CARGO_REPRESENTANTE, $titulos, true)) return 'representante';
    if (in_array(CARGO_VENDEDOR_INTERNO_SENIOR, $titulos, true)) return 'interno';
    if (in_array(CARGO_VENDEDOR_EXTERNO, $titulos, true)) return 'vendedor_externo';
    if (in_array(CARGO_CONSULTOR_SENIOR, $titulos, true)) return 'consultor_senior';
    if (in_array(CARGO_VENDEDOR_JR, $titulos, true)) return $atual === 'sem_politica' ? 'vendedor_ir' : $atual;
    if (in_array(CARGO_SUPORTE_VENDAS_JR, $titulos, true)) return $atual === 'sem_politica' ? 'suporte_ir' : $atual;
    return $atual;
}

function quantidadeAdministradoresAtivos(PDO $pdo, int $ignorarId = 0): int
{
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM usuarios u JOIN user_cargos uc ON uc.user_id=u.id JOIN cargos c ON c.id=uc.cargo_id WHERE u.status='active' AND c.title=? AND u.id<>?");
    $stmt->execute([CARGO_ADMINISTRADOR, $ignorarId]);
    return (int) $stmt->fetchColumn();
}

/** @return array<string,mixed> */
function validarDadosUsuario(PDO $pdo, array $dados, ?int $id = null): array
{
    $nome = trim((string) ($dados['nome'] ?? ''));
    $email = mb_strtolower(trim((string) ($dados['email'] ?? '')));
    $fone = trim((string) ($dados['fone'] ?? ''));
    $status = ($dados['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $cargoIds = array_values(array_unique(array_filter(array_map('intval', (array) ($dados['cargo_ids'] ?? [])))));
    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Informe nome e e-mail válido.');
    if (!$cargoIds) throw new InvalidArgumentException('Selecione ao menos um cargo.');
    $marks = implode(',', array_fill(0, count($cargoIds), '?'));
    $stmt = $pdo->prepare("SELECT id,title FROM cargos WHERE id IN ({$marks})");
    $stmt->execute($cargoIds); $cargos = $stmt->fetchAll();
    if (count($cargos) !== count($cargoIds)) throw new InvalidArgumentException('Cargo inválido.');
    $titulos = array_column($cargos, 'title');
    $papeis = papeisPorTitulosCargos($titulos);
    $supervisorId = !empty($dados['supervisor_id']) ? (int) $dados['supervisor_id'] : null;
    if ($supervisorId) {
        if ($supervisorId === (int) $id) throw new InvalidArgumentException('O usuário não pode supervisionar a si próprio.');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u JOIN user_cargos uc ON uc.user_id=u.id JOIN cargos c ON c.id=uc.cargo_id WHERE u.id=? AND u.status='active' AND c.title=?");
        $stmt->execute([$supervisorId, CARGO_SUPERVISOR]);
        if (!(int) $stmt->fetchColumn()) throw new InvalidArgumentException('Selecione um supervisor ativo.');
    }
    $pessoaId = trim((string) ($dados['pessoa_id'] ?? '')) ?: null;
    if ($pessoaId) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM vendedores WHERE id=?'); $stmt->execute([$pessoaId]);
        if (!(int) $stmt->fetchColumn()) throw new InvalidArgumentException('Pessoa vinculada não encontrada.');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE pessoa_id=? AND id<>?'); $stmt->execute([$pessoaId, $id ?? 0]);
        if ((int) $stmt->fetchColumn()) throw new InvalidArgumentException('Esta pessoa já possui credencial vinculada.');
    }
    return compact('nome','email','fone','status','cargoIds','titulos','papeis','supervisorId','pessoaId');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'criar' || $acao === 'salvar') {
            $id = $acao === 'salvar' ? (int) ($_POST['id'] ?? 0) : null;
            $d = validarDadosUsuario($pdo, $_POST, $id);
            $senha = (string) ($_POST['senha'] ?? '');
            if ($acao === 'criar' && strlen($senha) < 8) throw new InvalidArgumentException('A senha deve ter no mínimo 8 caracteres.');
            if ($senha !== '' && strlen($senha) < 8) throw new InvalidArgumentException('A nova senha deve ter no mínimo 8 caracteres.');
            if ($id && $d['status'] === 'active' && $senha === '') {
                $stmt=$pdo->prepare('SELECT senha_hash FROM usuarios WHERE id=?'); $stmt->execute([$id]);
                if (str_starts_with((string)$stmt->fetchColumn(),'!migrated-')) throw new InvalidArgumentException('Defina uma senha antes de ativar a credencial importada.');
            }
            $temAdmin = in_array(CARGO_ADMINISTRADOR, $d['titulos'], true);
            if ($id === (int) usuarioLogado()['id'] && ($d['status'] !== 'active' || !$temAdmin)) {
                throw new InvalidArgumentException('Você não pode desativar nem remover seu próprio cargo de Administrador.');
            }
            if ($id && ($d['status'] !== 'active' || !$temAdmin) && usuarioPossuiCargo($pdo, $id, CARGO_ADMINISTRADOR) && quantidadeAdministradoresAtivos($pdo, $id) < 1) {
                throw new InvalidArgumentException('O CRM precisa manter ao menos um Administrador ativo.');
            }

            $pdo->beginTransaction();
            $pessoaId = $d['pessoaId'];
            $politicaAtual = 'sem_politica';
            if ($pessoaId) {
                $stmt = $pdo->prepare('SELECT politica FROM vendedores WHERE id=? FOR UPDATE'); $stmt->execute([$pessoaId]);
                $politicaAtual = (string) ($stmt->fetchColumn() ?: 'sem_politica');
            } else {
                $pessoaId = gerarPessoaId($pdo);
                $pdo->prepare("INSERT INTO vendedores (id,nome,email,fone,funcao,politica,pol,ativo,criado_em) VALUES (?,?,?,?,?,'sem_politica','{}',1,NOW())")
                    ->execute([$pessoaId,$d['nome'],$d['email'],$d['fone'],'vendedor']);
            }
            $possuiComercial = (bool) array_intersect(TITULOS_CARGOS_COMERCIAIS, $d['titulos']);
            $funcao = in_array(CARGO_SUPORTE_VENDAS_JR, $d['titulos'], true) && !$possuiComercial ? 'suporte' : 'vendedor';
            $politica = politicaLegadaPorCargos($d['titulos'], $politicaAtual);
            $pdo->prepare('UPDATE vendedores SET nome=?,email=?,fone=?,funcao=?,politica=?,ativo=? WHERE id=?')
                ->execute([$d['nome'],$d['email'],$d['fone'],$funcao,$politica,$d['status']==='active'?1:0,$pessoaId]);

            $papelLegado = papelPrincipalDosPapeis($d['papeis']);
            if ($acao === 'criar') {
                $stmt = $pdo->prepare('INSERT INTO usuarios (nome,email,senha_hash,role,supervisor_id,vendedor_id,pessoa_id,status,ativo,criado_em,atualizado_em) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())');
                $stmt->execute([$d['nome'],$d['email'],password_hash($senha,PASSWORD_DEFAULT),$papelLegado,$d['supervisorId'],$pessoaId,$pessoaId,$d['status'],$d['status']==='active'?1:0]);
                $id = (int) $pdo->lastInsertId();
            } else {
                $params = [$d['nome'],$d['email'],$papelLegado,$d['supervisorId'],$pessoaId,$pessoaId,$d['status'],$d['status']==='active'?1:0];
                $sqlSenha = '';
                if ($senha !== '') { $sqlSenha = ',senha_hash=?'; $params[] = password_hash($senha,PASSWORD_DEFAULT); }
                $params[] = $id;
                $pdo->prepare("UPDATE usuarios SET nome=?,email=?,role=?,supervisor_id=?,vendedor_id=?,pessoa_id=?,status=?,ativo=?,atualizado_em=NOW(){$sqlSenha} WHERE id=?")->execute($params);
            }
            sincronizarCargosUsuario($pdo, (int) $id, $d['cargoIds']);
            if (!in_array(CARGO_SUPERVISOR, $d['titulos'], true) || $d['status'] !== 'active') {
                $pdo->prepare('UPDATE usuarios SET supervisor_id=NULL WHERE supervisor_id=?')->execute([(int) $id]);
            }
            $pdo->commit();
            usuariosRedirect($acao === 'criar' ? 'Pessoa e usuário criados com sucesso.' : 'Pessoa, cargos e credenciais atualizados.');
        }

        if ($acao === 'desativar') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) usuarioLogado()['id']) throw new InvalidArgumentException('Você não pode desativar a própria conta.');
            if (usuarioPossuiCargo($pdo,$id,CARGO_ADMINISTRADOR) && quantidadeAdministradoresAtivos($pdo,$id)<1) {
                throw new InvalidArgumentException('O CRM precisa manter ao menos um Administrador ativo.');
            }
            $pdo->beginTransaction();
            $stmt=$pdo->prepare('SELECT pessoa_id FROM usuarios WHERE id=?'); $stmt->execute([$id]); $pessoaId=$stmt->fetchColumn();
            $pdo->prepare("UPDATE usuarios SET status='inactive',ativo=0,atualizado_em=NOW() WHERE id=?")->execute([$id]);
            if ($pessoaId) $pdo->prepare('UPDATE vendedores SET ativo=0 WHERE id=?')->execute([$pessoaId]);
            $pdo->prepare('UPDATE usuarios SET supervisor_id=NULL WHERE supervisor_id=?')->execute([$id]);
            $pdo->commit();
            usuariosRedirect('Credencial desativada. O histórico foi preservado.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        usuariosRedirect($e instanceof InvalidArgumentException ? $e->getMessage() : 'Não foi possível salvar. Verifique e-mail, pessoa e cargos.');
    }
}

$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$cargos = listarCargos($pdo);
$usuarios = $pdo->query("SELECT u.*,v.fone,v.nome pessoa_nome,s.nome supervisor_nome,GROUP_CONCAT(DISTINCT c.title ORDER BY c.id SEPARATOR ', ') cargos
    FROM usuarios u LEFT JOIN vendedores v ON v.id=u.pessoa_id LEFT JOIN usuarios s ON s.id=u.supervisor_id
    LEFT JOIN user_cargos uc ON uc.user_id=u.id LEFT JOIN cargos c ON c.id=uc.cargo_id
    GROUP BY u.id,v.fone,v.nome,s.nome ORDER BY FIELD(u.status,'active','inactive'),u.nome")->fetchAll();
$supervisores = $pdo->query("SELECT DISTINCT u.id,u.nome FROM usuarios u JOIN user_cargos uc ON uc.user_id=u.id JOIN cargos c ON c.id=uc.cargo_id WHERE u.status='active' AND c.title='SUPERVISOR' ORDER BY u.nome")->fetchAll();
$pessoasLivres = $pdo->query('SELECT v.id,v.nome,v.email,v.fone FROM vendedores v LEFT JOIN usuarios u ON u.pessoa_id=v.id WHERE u.id IS NULL ORDER BY v.nome')->fetchAll();
$editando = null; $cargosEditando = [];
if (!empty($_GET['editar'])) {
    $stmt=$pdo->prepare('SELECT u.*,v.fone FROM usuarios u LEFT JOIN vendedores v ON v.id=u.pessoa_id WHERE u.id=?'); $stmt->execute([(int)$_GET['editar']]); $editando=$stmt->fetch()?:null;
    if ($editando) $cargosEditando=array_map('intval',array_column(cargosDoUsuario($pdo,(int)$editando['id']),'id'));
}

$tabAtual = 'usr';
include __DIR__ . '/includes/layout_header.php';
?>
<?php if ($flash): ?><div class="dnb-alert dnb-alert--success" role="status"><?= e($flash) ?></div><?php endif; ?>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4 flex-wrap gap-2"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;">Pessoas, cargos e acesso</span></div>
  <div class="p-4"><p class="text-sm text-slate-600 mb-4">Cada pessoa possui uma credencial e pode acumular cargos. O cargo Administrador mantém acesso total e nunca gera comissão.</p>
    <div class="overflow-x-auto"><table class="w-full text-sm min-w-[980px]"><thead><tr><th>Pessoa / usuário</th><th>Cargos</th><th>Supervisor</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach($usuarios as $u): ?><tr><td><strong><?= e($u['pessoa_nome']?:$u['nome']) ?></strong><div class="text-xs text-slate-500"><?= e($u['email']) ?><?= $u['fone']?' · '.e($u['fone']):'' ?></div></td><td class="max-w-[360px]"><?= e($u['cargos']?:'Sem cargo') ?></td><td><?= e($u['supervisor_nome']?:'—') ?></td><td><?= tag($u['status']==='active'?'Ativo':'Inativo',$u['status']==='active'?'green':'red') ?></td><td class="text-right whitespace-nowrap"><a href="/usuarios.php?editar=<?= (int)$u['id'] ?>" class="<?= BTN_S ?> inline-block">Editar</a><?php if((int)$u['id']!==(int)usuarioLogado()['id']&&$u['status']==='active'): ?><form method="post" class="inline" onsubmit="return confirm('Desativar esta credencial? O histórico será mantido.');"><?= csrfInput() ?><input type="hidden" name="acao" value="desativar"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="<?= BTN_S ?> dnb-button--danger">Desativar</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-4">
  <div class="flex items-center justify-between px-4 pt-4"><span class="text-white text-xs font-bold uppercase tracking-wider px-3 py-1.5 rounded-md" style="background:<?= NAVY ?>;"><?= $editando?'Editar pessoa e usuário':'Nova pessoa e usuário' ?></span></div>
  <div class="p-4"><form method="post" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 items-end"><?= csrfInput() ?><input type="hidden" name="acao" value="<?= $editando?'salvar':'criar' ?>"><?php if($editando): ?><input type="hidden" name="id" value="<?= (int)$editando['id'] ?>"><input type="hidden" name="pessoa_id" value="<?= e($editando['pessoa_id']??'') ?>"><?php else: ?><div><label class="block text-xs font-semibold mb-1">Pessoa existente (opcional)</label><select name="pessoa_id" class="<?= INPUT_CLS ?>"><option value="">Criar nova pessoa</option><?php foreach($pessoasLivres as $p): ?><option value="<?= e($p['id']) ?>"><?= e($p['nome']) ?> · <?= e($p['email']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div><label class="block text-xs font-semibold mb-1">Nome</label><input name="nome" required class="<?= INPUT_CLS ?>" value="<?= e($editando['nome']??'') ?>"></div>
    <div><label class="block text-xs font-semibold mb-1">E-mail</label><input type="email" name="email" required class="<?= INPUT_CLS ?>" value="<?= e($editando['email']??'') ?>"></div>
    <div><label class="block text-xs font-semibold mb-1">Telefone</label><input name="fone" class="<?= INPUT_CLS ?>" value="<?= e($editando['fone']??'') ?>"></div>
    <div><label class="block text-xs font-semibold mb-1"><?= $editando?'Nova senha (opcional)':'Senha (mín. 8)' ?></label><input type="password" name="senha" <?= $editando?'':'required' ?> minlength="8" class="<?= INPUT_CLS ?>"></div>
    <div><label class="block text-xs font-semibold mb-1">Supervisor direto</label><select name="supervisor_id" class="<?= INPUT_CLS ?>"><option value="">— sem supervisor —</option><?php foreach($supervisores as $s): if((int)($editando['id']??0)===(int)$s['id'])continue; ?><option value="<?= (int)$s['id'] ?>" <?= (int)($editando['supervisor_id']??0)===(int)$s['id']?'selected':'' ?>><?= e($s['nome']) ?></option><?php endforeach; ?></select></div>
    <div><label class="block text-xs font-semibold mb-1">Status da credencial</label><select name="status" class="<?= INPUT_CLS ?>"><option value="active" <?= ($editando['status']??'active')==='active'?'selected':'' ?>>Ativo</option><option value="inactive" <?= ($editando['status']??'')==='inactive'?'selected':'' ?>>Inativo</option></select></div>
    <fieldset class="md:col-span-2 lg:col-span-4"><legend class="block text-xs font-semibold mb-2">Cargos simultâneos</legend><div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-2"><?php foreach($cargos as $cargo): ?><label class="flex gap-2 items-start border border-slate-200 rounded-lg p-2 text-sm"><input type="checkbox" name="cargo_ids[]" value="<?= (int)$cargo['id'] ?>" <?= in_array((int)$cargo['id'],$cargosEditando,true)?'checked':'' ?>><span><b><?= e($cargo['title']) ?></b><small class="block text-slate-500"><?= $cargo['has_commission']?'Comissionável':'Sem comissão' ?></small></span></label><?php endforeach; ?></div></fieldset>
    <div class="flex gap-2"><button class="<?= BTN_P ?>" type="submit"><?= $editando?'Salvar alterações':'Criar pessoa e acesso' ?></button><?php if($editando): ?><a href="/usuarios.php" class="<?= BTN_S ?> inline-flex items-center">Cancelar</a><?php endif; ?></div>
  </form></div>
</div>
<?php include __DIR__ . '/includes/layout_footer.php'; ?>
