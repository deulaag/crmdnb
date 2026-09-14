<?php
declare(strict_types=1);

require_once __DIR__ . '/calc.php';

/** @return string[] meses YYYY-MM do primeiro registro comercial até o mês atual. */
function mesesAuditoriaPainel(PDO $pdo): array
{
    $datas=[];
    foreach([
        "SELECT MIN(data) FROM orcamentos WHERE data IS NOT NULL",
        "SELECT MIN(COALESCE(data_fat,data)) FROM pedidos WHERE COALESCE(data_fat,data) IS NOT NULL",
        "SELECT MIN(data) FROM visitas WHERE data IS NOT NULL",
    ] as $sql){
        try{$v=$pdo->query($sql)->fetchColumn();if($v)$datas[]=(string)$v;}catch(Throwable){}
    }
    $inicio=$datas?min($datas):date('Y-m-01');
    $inicioMes=substr($inicio,0,7);
    if(!preg_match('/^\d{4}-\d{2}$/',$inicioMes))$inicioMes=date('Y-m');
    $fim=date('Y-m');
    $cursor=DateTimeImmutable::createFromFormat('!Y-m',$inicioMes)?:new DateTimeImmutable('first day of this month');
    $limite=DateTimeImmutable::createFromFormat('!Y-m',$fim)?:new DateTimeImmutable('first day of this month');
    if($cursor>$limite)$cursor=$limite;
    $meses=[];
    while($cursor<=$limite){$meses[]=$cursor->format('Y-m');$cursor=$cursor->modify('+1 month');}
    return array_reverse($meses);
}

/** @return array<string,array<string,mixed>> */
function mapaVendedoresAuditoria(array $vendedores): array
{
    $map=[];foreach($vendedores as $v)$map[(string)$v['id']]=$v;return $map;
}

function metaRevendaAuditoria(array $v,array $d): float
{
    $pol=$d['polEfetiva']?:($v['pol']??[]);
    $meta=(float)($pol['metaRevenda']??0);
    if($meta<=0&&!empty($pol['metaPropriasAno']))$meta=(float)$pol['metaPropriasAno']/12;
    return $meta;
}

function metaAgenciadaAuditoria(array $v,array $d): float
{
    $pol=$d['polEfetiva']?:($v['pol']??[]);
    $meta=(float)($pol['metaAgenciadas']??0);
    if($meta<=0&&!empty($pol['metaAgenciadasAno']))$meta=(float)$pol['metaAgenciadasAno']/12;
    return $meta;
}

/**
 * Usa exatamente o mesmo motor do Painel/Comissões para evitar números paralelos.
 * @return array<string,mixed>
 */
function relatorioAuditoriaPainelMes(PDO $pdo,string $mes): array
{
    if(!preg_match('/^\d{4}-\d{2}$/',$mes))throw new InvalidArgumentException('Mês inválido.');
    $vendedores=obterVendedores($pdo);
    $map=mapaVendedoresAuditoria($vendedores);
    $calc=calcularMes($pdo,$mes,$vendedores);
    $resumo=['propostas'=>0.0,'metaPropostas'=>0.0,'revenda'=>0.0,'metaRevenda'=>0.0,'agenciadas'=>0.0,'metaAgenciadas'=>0.0,'atividade'=>0,'metaAtividade'=>0.0,'ganhos'=>0.0];
    $pessoas=[];
    foreach($vendedores as $v){
        $id=(string)$v['id'];$d=$calc['porPessoa'][$id]??null;if(!$d)continue;
        $pol=$d['polEfetiva']?:($v['pol']??[]);
        $metaRev=metaRevendaAuditoria($v,$d);$metaAg=metaAgenciadaAuditoria($v,$d);
        $metaAtiv=(float)($pol['visitasMes']??0)+(float)($pol['prospecMes']??0);
        $ativ=(int)($d['vis']??0)+(int)($d['prosp']??0);
        $resumo['propostas']+=(float)($d['propostas']??0);$resumo['metaPropostas']+=(float)($d['metaProp']??0);
        $resumo['revenda']+=(float)($d['revFat']??0);$resumo['metaRevenda']+=$metaRev;
        $resumo['agenciadas']+=(float)($d['agInterm']??0);$resumo['metaAgenciadas']+=$metaAg;
        $resumo['atividade']+=$ativ;$resumo['metaAtividade']+=$metaAtiv;$resumo['ganhos']+=(float)($d['total']??0);
        $pessoas[]=[
            'id'=>$id,'nome'=>(string)$v['nome'],'politica'=>(string)($v['politica']??''),
            'propostas'=>(float)($d['propostas']??0),'meta_propostas'=>(float)($d['metaProp']??0),
            'revenda'=>(float)($d['revFat']??0),'meta_revenda'=>$metaRev,
            'agenciadas'=>(float)($d['agInterm']??0),'meta_agenciadas'=>$metaAg,
            'visitas'=>(int)($d['vis']??0),'prospeccoes'=>(int)($d['prosp']??0),'meta_atividade'=>$metaAtiv,
            'ganhos'=>(float)($d['total']??0),
        ];
    }
    $nomePessoa=static fn(?string $id):string=>(string)($map[(string)$id]['nome']??'—');
    $orc=array_values($calc['orcMes']??[]);usort($orc,static fn($a,$b)=>strcmp((string)$b['data'],(string)$a['data']));
    $ped=array_values($calc['pedFatMes']??[]);usort($ped,static fn($a,$b)=>strcmp((string)($b['data_fat']??$b['data']),(string)($a['data_fat']??$a['data'])));
    $vis=array_values($calc['visMes']??[]);usort($vis,static fn($a,$b)=>strcmp((string)$b['data'],(string)$a['data']));
    foreach($orc as &$r)$r['_responsavel']=$nomePessoa($r['vendedor_id']??null);unset($r);
    foreach($ped as &$r)$r['_responsavel']=$nomePessoa($r['vendedor_id']??null);unset($r);
    foreach($vis as &$r)$r['_responsavel']=$nomePessoa($r['vendedor_id']??null);unset($r);
    $contRev=count(array_filter($ped,static fn($p)=>(string)($p['modalidade']??'')!=='agenciada'));
    $contAg=count(array_filter($ped,static fn($p)=>(string)($p['modalidade']??'')==='agenciada'));
    return [
        'mes'=>$mes,'rotulo'=>rotuloMes($mes),'resumo'=>$resumo,'pessoas'=>$pessoas,
        'orcamentos'=>$orc,'pedidos'=>$ped,'visitas'=>$vis,
        'contagens'=>['propostas'=>count($orc),'revenda'=>$contRev,'agenciadas'=>$contAg,'atividade'=>count($vis)],
    ];
}
