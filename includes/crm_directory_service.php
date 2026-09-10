<?php
declare(strict_types=1);

function diretorioCrmDisponivel(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('crm_companies','crm_contacts')");
        return $ok = ((int)$stmt->fetchColumn() === 2);
    } catch (Throwable) {
        return $ok = false;
    }
}

function normalizarCnpj(string $cnpj): string
{
    $digits = preg_replace('/\D+/', '', $cnpj) ?: '';
    if ($digits === '') return '';
    if (strlen($digits) !== 14) return $digits;
    return substr($digits,0,2).'.'.substr($digits,2,3).'.'.substr($digits,5,3).'/'.substr($digits,8,4).'-'.substr($digits,12,2);
}

function cnpjSomenteDigitos(string $cnpj): string
{
    return preg_replace('/\D+/', '', $cnpj) ?: '';
}

/** @return array<string,mixed>|null */
function buscarEmpresaCrm(PDO $pdo, string $nome = '', string $cnpj = ''): ?array
{
    if (!diretorioCrmDisponivel($pdo)) return null;
    $nome = trim($nome); $digits = cnpjSomenteDigitos($cnpj);
    if ($digits !== '') {
        $stmt = $pdo->prepare("SELECT * FROM crm_companies WHERE REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','')=? LIMIT 1");
        $stmt->execute([$digits]);
        if ($row = $stmt->fetch()) return $row;
    }
    if ($nome !== '') {
        $stmt = $pdo->prepare('SELECT * FROM crm_companies WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 1');
        $stmt->execute([$nome]);
        if ($row = $stmt->fetch()) return $row;
    }
    return null;
}

/** @return array<int,array<string,mixed>> */
function pesquisarEmpresasCrm(PDO $pdo, string $termo, int $limite = 8): array
{
    if (!diretorioCrmDisponivel($pdo)) return [];
    $termo = trim($termo);
    if ($termo === '') return [];
    $digits = cnpjSomenteDigitos($termo);
    $like = '%' . $termo . '%';
    $limite = max(1,min(20,$limite));
    if ($digits !== '') {
        $stmt = $pdo->prepare("SELECT * FROM crm_companies WHERE REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','') LIKE ? OR name LIKE ? ORDER BY name LIMIT {$limite}");
        $stmt->execute(['%'.$digits.'%',$like]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM crm_companies WHERE name LIKE ? OR trade_name LIKE ? ORDER BY name LIMIT {$limite}");
        $stmt->execute([$like,$like]);
    }
    return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function contatosEmpresaCrm(PDO $pdo, int $companyId): array
{
    if (!diretorioCrmDisponivel($pdo) || $companyId <= 0) return [];
    $stmt = $pdo->prepare('SELECT * FROM crm_contacts WHERE company_id=? ORDER BY name,id');
    $stmt->execute([$companyId]);
    return $stmt->fetchAll();
}

/** @return array<string,mixed> */
function salvarEmpresaCrm(PDO $pdo, string $nome, string $cnpj = '', string $endereco = '', string $localidade = ''): array
{
    $nome = trim($nome); $cnpj = normalizarCnpj($cnpj);
    if ($nome === '') throw new InvalidArgumentException('Informe o nome da empresa.');
    if (!diretorioCrmDisponivel($pdo)) return ['id'=>0,'name'=>$nome,'cnpj'=>$cnpj,'address'=>$endereco,'locality'=>$localidade];
    $existente = buscarEmpresaCrm($pdo,$nome,$cnpj);
    if ($existente) {
        $novoNome = $nome !== '' ? $nome : (string)$existente['name'];
        $novoCnpj = $cnpj !== '' ? $cnpj : (string)($existente['cnpj']??'');
        $novoEndereco = trim($endereco) !== '' ? trim($endereco) : (string)($existente['address']??'');
        $novaLocalidade = trim($localidade) !== '' ? trim($localidade) : (string)($existente['locality']??'');
        $pdo->prepare('UPDATE crm_companies SET name=?,cnpj=NULLIF(?,\'\'),address=?,locality=? WHERE id=?')
            ->execute([mb_substr($novoNome,0,200),mb_substr($novoCnpj,0,18),mb_substr($novoEndereco,0,255),mb_substr($novaLocalidade,0,180),(int)$existente['id']]);
        return array_merge($existente,['name'=>$novoNome,'cnpj'=>$novoCnpj,'address'=>$novoEndereco,'locality'=>$novaLocalidade]);
    }
    $pdo->prepare('INSERT INTO crm_companies (cnpj,name,address,locality) VALUES (NULLIF(?,\'\'),?,?,?)')
        ->execute([mb_substr($cnpj,0,18),mb_substr($nome,0,200),mb_substr(trim($endereco),0,255),mb_substr(trim($localidade),0,180)]);
    return ['id'=>(int)$pdo->lastInsertId(),'name'=>$nome,'cnpj'=>$cnpj,'address'=>$endereco,'locality'=>$localidade];
}

/** @return array<string,mixed>|null */
function salvarContatoCrm(PDO $pdo, int $companyId, string $nome, string $telefone = '', string $email = '', string $cargo = ''): ?array
{
    $nome = trim($nome); $telefone = trim($telefone); $email = trim($email); $cargo = trim($cargo);
    if ($nome === '' || $companyId <= 0 || !diretorioCrmDisponivel($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM crm_contacts WHERE company_id=? AND LOWER(TRIM(name))=LOWER(TRIM(?)) ORDER BY id LIMIT 1');
    $stmt->execute([$companyId,$nome]);
    if ($row = $stmt->fetch()) {
        $pdo->prepare('UPDATE crm_contacts SET phone=?,email=?,role_title=? WHERE id=?')->execute([
            $telefone !== '' ? mb_substr($telefone,0,40) : (string)$row['phone'],
            $email !== '' ? mb_substr($email,0,190) : (string)$row['email'],
            $cargo !== '' ? mb_substr($cargo,0,120) : (string)$row['role_title'],
            (int)$row['id'],
        ]);
        return array_merge($row,['phone'=>$telefone ?: $row['phone'],'email'=>$email ?: $row['email'],'role_title'=>$cargo ?: $row['role_title']]);
    }
    $pdo->prepare('INSERT INTO crm_contacts (company_id,name,phone,email,role_title) VALUES (?,?,?,?,?)')
        ->execute([$companyId,mb_substr($nome,0,180),mb_substr($telefone,0,40),mb_substr($email,0,190),mb_substr($cargo,0,120)]);
    return ['id'=>(int)$pdo->lastInsertId(),'company_id'=>$companyId,'name'=>$nome,'phone'=>$telefone,'email'=>$email,'role_title'=>$cargo];
}

/** @return array{company:?array,contact:?array} */
function resolverEmpresaContatoCrm(PDO $pdo, array $dados): array
{
    $nomeEmpresa = trim((string)($dados['company_name'] ?? $dados['cliente'] ?? ''));
    $cnpj = trim((string)($dados['cnpj'] ?? ''));
    $empresa = $nomeEmpresa !== '' ? salvarEmpresaCrm($pdo,$nomeEmpresa,$cnpj,(string)($dados['address']??''),(string)($dados['locality']??'')) : null;
    $contato = null;
    if ($empresa && (int)$empresa['id'] > 0) {
        $contato = salvarContatoCrm($pdo,(int)$empresa['id'],(string)($dados['contact_name']??$dados['contato']??''),(string)($dados['phone']??''),(string)($dados['email']??''),(string)($dados['contact_role']??''));
    }
    return ['company'=>$empresa,'contact'=>$contato];
}
