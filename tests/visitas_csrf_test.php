<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pagina = file_get_contents($root . '/visitas.php');

function confirmarVisitasCsrf(bool $condicao, string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR, "FALHA: {$mensagem}\n"); exit(1); }
}

confirmarVisitasCsrf($pagina !== false, 'visitas.php deve estar acessível');
confirmarVisitasCsrf(str_contains($pagina, 'exigirCsrf();'), 'ações POST de visitas devem validar CSRF');
confirmarVisitasCsrf(substr_count($pagina, '<form method="post"') === substr_count($pagina, 'csrfInput()'), 'todos os formulários POST devem enviar CSRF');
confirmarVisitasCsrf(str_contains($pagina, "acao === 'importar_pdf_preview'"), 'importação de PDF deve existir como ação dedicada');
confirmarVisitasCsrf(str_contains($pagina, 'extrairTextoPdf'), 'importação de PDF deve usar o serviço de extração de texto');
echo "Visitas CSRF/PDF: OK\n";
