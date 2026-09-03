<?php
// Synthetic data only: this fixture never bootstraps the app or connects to a database.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/account_history_export.php';

function account_history_test_rows(int $count = 49): array
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $credit = $i < 6 ? 2500.0 : 7000.0;
        $net = round(94000 + sin($i / 4) * 2500 + $i * 35, 2);
        $own = round(500 + cos($i / 3) * 600, 2);
        $rows[] = [
            'id' => $i + 1, 'snapshot_date' => (new DateTimeImmutable('2025-01-01'))->modify('+' . $i . ' days')->format('Y-m-d'),
            'fineco_total_assets' => $net + $credit,
            'fineco_portfolio_total' => $net - $own,
            'portfolio_market_value' => $net - $own - 80,
            'liquidity_total' => $own + $credit,
            'accounting_balance' => round($own - 40, 2),
            'available_balance' => round($own - 55 + $credit, 2),
            'reserved_liquidity' => -15.0,
            'credit_agreed_amount' => $credit,
            'credit_used_amount' => 0.0,
            'credit_available_amount' => $credit,
            'data_origin' => $i % 7 === 0 ? 'MANUALE' : 'IMPORT_FINECO',
        ];
    }
    return $rows;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $mode = $argv[1] ?? 'full';
    if (in_array($mode, ['header', 'header-empty'], true)) {
        $history = $mode === 'header-empty' ? [] : account_history_test_rows();
        $testBase = '';
        $source = file_get_contents(__DIR__ . '/../account_history.php');
        $start = strpos($source, '<section class="panel account-hero account-history-hero">');
        $end = strpos($source, '</section>', $start) + strlen('</section>');
        $fragment = str_replace('app_base_path()', '$testBase', substr($source, $start, $end - $start));
        echo '<!doctype html><html lang="it"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/assets/style.css"><body><main style="padding:16px">';
        eval('?>' . $fragment);
        echo '</main></body></html>';
        exit;
    }
    $rows = account_history_test_rows($mode === 'single' ? 1 : 49);
    if ($mode === 'partial') {
        $rows = [['id' => 1, 'snapshot_date' => '2025-01-01', 'fineco_total_assets' => 1000, 'data_origin' => 'MANUALE']];
    }
    if ($mode === 'nullable') {
        $rows = [['id' => 1, 'snapshot_date' => '2025-01-01', 'data_origin' => 'MANUALE']];
    }
    $report = account_history_export_model($rows);
    if ($mode === 'xlsx') {
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx(account_history_export_workbook($report)))->save('php://output');
    } else {
        $printBase = '';
        require __DIR__ . '/../partials/account_history_print.php';
    }
}
