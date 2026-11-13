<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/credit_lombard_interest.php';
require_once __DIR__ . '/../lib/credit_lombard_export.php';

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    $count++;
    if (!$condition) { throw new RuntimeException('Test fallito: ' . $message); }
};
$rates = [['valid_from' => '2026-11-02', 'tan' => 0.034, 'overdraft_tan' => 0.1115, 'credit_limit' => 12000]];
$monitor = lombard_monitor([
    ['snapshot_date' => '2026-11-01', 'accounting_balance' => -100],
    ['snapshot_date' => '2026-11-03', 'accounting_balance' => null],
    ['snapshot_date' => '2026-11-04', 'accounting_balance' => -13000],
], $rates, '2026-11-01');
$rows = lombard_export_rows($monitor);
$assert(count($rows) === 3 && $rows[0]['date'] === '2026-11-04' && $rows[2]['date'] === '2026-11-01', 'tutte le rilevazioni, ordinate per data decrescente');
$assert($rows[1]['balance'] === null && $rows[1]['usage'] === null && $rows[1]['interest'] === null, 'saldo assente non trasformato in zero');
$assert($rows[2]['tan'] === null && $rows[2]['interest'] === null, 'tasso e interesse mancanti');
$assert(abs($rows[0]['percentage'] - 13000 / 12000) < 0.0000001, 'percentuale oltre plafond');
$book = lombard_export_workbook($monitor);
$temporary = tempnam(sys_get_temp_dir(), 'lombard-xlsx-');
if ($temporary === false) { throw new RuntimeException('Impossibile preparare il file di test.'); }
try {
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($temporary);
    $book->disconnectWorksheets();
    $loaded = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($temporary);
    $sheet = $loaded->getActiveSheet();
    $assert($sheet->getCell('A6')->getDataType() === 'n', 'data Excel numerica');
    $assert(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($sheet->getCell('A6')->getValue())->format('Y-m-d') === '2026-11-04', 'data Excel corretta');
    $assert($sheet->getCell('B6')->getDataType() === 'n' && (float) $sheet->getCell('B6')->getValue() === -13000.0, 'saldo numerico');
    $assert(abs((float) $sheet->getCell('D6')->getValue() - 13000 / 12000) < 0.0000001, 'percentuale numerica');
    $assert($sheet->getStyle('D6')->getNumberFormat()->getFormatCode() === '0.00%', 'formato percentuale');
    $assert($sheet->getCell('B7')->getValue() === null && $sheet->getCell('F7')->getValue() === null, 'cella assente resta vuota dopo salvataggio');
    $assert($sheet->getCell('E8')->getValue() === null && $sheet->getCell('F8')->getValue() === null, 'nessun tasso inventato');
    $assert(str_contains((string) $sheet->getCell('A3')->getValue(), 'Giorni senza saldo: 2') && str_contains((string) $sheet->getCell('A3')->getValue(), 'senza tasso: 1'), 'avvertenze sulle lacune');
    $assert(str_contains((string) $sheet->getCell('A2')->getValue(), 'non utilizzo ufficiale'), 'avvertenza sulle stime');
    $assert($sheet->getAutoFilter()->getRange() === 'A5:G8', 'filtro su tutto lo storico');
    $loaded->disconnectWorksheets();
} finally {
    unlink($temporary);
}
echo "OK: $count controlli export Credit Lombard.\n";
