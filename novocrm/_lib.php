<?php
declare(strict_types=1);

/**
 * NOVOCRM — helpers compartilhados pelas telas (dados, período, escopo de vendedor, gráficos).
 * Carregado por _app.php. Não altera nada da raiz.
 */

/* ------------------------------------------------------------------ */
/* Banco                                                               */
/* ------------------------------------------------------------------ */

/** A coluna existe? (usado para tolerar migrações ainda não aplicadas). */
function nc_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!isset($cache[$key])) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $q->execute([$table, $column]);
        $cache[$key] = (int)$q->fetchColumn() > 0;
    }
    return $cache[$key];
}

function nc_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $q->execute([$table]);
        $cache[$table] = (int)$q->fetchColumn() > 0;
    }
    return $cache[$table];
}

/** Escapa % e _ para uso em LIKE. */
function nc_like(string $value): string
{
    return '%' . strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
}

function nc_users(PDO $pdo): array
{
    return $pdo->query('SELECT id,name FROM users WHERE active=1 ORDER BY name')->fetchAll();
}

/* ------------------------------------------------------------------ */
/* Permissões / escopo                                                 */
/* ------------------------------------------------------------------ */

function nc_is_admin(array $user): bool
{
    return ($user['role'] ?? '') === 'admin';
}

/** admin e gestor podem escolher o vendedor / editar metas. */
function nc_is_manager(array $user): bool
{
    return in_array($user['role'] ?? '', ['admin', 'gestor'], true);
}

/**
 * Escopo por vendedor: comercial vê só o que é seu; admin/gestor podem filtrar por ?vendedor=ID.
 * @return array{0:?int,1:bool} [id do vendedor filtrado ou null, pode escolher vendedor?]
 */
function nc_seller_scope(array $user): array
{
    if (($user['role'] ?? '') === 'comercial') {
        return [(int)$user['id'], false];
    }
    if (nc_is_manager($user)) {
        $pick = (int)($_GET['vendedor'] ?? 0);
        return [$pick > 0 ? $pick : null, true];
    }
    return [null, false];
}

/* ------------------------------------------------------------------ */
/* Período                                                             */
/* ------------------------------------------------------------------ */

/**
 * Período do painel: ?periodo=mes|30|90 (+ ?mes=YYYY-MM para mês escolhido).
 * @return array{key:string,label:string,from:string,to:string,prev_from:string,prev_to:string,month:string,days:int}
 */
function nc_period(): array
{
    $periodo = (string)($_GET['periodo'] ?? 'mes');
    $today = new DateTimeImmutable('today');

    if ($periodo === '30' || $periodo === '90') {
        $days = (int)$periodo;
        $from = $today->modify('-' . ($days - 1) . ' days');
        $prevTo = $from->modify('-1 day');
        $prevFrom = $prevTo->modify('-' . ($days - 1) . ' days');
        return [
            'key' => $periodo,
            'label' => 'Últimos ' . $days . ' dias',
            'from' => $from->format('Y-m-d'),
            'to' => $today->format('Y-m-d'),
            'prev_from' => $prevFrom->format('Y-m-d'),
            'prev_to' => $prevTo->format('Y-m-d'),
            'month' => current_month(),
            'days' => $days,
        ];
    }

    $month = valid_month($_GET['mes'] ?? null);
    $first = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01') ?: $today->modify('first day of this month');
    $last = $first->modify('last day of this month');
    $prevFirst = $first->modify('-1 month');
    $prevLast = $prevFirst->modify('last day of this month');

    return [
        'key' => $month === current_month() ? 'mes' : 'custom',
        'label' => $month === current_month() ? 'Mês atual' : nc_month_label($month),
        'from' => $first->format('Y-m-d'),
        'to' => $last->format('Y-m-d'),
        'prev_from' => $prevFirst->format('Y-m-d'),
        'prev_to' => $prevLast->format('Y-m-d'),
        'month' => $month,
        'days' => (int)$first->diff($last)->days + 1,
    ];
}

function nc_month_label(string $month): string
{
    static $names = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    [$y, $m] = array_map('intval', explode('-', valid_month($month)));
    return ($names[$m] ?? '') . ' de ' . $y;
}

/* ------------------------------------------------------------------ */
/* Formatação e componentes                                            */
/* ------------------------------------------------------------------ */

function nc_date(?string $date): string
{
    if (!$date || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m)) {
        return '—';
    }
    return $m[3] . '/' . $m[2] . '/' . $m[1];
}

/** Corta o texto em $max caracteres (UTF-8) sem depender de mbstring. */
function nc_truncate(string $text, int $max): string
{
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    if (preg_match_all('/./us', $text, $m) === false || count($m[0]) <= $max) {
        return $text;
    }
    return implode('', array_slice($m[0], 0, $max - 1)) . '…';
}

/** Badge com cor + texto (status nunca só por cor). Retorna HTML seguro. */
function nc_badge(string $text, string $tone = 'neutral'): string
{
    $class = in_array($tone, ['success', 'warning', 'danger', 'info'], true) ? ' nc-badge-' . $tone : '';
    return '<span class="nc-badge' . $class . '">' . e($text) . '</span>';
}

function nc_status_badge(string $status): string
{
    return match ($status) {
        'Ganha' => nc_badge('Ganha', 'success'),
        'Perdida' => nc_badge('Perdida', 'danger'),
        'Aberta' => nc_badge('Aberta', 'info'),
        default => nc_badge($status),
    };
}

function nc_portfolio_badge(string $status): string
{
    return match ($status) {
        'ativo' => nc_badge('Ativo', 'success'),
        'risco' => nc_badge('Em risco', 'warning'),
        default => nc_badge('Inativo'),
    };
}

function nc_source_label(string $source): string
{
    return match ($source) {
        'rd_crm' => 'RD Station CRM',
        'rd_marketing' => 'RD Marketing',
        'cnpja' => 'CNPJá',
        default => 'Manual',
    };
}

/** URL da própria página com parâmetros alterados (remove vazios). */
function nc_query_url(array $extra, ?string $path = null): string
{
    $query = array_merge($_GET, $extra);
    $query = array_filter($query, static fn($v) => $v !== null && $v !== '' && $v !== 0);
    $path = $path ?? (parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    return $path . ($query ? '?' . http_build_query($query) : '');
}

/** Paginação simples (anterior/próxima). */
function nc_pagination(int $page, int $pages): void
{
    if ($pages <= 1) {
        return;
    }
    ?>
    <nav class="nc-pagination" aria-label="Paginação">
        <?php if ($page > 1): ?><a class="nc-btn nc-btn-secondary" href="<?= e(nc_query_url(['p' => $page - 1])) ?>">← Anterior</a><?php endif; ?>
        <span>Página <?= $page ?> de <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="nc-btn nc-btn-secondary" href="<?= e(nc_query_url(['p' => $page + 1])) ?>">Próxima →</a><?php endif; ?>
    </nav>
    <?php
}

/* ------------------------------------------------------------------ */
/* Respostas HTMX                                                      */
/* ------------------------------------------------------------------ */

/** Recarrega a página inteira no cliente (a mensagem vai por flash()). */
function nc_hx_redirect(string $url): never
{
    header('HX-Redirect: ' . $url);
    exit;
}

/** Erro em texto puro: o JS mostra no modal de erro. */
function nc_fail(string $message, int $code = 400): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

/** Exige admin em endpoints de ação (resposta 403 em texto). */
function nc_require_admin_action(array $user): void
{
    if (!nc_is_admin($user)) {
        nc_fail('Ação não permitida.', 403);
    }
}

/* ------------------------------------------------------------------ */
/* Gráficos SVG (gerados no servidor)                                  */
/* ------------------------------------------------------------------ */

/**
 * Gráfico de linhas simples: período atual × anterior.
 * @param string[] $labels   rótulos do eixo X
 * @param array<int,array{name:string,class:string,values:float[]}> $series
 */
function nc_svg_line(array $labels, array $series, string $ariaLabel): string
{
    $w = 720; $h = 240; $padL = 64; $padR = 16; $padT = 16; $padB = 32;
    $n = max(1, count($labels));
    $max = 0.0;
    foreach ($series as $s) {
        foreach ($s['values'] as $v) {
            $max = max($max, (float)$v);
        }
    }
    $max = $max > 0 ? $max : 1.0;
    $plotW = $w - $padL - $padR;
    $plotH = $h - $padT - $padB;
    $x = static fn(int $i): float => $padL + ($n > 1 ? $plotW * $i / ($n - 1) : $plotW / 2);
    $y = static fn(float $v): float => $padT + $plotH - ($plotH * $v / $max);

    $svg = '<svg class="nc-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($ariaLabel) . '" preserveAspectRatio="xMidYMid meet">';
    for ($g = 0; $g <= 4; $g++) {
        $val = $max * $g / 4;
        $gy = $y($val);
        $svg .= '<line class="nc-chart-grid" x1="' . $padL . '" x2="' . ($w - $padR) . '" y1="' . round($gy, 1) . '" y2="' . round($gy, 1) . '"/>';
        $svg .= '<text class="nc-chart-axis" x="' . ($padL - 8) . '" y="' . round($gy + 4, 1) . '" text-anchor="end">' . e(nc_money_short($val)) . '</text>';
    }
    foreach ($labels as $i => $label) {
        $svg .= '<text class="nc-chart-axis" x="' . round($x((int)$i), 1) . '" y="' . ($h - 8) . '" text-anchor="middle">' . e((string)$label) . '</text>';
    }
    foreach ($series as $s) {
        $points = [];
        foreach (array_values($s['values']) as $i => $v) {
            $points[] = round($x($i), 1) . ',' . round($y((float)$v), 1);
        }
        $svg .= '<polyline class="nc-chart-line ' . e($s['class']) . '" fill="none" points="' . implode(' ', $points) . '"/>';
        foreach (array_values($s['values']) as $i => $v) {
            $svg .= '<circle class="nc-chart-dot ' . e($s['class']) . '" cx="' . round($x($i), 1) . '" cy="' . round($y((float)$v), 1) . '" r="3.5"><title>'
                . e($s['name'] . ' · ' . ($labels[$i] ?? '') . ': ' . money($v)) . '</title></circle>';
        }
    }
    return $svg . '</svg>';
}

/** Tabela equivalente do gráfico, só para leitores de tela. */
function nc_chart_table(array $labels, array $series, string $caption): string
{
    $html = '<table class="nc-sr-only"><caption>' . e($caption) . '</caption><thead><tr><th>Semana</th>';
    foreach ($series as $s) {
        $html .= '<th>' . e($s['name']) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($labels as $i => $label) {
        $html .= '<tr><td>' . e((string)$label) . '</td>';
        foreach ($series as $s) {
            $html .= '<td>' . e(money($s['values'][$i] ?? 0)) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table>';
}
