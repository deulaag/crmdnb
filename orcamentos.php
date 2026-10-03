<?php
declare(strict_types=1);
require_once __DIR__.'/includes/layout.php';
$user=require_auth();$pdo=db();

if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$action=(string)($_POST['action']??'create');
 try{
  if($action==='create'){
   $date=(string)($_POST['date']??date('Y-m-d'));$client=trim((string)($_POST['client_name']??''));$seller=($_POST['seller_user_id']??'')!==''?(int)$_POST['seller_user_id']:null;$support=($_POST['support_user_id']??'')!==''?(int)$_POST['support_user_id']:null;$lineId=(int)($_POST['product_line_id']??0);$amount=(float)($_POST['amount']??0);$commission=(float)($_POST['dnb_commission_estimated']??0);$notes=trim((string)($_POST['notes']??''));
   if($client===''||$lineId<1||$amount<0)throw new RuntimeException('Informe cliente, linha de produto e valor válido.');
   $q=$pdo->prepare('SELECT * FROM product_lines WHERE id=? AND active=1');$q->execute([$lineId]);$line=$q->fetch();if(!$line)throw new RuntimeException('Linha de produto inválida.');
   $q=$pdo->prepare('SELECT id FROM funnels WHERE funnel_key=? AND active=1');$q->execute([$line['funnel_key']]);$funnel=(int)$q->fetchColumn();
   $q=$pdo->prepare('SELECT id FROM funnel_stages WHERE funnel_id=? ORDER BY sort_order LIMIT 1');$q->execute([$funnel]);$stage=(int)$q->fetchColumn();
   $number=trim((string)($_POST['number']??''))?:generate_opportunity_number($pdo,$seller,$date);
   $q=$pdo->prepare('INSERT INTO opportunities(number,opportunity_date,client_name,seller_user_id,support_user_id,product_line_id,funnel_id,stage_id,item_type,amount,dnb_commission_estimated,status,source,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,"Aberta","manual",?,?)');
   $q->execute([$number,$date,$client,$seller,$support,$lineId,$funnel,$stage,$line['item_type'],$amount,$commission,$notes,(int)$user['id']]);$id=(int)$pdo->lastInsertId();audit($pdo,(int)$user['id'],'create','opportunity',$id,['number'=>$number]);flash('success','Oportunidade '.$number.' registrada.');
  }elseif($action==='stage'){
   $id=(int)($_POST['id']??0);$stage=(int)($_POST['stage_id']??0);$q=$pdo->prepare('SELECT funnel_id FROM opportunities WHERE id=?');$q->execute([$id]);$funnel=(int)$q->fetchColumn();$q=$pdo->prepare('SELECT COUNT(*) FROM funnel_stages WHERE id=? AND funnel_id=?');$q->execute([$stage,$funnel]);if(!(int)$q->fetchColumn())throw new RuntimeException('Etapa inválida.');$pdo->prepare('UPDATE opportunities SET stage_id=? WHERE id=?')->execute([$stage,$id]);audit($pdo,(int)$user['id'],'update_stage','opportunity',$id);flash('success','Etapa atualizada.');
  }elseif($action==='status'){
   $id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'');if(!in_array($status,['Aberta','Ganha','Perdida'],true))throw new RuntimeException('Status inválido.');$pdo->prepare('UPDATE opportunities SET status=? WHERE id=?')->execute([$status,$id]);audit($pdo,(int)$user['id'],'update_status','opportunity',$id,['status'=>$status]);flash('success','Status atualizado.');
  }
 }catch(Throwable $e){flash('danger',$e->getMessage());}
 redirect('orcamentos.php');
}

$users=$pdo->query("SELECT id,name FROM users WHERE active=1 ORDER BY name")->fetchAll();
$lines=$pdo->query("SELECT * FROM product_lines WHERE active=1 ORDER BY sort_order")->fetchAll();
$rows=$pdo->query("SELECT o.*,pl.name line_name,f.label funnel_label,u.name seller_name FROM opportunities o JOIN product_lines pl ON pl.id=o.product_line_id JOIN funnels f ON f.id=o.funnel_id LEFT JOIN users u ON u.id=o.seller_user_id ORDER BY o.opportunity_date DESC,o.id DESC LIMIT 300")->fetchAll();
$stages=[];foreach($pdo->query('SELECT * FROM funnel_stages ORDER BY funnel_id,sort_order')->fetchAll() as $s)$stages[(int)$s['funnel_id']][]=$s;
page_header('Orçamentos','orcamentos');
?>
<div class="page-actions"><div><span class="eyebrow">OPORTUNIDADES</span><h2>Nova oportunidade / orçamento</h2><p>Uma oportunidade por linha de produto, encaminhada automaticamente ao funil correspondente.</p></div></div>
<div class="card"><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="create">
<label><span>Nº</span><input name="number" placeholder="automático"></label><label><span>Data</span><input type="date" name="date" value="<?= date('Y-m-d') ?>" required></label><label class="span-2"><span>Cliente</span><input name="client_name" required></label>
<label><span>Vendedor</span><select name="seller_user_id"><option value="">—</option><?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></label><label><span>Suporte</span><select name="support_user_id"><option value="">—</option><?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></label>
<label><span>Linha de produto</span><select name="product_line_id" required><option value="">— selecione —</option><?php foreach($lines as $line): ?><option value="<?= (int)$line['id'] ?>"><?= e($line['name']) ?></option><?php endforeach; ?></select></label><label><span>Valor (R$)</span><input type="number" step="0.01" min="0" name="amount" required></label><label><span>Comissão DNB estimada (R$)</span><input type="number" step="0.01" min="0" name="dnb_commission_estimated"></label><label class="span-2"><span>Observações</span><textarea name="notes" rows="3"></textarea></label><div class="span-2 form-actions"><button class="btn btn-primary">Registrar oportunidade</button></div></form></div>
<div class="card"><div class="card-title"><div><span class="tag">FUNIL</span><h3>Orçamentos (<?= count($rows) ?>)</h3></div></div>
<?php if(!$rows): empty_state('Nenhum orçamento cadastrado','O CRM está limpo, sem dados demonstrativos.'); else: ?><div class="table-wrap"><table><thead><tr><th>Data</th><th>Nº</th><th>Cliente</th><th>Vendedor</th><th>Linha / funil</th><th>Etapa</th><th>Valor</th><th>Status</th><th>Ações</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><?= e($row['opportunity_date']) ?></td><td class="mono"><?= e($row['number']) ?></td><td><strong><?= e($row['client_name']) ?></strong></td><td><?= e($row['seller_name']?:'—') ?></td><td><?= e($row['line_name']) ?><small><?= e($row['funnel_label']) ?></small></td><td><?php if($row['status']==='Aberta'): ?><form method="post" class="cell-form"><?= csrf_field() ?><input type="hidden" name="action" value="stage"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><select name="stage_id" onchange="this.form.submit()"><?php foreach($stages[(int)$row['funnel_id']]??[] as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id']===(int)$row['stage_id']?'selected':'' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></form><?php else: ?>—<?php endif; ?></td><td><?= money($row['amount']) ?></td><td><span class="status status-<?= strtolower(e($row['status'])) ?>"><?= e($row['status']) ?></span></td><td><?php if($row['status']==='Aberta'): ?><div class="row-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="status" value="Ganha"><button class="link-btn">Ganha</button></form><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="status" value="Perdida"><button class="link-btn danger">Perdida</button></form></div><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php page_footer(); ?>