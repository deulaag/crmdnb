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
/* Metas de faturamento (tabela sales_targets, migração 004)           */
/* ------------------------------------------------------------------ */

/**
 * Metas de um ano: ['annual' => ?float, 'months' => [1..12 => ?float]].
 *
 * Resolução (V2, seção 7):
 *  - vendedor: linha (user, ano, mês); anual = linha (user, ano, NULL) senão soma dos meses cadastrados.
 *  - equipe ($userId = null): mês = linha (NULL, ano, mês) senão soma das metas dos vendedores ativos;
 *    anual = linha (NULL, ano, NULL) senão soma dos 12 meses resolvidos.
 * Sem nada cadastrado devolve null no campo (nunca 0), para a tela distinguir "sem meta" de "meta zero".
 */
function nc_targets_year(PDO $pdo, ?int $userId, int $year): array
{
    $result = ['annual' => null, 'months' => array_fill(1, 12, null)];
    if (!nc_table_exists($pdo, 'sales_targets')) {
        return $result;
    }

    $q = $pdo->prepare('SELECT user_id, `month`, amount FROM sales_targets WHERE `year` = ?');
    $q->execute([$year]);
    $rows = $q->fetchAll();

    $own = [];       // linhas do escopo pedido (vendedor ou equipe)
    $sellers = [];   // mês => soma das metas dos vendedores ativos (usado só para a equipe)
    $active = [];
    if ($userId === null) {
        foreach ($pdo->query('SELECT id FROM users WHERE active=1')->fetchAll() as $u) {
            $active[(int)$u['id']] = true;
        }
    }
    foreach ($rows as $r) {
        $uid = $r['user_id'] !== null ? (int)$r['user_id'] : null;
        $mon = $r['month'] !== null ? (int)$r['month'] : null;
        if ($uid === $userId) {
            $own[$mon ?? 0] = (float)$r['amount'];
        } elseif ($userId === null && $uid !== null && $mon !== null && isset($active[$uid])) {
            $sellers[$mon] = ($sellers[$mon] ?? 0.0) + (float)$r['amount'];
        }
    }

    foreach (range(1, 12) as $m) {
        if (array_key_exists($m, $own)) {
            $result['months'][$m] = $own[$m];
        } elseif ($userId === null && array_key_exists($m, $sellers)) {
            $result['months'][$m] = $sellers[$m];
        }
    }

    if (array_key_exists(0, $own)) {
        $result['annual'] = $own[0];
    } else {
        $known = array_filter($result['months'], static fn($v) => $v !== null);
        $result['annual'] = $known ? array_sum($known) : null;
    }
    return $result;
}

/** Meta de um mês ($month 1..12) ou do ano ($month = null). null = sem meta cadastrada. */
function nc_target(PDO $pdo, ?int $userId, int $year, ?int $month): ?float
{
    $t = nc_targets_year($pdo, $userId, $year);
    return $month === null ? $t['annual'] : ($t['months'][$month] ?? null);
}

/**
 * Grava (ou remove, se $amount = null) uma meta. Upsert em código: UNIQUE com NULL não protege
 * contra duplicidade no MySQL. $userId = null grava a meta da equipe toda.
 */
function nc_target_save(PDO $pdo, ?int $userId, int $year, ?int $month, ?float $amount, int $byUserId): void
{
    if ($year < 2000 || $year > 2100 || ($month !== null && ($month < 1 || $month > 12))) {
        throw new RuntimeException('Período da meta inválido.');
    }
    if ($amount !== null && $amount < 0) {
        throw new RuntimeException('A meta não pode ser negativa.');
    }
    if (!nc_table_exists($pdo, 'sales_targets')) {
        throw new RuntimeException('Aplique as migrações do NOVOCRM para definir metas.');
    }

    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT id FROM sales_targets WHERE user_id <=> ? AND `year` = ? AND `month` <=> ? ORDER BY id LIMIT 1 FOR UPDATE');
        $q->execute([$userId, $year, $month]);
        $id = $q->fetchColumn();

        if ($amount === null) {
            if ($id) {
                $pdo->prepare('DELETE FROM sales_targets WHERE user_id <=> ? AND `year` = ? AND `month` <=> ?')->execute([$userId, $year, $month]);
            }
        } elseif ($id) {
            $pdo->prepare('UPDATE sales_targets SET amount = ?, updated_by = ? WHERE id = ?')->execute([$amount, $byUserId, (int)$id]);
        } else {
            $pdo->prepare('INSERT INTO sales_targets (user_id, `year`, `month`, amount, updated_by) VALUES (?,?,?,?,?)')
                ->execute([$userId, $year, $month, $amount, $byUserId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    audit($pdo, $byUserId, 'update_target', 'sales_target', $userId, [
        'year' => $year, 'month' => $month, 'amount' => $amount,
    ]);
}

/** Converte "1.234,56" ou "1234.56" em float; '' vira null. */
function nc_parse_amount(string $raw): ?float
{
    $raw = trim(str_replace(['R$', ' '], '', $raw));
    if ($raw === '') {
        return null;
    }
    if (str_contains($raw, ',')) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    }
    if (!is_numeric($raw)) {
        throw new RuntimeException('Valor inválido: ' . $raw);
    }
    return round((float)$raw, 2);
}

/** Cor fixa de avatar derivada do id (paleta com contraste AA sobre texto branco). */
function nc_avatar_color(int $id): string
{
    static $palette = ['#1D4ED8', '#0F766E', '#7C3AED', '#B45309', '#BE123C', '#4D7C0F', '#0E7490', '#9D174D'];
    return $palette[abs($id) % count($palette)];
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
/* Gráficos SVG (gerados no servidor, sem biblioteca)                  */
/* ------------------------------------------------------------------ */

/**
 * Curva suave (spline cúbica monotônica, Fritsch–Carlson): não "passa" do valor máximo entre dois pontos,
 * então um acumulado nunca desenha uma barriga abaixo do anterior.
 * @param array<int,array{0:float,1:float}> $pts pontos [x, y] com x crescente
 */
function nc_smooth_path(array $pts): string
{
    $n = count($pts);
    if ($n === 0) {
        return '';
    }
    $f = static fn(float $v): string => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    $d = 'M' . $f($pts[0][0]) . ',' . $f($pts[0][1]);
    if ($n === 1) {
        return $d;
    }
    if ($n === 2) {
        return $d . 'L' . $f($pts[1][0]) . ',' . $f($pts[1][1]);
    }

    $dx = $dy = $m = [];
    for ($i = 0; $i < $n - 1; $i++) {
        $dx[$i] = $pts[$i + 1][0] - $pts[$i][0];
        $dy[$i] = $pts[$i + 1][1] - $pts[$i][1];
        $m[$i] = $dx[$i] != 0 ? $dy[$i] / $dx[$i] : 0.0;
    }
    $t = [$m[0]];
    for ($i = 1; $i < $n - 1; $i++) {
        $t[$i] = ($m[$i - 1] * $m[$i] <= 0) ? 0.0 : ($m[$i - 1] + $m[$i]) / 2;
    }
    $t[$n - 1] = $m[$n - 2];
    for ($i = 0; $i < $n - 1; $i++) {
        if ($m[$i] == 0.0) {
            $t[$i] = $t[$i + 1] = 0.0;
            continue;
        }
        $a = $t[$i] / $m[$i];
        $b = $t[$i + 1] / $m[$i];
        $s = $a * $a + $b * $b;
        if ($s > 9) {
            $k = 3 / sqrt($s);
            $t[$i] = $k * $a * $m[$i];
            $t[$i + 1] = $k * $b * $m[$i];
        }
    }
    for ($i = 0; $i < $n - 1; $i++) {
        $h = $dx[$i] / 3;
        $d .= 'C' . $f($pts[$i][0] + $h) . ',' . $f($pts[$i][1] + $t[$i] * $h)
            . ' ' . $f($pts[$i + 1][0] - $h) . ',' . $f($pts[$i + 1][1] - $t[$i + 1] * $h)
            . ' ' . $f($pts[$i + 1][0]) . ',' . $f($pts[$i + 1][1]);
    }
    return $d;
}

/**
 * Gráfico "Evolução de Faturamento": linhas suaves (+ área opcional) e barras opcionais.
 *
 * @param string[] $labels   rótulos do eixo X (um por ponto)
 * @param array<int,array{name:string,class:string,values:array<int,?float>,area?:bool}> $series
 *        values com null = sem dado (a linha termina ali)
 * @param float[]|null $bars valores das barras por ponto (modo ano) ou null
 * @param string[] $tips     texto do tooltip por ponto: "Título|linha 1|linha 2"
 */
function nc_svg_evolution(array $labels, array $series, ?array $bars, array $tips, string $ariaLabel): string
{
    $w = 760; $h = 280; $padL = 62; $padR = 16; $padT = 16; $padB = 30;
    $n = max(1, count($labels));
    $max = 0.0;
    foreach ($series as $s) {
        foreach ($s['values'] as $v) {
            if ($v !== null) {
                $max = max($max, (float)$v);
            }
        }
    }
    foreach ($bars ?? [] as $v) {
        $max = max($max, (float)$v);
    }
    $max = $max > 0 ? $max : 1.0;
    // arredonda o teto do eixo para um número "redondo"
    $mag = 10 ** floor(log10($max));
    $max = ceil($max / $mag * 2) / 2 * $mag;

    $plotW = $w - $padL - $padR;
    $plotH = $h - $padT - $padB;
    $slot = $plotW / $n;
    $useBars = $bars !== null;
    $x = static function (int $i) use ($padL, $plotW, $n, $slot, $useBars): float {
        if ($useBars) {
            return $padL + $slot * ($i + 0.5);
        }
        return $padL + ($n > 1 ? $plotW * $i / ($n - 1) : $plotW / 2);
    };
    $y = static fn(float $v): float => $padT + $plotH - ($plotH * min($v, $max) / $max);
    $r1 = static fn(float $v): string => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');

    $svg = '<svg class="nc-chart nc-evo" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($ariaLabel) . '" preserveAspectRatio="xMidYMid meet">';
    $svg .= '<defs><linearGradient id="nc-evo-grad" x1="0" y1="0" x2="0" y2="1">'
        . '<stop class="nc-evo-stop" offset="0%" stop-opacity="0.28"/><stop class="nc-evo-stop" offset="100%" stop-opacity="0"/></linearGradient></defs>';

    for ($g = 0; $g <= 4; $g++) {
        $val = $max * $g / 4;
        $gy = $y($val);
        $svg .= '<line class="nc-chart-grid" x1="' . $padL . '" x2="' . ($w - $padR) . '" y1="' . $r1($gy) . '" y2="' . $r1($gy) . '"/>';
        $svg .= '<text class="nc-chart-axis" x="' . ($padL - 8) . '" y="' . $r1($gy + 4) . '" text-anchor="end">' . e(nc_money_short($val)) . '</text>';
    }

    $step = $n > 16 ? 5 : 1;
    foreach ($labels as $i => $label) {
        $i = (int)$i;
        if ($i % $step !== 0 && $i !== $n - 1) {
            continue;
        }
        $svg .= '<text class="nc-chart-axis" x="' . $r1($x($i)) . '" y="' . ($h - 8) . '" text-anchor="middle">' . e((string)$label) . '</text>';
    }

    if ($bars !== null) {
        $bw = min(34.0, $slot * 0.55);
        foreach ($bars as $i => $v) {
            if ((float)$v <= 0) {
                continue;
            }
            $top = $y((float)$v);
            $svg .= '<rect class="nc-evo-bar" x="' . $r1($x((int)$i) - $bw / 2) . '" y="' . $r1($top) . '" width="' . $r1($bw) . '" height="' . $r1($padT + $plotH - $top) . '" rx="3"/>';
        }
    }

    foreach ($series as $s) {
        $pts = [];
        foreach (array_values($s['values']) as $i => $v) {
            if ($v === null) {
                break;
            }
            $pts[] = [$x($i), $y((float)$v)];
        }
        if (!$pts) {
            continue;
        }
        $path = nc_smooth_path($pts);
        if (!empty($s['area'])) {
            $last = $pts[count($pts) - 1];
            $svg .= '<path class="nc-evo-area" d="' . $path . 'L' . $r1($last[0]) . ',' . ($padT + $plotH) . 'L' . $r1($pts[0][0]) . ',' . ($padT + $plotH) . 'Z"/>';
        }
        $svg .= '<path class="nc-evo-line ' . e($s['class']) . '" d="' . $path . '"/>';
    }

    // zonas de passagem do mouse (tooltip via JS mínimo)
    $hitW = $useBars ? $slot : ($n > 1 ? $plotW / ($n - 1) : $plotW);
    foreach ($labels as $i => $label) {
        $i = (int)$i;
        $svg .= '<rect class="nc-evo-hit" x="' . $r1($x($i) - $hitW / 2) . '" y="' . $padT . '" width="' . $r1($hitW) . '" height="' . $plotH . '" data-tip="' . e($tips[$i] ?? (string)$label) . '"/>';
    }
    return '<div class="nc-evo-wrap">' . $svg . '</svg></div>';
}

/** Tabela equivalente do gráfico, só para leitores de tela. */
function nc_chart_table(array $labels, array $series, string $caption, string $firstHeader = 'Período'): string
{
    $html = '<table class="nc-sr-only"><caption>' . e($caption) . '</caption><thead><tr><th>' . e($firstHeader) . '</th>';
    foreach ($series as $s) {
        $html .= '<th>' . e($s['name']) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($labels as $i => $label) {
        $html .= '<tr><td>' . e((string)$label) . '</td>';
        foreach ($series as $s) {
            $v = $s['values'][$i] ?? null;
            $html .= '<td>' . ($v === null ? '—' : e(money($v))) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table>';
}

/** Dias úteis (seg–sex) entre duas datas, inclusive. */
function nc_business_days(DateTimeImmutable $from, DateTimeImmutable $to): int
{
    $count = 0;
    for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
        if ((int)$d->format('N') <= 5) {
            $count++;
        }
    }
    return $count;
}

/**
 * Dados do card "Evolução de Faturamento".
 * @return array{mode:string,labels:string[],series:array,bars:?array,tips:string[],real:float,goal:?float,pct:?float,
 *               note:string,pace:?string,projection:?float,has_goal:bool,title:string}
 */
function nc_evolution_data(PDO $pdo, string $mode, string $month, ?int $sellerId): array
{
    [$year, $mon] = array_map('intval', explode('-', $month));
    $today = new DateTimeImmutable('today');
    $seller = $sellerId !== null ? ' AND seller_user_id = ' . (int)$sellerId : '';
    $fmt = static fn(float $v): string => money($v);

    if ($mode === 'ano') {
        $q = $pdo->prepare('SELECT MONTH(invoice_date) m, COALESCE(SUM(amount),0) total FROM orders WHERE invoiced=1 AND YEAR(invoice_date)=?' . $seller . ' GROUP BY m');
        $q->execute([$year]);
        $perMonth = array_fill(1, 12, 0.0);
        foreach ($q->fetchAll() as $r) {
            $perMonth[(int)$r['m']] = (float)$r['total'];
        }
        $q->execute([$year - 1]);
        $prevPerMonth = array_fill(1, 12, 0.0);
        foreach ($q->fetchAll() as $r) {
            $prevPerMonth[(int)$r['m']] = (float)$r['total'];
        }

        $thisYear = (int)$today->format('Y');
        $cut = $year < $thisYear ? 12 : ($year === $thisYear ? (int)$today->format('n') : 0);
        $names = [1 => 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

        $t = nc_targets_year($pdo, $sellerId, $year);
        $monthlyGoal = array_fill(1, 12, 0.0);
        $anyMonthly = false;
        foreach ($t['months'] as $m => $v) {
            if ($v !== null) {
                $monthlyGoal[$m] = (float)$v;
                $anyMonthly = true;
            }
        }
        if (!$anyMonthly && $t['annual'] !== null) {
            $monthlyGoal = array_fill(1, 12, (float)$t['annual'] / 12);
        }
        $hasGoal = $t['annual'] !== null;

        $real = []; $goal = []; $prev = []; $acc = 0.0; $accG = 0.0; $accP = 0.0;
        $labels = []; $tips = []; $bars = [];
        for ($m = 1; $m <= 12; $m++) {
            $acc += $perMonth[$m];
            $accG += $monthlyGoal[$m];
            $accP += $prevPerMonth[$m];
            $real[] = $m <= $cut ? $acc : null;
            $goal[] = $accG;
            $prev[] = $accP;
            $bars[] = $m <= $cut ? $perMonth[$m] : 0.0;
            $labels[] = $names[$m];
            $tip = ucfirst($names[$m]) . ' de ' . $year . '|Faturado no mês: ' . $fmt($perMonth[$m]) . '|Acumulado: ' . $fmt($acc);
            if ($hasGoal) {
                $tip .= '|Meta acumulada: ' . $fmt($accG);
            }
            $tips[] = $tip;
        }

        $realTotal = array_sum($perMonth);
        $series = [['name' => 'Faturado acumulado', 'class' => 'is-real', 'values' => $real, 'area' => true]];
        if ($hasGoal) {
            $series[] = ['name' => 'Meta anual acumulada', 'class' => 'is-goal', 'values' => $goal];
        } else {
            $series[] = ['name' => 'Ano anterior acumulado', 'class' => 'is-prev', 'values' => $prev];
        }

        return [
            'mode' => 'ano', 'labels' => $labels, 'series' => $series, 'bars' => $bars, 'tips' => $tips,
            'real' => $realTotal, 'goal' => $t['annual'], 'has_goal' => $hasGoal,
            'pct' => $hasGoal && (float)$t['annual'] > 0 ? $realTotal / (float)$t['annual'] * 100 : null,
            'pace' => null,
            'projection' => $year === $thisYear && $cut > 0 ? $realTotal / $cut * 12 : null,
            'title' => 'no ano de ' . $year, 'table_header' => 'Mês',
        ];
    }

    // --- modo mês ---
    $firstDay = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $mon));
    $days = (int)$firstDay->format('t');
    $prevFirst = $firstDay->modify('-1 month');
    $prevDays = (int)$prevFirst->format('t');

    $daily = static function (PDO $pdo, string $ym, string $seller): array {
        $q = $pdo->prepare("SELECT DAY(invoice_date) d, COALESCE(SUM(amount),0) total FROM orders WHERE invoiced=1 AND DATE_FORMAT(invoice_date,'%Y-%m')=?" . $seller . ' GROUP BY d');
        $q->execute([$ym]);
        $out = [];
        foreach ($q->fetchAll() as $r) {
            $out[(int)$r['d']] = (float)$r['total'];
        }
        return $out;
    };
    $cur = $daily($pdo, $month, $seller);
    $prv = $daily($pdo, $prevFirst->format('Y-m'), $seller);

    $currentMonth = $today->format('Y-m');
    $cut = $month < $currentMonth ? $days : ($month === $currentMonth ? (int)$today->format('j') : 0);

    $goalMonth = nc_target($pdo, $sellerId, $year, $mon);
    $hasGoal = $goalMonth !== null;

    $real = []; $goal = []; $prev = []; $labels = []; $tips = [];
    $acc = 0.0; $accP = 0.0;
    for ($d = 1; $d <= $days; $d++) {
        $acc += $cur[$d] ?? 0.0;
        $accP += $prv[$d] ?? 0.0;
        $real[] = $d <= $cut ? $acc : null;
        $prev[] = $d <= $prevDays ? $accP : null;
        $goal[] = $hasGoal ? (float)$goalMonth * $d / $days : null;
        $labels[] = (string)$d;
        $tip = sprintf('%02d/%02d/%04d', $d, $mon, $year) . '|Acumulado: ' . ($d <= $cut ? $fmt($acc) : '—');
        if ($d <= $cut && ($cur[$d] ?? 0) > 0) {
            $tip .= '|No dia: ' . $fmt($cur[$d]);
        }
        if ($hasGoal) {
            $tip .= '|Meta até aqui: ' . $fmt((float)$goalMonth * $d / $days);
        }
        $tip .= '|Mês anterior: ' . ($d <= $prevDays ? $fmt($accP) : '—');
        $tips[] = $tip;
    }

    $realTotal = array_sum($cur);
    $series = [['name' => 'Faturado acumulado', 'class' => 'is-real', 'values' => $real, 'area' => true]];
    if ($hasGoal) {
        $series[] = ['name' => 'Meta do mês (rampa)', 'class' => 'is-goal', 'values' => $goal];
    }
    $series[] = ['name' => 'Mês anterior acumulado', 'class' => 'is-prev', 'values' => $prev];

    $pace = null;
    if ($hasGoal && $month === $currentMonth) {
        $left = max(0.0, (float)$goalMonth - $realTotal);
        $businessLeft = nc_business_days($today, $firstDay->modify('last day of this month'));
        if ($left <= 0) {
            $pace = 'Meta do mês atingida.';
        } elseif ($businessLeft > 0) {
            $pace = 'Ritmo necessário: ' . money($left / $businessLeft) . '/dia útil até o fim do mês.';
        }
    }

    return [
        'mode' => 'mes', 'labels' => $labels, 'series' => $series, 'bars' => null, 'tips' => $tips,
        'real' => $realTotal, 'goal' => $goalMonth, 'has_goal' => $hasGoal,
        'pct' => $hasGoal && (float)$goalMonth > 0 ? $realTotal / (float)$goalMonth * 100 : null,
        'pace' => $pace, 'projection' => null, 'title' => 'em ' . nc_month_label($month), 'table_header' => 'Dia',
    ];
}
