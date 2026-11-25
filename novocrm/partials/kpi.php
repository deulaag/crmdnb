<?php
declare(strict_types=1);

/**
 * Card de KPI com ícone colorido no canto (nc-kpi + nc-kpi-icon).
 *
 * @param string $tone      cor do quadradinho do ícone: info | success | warning | danger | neutral
 * @param string $valueTone cor do valor: '' | success | warning | danger
 */
function nc_kpi(string $label, string $value, string $support, string $icon, string $tone = 'neutral', string $valueTone = ''): void
{
    $tone = in_array($tone, ['info', 'success', 'warning', 'danger', 'neutral'], true) ? $tone : 'neutral';
    $valueClass = in_array($valueTone, ['success', 'warning', 'danger'], true) ? ' is-' . $valueTone : '';
    ?>
    <article class="nc-kpi">
        <div class="nc-kpi-head">
            <div class="nc-kpi-label"><?= e($label) ?></div>
            <span class="nc-kpi-icon is-<?= e($tone) ?>"><?= nc_icon($icon) ?></span>
        </div>
        <div class="nc-kpi-value<?= $valueClass ?>"><?= e($value) ?></div>
        <div class="nc-kpi-sub"><?= e($support) ?></div>
    </article>
    <?php
}
