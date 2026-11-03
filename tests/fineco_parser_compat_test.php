<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/fineco_xlsx_parser.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portafoglio-parser-' . bin2hex(random_bytes(8));
if (!mkdir($testDir, 0700)) {
    throw new RuntimeException('Impossibile creare la cartella temporanea dei test.');
}

$headers = ['Titolo', 'ISIN', 'Simbolo', 'Strumento', 'Valuta', 'Quantita',
    'P.zo medio di carico', 'Valore di carico', 'P.zo di mercato',
    'Valore di mercato euro', 'Var euro', 'Var %'];
$row = ['ETF esempio, azionario', 'IE00TEST0001', 'TEST', 'ETF', 'EUR', 10,
    100, 1000, 120, 1200, 200, 20];
$paths = [];

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_DEPRECATED);

try {
    foreach ([';' => 'semicolon', ',' => 'comma'] as $delimiter => $label) {
        $path = $testDir . DIRECTORY_SEPARATOR . $label . '.csv';
        $paths[] = $path;
        $handle = fopen($path, 'wb');
        fputcsv($handle, $headers, $delimiter, '"', '\\');
        fputcsv($handle, $row, $delimiter, '"', '\\');
        fclose($handle);
    }

    $book = new Spreadsheet();
    $book->getActiveSheet()->fromArray([$headers, $row]);
    foreach (['xls' => Xls::class, 'xlsx' => Xlsx::class] as $ext => $writerClass) {
        $path = $testDir . DIRECTORY_SEPARATOR . 'fineco.' . $ext;
        $paths[] = $path;
        (new $writerClass($book))->save($path);
    }
    $book->disconnectWorksheets();

    $parser = new FinecoXlsxParser();
    foreach ($paths as $path) {
        $positions = $parser->parse($path);
        if (count($positions) !== 1
            || $positions[0]->title !== 'ETF esempio, azionario'
            || $positions[0]->isin !== 'IE00TEST0001'
            || abs($positions[0]->quantity - 10.0) > 0.000001
            || abs($positions[0]->marketValue - 1200.0) > 0.000001
            || abs($positions[0]->unrealizedPnl - 200.0) > 0.000001
        ) {
            throw new RuntimeException('Valori importati non corretti per ' . basename($path));
        }
    }

    echo "OK: CSV con due separatori, XLS e XLSX importati senza deprecazioni.\n";
} finally {
    restore_error_handler();
    foreach ($paths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($testDir);
}
