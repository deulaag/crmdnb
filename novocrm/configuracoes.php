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
    </ul>
</section>
<?php if ($isAdmin):
    $pdo = db();
    $hasProb = nc_column_exists($pdo, 'funnel_stages', 'probability');
    $stages = $pdo->query('SELECT f.label funnel_label, fs.id, fs.name' . ($hasProb ? ', fs.probability' : ', NULL probability') . '
        FROM funnel_stages fs JOIN funnels f ON f.id = fs.funnel_id WHERE f.active = 1 ORDER BY f.sort_order, fs.sort_order')->fetchAll(); ?>
<section class="nc-card nc-section" aria-labelledby="h-prob">
    <h2 class="nc-card-title" id="h-prob">Probabilidade por etapa</h2>
    <p class="nc-muted">Percentual exibido nos cards do kanban de Oportunidades. Deixe em branco para não exibir.</p>
    <?php if (!$hasProb): ?>
        <div class="nc-alert nc-alert-warning" role="status">A coluna de probabilidade ainda não existe. <a href="<?= e(nc_url('migrations/run.php')) ?>">Aplicar migrações</a>.</div>
    <?php elseif (!$stages): ?>
        <p class="nc-muted">Nenhum funil ativo.</p>
    <?php else: ?>
        <div class="nc-table-wrap nc-flat">
            <table class="nc-table">
                <thead><tr><th>Funil</th><th>Etapa</th><th class="is-num">Probabilidade (%)</th><th><span class="nc-sr-only">Salvar</span></th></tr></thead>
                <tbody>
                <?php foreach ($stages as $st): ?>
                    <tr>
                        <td><?= e((string)$st['funnel_label']) ?></td>
                        <td><?= e((string)$st['name']) ?></td>
                        <td colspan="2">
                            <form class="nc-inline-fields" method="post" hx-post="<?= e(nc_url('acoes/etapa-probabilidade.php')) ?>" hx-swap="none">
                                <input type="hidden" name="stage_id" value="<?= (int)$st['id'] ?>">
                                <label class="nc-sr-only" for="pb-<?= (int)$st['id'] ?>">Probabilidade da etapa <?= e((string)$st['name']) ?></label>
                                <input class="nc-input nc-auto" id="pb-<?= (int)$st['id'] ?>" type="number" name="probability" min="0" max="100" step="1" inputmode="numeric"
                                       value="<?= $st['probability'] !== null ? (int)$st['probability'] : '' ?>" placeholder="—">
                                <button class="nc-btn nc-btn-secondary" type="submit">Salvar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif;

nc_footer();
