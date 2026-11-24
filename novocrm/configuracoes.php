<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';

$user = nc_boot();
$isAdmin = nc_is_admin($user);

nc_header('Configurações', 'configuracoes');
nc_page_header('Preferências deste navegador e atalhos administrativos', 'Configurações');

$groups = [
    ['key' => 'theme', 'title' => 'Tema', 'help' => 'Claro, escuro ou o mesmo do seu sistema operacional.',
     'options' => ['light' => 'Claro', 'dark' => 'Escuro', 'system' => 'Seguir sistema']],
    ['key' => 'density', 'title' => 'Densidade das tabelas', 'help' => 'A compacta mostra mais linhas na tela.',
     'options' => ['comfortable' => 'Confortável', 'compact' => 'Compacta']],
    ['key' => 'sidebar', 'title' => 'Menu lateral', 'help' => 'Também pode recolher com a tecla [ em qualquer tela.',
     'options' => ['expanded' => 'Expandido', 'collapsed' => 'Recolhido']],
];
?>
<section class="nc-card nc-section" aria-labelledby="h-aparencia">
    <h2 class="nc-card-title" id="h-aparencia">Aparência</h2>
    <p class="nc-muted">As escolhas ficam salvas neste navegador e valem só para você.</p>
    <div class="nc-prefs">
        <?php foreach ($groups as $g): ?>
            <fieldset class="nc-pref">
                <legend><?= e($g['title']) ?></legend>
                <p class="nc-help"><?= e($g['help']) ?></p>
                <div class="nc-segmented nc-segmented-radio">
                    <?php foreach ($g['options'] as $value => $label): ?>
                        <label class="nc-seg-radio">
                            <input type="radio" name="pref-<?= e($g['key']) ?>" value="<?= e($value) ?>" data-nc-pref="<?= e($g['key']) ?>">
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endforeach; ?>
    </div>
</section>

<section class="nc-card nc-section" aria-labelledby="h-atalhos">
    <h2 class="nc-card-title" id="h-atalhos">Atalhos</h2>
    <ul class="nc-shortcuts">
        <li><a class="nc-shortcut" href="/configuracoes.php"><strong>Configurações do CRM</strong><span class="nc-muted">Integrações (RD Station, e-mail, IA), segurança, marca e políticas de comissão por pessoa. Versão clássica.</span></a></li>
        <?php if ($isAdmin): ?>
            <li><a class="nc-shortcut" href="/usuarios.php"><strong>Usuários</strong><span class="nc-muted">Criar, editar e desativar usuários e papéis. Versão clássica.</span></a></li>
            <li><a class="nc-shortcut" href="<?= e(nc_url('migrations/run.php')) ?>"><strong>Migrações do NOVOCRM</strong><span class="nc-muted">Aplica as alterações aditivas de banco usadas pela nova interface.</span></a></li>
        <?php endif; ?>
        <li><a class="nc-shortcut" href="/cadencia.php"><strong>Cadência · Agente</strong><span class="nc-muted">Sequências e automações comerciais. Versão clássica.</span></a></li>
    </ul>
</section>
<?php
nc_footer();
