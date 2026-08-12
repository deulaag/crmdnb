<?php
/**
 * DNB · CRM Comercial — constantes e funções auxiliares.
 * Porte fiel do arquivo React original (DNB_CRM_v2.jsx) para PHP.
 * Requer PHP 8.0+ (usa str_contains/str_starts_with); array_is_list tem polyfill abaixo p/ PHP < 8.1.
 */

if (!function_exists('array_is_list')) {
    function array_is_list(array $arr): bool
    {
        $i = 0;
        foreach ($arr as $k => $v) { if ($k !== $i++) return false; }
        return true;
    }
}

// Identidade visual oficial DNB (Manual da Marca).
const NAVY = '#003060';
const NAVY2 = '#A4C8FF';
const SLATE_BRAND = '#5F6E7C';

/* ---------- Presets de política (parâmetros editáveis por pessoa) ---------- */
function politicas(): array
{
    return [
        'vendedor_ir' => [
            'label' => 'Vendedor IR (v3)', 'regime' => 'mensal',
            'metaRevenda' => 500000, 'metaAgenciadas' => 300000,
            'pctPecasServ' => 0.5, 'pctAgenciada' => 1,
            'visitasMes' => 60, 'prospecMes' => 0,
            'convIR' => 40, 'convEquip' => 20, 'convDemais' => 15,
        ],
        'vendedor_externo' => [
            'label' => 'Vendedor Externo (rev02 — jul/2026)', 'regime' => 'mensal',
            'metaRevenda' => 500000, 'metaAgenciadas' => 300000,
            'pctPecasServ' => 0.5, 'pctAgenciada' => 1, 'bonifKpiPct' => 100,
            'visitasMes' => 40, 'prospecMes' => 0,
            'convIR' => 40, 'convEquip' => 20, 'convDemais' => 15,
        ],
        'suporte_ir' => [
            'label' => 'Suporte em Vendas IR (rev04 — jul/2026)', 'regime' => 'mensal',
            'metaRevenda' => 500000, 'metaAgenciadas' => 300000,
            'pctPecasServ' => 0.25, 'pctAgenciada' => 0.5,
            'contatosMin' => 100, 'oportunidadesMin' => 20, 'contasNovasMin' => 5,
            'visitasMes' => 0, 'prospecMes' => 0,
            'convIR' => 40, 'convEquip' => 20, 'convDemais' => 15,
        ],
        'interno' => [
            'label' => 'Vendedor Interno Sênior (PCV-INT-001)', 'regime' => 'mensal',
            'metaRevenda' => 500000, 'metaAgenciadas' => 0,
            'pctPecasServ' => 0.5, 'pctAgenciada' => 1,
            'tetoBonusMes' => 9600, 'visitasMes' => 0, 'prospecMes' => 40,
            'convIR' => 0, 'convEquip' => 0, 'convDemais' => 15,
            'pipelineMin' => 1700000, 'cotacoesMin' => 80, 'oportunidadesMin' => 60, 'convMin' => 25,
        ],
        'representante' => [
            'label' => 'Representante Comercial Autônomo (PCV-REP-001)', 'regime' => 'mensal',
            'metaRevenda' => 300000, 'metaAgenciadas' => 0,
            'metaCompressores' => 200000, 'metaAcessorios' => 100000,
            'pctComDNB' => 30, 'pctMargem' => 5, 'bonifKpiPct' => 20,
            'pipelineMin' => 1000000, 'cotacoesMin' => 48, 'convMin' => 25,
            'visitasMes' => 40, 'prospecMes' => 0,
            'convIR' => 40, 'convEquip' => 20, 'convDemais' => 15,
        ],
        'consultor_senior' => [
            'label' => 'Consultor Comercial Sênior (v2 — anual)', 'regime' => 'anual',
            'metaPropriasAno' => 6300000, 'metaOrientadoAno' => 3150000, 'metaAgenciadasAno' => 2500000,
            'pctAgenciada' => 6, 'tetoAgMes' => 8000, 'tetoAgAno' => 60000,
            'tetoPropriasAno' => 60000, 'tetoOrientAno' => 20000, 'tetoGlobalAno' => 120000,
            'metaPropostasMes' => 3119792, 'visitasMes' => 40, 'prospecMes' => 0,
        ],
        'sem_politica' => [
            'label' => 'Sem política definida', 'regime' => 'mensal', 'metaRevenda' => 0, 'metaAgenciadas' => 0,
            'pctPecasServ' => 0, 'pctAgenciada' => 0, 'visitasMes' => 0, 'prospecMes' => 0,
            'convIR' => 40, 'convEquip' => 20, 'convDemais' => 15,
        ],
    ];
}

/* ---------- Funis por dinâmica de venda ---------- */
function funis(): array
{
    return [
        'equipamentos' => ['label' => 'Equipamentos', 'desc' => 'Compressores IR (agenciamento) · Bombas ARO — ciclo longo',
            'etapas' => ['Prospecção', 'Levantamento técnico / Visita', 'Proposta emitida', 'Negociação', 'Fechamento']],
        'servicos' => ['label' => 'Serviços e Locação', 'desc' => 'Manutenção IR · Locação',
            'etapas' => ['Solicitação', 'Levantamento / Vistoria', 'Proposta emitida', 'Negociação', 'Fechamento']],
        'pecas' => ['label' => 'Peças e Revenda', 'desc' => 'Peças IR · Peças ARO · linha Parker — ciclo curto',
            'etapas' => ['Solicitação', 'Cotação enviada', 'Follow-up', 'Fechamento']],
    ];
}

/* ---------- Cadências por funil (D+dias) ---------- */
function cadencias(): array
{
    return [
        'pecas' => ['passos' => [1, 3, 7, 10], 'nomes' => ['Confirmação + reforço de valor', 'Follow-up direto', 'Urgência suave / custo da espera', 'Break-up cordial']],
        'servicos' => ['passos' => [2, 7, 14, 21], 'nomes' => ['Reforço técnico da proposta', 'ROI / risco operacional', 'Pergunta direta de decisão', 'Break-up cordial']],
        'equipamentos' => ['passos' => [3, 10, 25], 'nomes' => ['Reforço de valor', 'Caso de ROI / referência do setor', 'Alinhamento de timing e budget'], 'nutricao' => 30],
    ];
}

const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

function fmtBRL($v): string
{
    return 'R$ ' . number_format((float) $v, 2, ',', '.');
}
function hoje(): string
{
    return date('Y-m-d');
}
function mesAtual(): string
{
    return date('Y-m');
}
function mesDe(?string $d): string
{
    return $d ? substr($d, 0, 7) : '';
}
function anoDe(?string $d): string
{
    return $d ? substr($d, 0, 4) : '';
}
function rotuloMes(string $ym): string
{
    $p = explode('-', $ym);
    if (count($p) < 2 || !isset($p[1])) return $ym;
    $m = (int) $p[1];
    return $m >= 1 && $m <= 12 ? (MESES[$m - 1] . '/' . $p[0]) : $ym;
}

/* ---------- Derivação da meta de propostas (regra da diretoria) ----------
   meta propostas = meta ÷ taxa de conversão:
   40% peças/serviços IR · 20% equipamentos completos · 15% demais linhas */
function metaPropostasDe(array $pessoa): float
{
    $p = $pessoa['pol'] ?? [];
    if (($pessoa['politica'] ?? '') === 'consultor_senior') return (float) ($p['metaPropostasMes'] ?? 0);
    if (($pessoa['politica'] ?? '') === 'representante') {
        $m = 0;
        if (!empty($p['metaCompressores']) && !empty($p['convEquip'])) $m += $p['metaCompressores'] / ($p['convEquip'] / 100);
        if (!empty($p['metaAcessorios']) && !empty($p['convIR'])) $m += $p['metaAcessorios'] / ($p['convIR'] / 100);
        return round($m);
    }
    $m = 0;
    if (($pessoa['politica'] ?? '') === 'interno') {
        if (!empty($p['metaRevenda']) && !empty($p['convDemais'])) $m += $p['metaRevenda'] / ($p['convDemais'] / 100);
    } else {
        if (!empty($p['metaRevenda']) && !empty($p['convIR'])) $m += $p['metaRevenda'] / ($p['convIR'] / 100);
        if (!empty($p['metaAgenciadas']) && !empty($p['convEquip'])) $m += $p['metaAgenciadas'] / ($p['convEquip'] / 100);
    }
    return round($m);
}

function funilDe(?string $linha): string
{
    $l = mb_strtolower($linha ?? '');
    if (str_contains($l, 'compressor') || str_contains($l, 'bombas aro') || str_contains($l, 'equipamento')) return 'equipamentos';
    if (str_contains($l, 'manuten') || str_contains($l, 'serviço') || str_contains($l, 'servico') || str_contains($l, 'loca')) return 'servicos';
    return 'pecas';
}
function ehAgenciamento(?string $linha): bool
{
    return str_contains(mb_strtolower($linha ?? ''), 'agenciamento');
}
function tipoDe(?string $linha): string
{
    $l = mb_strtolower($linha ?? '');
    if (ehAgenciamento($linha) || funilDe($linha) === 'equipamentos') return 'Equipamento';
    if (str_contains($l, 'loca')) return 'Locação';
    if (funilDe($linha) === 'servicos') return 'Manutenção';
    return 'Peças';
}
function mapLinha(?string $nome): string
{
    $l = mb_strtolower($nome ?? '');
    if (str_contains($l, 'compressor')) return 'Compressores IR (agenciamento)';
    if (str_contains($l, 'bomba')) return 'Bombas ARO';
    if (str_contains($l, 'loca')) return 'Locação';
    if (str_contains($l, 'manuten') || str_contains($l, 'serviço') || str_contains($l, 'servico')) return 'Serviço Manutenção IR';
    if (str_contains($l, 'aro')) return 'Peças ARO';
    if (str_contains($l, 'automa') || str_contains($l, 'pneum')) return 'Parker Automação';
    if (str_contains($l, 'gsf') || str_contains($l, 'domnick') || str_contains($l, 'comprimido')) return 'Parker Filtração GSF';
    if (str_contains($l, 'conex')) return 'Parker Conexões';
    if (str_contains($l, 'parker') || str_contains($l, 'filtra') || str_contains($l, 'hidr')) return 'Parker Filtração de Processos';
    return 'Peças IR';
}

/* ---------- Numeração automática: INICIAIS-SEQ/MM/AAAA (vendedor > suporte) ---------- */
function gerarNumeroProposta(PDO $pdo, array $pessoas, ?string $vendedorId, ?string $suporteId, ?string $data): string
{
    $pid = $vendedorId ?: $suporteId;
    $pes = null;
    foreach ($pessoas as $v) {
        if ($v['id'] === $pid) { $pes = $v; break; }
    }
    $nome = trim($pes['nome'] ?? '');
    $ws = $nome !== '' ? preg_split('/\s+/', $nome) : [];
    $ini = 'DNB';
    if (count($ws)) {
        $ini = mb_strtoupper(mb_substr($ws[0], 0, 1) . (count($ws) > 1 ? mb_substr($ws[count($ws) - 1], 0, 1) : ''));
    }
    $data = $data ?: hoje();
    [$ano, $mm] = [substr($data, 0, 4), substr($data, 5, 2)];

    $stmt = $pdo->prepare("SELECT numero FROM orcamentos WHERE (vendedor_id = ? OR (vendedor_id IS NULL AND suporte_id = ?)) AND DATE_FORMAT(data, '%Y-%m') = ?");
    $stmt->execute([$pid, $pid, "$ano-$mm"]);
    $existentesNoMes = $stmt->fetchAll();
    $seq = count($existentesNoMes) + 1;

    $stmtAll = $pdo->prepare('SELECT numero FROM orcamentos');
    $stmtAll->execute();
    $todos = array_column($stmtAll->fetchAll(), 'numero');

    $monta = fn($n) => $ini . '-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT) . '/' . $mm . '/' . $ano;
    while (in_array($monta($seq), $todos, true)) {
        $seq++;
    }
    return $monta($seq);
}

/* ---------- Rampa progressiva (6 meses): fator sobre metas e indicadores ---------- */
function fatorRampa(?string $dataContratacao, string $ym): array
{
    if (!$dataContratacao) return [1, 0];
    $ca = (int) substr($dataContratacao, 0, 4);
    $cm = (int) substr($dataContratacao, 5, 2);
    $a = (int) substr($ym, 0, 4);
    $m = (int) substr($ym, 5, 2);
    if (!$ca || !$cm || !$a || !$m) return [1, 0];
    $n = ($a - $ca) * 12 + ($m - $cm) + 1;
    if ($n <= 0) return [1, 0];
    $f = [0.3, 0.45, 0.6, 0.75, 0.9];
    return $n >= 6 ? [1, $n] : [$f[$n - 1], $n];
}

/* ---------- helpers de saída HTML ---------- */
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

const INPUT_CLS = 'dnb-input w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white';
const BTN_P = 'dnb-button dnb-button--primary px-4 py-2 rounded-lg text-sm font-bold text-white transition';
const BTN_S = 'dnb-button dnb-button--secondary px-3 py-1.5 rounded-lg text-xs font-bold border transition';

/** Ícones SVG locais para manter consistência sem adicionar dependências ao PHP puro. */
function dnbIcon(string $nome, string $classe = ''): string
{
    $paths = [
        'dashboard' => '<path d="M3 3h7v7H3V3Zm11 0h7v4h-7V3ZM3 14h7v7H3v-7Zm11-3h7v10h-7V11Z"/>',
        'quotes' => '<path d="M4 5h16v14H4V5Zm0 4h16M9 5v14"/><path d="m13 13 2 2 4-4"/>',
        'orders' => '<path d="M6 3h12v18H6V3Zm3 4h6M9 11h6M9 15h4"/>',
        'visits' => '<path d="M12 21s6-5.1 6-11a6 6 0 1 0-12 0c0 5.9 6 11 6 11Z"/><circle cx="12" cy="10" r="2"/>',
        'cadence' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'commissions' => '<path d="M4 19V9m5 10V5m5 14v-7m5 7V3"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',
        'ai' => '<path d="M9 3h6l1 3 3 1v6l-3 1-1 3H9l-1-3-3-1V7l3-1 1-3Z"/><circle cx="10" cy="10" r="1"/><circle cx="14" cy="10" r="1"/><path d="M9 14h6"/>',
        'cases' => '<path d="M4 6h16v13H4V6Zm4 0V3h8v3M4 11h16M10 14h4"/>',
        'whatsapp' => '<path d="M20 11.5a8 8 0 0 1-11.8 7L3 20l1.5-5.1A8 8 0 1 1 20 11.5Z"/><path d="M8.5 8.5c.4 3 2 4.6 5 5l1.2-1.2 1.8.9c-.5 1.5-1.5 2.3-3 2.3-3.9 0-7-3.1-7-7 0-1.5.8-2.5 2.3-3l.9 1.8-1.2 1.2Z"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-4 2-6 6-6s6 2 6 6m1-9a3 3 0 0 0 0-6m1 9c2.7.4 4 2.4 4 6"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'logout' => '<path d="M10 17l5-5-5-5m5 5H3m11-9h6v18h-6"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
        'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>',
        'trash' => '<path d="M4 7h16M9 7V4h6v3m3 0-1 14H7L6 7m4 4v6m4-6v6"/>',
    ];
    $conteudo = $paths[$nome] ?? $paths['dashboard'];
    return '<svg class="dnb-icon ' . e($classe) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $conteudo . '</svg>';
}

function tag(string $texto, string $tone = 'slate'): string
{
    $tones = [
        'green' => 'bg-green-100 text-green-800', 'red' => 'bg-red-100 text-red-700',
        'amber' => 'bg-amber-100 text-amber-800', 'blue' => 'bg-blue-100 text-blue-900', 'slate' => 'bg-slate-100 text-slate-600',
    ];
    $cls = $tones[$tone] ?? $tones['slate'];
    return '<span class="inline-block px-2 py-0.5 rounded-full text-xs font-bold ' . $cls . '">' . e($texto) . '</span>';
}

function farol(float $pct): string
{
    $p = max(0, min(100, round($pct)));
    $cor = $p >= 100 ? '#15803d' : ($p >= 70 ? '#b45309' : '#b91c1c');
    return '<div class="flex items-center gap-2 min-w-[120px]">'
        . '<div class="flex-1 h-2 rounded-full bg-slate-200 overflow-hidden">'
        . '<div class="h-full rounded-full transition-all" style="width:' . $p . '%;background:' . $cor . '"></div></div>'
        . '<span class="text-xs font-bold w-10 text-right" style="color:' . $cor . '">' . $p . '%</span></div>';
}

function uidCurto(): string
{
    return substr(bin2hex(random_bytes(5)), 0, 8);
}
