<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';
require_auth();$pdo=db();
$openCount=(int)$pdo->query("SELECT COUNT(*) FROM opportunities WHERE status='Aberta'")->fetchColumn();
$withoutOwner=(int)$pdo->query("SELECT COUNT(*) FROM opportunities WHERE status='Aberta' AND seller_user_id IS NULL")->fetchColumn();
$oldCount=(int)$pdo->query("SELECT COUNT(*) FROM opportunities WHERE status='Aberta' AND opportunity_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
page_header('Cadência · Agente','cadencia');
?>
<div class="page-actions"><div><span class="eyebrow">ASSISTÊNCIA</span><h2>Cadência · Agente</h2><p>Base preparada para o ChatGPT apoiar priorização e follow-up pelo backend PHP.</p></div></div>
<div class="kpi-grid kpi-grid-3"><article class="kpi"><span>Oportunidades abertas</span><strong><?= $openCount ?></strong><small>base para cadência</small></article><article class="kpi"><span>Sem responsável</span><strong><?= $withoutOwner ?></strong><small>precisam de atribuição</small></article><article class="kpi"><span>Com mais de 30 dias</span><strong><?= $oldCount ?></strong><small>merecem revisão comercial</small></article></div>
<div class="card agent-card"><div class="agent-symbol">✦</div><div><span class="tag">CHATGPT</span><h3>Integração preparada</h3><p>As credenciais ficam criptografadas no banco e a IA será chamada somente pelo servidor.</p><p class="muted">Nesta primeira base não enviamos dados para IA e não existem análises fictícias.</p></div></div>
<?php page_footer(); ?>
