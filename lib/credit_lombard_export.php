<?php
declare(strict_types=1);

function lombard_export_rows(array $monitor): array
{
    $rows = [];
    foreach (array_reverse($monitor['rows'] ?? []) as $row) {
        $limit = isset($row['rate']['credit_limit']) ? (float) $row['rate']['credit_limit'] : null;
        $rows[] = [
            'date' => $row['date'],
            'balance' => $row['balance'],
            'usage' => $row['usage'],
            'percentage' => $row['usage'] !== null && $limit !== null && $limit > 0 ? $row['usage'] / $limit : null,
            'tan' => isset($row['rate']['tan']) ? (float) $row['rate']['tan'] : null,
            'interest' => $row['interest'],
            'cumulative' => $row['cumulative'],
        ];
    }
    return $rows;
}

function lombard_export_workbook(array $monitor): \PhpOffice\PhpSpreadsheet\Spreadsheet
{
    $rows = lombard_export_rows($monitor);
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $book->getProperties()->setCreator('Portfolio Investimenti')->setTitle('Storico Credit Lombard');
    $sheet = $book->getActiveSheet()->setTitle('Storico Lombard');
    $text = static function (string $cell, string $value) use ($sheet): void {
        $sheet->setCellValueExplicit($cell, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    };
    $text('A1', 'Credit Lombard - storico giornaliero osservato');
    $sheet->mergeCells('A1:G1');
    $text('A2', 'Stima dal saldo contabile, non utilizzo ufficiale Fineco. Interessi osservati, non importi dovuti.');
    $sheet->mergeCells('A2:G2');
    $text('A3', sprintf('Giorni senza saldo: %d; giorni con utilizzo senza tasso: %d. I giorni mancanti non sono interpolati. Celle vuote: dato non disponibile.', $monitor['missing_days'], $monitor['missing_rates']));
    $sheet->mergeCells('A3:G3');
    $text('A4', 'Divisore 365. Date valuta e movimenti infragiornalieri possono differire; il tasso sull eccedenza e un ipotesi da verificare con Fineco.');
    $sheet->mergeCells('A4:G4');
    foreach (['Data', 'Saldo contabile EUR', 'Utilizzo stimato EUR', '% plafond', 'TAN ordinario', 'Interesse stimato EUR', 'Cumulato osservato EUR'] as $index => $label) {
        $text(chr(65 + $index) . '5', $label);
    }
    foreach ($rows as $index => $row) {
        $line = $index + 6;
        $date = new DateTimeImmutable($row['date']);
        $sheet->setCellValueExplicit('A' . $line, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($date), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        foreach (['B' => 'balance', 'C' => 'usage', 'D' => 'percentage', 'E' => 'tan', 'F' => 'interest', 'G' => 'cumulative'] as $column => $field) {
            if ($row[$field] !== null) {
                $sheet->setCellValueExplicit($column . $line, (float) $row[$field], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            }
        }
    }
    $last = max(6, count($rows) + 5);
    $sheet->getStyle('A1:G' . $last)->getFont()->setName('Calibri')->setSize(11);
    $sheet->getStyle('A1')->getFont()->setSize(17)->setBold(true)->getColor()->setRGB('087F78');
    $sheet->getStyle('A2:G4')->getAlignment()->setWrapText(true);
    $sheet->getRowDimension(3)->setRowHeight(32);
    $sheet->getRowDimension(4)->setRowHeight(32);
    $sheet->getStyle('A5:G5')->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '087F78']]]);
    $sheet->getStyle('A6:A' . $last)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    $sheet->getStyle('B6:C' . $last)->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00;0.00');
    $sheet->getStyle('D6:E' . $last)->getNumberFormat()->setFormatCode('0.00%');
    $sheet->getStyle('F6:G' . $last)->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00;0.00');
    foreach (['A' => 16, 'B' => 23, 'C' => 23, 'D' => 16, 'E' => 18, 'F' => 25, 'G' => 27] as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }
    $sheet->freezePane('B6');
    $sheet->setAutoFilter('A5:G' . $last);
    $sheet->setShowGridlines(false);
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 5)->setOrientation('landscape')->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
    return $book;
}
