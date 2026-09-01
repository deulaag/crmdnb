<?php
/**
 * Extração de texto de PDF (relatórios de visita/OS) via smalot/pdfparser.
 */

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
        throw new RuntimeException('O PDF não contém texto extraível — provavelmente é uma imagem escaneada. Preencha manualmente ou use um arquivo com texto selecionável.');
    }

    return $texto;
}

function mapearVisitaDeTextoPdf(string $texto, string $nomeArquivo): array
{
    $campo = function (string $regex, string $fallback = '') use ($texto): string {
        return preg_match($regex, $texto, $m) ? trim($m[1]) : $fallback;
    };

    $os = $campo('/\bOS\b[:\s]*([A-Za-z0-9\-\/]+)/i');
    $cliente = $campo('/(?:Cliente|Raz[aã]o\s*Social|Empresa)[:\s]+([^\n\r]+)/i', pathinfo($nomeArquivo, PATHINFO_FILENAME));
    $data = $campo('/(?:Data(?:\s*da\s*Visita)?|Emiss[aã]o)[:\s]+(\d{2}[\/\-]\d{2}[\/\-]\d{4}|\d{4}[\/\-]\d{2}[\/\-]\d{2})/i');
    if ($data !== '') {
        if (preg_match('#^(\d{2})[/\-](\d{2})[/\-](\d{4})$#', $data, $m)) {
            $data = "{$m[3]}-{$m[2]}-{$m[1]}";
        } elseif (preg_match('#^(\d{4})[/\-](\d{2})[/\-](\d{2})$#', $data, $m)) {
            $data = "{$m[1]}-{$m[2]}-{$m[3]}";
        } else {
            $data = '';
        }
    }
    $vendedor = $campo('/(?:Consultor|Vendedor|Respons[aá]vel|T[eé]cnico)[:\s]+([^\n\r]+)/i');
    $contato = $campo('/Contato[:\s]+([^\n\r]+)/i');
    $setor = $campo('/Setor[:\s]+([^\n\r]+)/i');
    $oportunidade = $campo('/(?:Oportunidade|Necessidade(?:s)?\s*identificada(?:s)?|Relat[oó]rio|Diagn[oó]stico)[:\s]+([^\n\r]+)/i');
    if ($oportunidade === '') {
        $oportunidade = mb_substr($texto, 0, 1000);
    }

    return [
        'os' => $os,
        'cliente' => $cliente,
        'data' => $data,
        'vendedor' => $vendedor,
        'contato' => $contato,
        'setor' => $setor,
        'oportunidade' => $oportunidade,
    ];
}
