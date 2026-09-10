<?php
/** Extração de texto de PDF (relatórios de visita/OS) via smalot/pdfparser. */
require_once __DIR__ . '/os_template_service.php';

function extrairTextoPdf(string $caminhoArquivo): string
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        throw new RuntimeException('Extração de PDF indisponível: dependência não instalada no servidor.');
    }
    require_once $autoload;
    try {
        $parser = new \Smalot\PdfParser\Parser();
        $texto = trim($parser->parseFile($caminhoArquivo)->getText());
    } catch (Throwable $e) {
        throw new RuntimeException('Não foi possível ler o PDF enviado. Verifique se o arquivo não está corrompido.');
    }
    if ($texto === '') {
        throw new RuntimeException('O PDF não contém texto extraível — provavelmente é uma imagem escaneada. Use um PDF com texto selecionável ou preencha a OS manualmente.');
    }
    return $texto;
}

function mapearVisitaDeTextoPdf(string $texto, string $nomeArquivo): array
{
    $template = extrairTemplateOsTexto($texto);
    $basicos = dadosBasicosDaOs($template);
    return [
        'os'=>$basicos['os'], 'cliente'=>$basicos['cliente'], 'cnpj'=>$basicos['cnpj'], 'data'=>$basicos['data'],
        'vendedor'=>$basicos['vendedor'], 'contato'=>$basicos['contato'], 'telefone'=>$basicos['telefone'], 'email'=>$basicos['email'],
        'setor'=>$basicos['setor'], 'endereco'=>$basicos['endereco'], 'localidade'=>$basicos['localidade'],
        'oportunidade'=>$basicos['oportunidade'], 'resumo'=>$basicos['resumo'], 'tipo_servico'=>$basicos['tipo_servico'],
        'template'=>$template, 'template_json'=>templateOsJson($template),
        'campos_extraidos'=>contarCamposPreenchidosTemplateOs($template),
        'arquivo_original'=>mb_substr(basename($nomeArquivo),0,240),
    ];
}
