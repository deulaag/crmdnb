<?php
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/helpers.php';
exigirLogin();

$pdo = db();
$usuario = usuarioLogado();
$usuarioId = (int) $usuario['id'];
$mes = $_GET['mes'] ?? mesAtual();

function perfilTabelasDisponiveis(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('user_profiles','user_mood_log')");
        return (int) $stmt->fetchColumn() === 2;
    } catch (Throwable) {
        return false;
    }
}

function perfilRedirect(string $mensagem): never
{
    $_SESSION['flash_perfil'] = $mensagem;
    header('Location: /perfil.php');
    exit;
}

$perfilDisponivel = perfilTabelasDisponiveis($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    exigirCsrf();
    $acao = (string) ($_POST['acao'] ?? '');

    try {
        if ($acao === 'senha') {
            $atual = (string) ($_POST['senha_atual'] ?? '');
            $nova = (string) ($_POST['nova_senha'] ?? '');
            $confirmacao = (string) ($_POST['confirmar_senha'] ?? '');
            $stmt = $pdo->prepare('SELECT senha_hash FROM usuarios WHERE id=? LIMIT 1');
            $stmt->execute([$usuarioId]);
            $hash = (string) $stmt->fetchColumn();
            if (!password_verify($atual, $hash)) throw new InvalidArgumentException('A senha atual não confere.');
            if (strlen($nova) < 8) throw new InvalidArgumentException('A nova senha deve ter no mínimo 8 caracteres.');
            if ($nova !== $confirmacao) throw new InvalidArgumentException('A confirmação da nova senha não confere.');
            $pdo->prepare('UPDATE usuarios SET senha_hash=?,atualizado_em=NOW() WHERE id=?')->execute([password_hash($nova, PASSWORD_DEFAULT), $usuarioId]);
            perfilRedirect('Senha atualizada com sucesso.');
        }

        if (!$perfilDisponivel) throw new RuntimeException('Instale a migração 20260908_perfil_humor.sql para habilitar foto e sentimento.');

        if ($acao === 'humor') {
            $humor = (string) ($_POST['humor'] ?? '');
            if (!in_array($humor, ['tristeza','alegria','loucura'], true)) throw new InvalidArgumentException('Selecione uma reação válida.');
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO user_profiles (user_id,mood) VALUES (?,?) ON DUPLICATE KEY UPDATE mood=VALUES(mood),updated_at=NOW()')->execute([$usuarioId, $humor]);
            $pdo->prepare('INSERT INTO user_mood_log (user_id,mood,mood_date) VALUES (?,?,CURDATE()) ON DUPLICATE KEY UPDATE mood=VALUES(mood),created_at=NOW()')->execute([$usuarioId, $humor]);
            $pdo->commit();
            perfilRedirect('Seu sentimento de hoje foi registrado.');
        }

        if ($acao === 'avatar') {
            if (empty($_FILES['avatar']['tmp_name']) || !is_uploaded_file($_FILES['avatar']['tmp_name'])) throw new InvalidArgumentException('Selecione uma foto para enviar.');
            if ((int) ($_FILES['avatar']['size'] ?? 0) > 3 * 1024 * 1024) throw new InvalidArgumentException('A foto deve ter no máximo 3 MB.');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($_FILES['avatar']['tmp_name']);
            $extensoes = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if (!isset($extensoes[$mime])) throw new InvalidArgumentException('Use uma foto JPG, PNG ou WEBP.');

            $dir = __DIR__ . '/uploads/avatars';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Não foi possível preparar o diretório de fotos.');
            $arquivo = 'u' . $usuarioId . '-' . bin2hex(random_bytes(12)) . '.' . $extensoes[$mime];
            $destino = $dir . '/' . $arquivo;
            if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $destino)) throw new RuntimeException('Não foi possível salvar a foto enviada.');
            @chmod($destino, 0644);

            $stmt = $pdo->prepare('SELECT avatar_path FROM user_profiles WHERE user_id=?');
            $stmt->execute([$usuarioId]);
            $anterior = (string) ($stmt->fetchColumn() ?: '');
            $novoPath = '/uploads/avatars/' . $arquivo;
            $pdo->prepare('INSERT INTO user_profiles (user_id,avatar_path) VALUES (?,?) ON DUPLICATE KEY UPDATE avatar_path=VALUES(avatar_path),updated_at=NOW()')->execute([$usuarioId, $novoPath]);
            if (str_starts_with($anterior, '/uploads/avatars/')) {
                $antigoArquivo = basename($anterior);
                $antigoPath = $dir . '/' . $antigoArquivo;
                if (is_file($antigoPath) && $antigoPath !== $destino) @unlink($antigoPath);
            }
            perfilRedirect('Foto do perfil atualizada.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        perfilRedirect($e->getMessage());
    }
}

$msg = (string) ($_SESSION['flash_perfil'] ?? '');
unset($_SESSION['flash_perfil']);
$perfil = ['avatar_path'=>'','mood'=>''];
if ($perfilDisponivel) {
    $stmt = $pdo->prepare('SELECT avatar_path,mood FROM user_profiles WHERE user_id=?');
    $stmt->execute([$usuarioId]);
    $perfil = $stmt->fetch() ?: $perfil;
}

$tabAtual = 'perfil';
include __DIR__ . '/includes/layout_header.php';
?>

<?php if ($msg): ?><div class="dnb-alert dnb-alert--success"><?= e($msg) ?></div><?php endif; ?>
<?php if (!$perfilDisponivel): ?><div class="dnb-alert dnb-alert--warning">Foto e sentimento serão habilitados após instalar <code>sql/migrations/20260908_perfil_humor.sql</code>. A troca de senha já está disponível.</div><?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
    <span class="dnb-eyebrow">Meu perfil</span>
    <h2 class="dnb-section-title">Foto e identidade</h2>
    <div class="flex items-center gap-4 my-5">
      <?php if (!empty($perfil['avatar_path'])): ?>
        <img src="<?= e($perfil['avatar_path']) ?>" alt="Foto de <?= e($usuario['nome']) ?>" class="dnb-profile-avatar">
      <?php else: ?>
        <div class="dnb-profile-avatar dnb-profile-avatar--fallback"><?= e(mb_strtoupper(mb_substr(trim($usuario['nome']),0,1))) ?></div>
      <?php endif; ?>
      <div><strong class="block text-lg" style="color:<?= NAVY ?>;"><?= e($usuario['nome']) ?></strong><span class="text-sm text-slate-500"><?= e($usuario['email']) ?></span><small class="block text-slate-400 mt-1"><?= e(resumoTitulosCargos((array)($usuario['cargos']??[]))) ?></small></div>
    </div>
    <form method="post" enctype="multipart/form-data" class="space-y-3">
      <?= csrfInput() ?><input type="hidden" name="acao" value="avatar">
      <label class="block"><span class="block text-xs font-semibold text-slate-600 mb-1">Nova foto</span><input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" class="<?= INPUT_CLS ?>" <?= $perfilDisponivel?'':'disabled' ?>></label>
      <p class="text-xs text-slate-400">JPG, PNG ou WEBP, até 3 MB.</p>
      <button class="<?= BTN_P ?>" <?= $perfilDisponivel?'':'disabled' ?>>Atualizar foto</button>
    </form>
  </section>

  <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
    <span class="dnb-eyebrow">Bem-estar</span>
    <h2 class="dnb-section-title">Qual seu sentimento hoje?</h2>
    <p class="text-sm text-slate-500 mt-1">Escolha a reação que mais representa seu dia.</p>
    <form method="post" class="dnb-mood-picker mt-5">
      <?= csrfInput() ?><input type="hidden" name="acao" value="humor">
      <?php foreach ([['tristeza','😢','Tristeza'],['alegria','😄','Alegria'],['loucura','🤪','Loucura']] as [$valor,$emoji,$rotulo]): ?>
        <button type="submit" name="humor" value="<?= e($valor) ?>" class="dnb-mood-option<?= ($perfil['mood']??'')===$valor?' is-selected':'' ?>" <?= $perfilDisponivel?'':'disabled' ?>><span><?= $emoji ?></span><strong><?= e($rotulo) ?></strong></button>
      <?php endforeach; ?>
    </form>
  </section>

  <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 lg:col-span-2">
    <span class="dnb-eyebrow">Segurança</span>
    <h2 class="dnb-section-title">Alterar senha</h2>
    <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4">
      <?= csrfInput() ?><input type="hidden" name="acao" value="senha">
      <label><span class="block text-xs font-semibold text-slate-600 mb-1">Senha atual</span><input type="password" name="senha_atual" autocomplete="current-password" required class="<?= INPUT_CLS ?>"></label>
      <label><span class="block text-xs font-semibold text-slate-600 mb-1">Nova senha</span><input type="password" name="nova_senha" minlength="8" autocomplete="new-password" required class="<?= INPUT_CLS ?>"></label>
      <label><span class="block text-xs font-semibold text-slate-600 mb-1">Confirmar nova senha</span><input type="password" name="confirmar_senha" minlength="8" autocomplete="new-password" required class="<?= INPUT_CLS ?>"></label>
      <div class="md:col-span-3"><button class="<?= BTN_P ?>">Salvar nova senha</button></div>
    </form>
  </section>
</div>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
