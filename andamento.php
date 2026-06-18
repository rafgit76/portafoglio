<?php
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Andamento';
$pdo = db();

$assetTypeOrder = [
    'AZIONE' => 1,
    'ETF' => 2,
    'PAC' => 3,
];

$columns = $pdo->query(
    'SELECT DISTINCT
        a.id,
        a.asset_type,
        a.name,
        a.ticker,
        a.isin
     FROM pf_assets a
     JOIN pf_snapshot_positions p ON p.asset_id = a.id
     JOIN pf_snapshots s ON s.id = p.snapshot_id
     WHERE a.asset_type IN ("AZIONE", "ETF", "PAC")
     ORDER BY
        CASE a.asset_type
            WHEN "AZIONE" THEN 1
            WHEN "ETF" THEN 2
            WHEN "PAC" THEN 3
            ELSE 99
        END,
        a.name ASC,
        a.ticker ASC,
        a.id ASC'
)->fetchAll();

$rows = [];

$history = $pdo->query(
    'SELECT
        s.snapshot_date,
        p.asset_id,
        p.quantity,
        p.market_value,
        p.unrealized_pnl
     FROM pf_snapshots s
     LEFT JOIN pf_snapshot_positions p ON p.snapshot_id = s.id
     LEFT JOIN pf_assets a ON a.id = p.asset_id
     WHERE p.asset_id IS NULL OR a.asset_type IN ("AZIONE", "ETF", "PAC")
     ORDER BY s.snapshot_date ASC, p.asset_id ASC'
)->fetchAll();

foreach ($history as $entry) {
    $snapshotDate = (string) $entry['snapshot_date'];
    if (!isset($rows[$snapshotDate])) {
        $rows[$snapshotDate] = [
            'snapshot_date' => $snapshotDate,
            'values' => [],
        ];
    }

    if ($entry['asset_id'] !== null) {
        $rows[$snapshotDate]['values'][(int) $entry['asset_id']] = [
            'quantity' => (float) $entry['quantity'],
            'market_value' => (float) $entry['market_value'],
            'unrealized_pnl' => (float) $entry['unrealized_pnl'],
        ];
    }
}

foreach ($columns as &$column) {
    $type = (string) $column['asset_type'];
    $column['sort_weight'] = $assetTypeOrder[$type] ?? 99;

    $name = trim((string) $column['name']);
    $ticker = trim((string) ($column['ticker'] ?? ''));
    $isin = trim((string) ($column['isin'] ?? ''));

    if ($ticker !== '' && $ticker !== '-') {
        $column['label'] = $name . ' (' . $ticker . ')';
        $column['short_label'] = $ticker;
    } elseif ($isin !== '') {
        $column['label'] = $name . ' (' . $isin . ')';
        $column['short_label'] = mb_strimwidth($name, 0, 14, '...');
    } else {
        $column['label'] = $name;
        $column['short_label'] = mb_strimwidth($name, 0, 14, '...');
    }
}
unset($column);

$orderedRows = array_values($rows);
$formatQuantity = static function (float $value): string {
    $formatted = number_format($value, 6, ',', '.');
    $formatted = rtrim($formatted, '0');
    $formatted = rtrim($formatted, ',');
    return $formatted === '' ? '0' : $formatted;
};
$formatMoney = static fn(float $value): string => 'EUR ' . number_format($value, 2, ',', '.');
$maxMarketByAsset = [];
$maxPnlByAsset = [];
$maxPnlDateByAsset = [];
$minMarketByAsset = [];
$minPnlByAsset = [];

foreach ($columns as $column) {
    $assetId = (int) $column['id'];
    $marketValues = [];
    $pnlValues = [];

    foreach ($orderedRows as $row) {
        $value = $row['values'][$assetId] ?? null;
        if ($value === null) {
            continue;
        }

        $marketValues[] = (float) $value['market_value'];
        $pnlValues[] = (float) $value['unrealized_pnl'];
    }

    $maxMarketByAsset[$assetId] = $marketValues ? max($marketValues) : null;
    $maxPnlByAsset[$assetId] = $pnlValues ? max($pnlValues) : null;
    $minMarketByAsset[$assetId] = $marketValues ? min($marketValues) : null;
    $minPnlByAsset[$assetId] = $pnlValues ? min($pnlValues) : null;

    $maxPnlDateByAsset[$assetId] = null;
    if ($maxPnlByAsset[$assetId] !== null) {
        foreach ($orderedRows as $row) {
            $value = $row['values'][$assetId] ?? null;
            if ($value !== null && abs((float) $value['unrealized_pnl'] - (float) $maxPnlByAsset[$assetId]) < 0.00001) {
                $maxPnlDateByAsset[$assetId] = (string) $row['snapshot_date'];
                break;
            }
        }
    }
}

if (($_GET['export'] ?? '') === 'xlsx') {
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        http_response_code(500);
        exit('PhpSpreadsheet non disponibile. Esegui composer install e carica vendor/.');
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('Portfolio Investimenti')
        ->setTitle('Andamento storico per titolo')
        ->setSubject('Storico snapshot portafoglio');

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Andamento');

    $generatedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i');
    $sheet->setCellValue('A1', 'Andamento storico per titolo');
    $sheet->setCellValue('A2', 'Generato il ' . $generatedAt . ' - Snapshot: ' . count($orderedRows) . ' - Titoli: ' . count($columns));

    $headerRow = 4;
    $metricRow = 5;
    $sheet->setCellValue('A' . $headerRow, 'DATA');
    $sheet->mergeCells('A' . $headerRow . ':A' . $metricRow);

    $columnIndex = 2;
    foreach ($columns as $column) {
        $startColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
        $endColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex + 2);
        $sheet->mergeCells($startColumn . $headerRow . ':' . $endColumn . $headerRow);
        $sheet->setCellValue($startColumn . $headerRow, $column['label']);
        $sheet->setCellValue($startColumn . $metricRow, 'QTA');
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex + 1) . $metricRow, 'VAL');
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex + 2) . $metricRow, 'P/L');
        $columnIndex += 3;
    }

    $dataRow = 6;
    foreach (array_reverse($orderedRows) as $row) {
        $sheet->setCellValue('A' . $dataRow, $row['snapshot_date']);
        $columnIndex = 2;

        foreach ($columns as $column) {
            $value = $row['values'][(int) $column['id']] ?? null;
            if ($value !== null) {
                $sheet->setCellValueExplicit(
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex) . $dataRow,
                    (float) $value['quantity'],
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                );
                $sheet->setCellValueExplicit(
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex + 1) . $dataRow,
                    (float) $value['market_value'],
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                );
                $sheet->setCellValueExplicit(
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex + 2) . $dataRow,
                    (float) $value['unrealized_pnl'],
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                );
            }
            $columnIndex += 3;
        }

        $dataRow++;
    }

    $lastColumnIndex = max(1, 1 + count($columns) * 3);
    $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColumnIndex);
    $lastDataRow = max($metricRow, $dataRow - 1);

    $sheet->mergeCells('A1:' . $lastColumn . '1');
    $sheet->mergeCells('A2:' . $lastColumn . '2');
    $sheet->freezePane('B6');
    $sheet->setAutoFilter('A' . $metricRow . ':' . $lastColumn . $lastDataRow);

    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A2')->getFont()->setSize(10)->getColor()->setARGB('FF647681');
    $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $metricRow)->getFont()->setBold(true);
    $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $metricRow)
        ->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()
        ->setARGB('FFE7F0F4');
    $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $lastDataRow)
        ->getBorders()
        ->getAllBorders()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
        ->getColor()
        ->setARGB('FFD8E2E8');

    for ($i = 2; $i <= $lastColumnIndex; $i += 3) {
        $qtyColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $valColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $pnlColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 2);
        $sheet->getStyle($qtyColumn . '6:' . $qtyColumn . $lastDataRow)->getNumberFormat()->setFormatCode('#,##0.######');
        $sheet->getStyle($valColumn . '6:' . $valColumn . $lastDataRow)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle($pnlColumn . '6:' . $pnlColumn . $lastDataRow)->getNumberFormat()->setFormatCode('#,##0.00');
    }

    $sheet->getColumnDimension('A')->setWidth(12);
    for ($i = 2; $i <= $lastColumnIndex; $i++) {
        $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setWidth(12);
    }

    $filename = 'andamento_portafoglio_' . (new DateTimeImmutable('now'))->format('Ymd_His') . '.xlsx';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

include __DIR__ . '/partials/header.php';
?>
<style>
main.container {
    width: min(1520px, 98%);
}
</style>
<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Andamento storico per titolo</h2>
            <p class="tiny-note">Tabella unica ordinabile. Un click su una colonna la ordina in modo crescente o decrescente.</p>
        </div>
        <span class="muted-small">
            Snapshot: <?= count($orderedRows) ?> | Titoli: <?= count($columns) ?> | Colonne dati: <?= count($columns) * 3 ?>
        </span>
    </div>
</section>

<section class="panel">
    <?php if (!$orderedRows || !$columns): ?>
        <p>Dati insufficienti per costruire l'andamento. Servono snapshot con posizioni salvate.</p>
    <?php else: ?>
        <div class="andamento-toolbar">
            <span class="tiny-note">Ordina cliccando l'intestazione della colonna. Le evidenziazioni MIN/MAX restano solo su VAL e P/L.</span>
            <div class="andamento-controls">
                <label class="andamento-page-size">
                    <span>Righe</span>
                    <select id="andamentoPageSize">
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="all">Tutto</option>
                    </select>
                </label>
                <div class="andamento-pagination" id="andamentoPager"></div>
            </div>
        </div>

        <div class="andamento-table-wrap">
            <table class="andamento-table" id="andamentoTable">
                <thead>
                    <tr>
                        <th class="sticky-col sortable andamento-date-head" data-sort-type="date" rowspan="2">DATA</th>
                        <?php foreach ($columns as $column): ?>
                            <th
                                class="andamento-heading-group asset-group-end"
                                colspan="3"
                                title="<?= e($column['label']) ?> - <?= e($column['asset_type']) ?>"
                            >
                                <div class="andamento-heading-title"><?= e($column['short_label']) ?></div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <th
                                class="andamento-heading-cell andamento-heading-cell-qty sortable"
                                data-sort-type="number"
                                title="<?= e($column['label']) ?> - Quantita - <?= e($column['asset_type']) ?>"
                            >
                                <div class="andamento-heading-metric">QTA</div>
                            </th>
                            <th
                                class="andamento-heading-cell sortable"
                                data-sort-type="number"
                                title="<?= e($column['label']) ?> - Valore di mercato - <?= e($column['asset_type']) ?>"
                            >
                                <div class="andamento-heading-metric">VAL</div>
                            </th>
                            <th
                                class="andamento-heading-cell andamento-heading-cell-pl asset-group-end sortable"
                                data-sort-type="number"
                                title="<?= e($column['label']) ?> - Profit/Loss - <?= e($column['asset_type']) ?>"
                            >
                                <div class="andamento-heading-metric">P/L</div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orderedRows as $row): ?>
                        <?php
                        $dateLabel = $row['snapshot_date'];
                        try {
                            $dateLabel = (new DateTimeImmutable($row['snapshot_date']))->format('Y-m-d');
                        } catch (Throwable $e) {
                            $dateLabel = (string) $row['snapshot_date'];
                        }
                        ?>
                        <tr>
                            <td class="sticky-col date-cell" data-sort-value="<?= e($row['snapshot_date']) ?>"><?= e($dateLabel) ?></td>
                            <?php foreach ($columns as $column): ?>
                                <?php $value = $row['values'][(int) $column['id']] ?? null; ?>
                                <?php
                                $assetId = (int) $column['id'];
                                $isMaxMarket = $value !== null
                                    && $maxMarketByAsset[$assetId] !== null
                                    && abs((float) $value['market_value'] - (float) $maxMarketByAsset[$assetId]) < 0.00001;
                                $isMaxPnl = $value !== null
                                    && $maxPnlByAsset[$assetId] !== null
                                    && abs((float) $value['unrealized_pnl'] - (float) $maxPnlByAsset[$assetId]) < 0.00001;
                                $isMinMarket = $value !== null
                                    && $minMarketByAsset[$assetId] !== null
                                    && abs((float) $value['market_value'] - (float) $minMarketByAsset[$assetId]) < 0.00001;
                                $isMinPnl = $value !== null
                                    && $minPnlByAsset[$assetId] !== null
                                    && abs((float) $value['unrealized_pnl'] - (float) $minPnlByAsset[$assetId]) < 0.00001;
                                $maxCellInlineStyle = 'background:#dff6e7 !important; border:2px solid #3cb66a !important; color:#0d5f31 !important;';
                                $minCellInlineStyle = 'background:#ffe7ea !important; border:2px solid #d35568 !important; color:#972036 !important;';
                                $marketCellStyle = $isMaxMarket ? $maxCellInlineStyle : (($isMinMarket && !$isMaxMarket) ? $minCellInlineStyle : '');
                                $pnlCellStyle = $isMaxPnl ? $maxCellInlineStyle : (($isMinPnl && !$isMaxPnl) ? $minCellInlineStyle : '');
                                $pnlTooltip = '';
                                if ($value !== null && $maxPnlByAsset[$assetId] !== null) {
                                    $currentPnl = (float) $value['unrealized_pnl'];
                                    $maxPnl = (float) $maxPnlByAsset[$assetId];
                                    $distanceFromMax = max(0.0, $maxPnl - $currentPnl);
                                    $distancePct = abs($maxPnl) > 0.00001 ? ($distanceFromMax / abs($maxPnl)) * 100 : null;
                                    $maxPnlDate = $maxPnlDateByAsset[$assetId] ?: 'data non disponibile';

                                    if ($distanceFromMax < 0.00001) {
                                        $pnlTooltip = 'P/L massimo storico raggiunto: ' . $formatMoney($maxPnl) . ' il ' . $maxPnlDate . '.';
                                    } else {
                                        $pnlTooltip = 'Massimo P/L: ' . $formatMoney($maxPnl) . ' il ' . $maxPnlDate . '. Distanza dal massimo: ' . $formatMoney($distanceFromMax);
                                        if ($distancePct !== null) {
                                            $pnlTooltip .= ' (' . number_format($distancePct, 2, ',', '.') . '%).';
                                        } else {
                                            $pnlTooltip .= '.';
                                        }
                                    }
                                }
                                ?>
                                <td
                                    class="mono-cell mono-cell-qty"
                                    data-sort-value="<?= $value === null ? '' : e(number_format((float) $value['quantity'], 8, '.', '')) ?>"
                                >
                                    <?php if ($value === null): ?>
                                        -
                                    <?php else: ?>
                                        <div class="cell-main-value"><?= e($formatQuantity((float) $value['quantity'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td
                                    class="mono-cell<?= $isMaxMarket ? ' cell-max-hit' : '' ?><?= (!$isMaxMarket && $isMinMarket) ? ' cell-min-hit' : '' ?>"
                                    data-sort-value="<?= $value === null ? '' : e(number_format((float) $value['market_value'], 8, '.', '')) ?>"
                                    <?= $marketCellStyle !== '' ? 'style="' . e($marketCellStyle) . '"' : '' ?>
                                >
                                    <?php if ($value === null): ?>
                                        -
                                    <?php else: ?>
                                        <div class="cell-main-value"<?= $marketCellStyle !== '' ? ' style="color:inherit !important;"' : '' ?>><?= number_format((float) $value['market_value'], 2, ',', '.') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td
                                    class="mono-cell mono-cell-pl asset-group-end<?= $isMaxPnl ? ' cell-max-hit' : '' ?><?= (!$isMaxPnl && $isMinPnl) ? ' cell-min-hit' : '' ?>"
                                    data-sort-value="<?= $value === null ? '' : e(number_format((float) $value['unrealized_pnl'], 8, '.', '')) ?>"
                                    <?= $pnlCellStyle !== '' ? 'style="' . e($pnlCellStyle) . '"' : '' ?>
                                    <?= $pnlTooltip !== '' ? 'title="' . e($pnlTooltip) . '"' : '' ?>
                                >
                                    <?php if ($value === null): ?>
                                        -
                                    <?php else: ?>
                                        <div class="cell-main-value"<?= $pnlCellStyle !== '' ? ' style="color:inherit !important;"' : '' ?>><?= number_format((float) $value['unrealized_pnl'], 2, ',', '.') ?></div>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="andamento-pagination andamento-pagination-bottom" id="andamentoPagerBottom"></div>

        <div class="andamento-export-actions">
            <a class="btn export-btn export-btn-excel" href="<?= e(app_base_path()) ?>/andamento.php?export=xlsx">
                <span class="export-file-icon" aria-hidden="true">XLS</span>
                <span>Esporta Excel</span>
            </a>
            <button type="button" class="btn export-btn export-btn-pdf" id="andamentoPrintButton">
                <span class="export-file-icon" aria-hidden="true">PDF</span>
                <span>Stampa PDF</span>
            </button>
        </div>
    <?php endif; ?>
</section>

<?php if ($orderedRows && $columns): ?>
<script>
(() => {
    const table = document.getElementById('andamentoTable');
    if (!table) return;

    const headers = Array.from(table.querySelectorAll('thead th.sortable'));
    const tbody = table.querySelector('tbody');
    const pageSizeSelect = document.getElementById('andamentoPageSize');
    const pager = document.getElementById('andamentoPager');
    const pagerBottom = document.getElementById('andamentoPagerBottom');
    const storageKey = 'portfolio.andamento.pageSize';
    let allRows = Array.from(tbody.querySelectorAll('tr'));
    let currentIndex = 0;
    let currentDirection = 'asc';
    let currentPage = 1;
    let pageSize = localStorage.getItem(storageKey) || '25';

    const parseValue = (value, type) => {
        if (type === 'number') {
            return value === '' ? Number.NEGATIVE_INFINITY : Number(value);
        }
        return value;
    };

    const pageSizeValue = () => pageSize === 'all' ? allRows.length : Number(pageSize);

    const pageCount = () => {
        const size = pageSizeValue();
        return size <= 0 ? 1 : Math.max(1, Math.ceil(allRows.length / size));
    };

    const updateHeaderState = () => {
        headers.forEach((header, index) => {
            header.classList.remove('sort-asc', 'sort-desc');
            if (index === currentIndex) {
                header.classList.add(currentDirection === 'asc' ? 'sort-asc' : 'sort-desc');
            }
        });
    };

    const buildPagerContent = (target) => {
        if (!target) return;

        const totalPages = pageCount();
        currentPage = Math.min(Math.max(1, currentPage), totalPages);
        const size = pageSizeValue();
        const start = allRows.length === 0 ? 0 : ((currentPage - 1) * size) + 1;
        const end = pageSize === 'all' ? allRows.length : Math.min(allRows.length, currentPage * size);

        target.replaceChildren();

        const summary = document.createElement('span');
        summary.className = 'andamento-page-summary';
        summary.textContent = pageSize === 'all'
            ? `Tutto lo storico (${allRows.length} righe)`
            : `Righe ${start}-${end} di ${allRows.length}`;
        target.appendChild(summary);

        if (pageSize === 'all' || totalPages <= 1) {
            return;
        }

        const prev = document.createElement('button');
        prev.type = 'button';
        prev.className = 'btn-secondary btn-sm';
        prev.textContent = 'Indietro';
        prev.disabled = currentPage <= 1;
        prev.addEventListener('click', () => {
            currentPage = Math.max(1, currentPage - 1);
            renderTable();
        });

        const pageLabel = document.createElement('span');
        pageLabel.className = 'andamento-page-count';
        pageLabel.textContent = `Pagina ${currentPage}/${totalPages}`;

        const next = document.createElement('button');
        next.type = 'button';
        next.className = 'btn-secondary btn-sm';
        next.textContent = 'Avanti';
        next.disabled = currentPage >= totalPages;
        next.addEventListener('click', () => {
            currentPage = Math.min(totalPages, currentPage + 1);
            renderTable();
        });

        target.append(prev, pageLabel, next);
    };

    const renderTable = () => {
        const size = pageSizeValue();
        const totalPages = pageCount();
        currentPage = Math.min(Math.max(1, currentPage), totalPages);
        const startIndex = pageSize === 'all' ? 0 : (currentPage - 1) * size;
        const visibleRows = pageSize === 'all' ? allRows : allRows.slice(startIndex, startIndex + size);

        tbody.replaceChildren(...visibleRows);
        buildPagerContent(pager);
        buildPagerContent(pagerBottom);
    };

    const sortTable = (index, direction) => {
        const type = headers[index].dataset.sortType || 'text';

        allRows.sort((rowA, rowB) => {
            const cellA = rowA.children[index];
            const cellB = rowB.children[index];
            const valueA = parseValue(cellA?.dataset.sortValue ?? cellA?.textContent.trim() ?? '', type);
            const valueB = parseValue(cellB?.dataset.sortValue ?? cellB?.textContent.trim() ?? '', type);

            if (valueA < valueB) {
                return direction === 'asc' ? -1 : 1;
            }
            if (valueA > valueB) {
                return direction === 'asc' ? 1 : -1;
            }
            return 0;
        });

        currentIndex = index;
        currentDirection = direction;
        currentPage = 1;
        updateHeaderState();
        renderTable();
    };

    headers.forEach((header, index) => {
        header.addEventListener('click', () => {
            const nextDirection = currentIndex === index && currentDirection === 'asc' ? 'desc' : 'asc';
            sortTable(index, nextDirection);
        });
    });

    if (pageSizeSelect) {
        const allowedPageSizes = Array.from(pageSizeSelect.options).map((option) => option.value);
        if (!allowedPageSizes.includes(pageSize)) {
            pageSize = '25';
        }
        pageSizeSelect.value = pageSize;
        pageSizeSelect.addEventListener('change', () => {
            pageSize = pageSizeSelect.value;
            localStorage.setItem(storageKey, pageSize);
            currentPage = 1;
            renderTable();
        });
    }

    sortTable(0, 'desc');

    document.getElementById('andamentoPrintButton')?.addEventListener('click', () => {
        const previousPageSize = pageSize;
        const previousPage = currentPage;
        let restored = false;

        const restoreView = () => {
            if (restored) return;
            restored = true;
            pageSize = previousPageSize;
            currentPage = previousPage;
            renderTable();
            window.removeEventListener('afterprint', restoreView);
        };

        pageSize = 'all';
        currentPage = 1;
        renderTable();
        window.addEventListener('afterprint', restoreView);
        window.print();
        setTimeout(restoreView, 1200);
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
