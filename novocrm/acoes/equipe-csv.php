<?php
declare(strict_types=1);

/** Exporta o extrato de comissões do mês em CSV (somente leitura). comercial exporta só o próprio. */
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../partials/comissao.php';

$user = nc_boot();
$pdo = db();

$month = valid_month($_GET['mes'] ?? null);
$report = nc_commission_report($pdo, $month);

$personFilter = (int)($_GET['pessoa'] ?? 0);
if (!nc_is_manager($user)) {
    $personFilter = (int)$user['id'];
}

/** Evita injeção de fórmula ao abrir no Excel (=, +, -, @, tab, CR no início). */
$cell = static function ($value): string {
    $text = (string)$value;
    if ($text !== '' && strpbrk($text[0], "=+-@\t\r") !== false) {
        $text = "'" . $text;
    }
    return $text;
};
$num = static fn(float $v): string => number_format($v, 2, ',', '');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="extrato-comissoes-' . $month . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Pedido', 'Data de faturamento', 'Cliente', 'Representante', 'Modalidade', 'Valor do pedido', 'Base', 'Percentual', 'Política', 'Comissão', 'Status'], ';');

$total = 0.0;
foreach ($report['statement'] as $row) {
    if ($personFilter > 0 && (int)$row['person_id'] !== $personFilter) {
        continue;
    }
    $total += (float)$row['commission'];
    fputcsv($out, [
        $cell($row['number']),
        nc_date((string)$row['invoice_date']),
        $cell($row['client']),
        $cell($row['person']),
        $row['modality'] === 'agenciada' ? 'Agenciada' : 'Revenda',
        $num((float)$row['amount']),
        $num((float)$row['base']),
        $num((float)$row['pct']) . '%',
        $cell($row['policy']),
        $num((float)$row['commission']),
        nc_closing_label($row['status'] === 'aberta' ? null : (string)$row['status']),
    ], ';');
}
fputcsv($out, ['Total', '', '', '', '', '', '', '', '', $num($total), ''], ';');
fclose($out);

audit($pdo, (int)$user['id'], 'export_csv', 'commission_statement', null, ['month' => $month]);
