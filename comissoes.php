<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';
require_auth();$pdo=db();$month=valid_month($_GET['mes']??null);
$q=$pdo->prepare("SELECT u.id,u.name,COUNT(o.id) pedidos,COALESCE(SUM(CASE WHEN o.modality='revenda' THEN o.amount ELSE 0 END),0) revenda,COALESCE(SUM(CASE WHEN o.modality='agenciada' THEN o.amount ELSE 0 END),0) agenciado,COALESCE(SUM(o.dnb_commission),0) comissao_dnb,COALESCE(SUM(o.margin_amount),0) margem FROM users u LEFT JOIN orders o ON o.seller_user_id=u.id AND o.invoiced=1 AND DATE_FORMAT(o.invoice_date,'%Y-%m')=? WHERE u.active=1 GROUP BY u.id ORDER BY u.name");$q->execute([$month]);$rows=$q->fetchAll();
page_header('Comissões','comissoes');
?>
<div class="page-actions"><div><span class="eyebrow">APURAÇÃO</span><h2>Comissões</h2><p>Base calculada apenas a partir de pedidos reais marcados como faturados.</p></div><form method="get" class="inline-form"><label><span>Mês de referência</span><input type="month" name="mes" value="<?= e($month) ?>" onchange="this.form.submit()"></label></form></div>
<div class="card"><div class="card-title"><div><span class="tag">RESUMO</span><h3>Resultado por responsável</h3></div></div><div class="table-wrap"><table><thead><tr><th>Responsável</th><th>Pedidos faturados</th><th>Revenda</th><th>Agenciado</th><th>Comissão DNB</th><th>Margem informada</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><strong><?= e($row['name']) ?></strong></td><td><?= (int)$row['pedidos'] ?></td><td><?= money($row['revenda']) ?></td><td><?= money($row['agenciado']) ?></td><td><?= money($row['comissao_dnb']) ?></td><td><?= money($row['margem']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="info-panel"><strong>Importante</strong><p>As regras individuais de comissão e metas do piloto serão migradas como configuração administrativa em etapa própria. Nenhuma regra vinculada a pessoas de exemplo foi trazida.</p></div>
<?php page_footer(); ?>
