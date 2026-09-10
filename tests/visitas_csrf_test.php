<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$pagina=file_get_contents($root.'/visitas.php');

function confirmarVisitasCsrf(bool $condicao,string $mensagem):void
{
    if(!$condicao){fwrite(STDERR,"FALHA: {$mensagem}\n");exit(1);}
}

confirmarVisitasCsrf($pagina!==false,'visitas.php deve estar acessível');
confirmarVisitasCsrf(str_contains($pagina,'exigirCsrf();'),'ações POST de visitas devem validar CSRF');
confirmarVisitasCsrf(substr_count($pagina,'<form method="post"')===substr_count($pagina,'csrfInput()'),'todos os formulários POST devem enviar CSRF');
confirmarVisitasCsrf((bool)preg_match('/if\s*\(\s*\$acao\s*===\s*[\'\"]importar_pdf_preview[\'\"]\s*\)/',$pagina),'leitura de PDF deve existir como ação dedicada');
confirmarVisitasCsrf(str_contains($pagina,'extrairConteudoPdfOs'),'leitura de PDF deve usar extração completa por texto e coordenadas');
confirmarVisitasCsrf(str_contains($pagina,'Nenhuma visita ou oportunidade foi criada'),'leitura não deve persistir registros antes da confirmação');
confirmarVisitasCsrf(str_contains($pagina,'Registrar visita e abrir oportunidade'),'deve existir confirmação explícita para persistir');
echo "Visitas CSRF/PDF: OK\n";
