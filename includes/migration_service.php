<?php
declare(strict_types=1);

const DNB_MIGRATION_BASELINE = [
    '20260809_crm_ia_rbac.sql',
    '20260809_followup_engine.sql',
    '20260809_whatsapp_rbac.sql',
    '20260810_crm_ia_rbac_cargos.sql',
    '20260812_followup_flows_pr3.sql',
    '20260901_orcamentos_rdstation_sync.sql',
    '20260908_perfil_humor.sql',
    '20260908_timeline_actor.sql',
    '20260909_crm_tasks.sql',
    '20260910_crm_directory_visits_taskboard.sql',
    '20260910_os_pdf_taskboard_metrics.sql',
    '20260918_crm_core_entities_phase1.sql',
    '20260918_crm_phase2_ui_ownership.sql',
    '20260918_rd_phase4_discovery_mapping.sql',
    '20260918_rd_phase5_first_sync.sql',
    '20260918_rd_phase6_incremental_webhooks.sql',
    '20260918_rd_phase8_ops_audit.sql',
    '20260918_rd_phase9_outbound_sync.sql',
];

/** @return array<string,array{nome:string,caminho:string,sha256:string}> */
function listarMigracoesDnb(): array
{
    $arquivos=glob(dirname(__DIR__).'/sql/migrations/*.sql')?:[];
    sort($arquivos,SORT_NATURAL|SORT_FLAG_CASE);
    $migracoes=[];
    foreach($arquivos as $caminho){
        $nome=basename($caminho);
        $sha=hash_file('sha256',$caminho);
        if($sha===false)throw new RuntimeException('Não foi possível validar a atualização '.$nome.'.');
        $migracoes[$nome]=['nome'=>$nome,'caminho'=>$caminho,'sha256'=>$sha];
    }
    return $migracoes;
}

function tabelaMigracoesDnbExiste(PDO $pdo): bool
{
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dnb_schema_migrations'");
    $stmt->execute();
    return (int)$stmt->fetchColumn()>0;
}

function colunaMigracaoDnbExiste(PDO $pdo,string $tabela,string $coluna): bool
{
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$tabela,$coluna]);
    return (int)$stmt->fetchColumn()>0;
}

/** Reconhece a estrutura já atualizada antes da criação do instalador automático. */
function bancoDnbCorrespondeAoBaseline(PDO $pdo): bool
{
    return colunaMigracaoDnbExiste($pdo,'visitas','pdf_storage_name')
        && colunaMigracaoDnbExiste($pdo,'crm_negotiations','created_by_user_id')
        && colunaMigracaoDnbExiste($pdo,'rd_outbound_queue','id');
}

/** @return array<string,array{nome:string,sha256:string}> */
function migracoesDnbAplicadas(PDO $pdo): array
{
    if(!tabelaMigracoesDnbExiste($pdo)){
        if(!bancoDnbCorrespondeAoBaseline($pdo))return [];
        $arquivos=listarMigracoesDnb();$aplicadas=[];
        foreach(DNB_MIGRATION_BASELINE as $nome){
            if(isset($arquivos[$nome]))$aplicadas[$nome]=['nome'=>$nome,'sha256'=>$arquivos[$nome]['sha256']];
        }
        return $aplicadas;
    }
    $rows=$pdo->query('SELECT migration_name,checksum_sha256 FROM dnb_schema_migrations ORDER BY migration_name')->fetchAll();
    $aplicadas=[];
    foreach($rows as $row)$aplicadas[(string)$row['migration_name']]=['nome'=>(string)$row['migration_name'],'sha256'=>(string)$row['checksum_sha256']];
    return $aplicadas;
}

/** @return array<string,array{nome:string,caminho:string,sha256:string}> */
function migracoesDnbPendentes(PDO $pdo): array
{
    $arquivos=listarMigracoesDnb();$aplicadas=migracoesDnbAplicadas($pdo);
    foreach($aplicadas as $nome=>$aplicada){
        if(isset($arquivos[$nome])&&$aplicada['sha256']!==$arquivos[$nome]['sha256']){
            throw new RuntimeException('A atualização já aplicada foi alterada: '.$nome.'. Crie um novo arquivo de migração.');
        }
    }
    return array_diff_key($arquivos,$aplicadas);
}

function dnbTemMigracoesPendentes(PDO $pdo): bool
{
    try{return migracoesDnbPendentes($pdo)!==[];}catch(Throwable){return true;}
}

function criarControleMigracoesDnb(PDO $pdo,bool $semearBaseline): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS dnb_schema_migrations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        migration_name VARCHAR(190) NOT NULL,
        checksum_sha256 CHAR(64) NOT NULL,
        applied_by_user_id INT DEFAULT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dnb_schema_migration_name (migration_name),
        KEY idx_dnb_schema_migration_applied_at (applied_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if(!$semearBaseline)return;
    $arquivos=listarMigracoesDnb();
    $stmt=$pdo->prepare('INSERT IGNORE INTO dnb_schema_migrations (migration_name,checksum_sha256,applied_by_user_id) VALUES (?,?,NULL)');
    foreach(DNB_MIGRATION_BASELINE as $nome){
        if(isset($arquivos[$nome]))$stmt->execute([$nome,$arquivos[$nome]['sha256']]);
    }
}

/** @return string[] */
function separarSqlMigracaoDnb(string $sql): array
{
    $blocos=[];$buffer='';$delimitador=';';
    $linhas=preg_split('/\R/u',$sql)?:[];
    foreach($linhas as $linha){
        if(preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i',$linha,$m)){
            if(trim($buffer)!==''){$blocos[]=[$buffer,$delimitador];$buffer='';}
            $delimitador=$m[1];continue;
        }
        $buffer.=$linha."\n";
    }
    if(trim($buffer)!=='')$blocos[]=[$buffer,$delimitador];

    $statements=[];
    foreach($blocos as [$bloco,$delim]){
        $inicio=0;$aspas=null;$comentarioLinha=false;$comentarioBloco=false;$tam=strlen($bloco);$tamDelim=strlen($delim);
        for($i=0;$i<$tam;$i++){
            $c=$bloco[$i];$prox=$i+1<$tam?$bloco[$i+1]:'';
            if($comentarioLinha){if($c==="\n")$comentarioLinha=false;continue;}
            if($comentarioBloco){if($c==='*'&&$prox==='/'){$comentarioBloco=false;$i++;}continue;}
            if($aspas!==null){
                if($c==='\\'){$i++;continue;}
                if($c===$aspas){if($prox===$aspas){$i++;continue;}$aspas=null;}
                continue;
            }
            if($c==="'"||$c==='"'||$c==='`'){$aspas=$c;continue;}
            if(($c==='-'&&$prox==='-'&&($i+2>=$tam||ctype_space($bloco[$i+2])))||$c==='#'){$comentarioLinha=true;continue;}
            if($c==='/'&&$prox==='*'){$comentarioBloco=true;$i++;continue;}
            if(substr($bloco,$i,$tamDelim)===$delim){
                $stmt=trim(substr($bloco,$inicio,$i-$inicio));
                if($stmt!=='')$statements[]=$stmt;
                $i+=$tamDelim-1;$inicio=$i+1;
            }
        }
        $resto=trim(substr($bloco,$inicio));
        if($resto!=='')$statements[]=$resto;
    }
    return $statements;
}

/** @return string[] nomes das migrações aplicadas */
function aplicarMigracoesDnbPendentes(PDO $pdo,int $usuarioId): array
{
    $lock=$pdo->query("SELECT GET_LOCK('dnb_crm_install',15)")->fetchColumn();
    if((int)$lock!==1)throw new RuntimeException('Outra atualização está em andamento. Aguarde alguns segundos e tente novamente.');
    try{
        $baselineExistente=!tabelaMigracoesDnbExiste($pdo)&&bancoDnbCorrespondeAoBaseline($pdo);
        criarControleMigracoesDnb($pdo,$baselineExistente);
        $pendentes=migracoesDnbPendentes($pdo);$aplicadas=[];
        $registrar=$pdo->prepare('INSERT INTO dnb_schema_migrations (migration_name,checksum_sha256,applied_by_user_id) VALUES (?,?,?)');
        foreach($pendentes as $migracao){
            $sql=file_get_contents($migracao['caminho']);
            if($sql===false)throw new RuntimeException('Não foi possível abrir '.$migracao['nome'].'.');
            foreach(separarSqlMigracaoDnb($sql) as $statement)$pdo->exec($statement);
            $registrar->execute([$migracao['nome'],$migracao['sha256'],$usuarioId]);
            $aplicadas[]=$migracao['nome'];
        }
        return $aplicadas;
    }finally{
        try{$pdo->query("SELECT RELEASE_LOCK('dnb_crm_install')");}catch(Throwable){}
    }
}
