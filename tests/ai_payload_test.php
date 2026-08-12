<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/ai_client.php';

function confirmar(bool $condicao, string $mensagem): void
{
    if (!$condicao) { fwrite(STDERR, "FALHA: {$mensagem}\n"); exit(1); }
}

$payload = [
    'contract_version'=>'1.0','correlation_id'=>'test-1','source'=>'dnb-crm-cpanel',
    'lead'=>['id'=>42,'customer'=>'Cliente Teste'],
    'request'=>['message'=>'Qualifique este lead.'],
    'requested_by'=>['id'=>7,'name'=>'Pessoa Teste','role'=>'VENDEDOR'],
];
validarPayloadIa($payload);
$resposta = normalizarRespostaIa([
    'route'=>'qualificador','response'=>'Próxima ação sugerida.','lead_score'=>['score'=>140,'justification'=>'Intenção alta'],
    'guardrail'=>['status'=>'approved'],'escalated'=>false,
]);
confirmar($resposta['score'] === 100, 'Score deve ser limitado a 100.');
confirmar($resposta['route'] === 'qualificador', 'Rota deve ser preservada.');
confirmar($resposta['guardrail_status'] === 'approved', 'Guardrail deve ser normalizado.');

$falhou = false;
try { validarPayloadIa(array_replace_recursive($payload, ['request'=>['message'=>'']])); } catch (InvalidArgumentException $e) { $falhou = true; }
confirmar($falhou, 'Payload sem mensagem deve ser rejeitado.');

echo "Contrato IA: OK\n";
