<?php
declare(strict_types=1);

function diretorioPdfOs(): string
{
    return dirname(__DIR__) . '/storage/os-pdfs';
}

function garantirDiretorioPdfOs(): string
{
    $dir = diretorioPdfOs();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        throw new RuntimeException('Não foi possível preparar o armazenamento privado dos PDFs de OS.');
    }
    return $dir;
}

/** @return array{storage_name:string,original_name:string,size:int,sha256:string} */
function armazenarPdfOsUpload(array $arquivo): array
{
    $erro = (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($erro !== UPLOAD_ERR_OK) throw new RuntimeException('Falha no upload do PDF da OS.');
    $tmp = (string) ($arquivo['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Upload de PDF inválido.');
    $size = (int) ($arquivo['size'] ?? 0);
    if ($size <= 0 || $size > 15 * 1024 * 1024) throw new RuntimeException('O PDF deve ter no máximo 15MB.');
    $original = trim((string) ($arquivo['name'] ?? 'ordem-servico.pdf')) ?: 'ordem-servico.pdf';
    if (strtolower(pathinfo($original, PATHINFO_EXTENSION)) !== 'pdf') throw new RuntimeException('O arquivo da OS deve ser PDF.');

    $inicio = file_get_contents($tmp, false, null, 0, 5);
    if ($inicio !== '%PDF-') throw new RuntimeException('O arquivo enviado não possui uma assinatura PDF válida.');
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (!in_array($mime, ['application/pdf', 'application/x-pdf', 'application/octet-stream'], true)) {
            throw new RuntimeException('O conteúdo enviado não foi reconhecido como PDF.');
        }
    }

    $storage = 'os_' . date('Ymd_His') . '_' . bin2hex(random_bytes(12)) . '.pdf';
    $destino = garantirDiretorioPdfOs() . '/' . $storage;
    if (!move_uploaded_file($tmp, $destino)) throw new RuntimeException('Não foi possível guardar o PDF original da OS.');
    @chmod($destino, 0640);
    return [
        'storage_name' => $storage,
        'original_name' => mb_substr(basename($original), 0, 240),
        'size' => $size,
        'sha256' => hash_file('sha256', $destino) ?: '',
    ];
}

function caminhoPdfOsSeguro(string $storageName): ?string
{
    $storageName = trim($storageName);
    if ($storageName === '' || basename($storageName) !== $storageName || !preg_match('/^os_[A-Za-z0-9_\-]+\.pdf$/', $storageName)) return null;
    $path = diretorioPdfOs() . '/' . $storageName;
    return is_file($path) ? $path : null;
}

function excluirPdfOsSeguro(?string $storageName): void
{
    if (!$storageName) return;
    $path = caminhoPdfOsSeguro($storageName);
    if ($path) @unlink($path);
}
