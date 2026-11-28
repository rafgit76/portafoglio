<?php
require_once __DIR__ . '/account_tracker.php';

function account_history_prepare(array $history): array
{
    $previous = null;
    foreach ($history as &$row) {
        $row['economic'] ??= account_tracker_economic_snapshot($row);
        $pne = $row['economic']['pne'];
        $row['pne_num'] = $pne;
        $row['pne_delta_num'] = $previous !== null && $pne !== null ? round($pne - $previous, 2) : null;
        $row['economic_liquidity_num'] = $row['economic']['net_liquidity'];
        $row['economic_credit_used_num'] = $row['economic']['credit_used'];
        $row['pne_candidate_num'] = $row['economic']['reliable'] ? null : $row['economic']['candidate'];
        $row['pne_reconstructed_num'] = $row['economic']['pne_reconstructed'];
        $row['pne_quadrature_num'] = $row['economic']['quadrature'];
        $row['economic_pending_num'] = $row['economic']['pending_operations'];
        $previous = $pne;
        $positionPortfolio = isset($row['portfolio_market_value']) ? (float) $row['portfolio_market_value'] : null;
        $portfolioValue = account_tracker_snapshot_decimal($row, 'fineco_portfolio_total') ?? $positionPortfolio;
        $adjustments = account_tracker_patrimonial_adjustments([
            'fineco_total_assets' => account_tracker_snapshot_decimal($row, 'fineco_total_assets'),
            'liquidity_total' => account_tracker_snapshot_decimal($row, 'liquidity_total'),
            'credit_available_amount' => account_tracker_snapshot_decimal($row, 'credit_available_amount'),
        ]);
        $wealth = account_tracker_reconstructed_wealth($portfolioValue, $adjustments['own_liquidity_adjusted'], null);
        $row['portfolio_market_value_num'] = $portfolioValue;
        $row['portfolio_position_value_num'] = $positionPortfolio;
        $row['own_liquidity_adjusted_num'] = $adjustments['own_liquidity_adjusted'];
        $row['reconstructed_gross_num'] = $wealth['gross'];
        $row['net_adjusted_num'] = $adjustments['net_assets_adjusted'];
    }
    unset($row);
    return $history;
}

function account_history_export_fields(): array
{
    return [
        'fineco_total_assets' => 'Patrimonio Fineco',
        'net_adjusted_num' => 'Netto rettificato',
        'portfolio_market_value_num' => 'Portafoglio di riferimento',
        'liquidity_total' => 'Liquidita Fineco',
        'own_liquidity_adjusted_num' => 'Liquidita propria rettificata',
        'reconstructed_gross_num' => 'Patrimonio ricostruito',
        'portfolio_position_value_num' => 'Portafoglio da posizioni',
        'accounting_balance' => 'Saldo contabile',
        'available_balance' => 'Saldo disponibile',
        'reserved_liquidity' => 'Liquidita prenotata',
        'credit_agreed_amount' => 'Fido accordato',
        'credit_used_amount' => 'Fido utilizzato',
        'credit_available_amount' => 'Credito disponibile',
    ];
}

function account_history_economic_fields(): array
{
    return [
        'pne_num' => 'Patrimonio netto economico',
        'pne_delta_num' => 'Variazione vs snapshot precedente',
        'economic_liquidity_num' => 'Liquidita economica netta',
        'economic_credit_used_num' => 'Fido utilizzato (anche derivato)',
        'pne_candidate_num' => 'PNE ipotetico, non validato',
        'pne_reconstructed_num' => 'Controllo PNE analitico',
        'pne_quadrature_num' => 'Scarto PNE / portafoglio + liquidita',
        'economic_pending_num' => 'Operazioni in corso',
        'multicurrency_amount' => 'Multicurrency',
        'credit_lombard_account' => 'Conto Credit Lombard',
        'other_liquidity' => 'Altra liquidita registrata',
    ];
}

function account_history_export_value(array $row, string $field): ?float
{
    return str_ends_with($field, '_num')
        ? ($row[$field] ?? null)
        : account_tracker_snapshot_decimal($row, $field);
}

function account_history_export_money(?float $value): string
{
    return $value === null ? 'N/D' : number_format($value, 2, ',', '.');
}

function account_history_export_date(string $date): string
{
    return (new DateTimeImmutable($date))->format('d/m/Y');
}

function account_history_export_origin(array $row): string
{
    return match ($row['data_origin'] ?? '') {
        'IMPORT_FINECO' => 'Import Fineco',
        'CALCOLATO' => 'Calcolato',
        'MANUALE', 'manual' => 'Manuale',
        default => (string) ($row['data_origin'] ?? 'Non specificata'),
    };
}

function account_history_export_model(array $history): array
{
    usort($history, static fn(array $a, array $b): int => [$a['snapshot_date'], $a['id'] ?? 0] <=> [$b['snapshot_date'], $b['id'] ?? 0]);
    $history = account_history_prepare($history);
    $fields = account_history_export_fields() + account_history_economic_fields();
    $stats = [];
    foreach ($fields as $field => $label) {
        $stats[$field] = ['min' => null, 'max' => null, 'count' => 0];
        foreach ($history as $row) {
            $value = account_history_export_value($row, $field);
            if ($value === null) {
                continue;
            }
            // Extrema use the same cent precision as the displayed/exported amounts.
            $value = round($value, 2);
            $stats[$field]['count']++;
            foreach (['min', 'max'] as $direction) {
                $current = $stats[$field][$direction];
                if ($current === null || ($direction === 'min' ? $value < $current['value'] : $value > $current['value'])) {
                    $stats[$field][$direction] = ['value' => $value, 'dates' => [$row['snapshot_date']]];
                } elseif ($value === $current['value']) {
                    $stats[$field][$direction]['dates'][] = $row['snapshot_date'];
                    $stats[$field][$direction]['dates'] = array_values(array_unique($stats[$field][$direction]['dates']));
                }
            }
        }
    }
    $latest = $history === [] ? null : $history[array_key_last($history)];
    $firstNet = $history === [] ? null : $history[0]['net_adjusted_num'];
    $lastNet = $latest['net_adjusted_num'] ?? null;
    return [
        'rows' => $history,
        'latest' => $latest,
        'fields' => $fields,
        'stats' => $stats,
        'net_change' => $firstNet !== null && $lastNet !== null ? $lastNet - $firstNet : null,
        'pne_change' => isset($history[0]['pne_num'], $latest['pne_num']) ? round($latest['pne_num'] - $history[0]['pne_num'], 2) : null,
        'period' => $history === [] ? 'Nessuno snapshot' : account_history_export_date($history[0]['snapshot_date']) . ' - ' . account_history_export_date($latest['snapshot_date']),
    ];
}

function account_history_export_notes(): array
{
    return [
        'PNE = patrimonio Fineco meno il fido accordato incluso, se dimostrabile. Il valore ipotetico non entra in grafici, variazioni ed estremi PNE. I valori legacy non sono PNE.',
        'Tutti gli importi sono in EUR. Archivio completo, indipendente dalla paginazione. N/D o cella vuota: dato non disponibile, non zero.',
        'Netto rettificato = patrimonio Fineco - credito disponibile. Liquidita propria = liquidita Fineco - credito disponibile. Se il credito disponibile manca, le rettifiche non sono calcolate.',
        'Portafoglio di riferimento: totale dossier Fineco, oppure valore delle posizioni consolidate della stessa data se il totale Fineco manca. Ricostruito = portafoglio di riferimento + liquidita propria.',
        'Patrimonio e liquidita Fineco possono includere credito. Il saldo disponibile rappresenta capacita di spesa, non patrimonio proprio. I fidi restano separati; accordato e utilizzato non sono sottratti nuovamente.',
        'Estremi calcolati sulle rilevazioni disponibili, al centesimo. Verde: massimo; rosa: minimo; blu: minimo e massimo coincidenti. Pari merito conservati. La variazione di valore non e un rendimento: puo includere movimenti di denaro.',
        'Elaborazione personale su dati importati e/o manuali. Non e un documento ufficiale Fineco. Nessuno snapshot viene modificato e nessun report AI viene generato.',
    ];
}

function account_history_export_workbook(array $report): \PhpOffice\PhpSpreadsheet\Spreadsheet
{
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $book->getProperties()->setCreator('Portfolio Investimenti')->setTitle('Andamento patrimonio')->setDescription('Storico completo; patrimonio e credito separati.');
    $numberFormat = '#,##0.00;[Red]-#,##0.00;0.00';
    $text = static function ($sheet, string $cell, string $value): void {
        $sheet->setCellValueExplicit($cell, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    };
    $style = static function ($sheet, int $lastColumn, int $lastRow) use ($numberFormat): void {
        $end = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColumn);
        $sheet->getStyle("A1:{$end}{$lastRow}")->getFont()->setName('Calibri')->setSize(11);
        $sheet->getStyle("A4:{$end}4")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '065497']]]);
        $sheet->getRowDimension(4)->setRowHeight(44);
        $sheet->getStyle("A4:{$end}4")->getAlignment()->setWrapText(true);
        $sheet->getStyle('A1')->getFont()->setSize(20)->setBold(true)->getColor()->setRGB('065497');
        $sheet->getStyle("B5:{$end}{$lastRow}")->getNumberFormat()->setFormatCode($numberFormat);
        $sheet->freezePane('B5');
        $sheet->setAutoFilter("A4:{$end}{$lastRow}");
        $sheet->setShowGridlines(false);
        $sheet->getColumnDimension('A')->setWidth(17);
        for ($col = 2; $col <= $lastColumn; $col++) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col))->setWidth(24);
        }
        for ($row = 5; $row <= $lastRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(28);
            if ($row % 2 === 1) {
                $sheet->getStyle("A{$row}:{$end}{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('F0F5FA');
            }
        }
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 4)->setOrientation('landscape')->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
    };
    $summary = $book->getActiveSheet()->setTitle('Riepilogo');
    $text($summary, 'A1', 'Andamento patrimonio');
    $summary->mergeCells('A1:G1');
    $text($summary, 'A2', $report['period'] . ' | ' . count($report['rows']) . ' snapshot | EUR');
    $summary->mergeCells('A2:G2');
    foreach (['Metrica', 'Ultimo snapshot', 'Minimo', 'Date minimo', 'Massimo', 'Date massimo', 'Rilevazioni valide'] as $i => $label) {
        $text($summary, chr(65 + $i) . '4', $label);
    }
    $r = 5;
    foreach ($report['fields'] as $field => $label) {
        $text($summary, 'A' . $r, $label);
        $stats = $report['stats'][$field];
        foreach (['B' => account_history_export_value($report['latest'] ?? [], $field), 'C' => $stats['min']['value'] ?? null, 'E' => $stats['max']['value'] ?? null, 'G' => $stats['count']] as $col => $value) {
            if ($value !== null) {
                $summary->setCellValueExplicit($col . $r, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            }
        }
        foreach (['D' => 'min', 'F' => 'max'] as $col => $direction) {
            $text($summary, $col . $r, implode(', ', array_map('account_history_export_date', $stats[$direction]['dates'] ?? [])));
        }
        $r++;
    }
    $style($summary, 7, $r - 1);
    $summary->getColumnDimension('A')->setWidth(32);
    $summary->getStyle('A5:G' . ($r - 1))->getAlignment()->setWrapText(true);
    $summary->getStyle('G5:G' . ($r - 1))->getNumberFormat()->setFormatCode('0');
    for ($row = 5; $row < $r; $row++) {
        $summary->getRowDimension($row)->setRowHeight(-1);
    }
    $r += 2;
    foreach (account_history_export_notes() as $note) {
        $summary->mergeCells("A{$r}:G{$r}");
        $text($summary, 'A' . $r, $note);
        $summary->getStyle("A{$r}")->getAlignment()->setWrapText(true);
        $summary->getRowDimension($r)->setRowHeight(35);
        $r++;
    }
    $groups = [
        'Patrimonio' => array_slice(account_history_export_fields(), 0, 7, true),
        'Saldi e credito' => array_slice(account_history_export_fields(), 7, null, true),
        'PNE' => account_history_economic_fields() + array_intersect_key(account_history_export_fields(), array_flip(['fineco_total_assets', 'portfolio_market_value_num', 'liquidity_total', 'accounting_balance', 'available_balance', 'credit_agreed_amount', 'credit_available_amount', 'reserved_liquidity'])),
    ];
    foreach ($groups as $name => $fields) {
        $sheet = $book->createSheet()->setTitle($name);
        $text($sheet, 'A1', $name . ' | Storico completo');
        $sheet->mergeCells('A1:F1');
        $text($sheet, 'A2', $report['period'] . ' | Importi EUR | Date piu recenti per prime');
        $sheet->mergeCells('A2:F2');
        $headers = array_merge(['Data'], array_values($fields), ['Origine snapshot'], $name === 'PNE' ? ['Stato PNE', 'Diagnostica'] : []);
        foreach ($headers as $i => $label) {
            $text($sheet, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . '4', $label);
        }
        $r = 5;
        foreach (array_reverse($report['rows']) as $row) {
            $sheet->setCellValueExplicit('A' . $r, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTimeImmutable($row['snapshot_date'])), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $c = 2;
            foreach ($fields as $field => $label) {
                $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c++) . $r;
                $value = account_history_export_value($row, $field);
                if ($value !== null) {
                    $sheet->setCellValueExplicit($cell, round($value, 2), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                }
            }
            $text($sheet, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r, account_history_export_origin($row));
            if ($name === 'PNE') {
                $text($sheet, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1) . $r, $row['economic']['status']);
                $issues = array_map(static fn(string $issue): string => $issue . (isset($row['economic']['checks'][$issue]) ? ': ' . account_history_export_money($row['economic']['checks'][$issue]) . ' EUR' : ''), $row['economic']['issues']);
                $text($sheet, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 2) . $r, implode(' | ', $issues));
            }
            $r++;
        }
        $style($sheet, count($headers), max(5, $r - 1));
        $sheet->getStyle('A5:A' . max(5, $r - 1))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $r = 5;
        foreach (array_reverse($report['rows']) as $row) {
            $c = 2;
            foreach ($fields as $field => $label) {
                $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c++) . $r;
                $value = account_history_export_value($row, $field);
                if ($value === null) {
                    continue;
                }
                $max = $report['stats'][$field]['max']['value'];
                $min = $report['stats'][$field]['min']['value'];
                $color = round($value, 2) === $max && $max === $min ? 'E7EEFB' : (round($value, 2) === $max ? 'DEF3E6' : (round($value, 2) === $min ? 'FDE8EC' : null));
                if ($color !== null) {
                    $sheet->getStyle($cell)->getFill()->setFillType('solid')->getStartColor()->setRGB($color);
                }
            }
            $r++;
        }
    }
    $book->setActiveSheetIndex(0);
    return $book;
}
