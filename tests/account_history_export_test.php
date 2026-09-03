<?php
require_once __DIR__ . '/account_history_fixture.php';
$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Test fallito: ' . $label);
    }
    $checks++;
};
$raw = account_history_test_rows();
$original = serialize($raw);
$report = account_history_export_model(array_reverse($raw));
$assert(count($report['rows']) === 49, 'tutto lo storico oltre una pagina da 25');
$assert(serialize($raw) === $original, 'dati raw e storico non modificati');
$assert($report['rows'][0]['snapshot_date'] === '2025-01-01', 'grafici cronologici');
$assert($report['latest']['snapshot_date'] === '2025-02-18', 'ultimo snapshot');
$assert($report['rows'][0]['net_adjusted_num'] === 94000.0, 'credito escluso dal netto');
$assert($report['rows'][0]['own_liquidity_adjusted_num'] === 1100.0, 'credito escluso dalla liquidita');
$assert($report['rows'][0]['reconstructed_gross_num'] === 94000.0, 'ricostruzione coerente');
$assert($report['rows'][0]['portfolio_market_value_num'] === 92900.0, 'priorita totale Fineco');
$assert($report['rows'][0]['portfolio_position_value_num'] === 92820.0, 'valore posizioni distinto');
$assert($report['stats']['accounting_balance']['min']['value'] < 0, 'saldi negativi ammessi');
$assert(count($report['stats']['credit_used_amount']['min']['dates']) === 49, 'pari merito tutti conservati');
$assert($report['stats']['credit_used_amount']['min'] === $report['stats']['credit_used_amount']['max'], 'zero unico valore, min uguale max');
$empty = account_history_export_model([]);
$assert($empty['latest'] === null && $empty['net_change'] === null, 'assenza patrimonio opzionale');
$base = ['snapshot_date' => '2025-01-01', 'fineco_total_assets' => 11000, 'liquidity_total' => 2000, 'portfolio_market_value' => 9000];
$partial = account_history_export_model([$base]);
$assert($partial['latest']['net_adjusted_num'] === null, 'credito mancante non inventato');
$assert($partial['latest']['own_liquidity_adjusted_num'] === null, 'liquidita rettificata mancante');
$assert($partial['stats']['net_adjusted_num']['max'] === null, 'nessun estremo inventato');
$assert($partial['latest']['portfolio_market_value_num'] === 9000.0, 'fallback posizioni consolidate');
$used = account_history_export_model([$base + ['credit_agreed_amount' => 5000, 'credit_used_amount' => 3500, 'credit_available_amount' => 1500]]);
$assert($used['latest']['net_adjusted_num'] === 9500.0, 'sottrae disponibile non accordato o utilizzato');
$zero = account_history_export_model([$base + ['credit_available_amount' => 0]]);
$assert($zero['latest']['net_adjusted_num'] === 11000.0, 'credito completamente utilizzato zero disponibile');
$legacy = account_history_export_model([['snapshot_date' => '2025-01-01', 'imported_values_json' => json_encode(['fineco_total_assets' => 5000, 'credit_available_amount' => 1000]), 'manual_overrides_json' => json_encode(['fineco_total_assets' => 5500])]]);
$assert($legacy['latest']['net_adjusted_num'] === 4500.0, 'fallback legacy e override esistenti riutilizzati');
$single = account_history_export_model([$raw[0]]);
$assert($single['net_change'] === 0.0 && $single['stats']['net_adjusted_num']['min'] === $single['stats']['net_adjusted_num']['max'], 'singolo snapshot');
$ties = account_history_export_model([$base, array_merge($base, ['snapshot_date' => '2025-01-02', 'fineco_total_assets' => 11000.001])]);
$assert(count($ties['stats']['fineco_total_assets']['max']['dates']) === 2, 'pari merito al centesimo');

$book = account_history_export_workbook($report);
$assert($book->getSheetNames() === ['Riepilogo', 'Patrimonio', 'Saldi e credito'], 'tre fogli distinti');
$wealth = $book->getSheetByName('Patrimonio');
$credit = $book->getSheetByName('Saldi e credito');
$assert($wealth->getHighestDataRow() === 53 && $credit->getHighestDataRow() === 53, 'tutte le 49 righe');
$assert($wealth->getCell('A5')->getDataType() === 'n', 'date Excel numeriche');
$assert(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($wealth->getCell('A5')->getValue())->format('Y-m-d') === '2025-02-18', 'date recenti per prime in Excel');
$assert($wealth->getCell('B5')->getDataType() === 'n' && $wealth->getCell('C5')->getValue() === round($report['latest']['net_adjusted_num'], 2), 'valori numerici');
$assert($credit->getCell('F5')->getValue() === 0.0, 'utilizzato zero distinto da vuoto');
$assert($wealth->getFreezePane() === 'B5' && $wealth->getAutoFilter()->getRange() === 'A4:I53', 'filtri e intestazioni congelate');
$assert($book->getActiveSheet()->getCell('G6')->getValue() === 49, 'riepilogo tutte le rilevazioni');
$nullBook = account_history_export_workbook($partial);
$assert($nullBook->getSheetByName('Patrimonio')->getCell('C5')->getValue() === null, 'netto assente cella vuota');
$assert($nullBook->getSheetByName('Saldi e credito')->getCell('G5')->getValue() === null, 'credito assente cella vuota');
$attack = account_history_export_model([array_merge($raw[0], ['data_origin' => '=HYPERLINK("https://example.invalid")'])]);
$attackBook = account_history_export_workbook($attack);
$assert($attackBook->getSheetByName('Patrimonio')->getCell('I5')->getDataType() === 's', 'testo non eseguito come formula Excel');
$buffer = fopen('php://temp', 'w+b');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($buffer);
rewind($buffer);
$assert(fread($buffer, 2) === 'PK', 'writer produce XLSX ZIP valido su stream');
fclose($buffer);
$file = tempnam(sys_get_temp_dir(), 'wealth-xlsx-');
try {
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($file);
    $roundTrip = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
    $assert($roundTrip->getSheetByName('Patrimonio')->getHighestDataRow() === 53, 'XLSX riletto completo');
    $assert(abs((float) $roundTrip->getSheetByName('Patrimonio')->getCell('C5')->getValue() - $report['latest']['net_adjusted_num']) < 0.001, 'importo riletto invariato');
    $assert($roundTrip->getSheetByName('Patrimonio')->getCell('A5')->getStyle()->getNumberFormat()->getFormatCode() === 'dd/mm/yyyy', 'formato data persistito');
    $roundTrip->disconnectWorksheets();
} finally {
    unlink($file);
}
$printBase = '';
ob_start();
include __DIR__ . '/../partials/account_history_print.php';
$html = ob_get_clean();
$assert(substr_count($html, '<section class="report-page') === 8, 'PDF otto pagine esplicite per 49 righe');
$assert(substr_count($html, '01/01/2025<small>') === 2 && substr_count($html, '18/02/2025<small>') === 2, 'PDF contiene anche gli estremi del periodo nei due archivi');
$assert(str_contains($html, 'class="both"'), 'pari merito min=max nella tabella PDF');
$assert(str_contains($html, 'Nessuno snapshot') === false && str_contains($html, 'chart-credit'), 'grafici ricchezza e credito distinti');
$report = $attack;
ob_start();
include __DIR__ . '/../partials/account_history_print.php';
$html = ob_get_clean();
$assert(str_contains($html, '&quot;https://example.invalid&quot;'), 'escape provenienza HTML');
$source = file_get_contents(__DIR__ . '/../account_history.php');
$assert(strpos($source, "'/bootstrap.php'") < strpos($source, "\$_GET['export']"), 'export dopo bootstrap protetto PIN');
$assert(str_contains($source, 'account_history_export_model($history)') && !str_contains($source, 'account_history_export_model($rows)'), 'export indipendente dalla paginazione');
foreach ([$book, $nullBook, $attackBook] as $workbook) {
    $workbook->disconnectWorksheets();
}
echo "OK: {$checks} verifiche export patrimonio. Nessun database o servizio esterno utilizzato.\n";
