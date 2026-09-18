<?php
declare(strict_types=1);

require_once __DIR__ . '/dnb_id_service.php';

function crmCoreFase1Disponivel(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('crm_companies','crm_contacts','crm_negotiations','crm_negotiation_contacts','crm_external_links')");
        if ((int)$stmt->fetchColumn() !== 5) return $cache = false;

        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='crm_companies' AND COLUMN_NAME='dnb_id') OR (TABLE_NAME='crm_contacts' AND COLUMN_NAME='dnb_id') OR (TABLE_NAME='crm_negotiations' AND COLUMN_NAME='dnb_id') OR (TABLE_NAME='orcamentos' AND COLUMN_NAME='negotiation_id') OR (TABLE_NAME='pedidos' AND COLUMN_NAME='negotiation_id') OR (TABLE_NAME='visitas' AND COLUMN_NAME='negotiation_id'))");
        return $cache = ((int)$stmt->fetchColumn() === 6);
    } catch (Throwable) {
        return $cache = false;
    }
}

function crmCoreEmail(string $email): string
{
    return mb_strtolower(trim($email));
}

function crmCoreCnpjDigits(string $cnpj): string
{
    return preg_replace('/\D+/', '', $cnpj) ?: '';
}

function crmCoreStatusNegociacao(string $status): string
{
    $status = mb_strtolower(trim($status));
    $map = [
        'aberta'=>'open','open'=>'open',
        'ganha'=>'won','won'=>'won',
        'perdida'=>'lost','lost'=>'lost',
    ];
    return $map[$status] ?? 'open';
}

/** @return array<int,array<string,mixed>> */
function empresasDuplicadasPorCnpj(PDO $pdo, string $cnpj, ?int $ignorarId = null): array
{
    $digits = crmCoreCnpjDigits($cnpj);
    if ($digits === '' || !crmCoreFase1Disponivel($pdo)) return [];
    $sql = 'SELECT * FROM crm_companies WHERE cnpj_digits=?';
    $params = [$digits];
    if ($ignorarId !== null && $ignorarId > 0) {
        $sql .= ' AND id<>?';
        $params[] = $ignorarId;
    }
    $sql .= ' ORDER BY id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function contatosDuplicadosPorEmail(PDO $pdo, string $email, ?int $ignorarId = null): array
{
    $email = crmCoreEmail($email);
    if ($email === '' || !crmCoreFase1Disponivel($pdo)) return [];
    $sql = 'SELECT * FROM crm_contacts WHERE LOWER(TRIM(email))=?';
    $params = [$email];
    if ($ignorarId !== null && $ignorarId > 0) {
        $sql .= ' AND id<>?';
        $params[] = $ignorarId;
    }
    $sql .= ' ORDER BY id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Cria empresa sem fazer merge silencioso.
 * Quando $permitirDuplicidade=true o registro entra marcado para revisão.
 *
 * @return array<string,mixed>
 */
function criarEmpresaCrmCore(PDO $pdo, array $dados, bool $permitirDuplicidade = false): array
{
    if (!crmCoreFase1Disponivel($pdo)) {
        throw new RuntimeException('Instale a migração 20260918_crm_core_entities_phase1.sql.');
    }

    $nome = trim((string)($dados['name'] ?? $dados['nome'] ?? $dados['razao_social'] ?? ''));
    if ($nome === '') throw new InvalidArgumentException('Informe o nome da empresa.');

    $cnpj = trim((string)($dados['cnpj'] ?? ''));
    $digits = crmCoreCnpjDigits($cnpj);
    $duplicadas = $digits !== '' ? empresasDuplicadasPorCnpj($pdo, $digits) : [];
    if ($duplicadas && !$permitirDuplicidade) {
        throw new DomainException('Já existe empresa com este CNPJ. Envie para conferência em vez de mesclar automaticamente.');
    }

    $review = $duplicadas ? 'needs_review' : 'ok';
    $reason = $duplicadas ? 'CNPJ duplicado: conferir antes de mesclar' : null;
    $stmt = $pdo->prepare('INSERT INTO crm_companies (cnpj,cnpj_digits,name,trade_name,primary_email,phone,website,address,locality,status,review_status,review_reason,source) VALUES (NULLIF(?,\'\'),NULLIF(?,\'\'),?,?,?,?,?,?,?,\'active\',?,?,?)');
    $stmt->execute([
        mb_substr($cnpj,0,18),
        mb_substr($digits,0,14),
        mb_substr($nome,0,200),
        mb_substr(trim((string)($dados['trade_name'] ?? $dados['nome_fantasia'] ?? '')),0,200),
        mb_substr(crmCoreEmail((string)($dados['email'] ?? $dados['primary_email'] ?? '')),0,190),
        mb_substr(trim((string)($dados['phone'] ?? $dados['telefone'] ?? '')),0,40),
        mb_substr(trim((string)($dados['website'] ?? '')),0,255),
        mb_substr(trim((string)($dados['address'] ?? $dados['endereco'] ?? '')),0,255),
        mb_substr(trim((string)($dados['locality'] ?? $dados['localidade'] ?? '')),0,180),
        $review,
        $reason,
        mb_substr(trim((string)($dados['source'] ?? 'manual')),0,30),
    ]);
    $id = (int)$pdo->lastInsertId();
    $dnbId = garantirDnbIdRegistro($pdo,'crm_companies',$id);
    $stmt = $pdo->prepare('SELECT * FROM crm_companies WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch() ?: ['id'=>$id,'dnb_id'=>$dnbId,'name'=>$nome];
    $row['duplicate_candidates'] = array_column($duplicadas,'dnb_id');
    return $row;
}

/**
 * Cria contato. Empresa é opcional para suportar "Contatos sem empresa".
 * E-mail é o identificador principal; duplicidades exigem decisão explícita.
 *
 * @return array<string,mixed>
 */
function criarContatoCrmCore(PDO $pdo, array $dados, bool $permitirDuplicidade = false): array
{
    if (!crmCoreFase1Disponivel($pdo)) {
        throw new RuntimeException('Instale a migração 20260918_crm_core_entities_phase1.sql.');
    }
    $nome = trim((string)($dados['name'] ?? $dados['nome'] ?? ''));
    if ($nome === '') throw new InvalidArgumentException('Informe o nome do contato.');

    $email = crmCoreEmail((string)($dados['email'] ?? ''));
    $duplicados = $email !== '' ? contatosDuplicadosPorEmail($pdo,$email) : [];
    if ($duplicados && !$permitirDuplicidade) {
        throw new DomainException('Já existe contato com este e-mail. Envie para conferência em vez de mesclar automaticamente.');
    }

    $companyId = (int)($dados['company_id'] ?? 0);
    $companyId = $companyId > 0 ? $companyId : null;
    $review = $duplicados ? 'needs_review' : 'ok';
    $reason = $duplicados ? 'E-mail duplicado: conferir antes de mesclar' : null;

    $stmt = $pdo->prepare('INSERT INTO crm_contacts (company_id,name,phone,email,role_title,status,review_status,review_reason,source) VALUES (?,?,?,?,?,\'active\',?,?,?)');
    $stmt->execute([
        $companyId,
        mb_substr($nome,0,180),
        mb_substr(trim((string)($dados['phone'] ?? $dados['telefone'] ?? '')),0,40),
        mb_substr($email,0,190),
        mb_substr(trim((string)($dados['role_title'] ?? $dados['cargo'] ?? '')),0,120),
        $review,
        $reason,
        mb_substr(trim((string)($dados['source'] ?? 'manual')),0,30),
    ]);
    $id = (int)$pdo->lastInsertId();
    $dnbId = garantirDnbIdRegistro($pdo,'crm_contacts',$id);
    $stmt = $pdo->prepare('SELECT * FROM crm_contacts WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch() ?: ['id'=>$id,'dnb_id'=>$dnbId,'name'=>$nome];
    $row['duplicate_candidates'] = array_column($duplicados,'dnb_id');
    return $row;
}

/** @return array<string,mixed> */
function criarNegociacaoCrmCore(PDO $pdo, array $dados): array
{
    if (!crmCoreFase1Disponivel($pdo)) {
        throw new RuntimeException('Instale a migração 20260918_crm_core_entities_phase1.sql.');
    }

    $titulo = trim((string)($dados['title'] ?? $dados['titulo'] ?? ''));
    if ($titulo === '') throw new InvalidArgumentException('Informe o título da negociação.');

    $companyId = (int)($dados['company_id'] ?? 0);
    $companyId = $companyId > 0 ? $companyId : null;
    $userId = (int)($dados['assigned_to_user_id'] ?? 0);
    $userId = $userId > 0 ? $userId : null;
    $status = crmCoreStatusNegociacao((string)($dados['status'] ?? 'open'));

    $stmt = $pdo->prepare('INSERT INTO crm_negotiations (title,company_id,category,pipeline_name,stage_name,estimated_value,status,lost_reason,order_status,vendedor_id,assigned_to_user_id,origin,business_created_at,business_updated_at,closed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        mb_substr($titulo,0,220),
        $companyId,
        mb_substr(trim((string)($dados['category'] ?? $dados['categoria'] ?? '')),0,120),
        mb_substr(trim((string)($dados['pipeline_name'] ?? $dados['funil'] ?? '')),0,160),
        mb_substr(trim((string)($dados['stage_name'] ?? $dados['etapa'] ?? '')),0,160),
        max(0,(float)($dados['estimated_value'] ?? $dados['valor_estimado'] ?? 0)),
        $status,
        mb_substr(trim((string)($dados['lost_reason'] ?? $dados['motivo_perda'] ?? '')),0,255),
        in_array(($dados['order_status'] ?? 'none'),['none','pending','generated'],true) ? $dados['order_status'] : 'none',
        trim((string)($dados['vendedor_id'] ?? '')) ?: null,
        $userId,
        mb_substr(trim((string)($dados['origin'] ?? 'manual')),0,40),
        $dados['business_created_at'] ?? null,
        $dados['business_updated_at'] ?? null,
        $dados['closed_at'] ?? null,
    ]);
    $id = (int)$pdo->lastInsertId();
    garantirDnbIdRegistro($pdo,'crm_negotiations',$id);

    foreach ((array)($dados['contacts'] ?? []) as $contato) {
        if (!is_array($contato)) continue;
        $contactId = (int)($contato['contact_id'] ?? $contato['id'] ?? 0);
        if ($contactId <= 0) continue;
        vincularContatoNegociacaoCrm($pdo,$id,$contactId,(string)($contato['role'] ?? 'outro'),!empty($contato['is_primary']));
    }

    $stmt = $pdo->prepare('SELECT * FROM crm_negotiations WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: ['id'=>$id,'dnb_id'=>dnbIdEntidade('negotiation',$id),'title'=>$titulo];
}

function vincularContatoNegociacaoCrm(PDO $pdo, int $negotiationId, int $contactId, string $role = 'outro', bool $principal = false): void
{
    $roles = ['principal','decisor','comprador','tecnico','financeiro','outro'];
    if (!in_array($role,$roles,true)) $role='outro';
    if ($negotiationId <= 0 || $contactId <= 0) throw new InvalidArgumentException('Negociação/contato inválido.');

    if ($principal) {
        $pdo->prepare('UPDATE crm_negotiation_contacts SET is_primary=0 WHERE negotiation_id=?')->execute([$negotiationId]);
    }
    $stmt = $pdo->prepare('INSERT INTO crm_negotiation_contacts (negotiation_id,contact_id,role,is_primary) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role),is_primary=VALUES(is_primary)');
    $stmt->execute([$negotiationId,$contactId,$role,$principal?1:0]);
}

/**
 * Durante a Fase 1 o menu antigo ainda cria Orçamentos diretamente.
 * Este adaptador garante que cada novo orçamento continue íntegro no novo
 * modelo: ele recebe ORC dnb_id e uma Negociação 1:1 até a Fase 2.
 */
function garantirNegociacaoParaOrcamento(PDO $pdo, int $orcamentoId): int
{
    if ($orcamentoId <= 0 || !crmCoreFase1Disponivel($pdo)) return 0;

    garantirDnbIdRegistro($pdo,'orcamentos',$orcamentoId);
    $stmt=$pdo->prepare('SELECT * FROM orcamentos WHERE id=? LIMIT 1');
    $stmt->execute([$orcamentoId]);
    $o=$stmt->fetch();
    if(!$o) return 0;
    if(!empty($o['negotiation_id'])) return (int)$o['negotiation_id'];

    $neg=criarNegociacaoCrmCore($pdo,[
        'title'=>trim((string)($o['numero']??''))!=='' ? ((string)$o['numero'].' · '.(string)$o['cliente']) : (string)$o['cliente'],
        'company_id'=>(int)($o['company_id']??0),
        'category'=>(string)(($o['tipo']??'')!==''?$o['tipo']:($o['linha']??'')),
        'pipeline_name'=>(string)($o['funil']??''),
        'stage_name'=>(string)($o['etapa']??''),
        'estimated_value'=>(float)($o['valor']??0),
        'status'=>(string)($o['status']??'Aberta'),
        'vendedor_id'=>(string)($o['vendedor_id']??''),
        'assigned_to_user_id'=>(int)($o['assigned_to_user_id']??0),
        'origin'=>'legacy_orcamento',
        'business_created_at'=>!empty($o['data'])?((string)$o['data'].' 00:00:00'):null,
        'contacts'=>!empty($o['contact_id'])?[['contact_id'=>(int)$o['contact_id'],'role'=>'principal','is_primary'=>true]]:[],
    ]);
    $negId=(int)($neg['id']??0);
    if($negId>0){
        $pdo->prepare('UPDATE crm_negotiations SET source_orcamento_id=? WHERE id=?')->execute([$orcamentoId,$negId]);
        $pdo->prepare('UPDATE orcamentos SET negotiation_id=? WHERE id=?')->execute([$negId,$orcamentoId]);
    }
    return $negId;
}

function garantirPedidoCore(PDO $pdo, int $pedidoId, ?int $orcamentoId=null): string
{
    $dnbId=garantirDnbIdRegistro($pdo,'pedidos',$pedidoId);
    if($orcamentoId && crmCoreFase1Disponivel($pdo)){
        $negId=garantirNegociacaoParaOrcamento($pdo,$orcamentoId);
        if($negId>0){
            $pdo->prepare('UPDATE pedidos p JOIN orcamentos o ON o.id=? SET p.negotiation_id=?,p.company_id=COALESCE(p.company_id,o.company_id) WHERE p.id=?')
                ->execute([$orcamentoId,$negId,$pedidoId]);
        }
    }
    return $dnbId;
}

function garantirVisitaCore(PDO $pdo, int $visitaId): string
{
    return garantirDnbIdRegistro($pdo,'visitas',$visitaId);
}

/** @return array<string,mixed>|null */
function buscarEntidadePorDnbId(PDO $pdo, string $dnbId): ?array
{
    $dnbId = strtoupper(trim($dnbId));
    $map = [
        'EMP'=>'crm_companies',
        'CON'=>'crm_contacts',
        'NEG'=>'crm_negotiations',
        'ORC'=>'orcamentos',
        'PED'=>'pedidos',
        'VIS'=>'visitas',
    ];
    $prefix = substr($dnbId,0,3);
    if (!isset($map[$prefix])) return null;
    $table = $map[$prefix];
    $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE dnb_id=? LIMIT 1");
    $stmt->execute([$dnbId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function registrarVinculoExternoCrm(PDO $pdo, string $provider, string $externalEntity, string $externalId, string $localEntity, string $dnbId): void
{
    if (!crmCoreFase1Disponivel($pdo)) {
        throw new RuntimeException('Núcleo CRM Fase 1 indisponível.');
    }
    $provider = trim($provider);
    $externalEntity = trim($externalEntity);
    $externalId = trim($externalId);
    $localEntity = trim($localEntity);
    $dnbId = strtoupper(trim($dnbId));
    if ($provider==='' || $externalEntity==='' || $externalId==='' || $localEntity==='' || $dnbId==='') {
        throw new InvalidArgumentException('Vínculo externo incompleto.');
    }

    $stmt = $pdo->prepare('INSERT INTO crm_external_links (provider,external_entity,external_id,local_entity,dnb_id,last_sync_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE local_entity=VALUES(local_entity),dnb_id=VALUES(dnb_id),last_sync_at=NOW()');
    $stmt->execute([
        mb_substr($provider,0,40),
        mb_substr($externalEntity,0,60),
        mb_substr($externalId,0,191),
        mb_substr($localEntity,0,60),
        mb_substr($dnbId,0,24),
    ]);
}

/** @return array<string,mixed> */
function contratoCrmCoreFase1(): array
{
    return [
        'company'=>[
            'table'=>'crm_companies','dnb_prefix'=>'EMP','primary_key'=>'cnpj',
            'duplicates'=>'review','contact_relation'=>'one_to_many'
        ],
        'contact'=>[
            'table'=>'crm_contacts','dnb_prefix'=>'CON','primary_key'=>'email',
            'company_optional'=>true,'duplicates'=>'review'
        ],
        'negotiation'=>[
            'table'=>'crm_negotiations','dnb_prefix'=>'NEG','category'=>'single',
            'contacts'=>'many','quotes'=>'many','orders'=>'many','visits'=>'many'
        ],
        'quote'=>['table'=>'orcamentos','dnb_prefix'=>'ORC','belongs_to'=>'negotiation'],
        'order'=>['table'=>'pedidos','dnb_prefix'=>'PED','belongs_to'=>'negotiation'],
        'visit'=>['table'=>'visitas','dnb_prefix'=>'VIS','belongs_to'=>'company+contact+negotiation'],
        'external_ids'=>['table'=>'crm_external_links','visible_to_user'=>false],
    ];
}
