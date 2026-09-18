<?php
declare(strict_types=1);

/**
 * Identificador público interno do CRM DNB.
 *
 * O dnb_id é a identidade estável da entidade dentro do nosso domínio.
 * IDs externos (RD Station etc.) nunca substituem este valor.
 */
function dnbIdPrefixos(): array
{
    return [
        'company' => 'EMP',
        'contact' => 'CON',
        'negotiation' => 'NEG',
        'quote' => 'ORC',
        'order' => 'PED',
        'visit' => 'VIS',
    ];
}

function formatarDnbId(string $prefixo, int $id): string
{
    $prefixo = strtoupper(trim($prefixo));
    if (!preg_match('/^[A-Z]{3}$/', $prefixo)) {
        throw new InvalidArgumentException('Prefixo de dnb_id inválido.');
    }
    if ($id <= 0) {
        throw new InvalidArgumentException('ID interno inválido para gerar dnb_id.');
    }
    return $prefixo . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

function dnbIdEntidade(string $entidade, int $id): string
{
    $prefixos = dnbIdPrefixos();
    if (!isset($prefixos[$entidade])) {
        throw new InvalidArgumentException('Entidade sem prefixo dnb_id.');
    }
    return formatarDnbId($prefixos[$entidade], $id);
}

/**
 * Garante dnb_id para um registro recém-criado ou legado.
 * Tabela e prefixo são whitelists para impedir SQL dinâmico arbitrário.
 */
function garantirDnbIdRegistro(PDO $pdo, string $tabela, int $id): string
{
    $mapa = [
        'crm_companies' => 'EMP',
        'crm_contacts' => 'CON',
        'crm_negotiations' => 'NEG',
        'orcamentos' => 'ORC',
        'pedidos' => 'PED',
        'visitas' => 'VIS',
    ];
    if (!isset($mapa[$tabela])) {
        throw new InvalidArgumentException('Tabela não habilitada para dnb_id.');
    }
    if ($id <= 0) {
        throw new InvalidArgumentException('Registro inválido para dnb_id.');
    }

    $stmt = $pdo->prepare("SELECT dnb_id FROM `{$tabela}` WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $atual = trim((string)($stmt->fetchColumn() ?: ''));
    if ($atual !== '') return $atual;

    $dnbId = formatarDnbId($mapa[$tabela], $id);
    $pdo->prepare("UPDATE `{$tabela}` SET dnb_id=? WHERE id=? AND (dnb_id IS NULL OR dnb_id='')")
        ->execute([$dnbId, $id]);
    return $dnbId;
}

/** @return array<string,string> */
function contratoDnbIds(): array
{
    return [
        'crm_companies' => 'EMP-000001',
        'crm_contacts' => 'CON-000001',
        'crm_negotiations' => 'NEG-000001',
        'orcamentos' => 'ORC-000001',
        'pedidos' => 'PED-000001',
        'visitas' => 'VIS-000001',
    ];
}
