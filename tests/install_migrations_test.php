<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/includes/migration_service.php';

function installOk(bool $ok,string $mensagem): void
{
    if(!$ok){fwrite(STDERR,"FALHA: {$mensagem}\n");exit(1);}
}

$sql=<<<'SQL'
CREATE TABLE exemplo (id INT, texto VARCHAR(50));
DELIMITER $$
CREATE PROCEDURE proc_exemplo()
BEGIN
  SELECT 'valor;preservado';
END$$
DELIMITER ;
INSERT INTO exemplo (id,texto) VALUES (1,'a;b');
SQL;
$statements=separarSqlMigracaoDnb($sql);
installOk(count($statements)===3,'separador deve respeitar DELIMITER e ponto-e-vírgula em strings');
installOk(str_contains($statements[1],"SELECT 'valor;preservado';"),'corpo da procedure deve permanecer inteiro');
installOk(str_contains($statements[2],"'a;b'"),'valor SQL com ponto-e-vírgula deve permanecer inteiro');

$pagina=(string)file_get_contents($root.'/install/index.php');
$rbac=(string)file_get_contents($root.'/includes/rbac.php');
$deploy=(string)file_get_contents($root.'/.github/workflows/deploy.yml');
$sqlHtaccess=(string)file_get_contents($root.'/sql/.htaccess');
installOk(str_contains($pagina,'exigirPapel(PAPEL_ADMINISTRADOR)'),'instalador deve exigir ADM logado');
installOk(str_contains($pagina,'exigirCsrf()'),'botão de atualização deve validar CSRF');
installOk(str_contains($pagina,'aplicarMigracoesDnbPendentes'),'botão deve aplicar apenas atualizações pendentes');
installOk(str_contains($pagina,"header('Location: /painel.php')"),'instalador atualizado deve ficar inacessível');
installOk(str_contains($rbac,"header('Location: /install/')"),'ADM deve ser direcionado automaticamente quando houver atualização');
installOk(count(listarMigracoesDnb())===count(DNB_MIGRATION_BASELINE),'baseline deve representar todas as migrações existentes no lançamento do instalador');
installOk(!str_contains($deploy,'**/*.sql'),'deploy precisa enviar as migrações usadas pelo instalador');
installOk(str_contains($sqlHtaccess,'Require all denied'),'arquivos SQL enviados não podem ser baixados pela web');

echo "Instalador de migrações: OK\n";
