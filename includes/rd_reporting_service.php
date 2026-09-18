<?php
declare(strict_types=1);

require_once __DIR__ . '/rbac.php';

function rdReportingDisponivel(PDO $pdo): bool
{
    static $cache=null;
    if($cache!==null)return $cache;
    try{
        $stmt=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_negotiations'");
        return $cache=((int)$stmt->fetchColumn()===1);
    }catch(Throwable){return $cache=false;}
}

function rdReportingMonthBounds(string $mes): array
{
    if(!preg_match('/^\d{4}-\d{2}$/',$mes))throw new InvalidArgumentException('Mês inválido.');
    $from=DateTimeImmutable::createFromFormat('!Y-m',$mes);
    if(!$from)throw new InvalidArgumentException('Mês inválido.');
    return [$from->format('Y-m-d H:i:s'),$from->modify('+1 month')->format('Y-m-d H:i:s')];
}

/**
 * Negociações do núcleo CRM movimentadas ou encerradas na competência.
 * Elas NÃO são propostas/orçamentos e nunca entram no KPI "Propostas emitidas".
 *
 * @return array<int,array<string,mixed>>
 */
function rdReportingNegotiationsMonth(PDO $pdo,string $mes,bool $respectScope=true): array
{
    if(!rdReportingDisponivel($pdo))return [];
    [$from,$to]=rdReportingMonthBounds($mes);
    [$scope,$scopeParams]=$respectScope?filtroEscopoAtribuicao($pdo,'n.assigned_to_user_id'):['1=1',[]];

    $sql="SELECT n.*,c.name company_name,u.nome owner_name,
      (SELECT COUNT(*) FROM crm_negotiation_contacts nc WHERE nc.negotiation_id=n.id) contacts_count,
      (SELECT COUNT(*) FROM orcamentos o WHERE o.negotiation_id=n.id) quotes_count,
      (SELECT COUNT(*) FROM pedidos p WHERE p.negotiation_id=n.id) orders_count
      FROM crm_negotiations n
      LEFT JOIN crm_companies c ON c.id=n.company_id
      LEFT JOIN usuarios u ON u.id=n.assigned_to_user_id
      WHERE {$scope}
        AND (
          (COALESCE(n.business_updated_at,n.business_created_at,n.created_at)>=? AND COALESCE(n.business_updated_at,n.business_created_at,n.created_at)<?)
          OR (n.closed_at>=? AND n.closed_at<?)
        )
      ORDER BY COALESCE(n.closed_at,n.business_updated_at,n.business_created_at,n.created_at) DESC,n.id DESC";
    $stmt=$pdo->prepare($sql);
    $stmt->execute(array_merge($scopeParams,[$from,$to,$from,$to]));
    return $stmt->fetchAll();
}

/** @return array<string,mixed> */
function rdReportingSummary(PDO $pdo,string $mes,bool $respectScope=true): array
{
    $items=rdReportingNegotiationsMonth($pdo,$mes,$respectScope);
    $summary=[
        'total_count'=>0,'total_value'=>0.0,
        'created_count'=>0,'created_value'=>0.0,
        'open_count'=>0,'open_value'=>0.0,
        'won_count'=>0,'won_value'=>0.0,
        'lost_count'=>0,'lost_value'=>0.0,
        'rd_count'=>0,'rd_value'=>0.0,
        'manual_count'=>0,'manual_value'=>0.0,
        'won_without_order_count'=>0,'won_without_order_value'=>0.0,
    ];
    [$from,$to]=rdReportingMonthBounds($mes);
    foreach($items as $n){
        $value=(float)($n['estimated_value']??0);
        $summary['total_count']++;$summary['total_value']+=$value;

        $created=(string)($n['business_created_at']??$n['created_at']??'');
        if($created>=$from&&$created<$to){$summary['created_count']++;$summary['created_value']+=$value;}

        $status=(string)($n['status']??'open');
        if($status==='won'){$summary['won_count']++;$summary['won_value']+=$value;}
        elseif($status==='lost'){$summary['lost_count']++;$summary['lost_value']+=$value;}
        else{$summary['open_count']++;$summary['open_value']+=$value;}

        if((string)($n['origin']??'')==='rd_crm'){$summary['rd_count']++;$summary['rd_value']+=$value;}
        else{$summary['manual_count']++;$summary['manual_value']+=$value;}

        if($status==='won'&&(int)($n['orders_count']??0)===0){
            $summary['won_without_order_count']++;$summary['won_without_order_value']+=$value;
        }
    }
    return ['items'=>$items,'summary'=>$summary];
}

/** @return array<int,array<string,mixed>> */
function rdReportingByPipeline(array $items): array
{
    $groups=[];
    foreach($items as $n){
        $pipeline=trim((string)($n['pipeline_name']??''))?:'Sem funil';
        $stage=trim((string)($n['stage_name']??''))?:'Sem etapa';
        $key=$pipeline.'\x1f'.$stage;
        if(!isset($groups[$key]))$groups[$key]=['pipeline'=>$pipeline,'stage'=>$stage,'count'=>0,'value'=>0.0,'won'=>0,'lost'=>0,'open'=>0];
        $groups[$key]['count']++;
        $groups[$key]['value']+=(float)($n['estimated_value']??0);
        $status=(string)($n['status']??'open');
        if(isset($groups[$key][$status]))$groups[$key][$status]++;
    }
    $out=array_values($groups);
    usort($out,static fn($a,$b)=>$b['value']<=>$a['value']);
    return $out;
}

function rdReportingCsvCell(mixed $value): string
{
    $s=(string)$value;
    $s=str_replace(["\r","\n"],' ',$s);
    return '"'.str_replace('"','""',$s).'"';
}

/** @param array<string,mixed> $report */
function rdReportingCsv(array $report): string
{
    $lines=[];
    $append=static function(array $row)use(&$lines):void{$lines[]=implode(';',array_map('rdReportingCsvCell',$row));};

    $append(['DNB CRM','Auditoria mensal',(string)$report['rotulo']]);
    $append(['Seção','Métrica','Quantidade','Valor']);
    $rd=$report['rd_negotiations']['summary']??[];
    $append(['Negociações','Movimentadas',(int)($rd['total_count']??0),number_format((float)($rd['total_value']??0),2,',','.')]);
    $append(['Negociações','Criadas',(int)($rd['created_count']??0),number_format((float)($rd['created_value']??0),2,',','.')]);
    $append(['Negociações','Ganhas',(int)($rd['won_count']??0),number_format((float)($rd['won_value']??0),2,',','.')]);
    $append(['Negociações','Perdidas',(int)($rd['lost_count']??0),number_format((float)($rd['lost_value']??0),2,',','.')]);
    $append(['Negociações','Ganhas sem Pedido DNB',(int)($rd['won_without_order_count']??0),number_format((float)($rd['won_without_order_value']??0),2,',','.')]);
    $append([]);

    $append(['NEGOCIAÇÕES']);
    $append(['DNB ID','Título','Empresa','Responsável','Categoria','Funil','Etapa','Status','Origem','Valor estimado','Criada em','Atualizada em','Encerrada em','Contatos','Propostas','Pedidos']);
    foreach(($report['rd_negotiations']['items']??[]) as $n){
        $append([
            $n['dnb_id']??'',$n['title']??'',$n['company_name']??'',$n['owner_name']??'',
            $n['category']??'',$n['pipeline_name']??'',$n['stage_name']??'',$n['status']??'',$n['origin']??'',
            number_format((float)($n['estimated_value']??0),2,',','.'),
            $n['business_created_at']??$n['created_at']??'',$n['business_updated_at']??'',$n['closed_at']??'',
            (int)($n['contacts_count']??0),(int)($n['quotes_count']??0),(int)($n['orders_count']??0)
        ]);
    }
    $append([]);

    $append(['PROPOSTAS / ORÇAMENTOS']);
    $append(['Data','Número','Cliente','Responsável','Linha','Etapa','Status','Valor']);
    foreach(($report['orcamentos']??[]) as $o)$append([$o['data']??'',$o['numero']??'',$o['cliente']??'',$o['_responsavel']??'',$o['linha']??'',$o['etapa']??'',$o['status']??'',number_format((float)($o['valor']??0),2,',','.')]);
    $append([]);

    $append(['PEDIDOS']);
    $append(['Data','Faturamento','Número','Cliente','Responsável','Modalidade','Linha','Valor']);
    foreach(($report['pedidos']??[]) as $p)$append([$p['data']??'',$p['data_fat']??'',$p['numero']??'',$p['cliente']??'',$p['_responsavel']??'',$p['modalidade']??'',$p['linha']??'',number_format((float)($p['valor']??0),2,',','.')]);
    $append([]);

    $append(['VISITAS / PROSPECÇÕES']);
    $append(['Data','OS','Cliente','Responsável','Tipo','Oportunidade']);
    foreach(($report['visitas']??[]) as $v)$append([$v['data']??'',$v['os']??'',$v['cliente']??'',$v['_responsavel']??'',$v['tipo']??'',$v['oportunidade']??'']);

    return "\xEF\xBB\xBF".implode("\r\n",$lines)."\r\n";
}
