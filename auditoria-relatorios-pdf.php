<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/audit_dashboard_service.php';
require_once __DIR__ . '/includes/audit_pdf_service.php';
exigirPapel(PAPEL_ADMINISTRADOR);

$mes=trim((string)($_GET['mes']??''));
if(!preg_match('/^\d{4}-\d{2}$/',$mes)){http_response_code(422);exit('Mês inválido.');}
try{$relatorio=relatorioAuditoriaPainelMes(db(),$mes);$bytes=gerarPdfAuditoriaPainel($relatorio);}catch(Throwable $e){error_log('audit.pdf: '.$e->getMessage());http_response_code(500);exit('Não foi possível gerar o PDF deste mês.');}
$nome='auditoria-painel-'.$mes.'.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="'.$nome.'"');
header('Content-Length: '.strlen($bytes));
header('Cache-Control: private, no-store, max-age=0');
echo $bytes;
