<?php
declare(strict_types=1);

require_once __DIR__ . '/../_empresas_merge.php'; // nc_merged_names(), nc_origin_label()

/** O nome candidato corresponde (normalizado) a algum dos nomes da empresa? */
function nc_name_matches(string $candidate, array $norms): bool
{
    $n = normalized_name($candidate);
    return $n !== '' && in_array($n, $norms, true);
}

/** Primeiro termo significativo (>= 3 letras) do nome, para pré-filtrar no SQL. */
function nc_name_token(string $name): string
{
    foreach (explode(' ', normalized_name($name)) as $word) {
        if (strlen($word) >= 3) {
            return $word;
        }
    }
    return normalized_name($name);
}

/**
 * Reúne tudo que pertence à empresa. Oportunidades, pedidos, visitas e agenda guardam client_name
 * (texto) e company_id opcional: busca por company_id E por nome normalizado. Não altera dados.
 */
function nc_cliente_dados(PDO $pdo, array $co): array
{
    $id = (int)$co['id'];
    // Nome, nome fantasia e nomes de empresas já mescladas nesta (V3, seção 7).
    $names = array_merge([(string)$co['name'], (string)($co['alias'] ?? '')], nc_merged_names($co));
    $norms = array_values(array_filter(array_unique(array_map('normalized_name', $names))));
    $likes = [];
    foreach ($names as $n) {
        $token = nc_name_token($n);
        if ($token !== '') {
            $likes[$token] = nc_like($token);
        }
    }
    $likes = array_values($likes);
    $like = $likes[0] ?? '';
    // "(col LIKE ? OR col LIKE ?)" para todos os termos; vazio => condição sempre falsa.
    $likeSql = static fn(string $col): string => $likes ? '(' . implode(' OR ', array_fill(0, count($likes), "$col LIKE ?")) . ')' : '1=0';

    $contacts = $pdo->prepare('SELECT * FROM contacts WHERE company_id = ? ORDER BY name');
    $contacts->execute([$id]);

    $oppExtra = '';
    foreach (['source', 'import_batch_id', 'external_ref'] as $col) {
        if (nc_column_exists($pdo, 'opportunities', $col)) {
            $oppExtra .= ',o.' . $col;
        }
    }
    $opps = [];
    $q = $pdo->prepare(
        "SELECT o.id,o.number,o.opportunity_date,o.client_name,o.company_id,o.amount,o.status,pl.name line_name$oppExtra
         FROM opportunities o JOIN product_lines pl ON pl.id = o.product_line_id
         WHERE (o.company_id = ? OR " . $likeSql('o.client_name') . ') ORDER BY o.id DESC LIMIT 300'
    );
    $q->execute(array_merge([$id], $likes));
    foreach ($q->fetchAll() as $row) {
        if ((int)$row['company_id'] === $id || nc_name_matches((string)$row['client_name'], $norms)) {
            $opps[] = $row;
        }
    }
    $oppIds = array_map(static fn($r) => (int)$r['id'], $opps);

    $orders = [];
    $q = $pdo->prepare(
        'SELECT ord.id,ord.number,ord.order_date,ord.client_name,ord.opportunity_id,ord.amount,ord.invoiced,ord.invoice_date,ord.modality
         FROM orders ord WHERE ' . $likeSql('ord.client_name') . ' ORDER BY ord.id DESC LIMIT 300'
    );
    $q->execute($likes);
    $seen = [];
    foreach ($q->fetchAll() as $row) {
        if (nc_name_matches((string)$row['client_name'], $norms) || in_array((int)$row['opportunity_id'], $oppIds, true)) {
            $orders[] = $row;
            $seen[(int)$row['id']] = true;
        }
    }
    if ($oppIds) {
        $in = implode(',', array_fill(0, count($oppIds), '?'));
        $q = $pdo->prepare("SELECT id,number,order_date,client_name,opportunity_id,amount,invoiced,invoice_date,modality FROM orders WHERE opportunity_id IN ($in) ORDER BY id DESC");
        $q->execute($oppIds);
        foreach ($q->fetchAll() as $row) {
            if (!isset($seen[(int)$row['id']])) {
                $orders[] = $row;
            }
        }
        usort($orders, static fn($a, $b) => strcmp((string)$b['order_date'], (string)$a['order_date']));
    }

    $visits = [];
    $agenda = [];
    if ($like !== '') {
        $q = $pdo->prepare('SELECT id,os_number,visit_date,client_name,visit_type,lead_status FROM visits WHERE ' . $likeSql('client_name') . ' ORDER BY visit_date DESC LIMIT 200');
        $q->execute($likes);
        foreach ($q->fetchAll() as $row) {
            if (nc_name_matches((string)$row['client_name'], $norms)) {
                $visits[] = $row;
            }
        }
        $q = $pdo->prepare('SELECT id,event_date,event_time,client_name,objective,status,event_type FROM agenda WHERE ' . $likeSql('client_name') . ' ORDER BY event_date DESC LIMIT 200');
        $q->execute($likes);
        foreach ($q->fetchAll() as $row) {
            if (nc_name_matches((string)$row['client_name'], $norms)) {
                $agenda[] = $row;
            }
        }
    }

    $lastOrder = null;
    foreach ($orders as $o) {
        if ($lastOrder === null || strcmp((string)$o['order_date'], $lastOrder) > 0) {
            $lastOrder = (string)$o['order_date'];
        }
    }

    return [
        'contacts' => $contacts->fetchAll(),
        'opps' => $opps,
        'orders' => $orders,
        'visits' => $visits,
        'agenda' => $agenda,
        'last_order' => $lastOrder,
    ];
}

/** Status da carteira (mesma regra da lista): manual quando houver, senão por último pedido. */
function nc_portfolio_status(array $co, ?string $lastOrder): string
{
    $manual = trim((string)($co['portfolio_status'] ?? ''));
    if ($manual !== '') {
        return $manual;
    }
    if ($lastOrder === null) {
        return 'inativo';
    }
    $days = (int)(new DateTimeImmutable('today'))->diff(new DateTimeImmutable($lastOrder))->days;
    return $days <= 180 ? 'ativo' : ($days <= 365 ? 'risco' : 'inativo');
}

/** Conteúdo da ficha (usado no drawer e na página cheia). */
function nc_cliente_ficha(array $co, array $d, bool $isAdmin): void
{
    $status = nc_portfolio_status($co, $d['last_order']);
    $address = trim(implode(', ', array_filter([
        (string)($co['street'] ?? ''), (string)($co['address_number'] ?? ''), (string)($co['address_details'] ?? ''), (string)($co['district'] ?? ''),
    ])));
    $place = trim(($co['city'] ?? '') . ($co['state'] ? ' / ' . $co['state'] : ''));
    $info = [
        'CNPJ' => $co['cnpj'] ? format_cnpj((string)$co['cnpj']) : '',
        'Situação cadastral' => (string)($co['registration_status'] ?? ''),
        'Segmento' => (string)($co['industry'] ?? ''),
        'Porte' => (string)($co['company_size'] ?? ''),
        'E-mail' => (string)($co['email'] ?? ''),
        'Telefone' => (string)($co['phone'] ?? ''),
        'Site' => (string)($co['website'] ?? ''),
        'Endereço' => $address,
        'Cidade / UF' => $place,
        'CEP' => (string)($co['zip'] ?? ''),
    ];
    ?>
    <div class="nc-ficha" data-drawer-title="<?= e((string)$co['name']) ?>">
        <header class="nc-ficha-head">
            <div>
                <h2 class="nc-ficha-title"><?= e((string)$co['name']) ?></h2>
                <?php if (!empty($co['alias'])): ?><p class="nc-muted"><?= e((string)$co['alias']) ?></p><?php endif; ?>
            </div>
            <div class="nc-ficha-badges">
                <?= nc_portfolio_badge($status) ?>
                <?= nc_badge(nc_source_label((string)($co['source'] ?? 'manual'))) ?>
            </div>
        </header>

        <section class="nc-ficha-section" aria-label="Dados cadastrais">
            <h3>Dados cadastrais</h3>
            <dl class="nc-dl">
                <?php foreach ($info as $label => $value): if ($value === '') continue; ?>
                    <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
                <?php endforeach; ?>
                <div><dt>Último pedido</dt><dd><?= e(nc_date($d['last_order'])) ?></dd></div>
            </dl>
            <?php if ($isAdmin): ?>
                <form method="post" action="<?= e(nc_url('acoes/rd-sync.php')) ?>" class="nc-inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return" value="<?= e(nc_url('cliente.php?id=' . (int)$co['id'])) ?>">
                    <button class="nc-btn nc-btn-secondary" type="submit">Sincronizar RD Station</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="nc-ficha-section" aria-label="Contatos">
            <h3>Contatos <span class="nc-tab-count"><?= count($d['contacts']) ?></span></h3>
            <?php if (!$d['contacts']): ?><p class="nc-muted">Nenhum contato vinculado.</p><?php else: ?>
                <ul class="nc-list">
                    <?php foreach ($d['contacts'] as $c): ?>
                        <li>
                            <strong><?= e((string)$c['name']) ?></strong>
                            <span class="nc-muted"><?= e(implode(' · ', array_filter([(string)($c['job_title'] ?? ''), (string)($c['email'] ?? ''), (string)($c['phone'] ?? '')]))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <button type="button" class="nc-btn nc-btn-secondary"
                    hx-get="<?= e(nc_url('acoes/contato-form.php?empresa=' . (int)$co['id'] . '&return=' . rawurlencode(nc_url('cliente.php?id=' . (int)$co['id'])))) ?>"
                    hx-target="#nc-drawer-body" hx-swap="innerHTML">+ Novo contato</button>
        </section>

        <section class="nc-ficha-section" aria-label="Orçamentos e propostas">
            <h3>Orçamentos e propostas <span class="nc-tab-count"><?= count($d['opps']) ?></span></h3>
            <?php if (!$d['opps']): ?><p class="nc-muted">Nenhum orçamento ou proposta.</p><?php else: ?>
                <ul class="nc-list">
                    <?php foreach (array_slice($d['opps'], 0, 10) as $o):
                        $byName = (int)($o['company_id'] ?? 0) !== (int)$co['id']; ?>
                        <li>
                            <span>
                                <strong><?= e((string)$o['number']) ?></strong>
                                <span class="nc-muted"><?= e((string)$o['line_name']) ?> · <?= e(nc_date((string)$o['opportunity_date'])) ?></span><br>
                                <small class="nc-muted">Origem: <?= e(nc_origin_label($o)) ?><?= !empty($o['external_ref']) ? ' · Ref. origem ' . e((string)$o['external_ref']) : '' ?><?= $byName ? ' · vínculo só pelo nome' : '' ?></small>
                            </span>
                            <span class="nc-row-end"><span class="nc-num"><?= e(money($o['amount'])) ?></span> <?= nc_status_badge((string)$o['status']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="nc-ficha-section" aria-label="Pedidos">
            <h3>Pedidos <span class="nc-tab-count"><?= count($d['orders']) ?></span></h3>
            <?php if (!$d['orders']): ?><p class="nc-muted">Nenhum pedido.</p><?php else: ?>
                <ul class="nc-list">
                    <?php foreach (array_slice($d['orders'], 0, 10) as $o): ?>
                        <li>
                            <span><strong><?= e((string)$o['number']) ?></strong> <span class="nc-muted"><?= e(nc_date((string)$o['order_date'])) ?></span></span>
                            <span class="nc-row-end"><span class="nc-num"><?= e(money($o['amount'])) ?></span> <?= (int)$o['invoiced'] === 1 ? nc_badge('Faturado', 'success') : nc_badge('A faturar', 'warning') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="nc-ficha-section" aria-label="Visitas e agenda">
            <h3>Visitas <span class="nc-tab-count"><?= count($d['visits']) ?></span></h3>
            <?php if (!$d['visits']): ?><p class="nc-muted">Nenhuma visita registrada.</p><?php else: ?>
                <ul class="nc-list">
                    <?php foreach (array_slice($d['visits'], 0, 8) as $v): ?>
                        <li>
                            <span><strong><?= e(nc_date((string)$v['visit_date'])) ?></strong> <span class="nc-muted"><?= e($v['os_number'] ? 'OS ' . $v['os_number'] : ucfirst((string)$v['visit_type'])) ?></span></span>
                            <?= nc_badge(ucfirst((string)$v['lead_status']), $v['lead_status'] === 'promovida' ? 'success' : ($v['lead_status'] === 'descartada' ? 'neutral' : 'warning')) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <h3>Agenda <span class="nc-tab-count"><?= count($d['agenda']) ?></span></h3>
            <?php if (!$d['agenda']): ?><p class="nc-muted">Nenhum compromisso.</p><?php else: ?>
                <ul class="nc-list">
                    <?php foreach (array_slice($d['agenda'], 0, 8) as $a): ?>
                        <li>
                            <span><strong><?= e(nc_date((string)$a['event_date'])) ?></strong> <span class="nc-muted"><?= e(nc_truncate((string)$a['objective'], 60)) ?></span></span>
                            <?= nc_badge(ucfirst((string)$a['status']), in_array($a['status'], ['confirmado', 'concluido'], true) ? 'success' : ($a['status'] === 'cancelado' ? 'neutral' : 'info')) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
    <?php
}
