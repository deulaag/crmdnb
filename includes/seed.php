<?php
/**
 * Dados de referência (seed): equipe padrão e dataset de simulação (demo),
 * porte fiel de SEED_VENDEDORES / SEED_LINHAS / carregarDemo() do JS original.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crm_core_service.php';

function seedVendedoresPadrao(): array
{
    return [
        ['id' => 'v1', 'nome' => 'Hiago Azevedo', 'email' => 'hiago@dnbe.com.br', 'fone' => '75 99270-7879', 'funcao' => 'vendedor', 'vinculo_id' => null, 'orientador_id' => null, 'politica' => 'consultor_senior'],
        ['id' => 'v2', 'nome' => 'Rogerio Viana', 'email' => 'rogerio@dnbe.com.br', 'fone' => '71 98151-1860', 'funcao' => 'vendedor', 'vinculo_id' => null, 'orientador_id' => null, 'politica' => 'vendedor_ir'],
        ['id' => 'v3', 'nome' => 'Rafael Cerqueira', 'email' => 'rafael.cerqueira@dnbe.com.br', 'fone' => '71 3503-0263', 'funcao' => 'vendedor', 'vinculo_id' => null, 'orientador_id' => null, 'politica' => 'vendedor_externo'],
        ['id' => 'v4', 'nome' => 'João Fernando', 'email' => 'fernando.barbosa@dnbe.com.br', 'fone' => '71 98151-2058', 'funcao' => 'vendedor', 'vinculo_id' => null, 'orientador_id' => null, 'politica' => 'vendedor_externo'],
        ['id' => 'v5', 'nome' => 'Juliana Santos', 'email' => 'juliana.santos@dnbe.com.br', 'fone' => '71 3503-0263', 'funcao' => 'suporte', 'vinculo_id' => 'v3', 'orientador_id' => null, 'politica' => 'suporte_ir'],
        ['id' => 'v6', 'nome' => 'Marilia Abreu', 'email' => 'marilia.abreu@dnbe.com.br', 'fone' => '71 98151-2128', 'funcao' => 'suporte', 'vinculo_id' => 'v4', 'orientador_id' => null, 'politica' => 'suporte_ir'],
        ['id' => 'v7', 'nome' => 'Carlos Camerino', 'email' => 'ceacamerino@gmail.com', 'fone' => '71 99962-2079', 'funcao' => 'vendedor', 'vinculo_id' => null, 'orientador_id' => 'v1', 'politica' => 'representante'],
        ['id' => 'v9', 'nome' => 'Denis Carvalho', 'email' => '', 'fone' => '', 'funcao' => 'vendedor', 'vinculo_id' => null, 'orientador_id' => null, 'politica' => 'interno'],
        ['id' => 'v10', 'nome' => 'Felipe Maciel', 'email' => 'vendas@dnbe.com.br', 'fone' => '71 3503-0263', 'funcao' => 'suporte', 'vinculo_id' => null, 'orientador_id' => null, 'politica' => 'suporte_ir'],
        ['id' => 'v11', 'nome' => 'Raquel', 'email' => '', 'fone' => '', 'funcao' => 'suporte', 'vinculo_id' => null, 'orientador_id' => null, 'politica' => 'suporte_ir'],
    ];
}

function seedLinhasPadrao(): array
{
    return [
        'Compressores IR (agenciamento)', 'Serviço Manutenção IR', 'Locação', 'Peças IR',
        'Bombas ARO', 'Peças ARO', 'Parker Automação', 'Parker Filtração de Processos',
        'Parker Filtração GSF', 'Parker Conexões',
    ];
}

/** Restaura vendedores e linhas para o padrão de fábrica (mantém login/dados comerciais). */
function restaurarEquipePadrao(PDO $pdo): void
{
    $pres = politicas();
    $stmt = $pdo->prepare('UPDATE vendedores SET nome=?, email=?, fone=?, funcao=?, vinculo_id=?, orientador_id=?, politica=?, pol=?, data_contratacao=NULL, ativo=1 WHERE id=?');
    foreach (seedVendedoresPadrao() as $v) {
        $stmt->execute([$v['nome'], $v['email'], $v['fone'], $v['funcao'], $v['vinculo_id'], $v['orientador_id'], $v['politica'], json_encode($pres[$v['politica']]), $v['id']]);
    }
}

/** Carrega o dataset de demonstração (jul/2026) — mesmos números do simulador original. */
function carregarDadosDemo(PDO $pdo): void
{
    $M = '2026-07';
    restaurarEquipePadrao($pdo);

    $pdo->exec('DELETE FROM orcamento_cadencia_log');
    if (crmCoreFase1Disponivel($pdo)) $pdo->exec('DELETE FROM crm_negotiations');
    $pdo->exec('DELETE FROM orcamentos');
    $pdo->exec('DELETE FROM pedidos');
    $pdo->exec('DELETE FROM visitas');
    $pdo->exec('DELETE FROM apuracao');

    $insPed = $pdo->prepare('INSERT INTO pedidos (numero,data,cliente,vendedor_id,suporte_id,linha,valor,margem,modalidade,comissao_dnb,faturado,data_fat) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $P = function ($n, $d, $cli, $vid, $sid, $linha, $val, $mod, $comDNB, $fat, $dFat, $margem = null) use ($insPed) {
        $insPed->execute([$n, $d, $cli, $vid ?: null, $sid ?: null, $linha, $val, $margem, $mod, $comDNB, $fat ? 1 : 0, $fat ? $dFat : null]);
    };
    $P('PV-54435', '2026-07-03', 'Laticínios Santa Maria', 'v3', 'v5', 'Ingersoll Rand', 185000, 'revenda', 0, true, '2026-07-06');
    $P('PV-FRS-04591', '2026-07-10', 'Betânia Alvoar', 'v3', 'v5', 'Locação', 330000, 'revenda', 0, true, '2026-07-15');
    $P('PV-54440', '2026-07-08', 'Vopak', 'v4', 'v6', 'Serviços / Manutenção', 260000, 'revenda', 0, true, '2026-07-14');
    $P('PV-54443', '2026-07-11', 'Binatural', 'v4', '', 'Ingersoll Rand', 150000, 'revenda', 0, true, '2026-07-16');
    $P('AG-1021', '2026-07-05', 'Bracell', 'v4', '', 'Equipamentos', 900000, 'agenciada', 45000, true, '2026-07-18');
    $P('PV-1793', '2026-07-04', 'Petrobras', 'v9', '', 'Parker', 260000, 'revenda', 0, true, '2026-07-09');
    $P('PV-1801', '2026-07-12', 'Braskem', 'v9', '', 'Parker', 200000, 'revenda', 0, true, '2026-07-17');
    $P('PV-54438', '2026-07-06', 'Cargill Ilhéus', 'v1', '', 'Parker', 610000, 'revenda', 0, true, '2026-07-10');
    $P('AG-1022', '2026-07-08', 'Mosaic', 'v1', '', 'Equipamentos', 500000, 'agenciada', 30000, true, '2026-07-19');
    $P('AG-1030', '2026-07-07', 'Mineração Caraíba', 'v7', '', 'Equipamentos', 620000, 'agenciada', 62000, true, '2026-07-16');
    $P('PV-54462', '2026-07-12', 'Cerâmica Guanambi', 'v7', '', 'Ingersoll Rand', 90000, 'revenda', 0, true, '2026-07-18', 27000);
    $P('PV-54457', '2026-07-17', 'Fiação Itabaiana', 'v6', '', 'Peças', 6700, 'revenda', 0, true, '2026-07-20');
    $P('PV-54430', '2026-07-01', 'Atlantic Nickel', 'v2', '', 'Parker', 11300, 'revenda', 0, false, '');

    $insOrc = $pdo->prepare('INSERT INTO orcamentos (numero,data,cliente,vendedor_id,suporte_id,linha,tipo,funil,etapa,valor,comissao_dnb_estimada,status,origem) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $O = function ($n, $d, $cli, $vid, $sid, $linha, $tipo, $val, $status, $comEst = 0, $etapaForcada = null) use ($insOrc) {
        $funil = funilDe($linha);
        $funisArr = funis();
        $etapa = $etapaForcada ?: $funisArr[$funil]['etapas'][0];
        $insOrc->execute([$n, $d, $cli, $vid ?: null, $sid ?: null, $linha, $tipo, $funil, $etapa, $val, $comEst, $status, 'demo']);
    };
    $O('54435', '2026-07-02', 'Laticínios Santa Maria', 'v3', 'v5', 'Ingersoll Rand', 'Manutenção', 185000, 'Ganha');
    $O('FRS-04591', '2026-07-05', 'Betânia Alvoar', 'v3', 'v5', 'Locação', 'Locação', 330000, 'Ganha');
    $O('54455', '2026-07-15', 'Usina Santa Maria', 'v3', 'v5', 'Ingersoll Rand', 'Manutenção', 420000, 'Aberta');
    $O('54460', '2026-07-16', 'Mucambo', 'v3', '', 'Ingersoll Rand', 'Peças', 260000, 'Aberta');
    $O('54440', '2026-07-03', 'Vopak', 'v4', 'v6', 'Serviços / Manutenção', 'Manutenção', 260000, 'Ganha');
    $O('54443', '2026-07-06', 'Binatural', 'v4', '', 'Ingersoll Rand', 'Peças', 150000, 'Ganha');
    $O('AG-1021', '2026-07-02', 'Bracell', 'v4', '', 'Compressores IR (agenciamento)', 'Equipamento', 900000, 'Ganha', 45000);
    $O('54448', '2026-07-10', 'Nova Coating', 'v2', '', 'Serviços / Manutenção', 'Manutenção', 12400, 'Aberta');
    $O('211', '2026-06-26', 'Cimento Apodi', 'v9', '', 'Peças', 'Peças', 23640.26, 'Aberta');
    $O('DNB-273', '2026-07-08', 'Braskem', 'v9', '', 'Parker', 'Peças', 200000, 'Ganha');
    $O('54450', '2026-07-13', 'Barry Callebaut', 'v1', '', 'domnick hunter', 'Peças', 337000, 'Aberta');
    $O('54438', '2026-07-01', 'Cargill Ilhéus', 'v1', '', 'Parker', 'Peças', 610000, 'Ganha');
    $O('AG-1030', '2026-07-02', 'Mineração Caraíba', 'v7', '', 'Compressores IR (agenciamento)', 'Equipamento', 620000, 'Ganha', 62000);
    $O('54462', '2026-07-08', 'Cerâmica Guanambi', 'v7', '', 'Ingersoll Rand', 'Peças', 90000, 'Ganha');
    $O('54465', '2026-07-14', 'Ferbasa', 'v7', '', 'Ingersoll Rand', 'Peças', 480000, 'Aberta');
    $O('54468', '2026-07-18', 'Magnesita', 'v7', '', 'Compressores IR (agenciamento)', 'Equipamento', 700000, 'Aberta', 70000, 'Negociação');

    $insVis = $pdo->prepare('INSERT INTO visitas (os,data,cliente,vendedor_id,tipo,oportunidade) VALUES (?,?,?,?,?,?)');
    $V = function ($os, $d, $cli, $vid, $tipo, $op) use ($insVis) {
        $insVis->execute([$os, $d, $cli, $vid, $tipo, $op]);
    };
    $V('4391', '2026-07-15', 'Usina Pinheiro', 'v3', 'visita', 'Prospecção — agendar visita comercial.');
    $V('4394', '2026-07-08', 'Laticínios Santa Maria', 'v3', 'visita', 'Preventiva 6000h vencendo.');
    $V('4397', '2026-07-10', 'Betânia Alvoar', 'v3', 'visita', 'Ampliar locação p/ 2ª unidade.');
    $V('4399', '2026-07-06', 'Cargill Ilhéus', 'v1', 'visita', 'Cotação trimestral de filtros.');
    $V('4402', '2026-07-14', 'Bunge LEM', 'v1', 'visita', 'Nitrogênio on-site 2027.');
    $V('4405', '2026-07-09', 'Vopak', 'v4', 'visita', 'Renovação de contrato de preventiva.');
    $V('4408', '2026-07-16', 'Engepack', 'v4', 'visita', 'Auditoria caça-vazamento.');
    for ($i = 0; $i < 12; $i++) {
        $V('P' . (100 + $i), '2026-07-' . str_pad((string) (2 + $i), 2, '0', STR_PAD_LEFT), 'Prospect ' . ($i + 1), 'v9', 'prospeccao', 'Novo cliente mapeado — canal interno.');
    }
    for ($i = 0; $i < 9; $i++) {
        $V('C' . (200 + $i), '2026-07-' . str_pad((string) (3 + $i * 3), 2, '0', STR_PAD_LEFT), 'Cliente região ' . ($i + 1), 'v7', 'visita', 'Visita de carteira — representante.');
    }

    $pdo->prepare('INSERT INTO apuracao (mes, vendedor_id, ocorrencias) VALUES (?,?,?)')->execute([$M, 'v2', 1]);
    if (crmCoreFase1Disponivel($pdo)) sincronizarNucleoLegadoCrm($pdo);
}

/** Zera todos os dados comerciais e volta a equipe ao padrão de fábrica. */
function limparTudo(PDO $pdo): void
{
    $pdo->exec('DELETE FROM orcamento_cadencia_log');
    if (crmCoreFase1Disponivel($pdo)) $pdo->exec('DELETE FROM crm_negotiations');
    $pdo->exec('DELETE FROM orcamentos');
    $pdo->exec('DELETE FROM pedidos');
    $pdo->exec('DELETE FROM visitas');
    $pdo->exec('DELETE FROM apuracao');
    restaurarEquipePadrao($pdo);
}
