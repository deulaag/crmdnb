<?php
declare(strict_types=1);

final class DnbAuditPdf
{
    private float $w=841.89;
    private float $h=595.28;
    /** @var string[] */ private array $pages=[];
    private int $page=-1;

    public function addPage(): void{$this->pages[]='';$this->page=count($this->pages)-1;}
    private function out(string $cmd): void{if($this->page<0)$this->addPage();$this->pages[$this->page].=$cmd."\n";}
    private function rgb(string $hex): array{$hex=ltrim($hex,'#');return [hexdec(substr($hex,0,2))/255,hexdec(substr($hex,2,2))/255,hexdec(substr($hex,4,2))/255];}
    private function enc(string $s): string{$v=@iconv('UTF-8','Windows-1252//TRANSLIT',$s);if($v===false)$v=$s;return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$v);}
    public function rect(float $x,float $top,float $w,float $h,string $fill,string $stroke=''): void
    {
        [$r,$g,$b]=$this->rgb($fill);$y=$this->h-$top-$h;$cmd=sprintf('q %.3F %.3F %.3F rg',$r,$g,$b);
        if($stroke!==''){[$sr,$sg,$sb]=$this->rgb($stroke);$cmd.=sprintf(' %.3F %.3F %.3F RG 0.7 w',$sr,$sg,$sb);}
        $cmd.=sprintf(' %.2F %.2F %.2F %.2F re %s Q',$x,$y,$w,$h,$stroke!==''?'B':'f');$this->out($cmd);
    }
    public function line(float $x1,float $top1,float $x2,float $top2,string $color='#dbe4ee'): void
    {[$r,$g,$b]=$this->rgb($color);$this->out(sprintf('q %.3F %.3F %.3F RG 0.6 w %.2F %.2F m %.2F %.2F l S Q',$r,$g,$b,$x1,$this->h-$top1,$x2,$this->h-$top2));}
    public function text(float $x,float $top,string $text,float $size=9,bool $bold=false,string $color='#243447'): void
    {[$r,$g,$b]=$this->rgb($color);$font=$bold?'F2':'F1';$y=$this->h-$top-$size;$this->out(sprintf('BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET',$font,$size,$r,$g,$b,$x,$y,$this->enc($text)));}
    public function fit(string $text,int $max): string{if(mb_strlen($text)<=$max)return $text;return rtrim(mb_substr($text,0,max(1,$max-1))).'…';}
    public function bytes(): string
    {
        if(!$this->pages)$this->addPage();
        $objs=[1=>'',2=>'',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
        $pageIds=[];$next=5;
        foreach($this->pages as $content){$contentId=$next++;$pageId=$next++;$objs[$contentId]="<< /Length ".strlen($content)." >>\nstream\n{$content}endstream";$objs[$pageId]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$this->w} {$this->h}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";$pageIds[]=$pageId;}
        $kids=implode(' ',array_map(static fn($id)=>$id.' 0 R',$pageIds));$objs[2]='<< /Type /Pages /Kids [ '.$kids.' ] /Count '.count($pageIds).' >>';$objs[1]='<< /Type /Catalog /Pages 2 0 R >>';ksort($objs);
        $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offset=[0];
        foreach($objs as $id=>$body){$offset[$id]=strlen($pdf);$pdf.=$id." 0 obj\n".$body."\nendobj\n";}
        $xref=strlen($pdf);$max=max(array_keys($objs));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($i=1;$i<=$max;$i++)$pdf.=sprintf("%010d 00000 n \n",$offset[$i]??0);
        $pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";return $pdf;
    }
}

function auditPdfMoney(float $v): string{return 'R$ '.number_format($v,2,',','.');}
function auditPdfPct(float $real,float $meta): string{return $meta>0?number_format(($real/$meta)*100,0,',','.').'%':'—';}

/** @param array<string,mixed> $r */
function gerarPdfAuditoriaPainel(array $r): string
{
    $pdf=new DnbAuditPdf();$navy='#003060';$blue='#0f5a92';$ink='#243447';$muted='#60758a';$soft='#f4f7fa';$border='#dbe4ee';$green='#2f855a';
    $header=function(string $secao='')use($pdf,$navy,$muted):void{$pdf->rect(0,0,841.89,46,$navy);$pdf->text(28,12,'DNB EQUIPAMENTOS',15,true,'#ffffff');$pdf->text(28,29,'CRM Comercial · Auditoria',8,false,'#d7e7f6');if($secao!=='')$pdf->text(620,17,$secao,9,true,'#ffffff');};
    $footer=function()use($pdf,$muted):void{$pdf->line(28,566,814,566,'#dbe4ee');$pdf->text(28,571,'Documento gerado pelo DNB CRM · uso interno',7,false,$muted);$pdf->text(668,571,date('d/m/Y H:i'),7,false,$muted);};
    $newPage=function(string $secao='')use($pdf,$header):void{$pdf->addPage();$header($secao);};
    $newPage('Relatório mensal');$pdf->text(28,66,'Relatório do Painel · '.(string)$r['rotulo'],19,true,$navy);$pdf->text(28,91,'Consolidação mensal de propostas, faturamento, atividades e ganhos da equipe.',9,false,$muted);
    $s=$r['resumo'];$cards=[['Propostas emitidas',(float)$s['propostas'],(float)$s['metaPropostas'],'money'],['Revenda faturada',(float)$s['revenda'],(float)$s['metaRevenda'],'money'],['Agenciadas',(float)$s['agenciadas'],(float)$s['metaAgenciadas'],'money'],['Visitas + prospecções',(float)$s['atividade'],(float)$s['metaAtividade'],'number']];
    $x=28;foreach($cards as [$label,$real,$meta,$tipo]){$pdf->rect($x,118,188,84,$soft,$border);$pdf->text($x+12,130,$label,8,true,$muted);$valor=$tipo==='money'?auditPdfMoney($real):(string)(int)$real;$pdf->text($x+12,150,$valor,15,true,$navy);$metaTxt=$meta>0?('Meta: '.($tipo==='money'?auditPdfMoney($meta):(string)(int)$meta)):'Meta não definida';$pdf->text($x+12,178,$metaTxt,7,false,$muted);if($meta>0)$pdf->text($x+132,178,auditPdfPct($real,$meta),8,true,$real>=$meta?$green:$blue);$x+=198;}
    $pdf->rect(28,216,786,58,'#eef4f9',$border);$pdf->text(42,229,'Ganhos / comissão apurada',8,true,$muted);$pdf->text(42,247,auditPdfMoney((float)$s['ganhos']),18,true,$navy);$pdf->text(280,247,'Propostas: '.(int)$r['contagens']['propostas'].'  ·  Revenda: '.(int)$r['contagens']['revenda'].'  ·  Agenciadas: '.(int)$r['contagens']['agenciadas'].'  ·  Atividades: '.(int)$r['contagens']['atividade'],9,false,$ink);

    $table=function(string $titulo,array $headers,array $rows,array $widths,int $maxRowsPerPage=18)use($pdf,$newPage,$footer,$navy,$soft,$border,$muted,$ink):void{
        $idx=0;$count=count($rows);do{$newPage($titulo);$pdf->text(28,66,$titulo,16,true,$navy);$top=96;$x=28;foreach($headers as $i=>$h){$pdf->rect($x,$top,$widths[$i],24,$navy);$pdf->text($x+5,$top+7,$h,7,true,'#ffffff');$x+=$widths[$i];}$top+=24;$end=min($idx+$maxRowsPerPage,$count);if($count===0){$pdf->rect(28,$top,array_sum($widths),34,$soft,$border);$pdf->text(38,$top+11,'Nenhum registro neste mês.',8,false,$muted);}else{for(;$idx<$end;$idx++){$row=$rows[$idx];$x=28;$fill=$idx%2===0?'#ffffff':$soft;foreach($headers as $i=>$h){$pdf->rect($x,$top,$widths[$i],25,$fill,$border);$txt=(string)($row[$i]??'');$chars=max(5,(int)floor($widths[$i]/5.3));$pdf->text($x+5,$top+8,$pdf->fit($txt,$chars),7,false,$ink);$x+=$widths[$i];}$top+=25;}}$footer();}while($idx<$count);
    };

    $rows=[];foreach($r['orcamentos'] as $o)$rows[]=[date('d/m/Y',strtotime((string)$o['data'])),(string)($o['numero']?:'#'.$o['id']),(string)$o['cliente'],(string)$o['_responsavel'],(string)($o['linha']??''),(string)($o['etapa']??''),(string)($o['status']??''),auditPdfMoney((float)$o['valor'])];
    $table('Oportunidades / propostas',['Data','Número','Cliente','Responsável','Linha','Etapa','Status','Valor'],$rows,[58,70,180,110,105,90,65,108],16);
    $rows=[];foreach($r['pedidos'] as $p)$rows[]=[date('d/m/Y',strtotime((string)($p['data_fat']?:$p['data']))),(string)($p['numero']?:'#'.$p['id']),(string)$p['cliente'],(string)$p['_responsavel'],ucfirst((string)($p['modalidade']??'')),(string)($p['linha']??''),auditPdfMoney((float)$p['valor'])];
    $table('Pedidos faturados',['Faturamento','Número','Cliente','Responsável','Modalidade','Linha','Valor'],$rows,[75,70,190,115,90,120,126],17);
    $rows=[];foreach($r['visitas'] as $v)$rows[]=[date('d/m/Y',strtotime((string)$v['data'])),(string)($v['os']?:'—'),(string)$v['cliente'],(string)$v['_responsavel'],($v['tipo']??'')==='prospeccao'?'Prospecção':'Visita',(string)($v['oportunidade']??'')];
    $table('Visitas e prospecções',['Data','OS','Cliente','Responsável','Tipo','Oportunidade / observação'],$rows,[68,68,185,120,90,255],17);
    $rows=[];foreach($r['pessoas'] as $p)$rows[]=[(string)$p['nome'],auditPdfMoney((float)$p['propostas']).' / '.auditPdfMoney((float)$p['meta_propostas']),auditPdfMoney((float)$p['revenda']).' / '.auditPdfMoney((float)$p['meta_revenda']),auditPdfMoney((float)$p['agenciadas']).' / '.auditPdfMoney((float)$p['meta_agenciadas']),((int)$p['visitas']+(int)$p['prospeccoes']).' / '.(int)$p['meta_atividade'],auditPdfMoney((float)$p['ganhos'])];
    $table('Apuração por colaborador',['Colaborador','Propostas / meta','Revenda / meta','Agenciadas / meta','Atividades / meta','Ganhos'],$rows,[145,145,145,145,100,106],18);
    return $pdf->bytes();
}
