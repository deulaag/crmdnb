<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/lead_service.php';
require_once __DIR__ . '/crm_directory_service.php';
require_once __DIR__ . '/os_template_service.php';

function recursosVisitaOportunidadeDisponiveis(PDO $pdo): bool
{
    try {
        $stmt=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='orcamentos' AND COLUMN_NAME IN ('source_visit_id','visit_template_json','company_id','contact_id')) OR (TABLE_NAME='visitas' AND COLUMN_NAME IN ('template_data_json','company_id','contact_id')))");
        return (int)$stmt->fetchColumn()===7 && diretorioCrmDisponivel($pdo);
    } catch(Throwable){return false;}
}

/**
 * Cria exatamente uma oportunidade por visita e marca a visita como promovida.
 * @return int ID da oportunidade ou 0 quando a migracao v3.6 ainda nao estiver instalada.
 */
function criarOportunidadeDaVisita(PDO $pdo, int $visitaId, array $dados, array $template, ?string $vendedorId, ?int $assignedTo): int
{
    if(!recursosVisitaOportunidadeDisponiveis($pdo)) return 0;
    $stmt=$pdo->prepare('SELECT id FROM orcamentos WHERE source_visit_id=? LIMIT 1');
    $stmt->execute([$visitaId]);
    if($id=(int)$stmt->fetchColumn()) return $id;

    $dir=resolverEmpresaContatoCrm($pdo,[
        'company_name'=>(string)($dados['cliente']??''),'cnpj'=>(string)($dados['cnpj']??''),
        'address'=>(string)($dados['endereco']??''),'locality'=>(string)($dados['localidade']??''),
        'contact_name'=>(string)($dados['contato']??''),'phone'=>(string)($dados['telefone']??''),'email'=>(string)($dados['email']??''),
    ]);
    $companyId=(int)($dir['company']['id']??0) ?: null;
    $contactId=(int)($dir['contact']['id']??0) ?: null;
    $textoLinha=implode(' ',[(string)($dados['tipo_servico']??''),(string)($dados['oportunidade']??''),(string)($dados['resumo']??'')]);
    $linha=mapLinha($textoLinha);
    $funil=funilDe($linha);
    $funis=funis();
    $etapa=(string)($funis[$funil]['etapas'][0]??'Prospecção');
    $numero=trim((string)($dados['os']??''));
    $numero=$numero!==''?'OS-'.$numero:'VIS-'.$visitaId;
    $cargoId=cargoParaRegistroComercial($pdo,$assignedTo);
    $obs=trim((string)($dados['resumo']??''));
    if(($dados['oportunidade']??'')!=='') $obs=trim($obs."\nOportunidade: ".$dados['oportunidade']);

    $stmt=$pdo->prepare('INSERT INTO orcamentos (numero,data,cliente,company_id,contact_id,source_visit_id,vendedor_id,linha,tipo,funil,etapa,valor,status,origem,obs,visit_template_json,assigned_to_user_id,cargo_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        mb_substr($numero,0,40),(string)($dados['data']?:hoje()),mb_substr((string)($dados['cliente']??'Cliente da visita'),0,200),$companyId,$contactId,$visitaId,$vendedorId,
        $linha,tipoDe($linha),$funil,$etapa,0,'Aberta','visita-auto',$obs,templateOsJson($template),$assignedTo,$cargoId,
    ]);
    $leadId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE visitas SET company_id=?,contact_id=?,template_data_json=?,lead_status=? WHERE id=?')->execute([$companyId,$contactId,templateOsJson($template),'promovida',$visitaId]);
    registrarTimeline($pdo,$leadId,'created','Oportunidade criada automaticamente a partir da visita/OS #'.$visitaId.'.');
    registrarTimeline($pdo,$leadId,'visit_import','Dados completos do template de Ordem de Serviço de Campo vinculados à oportunidade.');
    return $leadId;
}
