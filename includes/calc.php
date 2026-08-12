<?php
require_once __DIR__ . '/rbac.php';
/**
 * DNB · CRM Comercial — motor de apuração de comissão.
 * Porte fiel (linha a linha) do useMemo "calc" do DNB_CRM_v2.jsx original.
 * Qualquer alteração aqui precisa ser revisada com atenção: define pagamento
 * de comissão/bonificação da equipe.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/** Busca todos os vendedores/suporte, com "pol" já decodificado. */
function obterVendedores(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM vendedores WHERE ativo = 1 ORDER BY nome ASC')->fetchAll();
    foreach ($rows as &$v) {
        $v['pol'] = json_decode($v['pol'] ?? '{}', true) ?: [];
    }
    return $rows;
}

function obterVendedorPorId(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM vendedores WHERE id = ?');
    $stmt->execute([$id]);
    $v = $stmt->fetch();
    if ($v) $v['pol'] = json_decode($v['pol'] ?? '{}', true) ?: [];
    return $v ?: null;
}

function nomeVendedor(array $vendedores, ?string $id): string
{
    foreach ($vendedores as $v) {
        if ($v['id'] === $id) return $v['nome'];
    }
    return '—';
}

/** Soma o campo "valor" de uma lista de linhas (pedidos/orçamentos). */
function somaValor(array $linhas): float
{
    $s = 0;
    foreach ($linhas as $l) $s += (float) ($l['valor'] ?? 0);
    return $s;
}
/** Soma o campo "comissao_dnb" (comissão recebida pela DNB em agenciadas). */
function somaComissaoDNB(array $linhas): float
{
    $s = 0;
    foreach ($linhas as $l) $s += (float) ($l['comissao_dnb'] ?? 0);
    return $s;
}

/**
 * Motor de apuração do mês — replica 1:1 a lógica de comissão das 7 políticas.
 *
 * @param PDO $pdo
 * @param string $mes formato "YYYY-MM"
 * @param array $vendedores lista de vendedores (obterVendedores())
 * @return array{pedFatMes:array,orcMes:array,visMes:array,porPessoa:array}
 */
function calcularMes(PDO $pdo, string $mes, array $vendedores): array
{
    $pol_presets = politicas();

    [$filtroEscopo, $paramsEscopo] = filtroEscopoAtribuicao($pdo);
    $stmtEscopo = $pdo->prepare("SELECT pedidos.*,c.has_commission cargo_has_commission,c.title cargo_title FROM pedidos LEFT JOIN cargos c ON c.id=pedidos.cargo_id WHERE {$filtroEscopo}");
    $stmtEscopo->execute($paramsEscopo); $pedidos = $stmtEscopo->fetchAll();
    $stmtEscopo = $pdo->prepare("SELECT orcamentos.*,c.has_commission cargo_has_commission,c.title cargo_title FROM orcamentos LEFT JOIN cargos c ON c.id=orcamentos.cargo_id WHERE {$filtroEscopo}");
    $stmtEscopo->execute($paramsEscopo); $orcamentos = $stmtEscopo->fetchAll();
    $stmtEscopo = $pdo->prepare("SELECT * FROM visitas WHERE {$filtroEscopo}");
    $stmtEscopo->execute($paramsEscopo); $visitas = $stmtEscopo->fetchAll();
    $pedidos = array_values(array_filter($pedidos, 'registroComissionavelPorCargo'));
    $orcamentos = array_values(array_filter($orcamentos, 'registroComissionavelPorCargo'));

    // ids de orçamentos com pelo menos 1 toque de cadência registrado
    $comCadLog = array_column($pdo->query('SELECT DISTINCT orcamento_id FROM orcamento_cadencia_log')->fetchAll(), 'orcamento_id');
    $comCadLog = array_flip($comCadLog);

    // ocorrências operacionais lançadas para este mês
    $stmtAp = $pdo->prepare('SELECT vendedor_id, ocorrencias FROM apuracao WHERE mes = ?');
    $stmtAp->execute([$mes]);
    $apuracaoMes = [];
    foreach ($stmtAp->fetchAll() as $a) $apuracaoMes[$a['vendedor_id']] = (int) $a['ocorrencias'];

    $ano = substr($mes, 0, 4);
    $mesNum = (int) substr($mes, 5, 2);

    $pedFatMes = array_values(array_filter($pedidos, fn($p) => !empty($p['faturado']) && mesDe($p['data_fat']) === $mes));
    $pedFatAno = array_values(array_filter($pedidos, fn($p) => !empty($p['faturado']) && anoDe($p['data_fat']) === $ano && mesDe($p['data_fat']) <= $mes));
    $orcMes = array_values(array_filter($orcamentos, fn($o) => mesDe($o['data']) === $mes));
    $visMes = array_values(array_filter($visitas, fn($v) => mesDe($v['data']) === $mes));

    $porPessoa = [];

    foreach ($vendedores as $pes) {
        $polBase = $pes['pol'] ?: ($pol_presets['sem_politica']);
        if ($pes['politica'] === 'consultor_senior') {
            $fr = 1.0; $mesAtuacao = 0;
        } else {
            [$fr, $mesAtuacao] = fatorRampa($pes['data_contratacao'] ?? null, $mes);
        }
        $pol = $polBase;
        if ($fr < 1) {
            foreach (['metaRevenda', 'metaAgenciadas', 'visitasMes', 'prospecMes', 'pipelineMin', 'cotacoesMin', 'oportunidadesMin', 'contatosMin', 'contasNovasMin'] as $k) {
                if (!empty($pol[$k])) $pol[$k] = round($pol[$k] * $fr);
            }
        }
        $ocorrenciasApuradas = $apuracaoMes[$pes['id']] ?? 0;

        $minhaVenda = fn($p) => ($p['vendedor_id'] ?? null) === $pes['id'];
        $meuApoio = fn($p) => ($p['suporte_id'] ?? null) === $pes['id'];
        $escopo = $pes['funcao'] === 'suporte'
            ? fn($p) => $meuApoio($p) || $minhaVenda($p)
            : $minhaVenda;

        $revMes = array_values(array_filter($pedFatMes, fn($p) => ($p['modalidade'] ?? '') !== 'agenciada' && $escopo($p)));
        $agMes = array_values(array_filter($pedFatMes, fn($p) => ($p['modalidade'] ?? '') === 'agenciada' && $escopo($p)));
        $revFat = somaValor($revMes);
        $agInterm = somaValor($agMes);
        $agComDNB = somaComissaoDNB($agMes);

        $propostas = somaValor(array_values(array_filter($orcMes, fn($o) => ($o['vendedor_id'] ?? null) === $pes['id'])));
        $vis = count(array_filter($visMes, fn($v) => ($v['vendedor_id'] ?? null) === $pes['id'] && ($v['tipo'] ?? '') !== 'prospeccao'));
        $prosp = count(array_filter($visMes, fn($v) => ($v['vendedor_id'] ?? null) === $pes['id'] && ($v['tipo'] ?? '') === 'prospeccao'));
        $metaProp = round(metaPropostasDe($pes) * $fr);

        /* ---- KPIs automáticos, apurados dos dados do CRM ---- */
        $meusOrc = array_values(array_filter($orcMes, fn($o) => ($o['vendedor_id'] ?? null) === $pes['id']));
        $nCot = count($meusOrc);
        $clientesUnicos = [];
        foreach ($meusOrc as $o) $clientesUnicos[mb_strtolower(trim($o['cliente'] ?? ''))] = true;
        $nOport = count($clientesUnicos);
        $ganhas = count(array_filter($meusOrc, fn($o) => ($o['status'] ?? '') === 'Ganha'));
        $convPct = $nCot ? ($ganhas / $nCot) * 100 : 0;

        $fimMesTs = strtotime($mes . '-28');
        $abertasElegiveis = array_values(array_filter($orcamentos, function ($o) use ($pes, $fimMesTs) {
            if (($o['vendedor_id'] ?? null) !== $pes['id'] || ($o['status'] ?? '') !== 'Aberta') return false;
            $dias = ($fimMesTs - strtotime($o['data'])) / 86400;
            return $dias >= 5;
        }));
        $comToque = count(array_filter($abertasElegiveis, fn($o) => isset($comCadLog[$o['id']])));
        $fupPct = count($abertasElegiveis) ? ($comToque / count($abertasElegiveis)) * 100 : 100;

        $pipeline = 0;
        foreach ($orcamentos as $o) {
            if (($o['vendedor_id'] ?? null) === $pes['id'] && ($o['status'] ?? '') === 'Aberta') $pipeline += (float) ($o['valor'] ?? 0);
        }

        $kpiItem = function (string $nome, $valor, $meta, ?callable $fmt = null, bool $reduz = false) {
            return ['nome' => $nome, 'valor' => $valor, 'meta' => $meta, 'ok' => $valor >= $meta, 'reduz' => $reduz, 'fmt' => $fmt ?: fn($x) => (string) round($x)];
        };
        $kpis = [];

        if ($pes['politica'] === 'interno') {
            $kpis = [
                $kpiItem('Cotações emitidas', $nCot, (float) ($pol['cotacoesMin'] ?? 0)),
                $kpiItem('Oportunidades (clientes cotados)', $nOport, (float) ($pol['oportunidadesMin'] ?? 0)),
                $kpiItem('Conversão cotação→pedido (%)', $convPct, (float) ($pol['convMin'] ?? 0)),
                $kpiItem('Follow-ups em propostas abertas (%)', $fupPct, 100),
                $kpiItem('Pipeline aberto (R$)', $pipeline, (float) ($pol['pipelineMin'] ?? 0), 'fmtBRL'),
                $kpiItem('Prospecções de novos clientes', $prosp, (float) ($pol['prospecMes'] ?? 0)),
            ];
        }
        if ($pes['politica'] === 'representante') {
            $kpis = [
                $kpiItem('Visitas com relatório (iliot)', $vis, (float) ($pol['visitasMes'] ?? 0)),
                $kpiItem('Cotações emitidas', $nCot, (float) ($pol['cotacoesMin'] ?? 0)),
                $kpiItem('Conversão cotação→pedido (%)', $convPct, (float) ($pol['convMin'] ?? 0)),
                $kpiItem('Follow-ups em propostas abertas (%)', $fupPct, 100),
                $kpiItem('Pipeline aberto (R$)', $pipeline, (float) ($pol['pipelineMin'] ?? 0), 'fmtBRL'),
            ];
        }

        $orcEquipe = array_values(array_filter($orcMes, function ($o) use ($pes) {
            return ($o['suporte_id'] ?? null) === $pes['id']
                || ($o['vendedor_id'] ?? null) === $pes['id']
                || (!empty($pes['vinculo_id']) && ($o['vendedor_id'] ?? null) === $pes['vinculo_id']);
        }));
        $propostasEquipe = somaValor($orcEquipe);
        $cliMesEquipe = [];
        foreach ($orcEquipe as $o) {
            $c = mb_strtolower(trim($o['cliente'] ?? ''));
            if ($c !== '') $cliMesEquipe[$c] = true;
        }
        $historico12m = [];
        foreach ($orcamentos as $o) if (mesDe($o['data']) < $mes) $historico12m[mb_strtolower(trim($o['cliente'] ?? ''))] = true;
        foreach ($pedidos as $p) if (mesDe($p['data']) < $mes) $historico12m[mb_strtolower(trim($p['cliente'] ?? ''))] = true;
        $contasNR = 0;
        foreach (array_keys($cliMesEquipe) as $c) if (!isset($historico12m[$c])) $contasNR++;

        if ($pes['politica'] === 'suporte_ir') {
            $kpis = [
                $kpiItem('Contatos ativos de prospecção registrados', $prosp, (float) ($pol['contatosMin'] ?? 0), null, true),
                $kpiItem('Oportunidades qualificadas abertas no funil', count($orcEquipe), (float) ($pol['oportunidadesMin'] ?? 0), null, true),
                $kpiItem('Contas novas/reativadas (sem compra em 12m)', $contasNR, (float) ($pol['contasNovasMin'] ?? 0)),
                $kpiItem('Volume de propostas — funil conjunto (R$)', $propostasEquipe, $metaProp, 'fmtBRL', true),
            ];
        }
        if ($pes['politica'] === 'vendedor_externo') {
            $kpis = [
                $kpiItem('Meta de revenda faturada (R$)', $revFat, (float) ($pol['metaRevenda'] ?? 0), 'fmtBRL'),
                $kpiItem('Meta de agenciamento — valor intermediado (R$)', $agInterm, (float) ($pol['metaAgenciadas'] ?? 0), 'fmtBRL'),
                $kpiItem('Visitas com relatório registrado', $vis, (float) ($pol['visitasMes'] ?? 0), null, true),
                $kpiItem('Volume de propostas emitidas (R$)', $propostas, $metaProp, null, true),
            ];
        }
        $kpiAutoOk = count($kpis) > 0 && !count(array_filter($kpis, fn($k) => !$k['ok']));

        $r = [
            'revFat' => $revFat, 'agInterm' => $agInterm, 'agComDNB' => $agComDNB,
            'propostas' => $propostas, 'vis' => $vis, 'prosp' => $prosp, 'metaProp' => $metaProp, 'kpis' => $kpis,
            'comRevenda' => 0.0, 'comAgenciada' => 0.0, 'bonif' => 0.0, 'teto' => '', 'total' => 0.0,
            'ating' => 0.0, 'atingAg' => 0.0, 'faixaTxt' => '', 'notas' => [], 'kpiOk' => $kpiAutoOk, 'ocorrencias' => $ocorrenciasApuradas,
            'polEfetiva' => $pol, 'rampa' => $fr, 'mesAtuacao' => $mesAtuacao,
        ];
        if ($fr < 1) $r['notas'][] = "Rampa progressiva — mês {$mesAtuacao} de atuação: metas e indicadores a " . round($fr * 100) . "% da plena.";

        if ($pes['politica'] === 'consultor_senior') {
            $revAno = somaValor(array_values(array_filter($pedFatAno, fn($p) => ($p['modalidade'] ?? '') !== 'agenciada' && $minhaVenda($p))));
            $agComAno = somaComissaoDNB(array_values(array_filter($pedFatAno, fn($p) => ($p['modalidade'] ?? '') === 'agenciada' && $minhaVenda($p))));
            $metaProp_ = ((float) ($pol['metaPropriasAno'] ?? 0)) * ($mesNum / 12);
            $atg = $metaProp_ ? ($revAno / $metaProp_) * 100 : 0;
            $aliq = $atg > 130 ? 0.75 : ($atg > 110 ? 0.65 : ($atg > 90 ? 0.5 : ($atg > 70 ? 0.35 : 0)));
            $comProprias = min($revAno * ($aliq / 100), (float) ($pol['tetoPropriasAno'] ?? INF));
            $comAgMes = min($agComDNB * (((float) ($pol['pctAgenciada'] ?? 0)) / 100), (float) ($pol['tetoAgMes'] ?? INF));
            $comAgAno = min($agComAno * (((float) ($pol['pctAgenciada'] ?? 0)) / 100), (float) ($pol['tetoAgAno'] ?? INF));

            $orientadosIds = array_column(array_values(array_filter($vendedores, fn($v) => ($v['orientador_id'] ?? null) === $pes['id'])), 'id');
            $agOrient = array_values(array_filter($pedFatAno, fn($p) => ($p['modalidade'] ?? '') === 'agenciada' && in_array($p['vendedor_id'] ?? null, $orientadosIds, true)));
            $agOrientComAno = somaComissaoDNB($agOrient);
            $metaOr = ((float) ($pol['metaOrientadoAno'] ?? 0)) * ($mesNum / 12);
            $atgOr = $metaOr ? (somaValor($agOrient) / $metaOr) * 100 : 0;
            $pctOr = $atgOr > 120 ? 8 : ($atgOr > 100 ? 6 : ($atgOr > 70 ? 4 : 0));
            $bonifOrient = min($agOrientComAno * ($pctOr / 100), (float) ($pol['tetoOrientAno'] ?? INF));
            $totalAno = min($comProprias + $comAgAno + $bonifOrient, (float) ($pol['tetoGlobalAno'] ?? INF));

            $r['ating'] = $atg;
            $r['comRevenda'] = $comProprias; $r['comAgenciada'] = $comAgMes; $r['bonif'] = $bonifOrient;
            $r['total'] = $totalAno;
            $r['faixaTxt'] = "acum. {$ano}: " . fmtBRL($revAno) . " · faixa " . $aliq . "%";
            $r['notas'][] = "Regime anual acumulado — valores do exercício {$ano} até " . rotuloMes($mes) . "; pagamento provisório, ajuste no fechamento anual. Tetos: próprias 60k · agenc. 8k/mês e 60k/ano · orientação 20k · global 120k.";
            if ($pctOr > 0) $r['notas'][] = "Orientação: atingimento do orientado " . round($atgOr) . "% → {$pctOr}% sobre comissão DNB.";
        } elseif ($pes['politica'] === 'interno') {
            $com = $revFat * (((float) ($pol['pctPecasServ'] ?? 0)) / 100) + $agComDNB * (((float) ($pol['pctAgenciada'] ?? 0)) / 100);
            $atg = !empty($pol['metaRevenda']) ? ($revFat / $pol['metaRevenda']) * 100 : 0;
            $f = $atg >= 120 ? 1.2 : ($atg >= 100 ? 1 : ($atg >= 96 ? 0.9 : ($atg >= 86 ? 0.7 : ($atg >= 71 ? 0.5 : 0))));
            $bonus = $com * $f * ($r['kpiOk'] ? 2 : 1);
            $teto = (float) ($pol['tetoBonusMes'] ?? INF);
            if ($bonus > $teto) { $bonus = $teto; $r['teto'] = 'teto R$ ' . number_format($teto, 0, ',', '.'); }
            $r['ating'] = $atg;
            $r['comRevenda'] = $revFat * (((float) ($pol['pctPecasServ'] ?? 0)) / 100);
            $r['comAgenciada'] = $agComDNB * (((float) ($pol['pctAgenciada'] ?? 0)) / 100);
            $r['bonif'] = $bonus; $r['total'] = $com + $bonus;
            $r['faixaTxt'] = "multiplicador ×{$f}" . ($r['kpiOk'] ? ' ×2 (KPIs)' : '');
            if (!$r['kpiOk']) {
                $falt = implode(', ', array_map(fn($k) => $k['nome'], array_filter($kpis, fn($k) => !$k['ok'])));
                $r['notas'][] = "Bônus KPI (dobra) inativo — apuração automática: pendente em {$falt}. Indicadores fora do sistema (tempo de resposta 2h, 10 reativações) seguem verificação documental.";
            }
        } elseif ($pes['politica'] === 'representante') {
            $margem = 0;
            foreach ($revMes as $p) $margem += (float) ($p['margem'] ?? 0);
            $comRev = $margem * (((float) ($pol['pctMargem'] ?? 0)) / 100);
            $comAg = $agComDNB * (((float) ($pol['pctComDNB'] ?? 0)) / 100);
            $com = $comRev + $comAg;
            $atg = !empty($pol['metaRevenda']) ? (($revFat + $agInterm) / $pol['metaRevenda']) * 100 : 0;
            $bonus = $r['kpiOk'] ? $com * (((float) ($pol['bonifKpiPct'] ?? 0)) / 100) : 0;
            $r['ating'] = $atg; $r['comRevenda'] = $comRev; $r['comAgenciada'] = $comAg; $r['bonif'] = $bonus; $r['total'] = $com + $bonus;
            $r['faixaTxt'] = $r['kpiOk'] ? "KPIs 100% → bonificação {$pol['bonifKpiPct']}%" : 'KPIs incompletos — sem bonificação (não há proporcionalidade)';
            $r['notas'][] = 'Comissão: 30% s/ comissão DNB (agenciadas) · 5% s/ resultado/margem (revendas — informe a margem no pedido). Pagamento condicionado ao recebimento financeiro integral e emissão de NF.';
            if (!$r['kpiOk'] && count(array_filter($kpis, fn($k) => !$k['ok']))) {
                $r['notas'][] = 'KPIs pendentes: ' . implode(', ', array_map(fn($k) => $k['nome'], array_filter($kpis, fn($k) => !$k['ok']))) . '. Fora do sistema: tempo de resposta, 6 reativações, relatórios semanais.';
            }
            if ($margem === 0 && $revFat > 0) $r['notas'][] = 'Atenção: revendas faturadas sem margem informada — comissão de 5% zerada até o lançamento do resultado.';
        } elseif ($pes['politica'] === 'vendedor_externo') {
            $com = $revFat * (((float) ($pol['pctPecasServ'] ?? 0)) / 100) + $agComDNB * (((float) ($pol['pctAgenciada'] ?? 0)) / 100);
            $atg = !empty($pol['metaRevenda']) ? ($revFat / $pol['metaRevenda']) * 100 : 0;
            $fB = $atg >= 100 ? 1 : ($atg >= 90 ? 0.9 : ($atg >= 80 ? 0.8 : 0));
            $bonus = $com * $fB;
            $autoFalhasExt = count(array_filter($kpis, fn($k) => $k['reduz'] && !$k['ok']));
            $ocorrTotExt = $r['ocorrencias'] + $autoFalhasExt;
            if ($ocorrTotExt > 0 && $bonus > 0) {
                $red = min(1, 0.05 * $ocorrTotExt);
                $bonus = $bonus * (1 - $red);
                $r['notas'][] = 'Redução de ' . round($red * 100) . "% na bonificação — {$autoFalhasExt} indicador(es) automático(s) descumprido(s) (visitas / volume de propostas)"
                    . ($r['ocorrencias'] > 0 ? " + {$r['ocorrencias']} desconformidade(s) documental(is)" : '') . ' (5% por ocorrência, cumulativas, limite 100%).';
            }
            $r['ating'] = $atg;
            $r['atingAg'] = !empty($pol['metaAgenciadas']) ? ($agInterm / $pol['metaAgenciadas']) * 100 : 0;
            $r['comRevenda'] = $revFat * (((float) ($pol['pctPecasServ'] ?? 0)) / 100);
            $r['comAgenciada'] = $agComDNB * (((float) ($pol['pctAgenciada'] ?? 0)) / 100);
            $r['bonif'] = $bonus; $r['total'] = $com + $bonus;
            $r['faixaTxt'] = $fB > 0 ? "bonificação ×{$fB}" : 'abaixo de 80% da meta — sem bonificação';
            $r['notas'][] = 'Rev02: escala sobre a comissão do mês (100/90/80%) apurada pela meta ' . ($fr < 1 ? 'progressiva' : 'plena') . ' de revenda; reduções de 5% por ocorrência. Pagamento condicionado à liquidação financeira e registro das negociações no CRM.';
        } else {
            // vendedor_ir / suporte_ir / sem_politica
            $com = $revFat * (((float) ($pol['pctPecasServ'] ?? 0)) / 100) + $agComDNB * (((float) ($pol['pctAgenciada'] ?? 0)) / 100);
            $atg = !empty($pol['metaRevenda']) ? ($revFat / $pol['metaRevenda']) * 100 : 0;
            $f = $atg >= 100 ? 1 : ($atg >= 90 ? 0.9 : ($atg >= 80 ? 0.8 : 0));
            $bonus = $com * $f;
            $autoFalhas = $pes['politica'] === 'suporte_ir' ? count(array_filter($kpis, fn($k) => $k['reduz'] && !$k['ok'])) : 0;
            $ocorrTot = $r['ocorrencias'] + $autoFalhas;
            if ($ocorrTot > 0 && $bonus > 0) {
                $red = min(1, 0.05 * $ocorrTot);
                $bonus = $bonus * (1 - $red);
                $r['notas'][] = 'Redução de ' . round($red * 100) . "% na bonificação — {$autoFalhas} indicador(es) automático(s) descumprido(s)"
                    . ($r['ocorrencias'] > 0 ? " + {$r['ocorrencias']} desconformidade(s) documental(is)" : '') . ' (5% por ocorrência, limite 100%).';
            }
            $r['ating'] = $atg;
            $r['atingAg'] = !empty($pol['metaAgenciadas']) ? ($agInterm / $pol['metaAgenciadas']) * 100 : 0;
            $r['comRevenda'] = $revFat * (((float) ($pol['pctPecasServ'] ?? 0)) / 100);
            $r['comAgenciada'] = $agComDNB * (((float) ($pol['pctAgenciada'] ?? 0)) / 100);
            $r['bonif'] = $bonus; $r['total'] = $com + $bonus;
            $r['faixaTxt'] = $f > 0 ? "bonificação ×{$f}" : 'abaixo de 80% — sem bonificação';
        }

        $porPessoa[$pes['id']] = $r;
    }

    return ['pedFatMes' => $pedFatMes, 'orcMes' => $orcMes, 'visMes' => $visMes, 'porPessoa' => $porPessoa];
}
