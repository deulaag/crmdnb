<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/os_template_service.php';

function v37File(string $path): string
{
    global $root;
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) { fwrite(STDERR, "FALHA: arquivo {$path} não encontrado\n"); exit(1); }
    return $content;
}
function v37Ok(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FALHA: {$message}\n"); exit(1); }
}

$texto = <<<'TXT'
TEMPLATE: ORDEM DE SERVIÇO DE CAMPO SIMPLIFICADA
OS: 7788
CÓD. CHECKLIST (SERVIÇO): CK-12
DATA ENVIO OS SISTEMA: 10/09/2026
IDENTIFICAÇÃO DO CLIENTE
ENDEREÇO: Rua da Fábrica, 100
NOME/CPNJ: Indústria Exemplo Ltda 12.345.678/0001-90
PESSOA DE CONTATO: Maria Compras
TELEFONE 1: 11999998888
EMAIL: maria@example.test
IDENTIFICAÇÃO DA LOCALIDADE: Planta A
DETALHAMENTO DO SERVIÇO
PROJETO/CONTRATO: Projeto Ar 2026
FORMULÁRIO DE SERVIÇO: Preventiva
PROFISSIONAIS: Técnico Um
DESCRIÇÃO DA ATIVIDADE: Inspeção completa
com verificação da rede de ar.
TIPO DE SERVIÇO: Manutenção preventiva
EVENTOS EM CAMPO
HORA: 08:00
EVENTO: Chegada e bloqueio da máquina
TIPO: Segurança
HORA: 09:10
EVENTO: Medição de pressão
TIPO: Diagnóstico
TOTAIS
POR PROFISSIONAL: 4h
TOTAL HH: 4h
Tempo total de trabalho: 3h
Tempo total de deslocamento: 1h
ITENS VERIFICADOS
0-INFORMAÇÃO DO CLIENTE
Informações do cliente: Operação 24h
Respónsavel: João Planta
Nº Contato: 1133334444
Email: joao@example.test
Setor: Utilidades
Local da Instalação: Casa de máquinas
Vendedor: Vendedor Teste
1- CONDIÇÕES GERAIS DA INSTALAÇÃO
Equipamento está operando normalmente?: Sim
A instalação está conforme recomendação do fabricante?: Parcial
A instalação possui secador de ar?: Sim
O secador de ar está funcionando corretamente?: Não
A instalação possui reservatório de ar? reservatório possui dreno?: Sim
Sai água pelo dreno do reservatório?: Sim
A instalação possui filtros de ar?: Sim
Os filtros possuem dreno?: Sim
Fotos: 4
Detalhes foto 1: Compressor principal
Detalhes foto 2: Secador
Detalhes foto 3: Reservatório
Detalhes foto 4: Filtro
Relatório descritivo: Foi identificada queda de pressão
na linha principal durante pico de produção.
2-ORDEM DE INSTALAÇÃO DOS EQUIPAMENTOS
COMPRESSOR: Primeiro
SECADOR DE AR: Segundo
3-DADOS TÉCNICOS DOS EQUIPAMENTOS E FOTOGRAFIAS GERAIS.
Compressores: IR R-Series
Comentário: Comentário técnico exclusivo do equipamento
Fabricante: Ingersoll Rand
Modelos: R11
Numero de serie: SN-123
Horimetro (horas totais): 12000
Registro de manutenção existente?: Sim
Cliente utiliza peças originais?: Sim
Tipo de óleo utilizado: Ultra Coolant
4-ABORDAGEM COMERCIAL - MANUTENÇÃO PREVENTIVA
Indicador de Manutenção preventiva: Alto
Foi apresentado ao cliente o plano de manutenção preventiva (1x ao ano)?: Sim
Cliente demonstrou interesse?: Sim
5-PERCEPÇÃO DO CLIENTE - PROBLEMAS COMUNS
Informação da Oportunidade: Cliente quer reduzir perdas
Presença excessiva de água na linha de ar: Sim
Contaminação por óleo no ar comprimido: Não
Paradas frequentes ou falhas inesperadas: Sim
Queda de pressão durante a operação: Sim
Consumo elevado de energia: Sim
Ruídos ou vibrações anormais: Não
Vazamentos frequentes na rede: Sim
Dificuldade em manter qualidade do ar: Sim
Perda de produção por falha do sistema: Sim
Manutenção preventiva realizada regularmente: Não
6-OPORTUNIDADES IDENTIFICADAS
Oportunidade Comercial: Contrato de preventiva anual
Comentário: Comentário comercial exclusivo da oportunidade
Relatório de Visita Técnica: Recomenda-se revisão completa
Próximos Passos: Enviar proposta em 3 dias
Assinatura do Vendedor: Vendedor Teste
TXT;

$extraido = extrairTemplateOsTexto($texto);
v37Ok(($extraido['os'] ?? '') === '7788', 'deve extrair o número da OS');
v37Ok(str_contains((string)($extraido['descricao_atividade'] ?? ''), 'verificação da rede de ar'), 'deve preservar valores multilinha');
v37Ok(($extraido['equipamento_comentario'] ?? '') === 'Comentário técnico exclusivo do equipamento', 'comentário técnico não pode ser confundido');
v37Ok(($extraido['oportunidade_comentario'] ?? '') === 'Comentário comercial exclusivo da oportunidade', 'comentário comercial não pode ser confundido');
v37Ok(count($extraido['eventos_campo'] ?? []) === 2, 'deve extrair múltiplos eventos em campo');
v37Ok(str_contains((string)($extraido['_raw_text'] ?? ''), 'TEMPLATE: ORDEM DE SERVIÇO DE CAMPO SIMPLIFICADA'), 'deve preservar o texto integral extraído');
$basicos = dadosBasicosDaOs($extraido);
v37Ok(($basicos['cnpj'] ?? '') === '12.345.678/0001-90', 'deve extrair CNPJ do campo NOME/CPNJ');
v37Ok(($basicos['contato'] ?? '') === 'Maria Compras', 'deve extrair contato da OS');

$visitas = v37File('visitas.php');
$pdfStorage = v37File('includes/os_pdf_storage.php');
$visitOpportunity = v37File('includes/visit_opportunity_service.php');
$opportunity = v37File('oportunidade.php');
$servePdf = v37File('arquivo-oportunidade.php');
$painel = v37File('painel.php');
$tasks = v37File('includes/task_service.php');
$taskApi = v37File('api/v1/tasks.php');
$js = v37File('assets/crm-v36.js');
$gitignore = v37File('.gitignore');

v37Ok(str_contains($visitas, '>Ler PDF</button>'), 'Visitas deve manter ação explícita Ler PDF');
v37Ok(str_contains($visitas, 'armazenarPdfOsUpload'), 'Ler PDF deve preservar o arquivo original');
v37Ok(str_contains($visitas, 'pdf_token'), 'anexo pendente deve ser vinculado por token de sessão');
v37Ok(str_contains($visitOpportunity, 'visit_pdf_storage_name'), 'oportunidade deve receber metadados do PDF da visita');
v37Ok(str_contains($pdfStorage, "move_uploaded_file"), 'PDF original deve ser movido para armazenamento privado');
v37Ok(str_contains($gitignore, 'storage/os-pdfs/*'), 'arquivos reais de OS não podem entrar no Git');
v37Ok(str_contains($servePdf, 'buscarLeadAutorizado'), 'download/visualização do PDF deve exigir acesso à oportunidade');
v37Ok(str_contains($servePdf, "Cache-Control: private, no-store"), 'PDF autenticado não deve ser cacheado publicamente');
v37Ok(str_contains($opportunity, '/arquivo-oportunidade.php?id='), 'página da oportunidade deve expor o anexo autorizado');
v37Ok(str_contains($opportunity, 'Texto integral extraído do PDF'), 'oportunidade deve permitir consultar o texto integral da OS');
$contratoOs = gruposTemplateOsSimplificada();
$camposContratoOs = array_merge(...array_map(static fn(array $g): array => $g['campos'], $contratoOs));
v37Ok(in_array('equipamento_comentario', $camposContratoOs, true), 'contrato da página deve incluir comentário técnico');
v37Ok(in_array('oportunidade_comentario', $camposContratoOs, true), 'contrato da página deve incluir comentário comercial');
v37Ok(str_contains($opportunity, 'gruposTemplateOsSimplificada()'), 'página deve renderizar os comentários e demais campos pelo contrato compartilhado');

v37Ok(str_contains($painel, 'data-v36-task-board'), 'Kanban de tarefas deve viver no Painel');
v37Ok(!str_contains($painel, 'Tarefas dos orçamentos'), 'lista antiga de tarefas não deve permanecer');
foreach (['Equipamento','Serviço e Locação','Peça e Revenda'] as $funil) v37Ok(str_contains($painel, $funil), "filtro deve conter {$funil}");
foreach (['Criadas primeiro','Últimos 7 dias','Todo o período'] as $periodo) v37Ok(str_contains($painel, $periodo), "filtro deve conter {$periodo}");
v37Ok(str_contains($painel, 'data-task-view="kanban"') && str_contains($painel, 'data-task-view="list"'), 'deve existir alternância Kanban/Lista');
v37Ok(str_contains($tasks, "['equipamentos','servicos','pecas']"), 'backend das tarefas deve aceitar somente os três funis comerciais');
v37Ok(str_contains($taskApi, 'period_sort'), 'API deve receber filtro combinado de período/ordem');
v37Ok(str_contains($js, "localStorage.getItem('dnb-task-view')"), 'preferência Kanban/Lista deve ser mantida no navegador');
v37Ok(str_contains($js, "setData('text/task-id'"), 'Kanban deve suportar drag-and-drop das tarefas');

foreach (['Propostas emitidas','Revenda faturada','Agenciadas (intermediado)','Visitas + prospecções'] as $kpi) v37Ok(str_contains($painel, $kpi), "Visão geral deve conter {$kpi}");
v37Ok(str_contains($painel, 'Ganhos / comissão apurada'), 'Visão geral deve mostrar a apuração de ganhos');
v37Ok(str_contains($painel, "\$calc['porPessoa']"), 'Visão geral deve derivar dos mesmos dados por pessoa do motor de apuração');
v37Ok(is_file($root . '/sql/migrations/20260910_os_pdf_taskboard_metrics.sql'), 'migração do PDF da OS deve existir');

echo "CRM OS + Taskboard v3.7: OK\n";
