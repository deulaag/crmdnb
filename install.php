<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
if(is_installed())redirect('login.php');

$errors=[];$success=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $host=trim((string)($_POST['db_host']??'localhost'));
    $port=(int)($_POST['db_port']??3306);
    $dbName=trim((string)($_POST['db_name']??''));
    $dbUser=trim((string)($_POST['db_user']??''));
    $dbPass=(string)($_POST['db_pass']??'');
    $adminName=trim((string)($_POST['admin_name']??''));
    $adminEmail=strtolower(trim((string)($_POST['admin_email']??'')));
    $adminPass=(string)($_POST['admin_pass']??'');
    $adminPass2=(string)($_POST['admin_pass_confirm']??'');

    if(!preg_match('/^[A-Za-z0-9_]+$/',$dbName))$errors[]='O nome do banco deve conter apenas letras, números e underline.';
    if($dbUser==='')$errors[]='Informe o usuário do banco.';
    if($adminName==='')$errors[]='Informe o nome do administrador.';
    if(!filter_var($adminEmail,FILTER_VALIDATE_EMAIL))$errors[]='Informe um e-mail válido.';
    if(strlen($adminPass)<10)$errors[]='A senha do administrador deve ter pelo menos 10 caracteres.';
    if($adminPass!==$adminPass2)$errors[]='As senhas do administrador não conferem.';

    if(!$errors){
        try{
            $server=new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4',$host,$port),$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            try{$server->exec('CREATE DATABASE IF NOT EXISTS `'.str_replace('`','``',$dbName).'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}catch(Throwable $ignore){}
            $pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$host,$port,$dbName),$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);

            foreach(['schema_core.php','schema_crm.php','schema_activity.php'] as $schemaFile){
                $schema=require __DIR__.'/config/'.$schemaFile;
                foreach($schema as $sql)$pdo->exec($sql);
            }

            $pdo->beginTransaction();
            $q=$pdo->prepare('INSERT INTO users(name,email,password_hash,role,active) VALUES(?,?,?,"admin",1) ON DUPLICATE KEY UPDATE name=VALUES(name),password_hash=VALUES(password_hash),role="admin",active=1');
            $q->execute([$adminName,$adminEmail,password_hash($adminPass,PASSWORD_DEFAULT)]);

            $funnels=[
                ['equipamentos','Equipamentos','Compressores IR (agenciamento) · Bombas ARO — ciclo longo',['Prospecção','Levantamento técnico / Visita','Proposta emitida','Negociação','Fechamento']],
                ['servicos','Serviços e Locação','Manutenção IR · Locação',['Solicitação','Levantamento / Vistoria','Proposta emitida','Negociação','Fechamento']],
                ['pecas','Peças e Revenda','Peças IR · Peças ARO · linha Parker — ciclo curto',['Solicitação','Cotação enviada','Follow-up','Fechamento']]
            ];
            foreach($funnels as $i=>[$key,$label,$description,$stages]){
                $q=$pdo->prepare('INSERT INTO funnels(funnel_key,label,description,sort_order) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description),sort_order=VALUES(sort_order)');
                $q->execute([$key,$label,$description,$i+1]);
                $q=$pdo->prepare('SELECT id FROM funnels WHERE funnel_key=?');$q->execute([$key]);$fid=(int)$q->fetchColumn();
                foreach($stages as $j=>$stage){$q=$pdo->prepare('INSERT INTO funnel_stages(funnel_id,name,sort_order) VALUES(?,?,?) ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order)');$q->execute([$fid,$stage,$j+1]);}
            }

            $lines=[
                ['Compressores IR (agenciamento)','equipamentos','Equipamento',1],
                ['Serviço Manutenção IR','servicos','Manutenção',0],
                ['Locação','servicos','Locação',0],
                ['Peças IR','pecas','Peças',0],
                ['Bombas ARO','equipamentos','Equipamento',0],
                ['Peças ARO','pecas','Peças',0],
                ['Parker Automação','pecas','Peças',0],
                ['Parker Filtração de Processos','pecas','Peças',0],
                ['Parker Filtração GSF','pecas','Peças',0],
                ['Parker Conexões','pecas','Peças',0]
            ];
            foreach($lines as $i=>[$name,$funnelKey,$type,$agency]){
                $q=$pdo->prepare('INSERT INTO product_lines(name,funnel_key,item_type,is_agency,sort_order) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE funnel_key=VALUES(funnel_key),item_type=VALUES(item_type),is_agency=VALUES(is_agency),sort_order=VALUES(sort_order)');
                $q->execute([$name,$funnelKey,$type,$agency,$i+1]);
            }
            $pdo->commit();

            $config=['db_host'=>$host,'db_port'=>$port,'db_name'=>$dbName,'db_user'=>$dbUser,'db_pass'=>$dbPass,'app_key'=>bin2hex(random_bytes(32))];
            $configPath=__DIR__.'/config/database.local.php';
            if(file_put_contents($configPath,"<?php\nreturn ".var_export($config,true).";\n",LOCK_EX)===false)throw new RuntimeException('Não foi possível gravar config/database.local.php.');
            @chmod($configPath,0640);
            if(!is_dir(__DIR__.'/storage'))mkdir(__DIR__.'/storage',0755,true);
            file_put_contents(__DIR__.'/storage/installed.lock',date(DATE_ATOM),LOCK_EX);
            $success=true;
        }catch(Throwable $e){
            if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
            $errors[]='Falha na instalação: '.$e->getMessage();
        }
    }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar · DNB CRM</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Lato:wght@300;400;700;900&family=Michroma&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/app.css?v=1"></head><body class="auth-body"><div class="install-wrap"><div class="auth-brand"><img src="assets/dnb-logo.png" alt="DNB"><span>CRM Comercial</span></div><div class="install-card"><div class="install-heading"><span class="eyebrow">INSTALAÇÃO SEGURA</span><h1>Preparar CRM DNB</h1><p>Cria o banco, a estrutura do CRM, os funis e a primeira conta administradora. Nenhum cliente, contato, oportunidade, pedido ou visita de exemplo será criado.</p></div>
<?php if($success): ?><div class="alert alert-success">Instalação concluída. O banco está pronto e o administrador foi criado.</div><a class="btn btn-primary btn-block" href="login.php">Entrar no CRM</a>
<?php else: foreach($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>
<form method="post" class="form-grid install-grid" autocomplete="off"><?= csrf_field() ?><div class="form-section-title">Banco de dados</div>
<label><span>Servidor</span><input name="db_host" required value="<?= e($_POST['db_host']??'localhost') ?>"></label><label><span>Porta</span><input name="db_port" type="number" required value="<?= e($_POST['db_port']??'3306') ?>"></label><label><span>Nome do banco</span><input name="db_name" required value="<?= e($_POST['db_name']??'') ?>" placeholder="ex: crmdnb"></label><label><span>Usuário do banco</span><input name="db_user" required value="<?= e($_POST['db_user']??'') ?>"></label><label class="span-2"><span>Senha do banco</span><input name="db_pass" type="password"></label>
<div class="form-section-title">Administrador</div><label><span>Nome</span><input name="admin_name" required value="<?= e($_POST['admin_name']??'') ?>"></label><label><span>E-mail</span><input name="admin_email" type="email" required value="<?= e($_POST['admin_email']??'') ?>"></label><label><span>Senha</span><input name="admin_pass" type="password" minlength="10" required></label><label><span>Confirmar senha</span><input name="admin_pass_confirm" type="password" minlength="10" required></label>
<div class="span-2 install-note">A configuração do banco é criada localmente em <code>config/database.local.php</code> e fica fora do Git.</div><button class="btn btn-primary span-2" type="submit">Instalar CRM e criar administrador</button></form><?php endif; ?></div></div></body></html>