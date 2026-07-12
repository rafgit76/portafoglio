<?php

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class FinecoParsedPosition
{
    public function __construct(
        public readonly string $title,
        public readonly string $isin,
        public readonly ?string $ticker,
        public readonly string $instrument,
        public readonly string $currency,
        public readonly float $quantity,
        public readonly float $avgLoadPrice,
        public readonly float $currentPrice,
        public readonly float $costBasis,
        public readonly float $marketValue,
        public readonly float $unrealizedPnl,
        public readonly float $varPct
    ) {
    }
}

final class FinecoXlsxParser
{
    private const HEADER_ALIASES = [
        'titolo' => ['titolo', 'strumento titolo'],
        'isin' => ['isin'],
        'simbolo' => ['simbolo', 'ticker'],
        'strumento' => ['strumento', 'tipo strumento', 'categoria'],
        'valuta' => ['valuta', 'divisa'],
        'quantita' => ['quantita', 'qta'],
        'p_zo_medio_di_carico' => ['pzo medio di carico', 'prezzo medio di carico'],
        'valore_di_carico' => ['valore di carico', 'controvalore di carico'],
        'p_zo_di_mercato' => ['pzo di mercato', 'prezzo di mercato'],
        'valore_di_mercato_euro' => ['valore di mercato eur', 'valore di mercato euro', 'valore di mercato'],
        'var_pct' => ['var', 'var percentuale'],
        'var_euro' => ['var eur', 'var euro'],
    ];

    private const REQUIRED_FIELDS = [
        'titolo',
        'isin',
        'quantita',
        'valore_di_carico',
        'valore_di_mercato_euro',
        'var_euro',
    ];

    private const NUMERIC_FIELDS = [
        'quantita' => true,
        'p_zo_medio_di_carico' => true,
        'valore_di_carico' => true,
        'p_zo_di_mercato' => true,
        'valore_di_mercato_euro' => true,
        'var_pct' => true,
        'var_euro' => true,
    ];

    public function parse(string $spreadsheetPath): array
    {
        if (class_exists(IOFactory::class)) {
            return $this->parseWithPhpSpreadsheet($spreadsheetPath);
        }

        if (strtolower(pathinfo($spreadsheetPath, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('Per importare file .xls e\' necessario caricare la cartella vendor completa.');
        }

        return $this->parseWithSimpleReader($spreadsheetPath);
    }

    private function parseWithPhpSpreadsheet(string $spreadsheetPath): array
    {
        $readerType = IOFactory::identify($spreadsheetPath);
        if (!in_array($readerType, ['Xls', 'Xlsx'], true)) {
            throw new RuntimeException('Formato Excel non supportato. Usa un export Fineco .xls o .xlsx.');
        }

        $reader = IOFactory::createReader($readerType);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($spreadsheetPath);

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $detection = $this->detectHeaderRowInSheet($sheet);

            if ($detection === null) {
                throw new RuntimeException('Intestazioni Fineco non trovate nel file.');
            }

            $positions = [];
            $maxRow = $sheet->getHighestDataRow();
            $emptyStreak = 0;

            for ($row = $detection['row'] + 1; $row <= $maxRow; $row++) {
                $record = [];
                foreach ($detection['headers'] as $field => $column) {
                    $cell = $sheet->getCell([$column, $row]);
                    $record[$field] = isset(self::NUMERIC_FIELDS[$field])
                        ? $cell->getCalculatedValue()
                        : trim((string) $cell->getFormattedValue());
                }

                $position = $this->positionFromRecord($record);
                if ($position === null) {
                    $emptyStreak++;
                    if ($emptyStreak >= 20) {
                        break;
                    }
                    continue;
                }

                $emptyStreak = 0;
                $positions[] = $position;
            }

            if ($positions === []) {
                throw new RuntimeException('Nessuna posizione valida trovata nel file caricato.');
            }

            return $positions;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function detectHeaderRowInSheet(Worksheet $sheet): ?array
    {
        $maxRow = min(120, $sheet->getHighestDataRow());
        $maxCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($row = 1; $row <= $maxRow; $row++) {
            $headers = [];

            for ($col = 1; $col <= $maxCol; $col++) {
                $value = trim((string) $sheet->getCell([$col, $row])->getFormattedValue());
                $field = $this->fieldForHeader($value);
                if ($field !== null && !isset($headers[$field])) {
                    $headers[$field] = $col;
                }
            }

            if ($this->headersAreEnough($headers)) {
                return ['row' => $row, 'headers' => $headers];
            }
        }

        return null;
    }

    private function parseWithSimpleReader(string $xlsxPath): array
    {
        $reader = new SimpleXlsxFinecoReader($xlsxPath);
        $rows = $reader->readFirstSheetRows(5000);
        $detection = $this->detectHeaderRowInArray($rows);

        if ($detection === null) {
            throw new RuntimeException('Intestazioni Fineco non trovate nel file.');
        }

        $positions = [];
        $emptyStreak = 0;

        foreach ($rows as $rowNum => $line) {
            if ($rowNum <= $detection['row']) {
                continue;
            }

            $record = [];
            foreach ($detection['headers'] as $field => $idx) {
                $record[$field] = trim((string) ($line[$idx] ?? ''));
            }

            $position = $this->positionFromRecord($record);
            if ($position === null) {
                $emptyStreak++;
                if ($emptyStreak >= 20) {
                    break;
                }
                continue;
            }

            $emptyStreak = 0;
            $positions[] = $position;
        }

        if ($positions === []) {
            throw new RuntimeException('Nessuna posizione valida trovata nel file caricato.');
        }

        return $positions;
    }

    private function detectHeaderRowInArray(array $rows): ?array
    {
        foreach ($rows as $rowNum => $line) {
            if ($rowNum > 120) {
                break;
            }

            $headers = [];
            foreach ($line as $idx => $value) {
                $field = $this->fieldForHeader((string) $value);
                if ($field !== null && !isset($headers[$field])) {
                    $headers[$field] = $idx;
                }
            }

            if ($this->headersAreEnough($headers)) {
                return ['row' => (int) $rowNum, 'headers' => $headers];
            }
        }

        return null;
    }

    private function headersAreEnough(array $headers): bool
    {
        if (count($headers) < 8) {
            return false;
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (!isset($headers[$field])) {
                return false;
            }
        }

        return true;
    }

    private function fieldForHeader(string $header): ?string
    {
        $normalized = $this->normalizeHeader($header);
        if ($normalized === '') {
            return null;
        }

        foreach (self::HEADER_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                if ($normalized === $alias) {
                    return $field;
                }
            }
        }

        return null;
    }

    private function positionFromRecord(array $record): ?FinecoParsedPosition
    {
        $title = trim((string) ($record['titolo'] ?? ''));
        $isin = strtoupper(preg_replace('/\s+/', '', trim((string) ($record['isin'] ?? ''))) ?? '');

        if ($title === '' || $this->isTotalOrSummaryRow($title, $isin, $record)) {
            return null;
        }

        foreach ([
            'quantita' => 'quantita',
            'valore_di_carico' => 'valore di carico',
            'valore_di_mercato_euro' => 'valore di mercato',
            'var_euro' => 'P/L',
        ] as $field => $label) {
            if (!$this->isNumericValue($record[$field] ?? null)) {
                throw new RuntimeException('Valore ' . $label . ' non valido per il titolo ' . $title . '.');
            }
        }

        $ticker = trim((string) ($record['simbolo'] ?? ''));
        if ($ticker === '' || $ticker === '-') {
            $ticker = null;
        }

        return new FinecoParsedPosition(
            title: $title,
            isin: $isin,
            ticker: $ticker,
            instrument: trim((string) ($record['strumento'] ?? '')),
            currency: strtoupper(trim((string) ($record['valuta'] ?? 'EUR')) ?: 'EUR'),
            quantity: parse_decimal($record['quantita'] ?? null),
            avgLoadPrice: parse_decimal($record['p_zo_medio_di_carico'] ?? null),
            currentPrice: parse_decimal($record['p_zo_di_mercato'] ?? null),
            costBasis: parse_decimal($record['valore_di_carico'] ?? null),
            marketValue: parse_decimal($record['valore_di_mercato_euro'] ?? null),
            unrealizedPnl: parse_decimal($record['var_euro'] ?? null),
            varPct: parse_percentage_to_decimal($record['var_pct'] ?? null)
        );
    }

    private function isNumericValue(mixed $value): bool
    {
        return parse_decimal_nullable($value) !== null;
    }

    private function isTotalOrSummaryRow(string $title, string $isin, array $record): bool
    {
        $normalizedTitle = $this->normalizeHeader($title);
        if (str_starts_with($normalizedTitle, 'totale') || str_starts_with($normalizedTitle, 'totali')) {
            return true;
        }

        if ($isin === 'VALOREDICARICO') {
            return true;
        }

        $symbol = trim((string) ($record['simbolo'] ?? ''));
        $instrument = trim((string) ($record['strumento'] ?? ''));
        if ($isin !== '' || $symbol !== '' || $instrument !== '') {
            return false;
        }

        return preg_match('/^[A-Z]{3}$/', strtoupper($title)) === 1
            || str_contains(strtoupper($title), 'LIQUIDITA')
            || str_contains(strtoupper($title), 'DISPONIBILITA')
            || str_contains(strtoupper($title), 'SALDO');
    }

    private function normalizeHeader(string $header): string
    {
        $header = trim(mb_strtolower($header, 'UTF-8'));
        $header = str_replace(["\u{00A0}", '%', '€'], [' ', ' percentuale ', ' eur '], $header);
        $header = str_replace(['p.zo', 'pzo', 'prezzo'], ['prezzo', 'prezzo', 'prezzo'], $header);
        $header = normalize_ascii($header);
        $header = str_replace(["'", '`'], '', $header);
        $header = preg_replace('/[^a-z0-9]+/', ' ', $header) ?? '';
        $header = preg_replace('/\s+/', ' ', $header) ?? '';
        $header = trim($header);

        return match ($header) {
            'var percentuale' => 'var',
            default => $header,
        };
    }
}

function convert_fineco_spreadsheet_to_xlsx(string $sourcePath, string $destinationPath): void
{
    if (!class_exists(IOFactory::class) || !class_exists(\PhpOffice\PhpSpreadsheet\Writer\Xlsx::class)) {
        throw new RuntimeException('Conversione Excel non disponibile. Carica la cartella vendor completa.');
    }

    $readerType = IOFactory::identify($sourcePath);
    if (!in_array($readerType, ['Xls', 'Xlsx'], true)) {
        throw new RuntimeException('Formato Excel non supportato.');
    }

    $reader = IOFactory::createReader($readerType);
    $spreadsheet = $reader->load($sourcePath);

    try {
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($destinationPath);
    } finally {
        $spreadsheet->disconnectWorksheets();
    }
}
