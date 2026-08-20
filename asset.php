<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/account_tracker.php';

$pageTitle = 'Dettaglio titolo';
$pdo = db();
$accountTrackerReady = account_tracker_schema_ready($pdo);

$assetId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($assetId <= 0) {
    set_flash('error', 'Titolo non valido.');
    redirect('/assets.php');
}

$assetStmt = $pdo->prepare('SELECT * FROM pf_assets WHERE id = :id LIMIT 1');
$assetStmt->execute([':id' => $assetId]);
$asset = $assetStmt->fetch();

if (!$asset) {
    set_flash('error', 'Titolo non trovato.');
    redirect('/assets.php');
}

$historySql = 'SELECT s.id AS snapshot_id, s.snapshot_date, s.total_market_value AS snapshot_total_market_value,
        SUM(p.quantity) AS quantity,
        CASE WHEN SUM(p.quantity) <> 0 THEN SUM(p.avg_load_price * p.quantity) / SUM(p.quantity) ELSE 0 END AS avg_load_price,
        CASE WHEN SUM(p.quantity) <> 0 THEN SUM(p.current_price * p.quantity) / SUM(p.quantity) ELSE 0 END AS current_price,
        SUM(p.cost_basis) AS cost_basis, SUM(p.market_value) AS market_value, SUM(p.unrealized_pnl) AS unrealized_pnl,
        CASE WHEN s.total_market_value > 0 THEN SUM(p.market_value) / s.total_market_value ELSE 0 END AS weight_pct,
        MAX(p.currency) AS currency';
if ($accountTrackerReady) {
    $historySql .= ', GROUP_CONCAT(DISTINCT d.name ORDER BY d.sort_order SEPARATOR ", ") AS dossier_names';
} else {
    $historySql .= ', NULL AS dossier_names';
}
$historySql .= ' FROM pf_snapshot_positions p JOIN pf_snapshots s ON s.id=p.snapshot_id';
if ($accountTrackerReady) { $historySql .= ' LEFT JOIN pf_dossiers d ON d.id=p.dossier_id'; }
$historySql .= ' WHERE p.asset_id=:asset_id GROUP BY s.id,s.snapshot_date,s.total_market_value ORDER BY s.snapshot_date ASC,s.id ASC';
$historyStmt = $pdo->prepare($historySql);
$historyStmt->execute([':asset_id' => $assetId]);
$history = $historyStmt->fetchAll();

if (!$history) {
    set_flash('error', 'Nessuno storico disponibile per il titolo selezionato.');
    redirect('/assets.php');
}

$formatMoney = static fn(float $value): string => 'EUR ' . number_format($value, 2, ',', '.');
$formatNumber = static fn(float $value, int $decimals = 2): string => number_format($value, $decimals, ',', '.');
$formatPct = static fn(float $value): string => number_format($value, 2, ',', '.') . '%';

$first = $history[0];
$latest = $history[count($history) - 1];
$maxMarket = null;
$minMarket = null;
$maxPnl = null;
$minPnl = null;
$maxWeight = null;
$maxDrawdown = 0.0;
$runningMarketPeak = null;

foreach ($history as $row) {
    $market = (float) $row['market_value'];
    $pnl = (float) $row['unrealized_pnl'];
    $weight = ((float) $row['weight_pct']) * 100;

    if ($maxMarket === null || $market > $maxMarket['value']) {
        $maxMarket = ['value' => $market, 'date' => (string) $row['snapshot_date']];
    }
    if ($minMarket === null || $market < $minMarket['value']) {
        $minMarket = ['value' => $market, 'date' => (string) $row['snapshot_date']];
    }
    if ($maxPnl === null || $pnl > $maxPnl['value']) {
        $maxPnl = ['value' => $pnl, 'date' => (string) $row['snapshot_date']];
    }
    if ($minPnl === null || $pnl < $minPnl['value']) {
        $minPnl = ['value' => $pnl, 'date' => (string) $row['snapshot_date']];
    }
    if ($maxWeight === null || $weight > $maxWeight['value']) {
        $maxWeight = ['value' => $weight, 'date' => (string) $row['snapshot_date']];
    }

    if ($runningMarketPeak === null || $market > $runningMarketPeak) {
        $runningMarketPeak = $market;
    }
    if ($runningMarketPeak > 0) {
        $drawdown = (($runningMarketPeak - $market) / $runningMarketPeak) * 100;
        $maxDrawdown = max($maxDrawdown, $drawdown);
    }
}

$latestMarket = (float) $latest['market_value'];
$latestCost = (float) $latest['cost_basis'];
$latestPnl = (float) $latest['unrealized_pnl'];
$latestWeight = ((float) $latest['weight_pct']) * 100;
$latestPnlPct = $latestCost > 0 ? ($latestPnl / $latestCost) * 100 : 0.0;
$deltaMarket = $latestMarket - (float) $first['market_value'];
$deltaPnl = $latestPnl - (float) $first['unrealized_pnl'];
$deltaWeight = $latestWeight - (((float) $first['weight_pct']) * 100);
$distanceFromMaxPnl = $maxPnl ? max(0.0, (float) $maxPnl['value'] - $latestPnl) : 0.0;
$distanceFromMaxPnlPct = $maxPnl && abs((float) $maxPnl['value']) > 0.00001
    ? ($distanceFromMaxPnl / abs((float) $maxPnl['value'])) * 100
    : 0.0;

$labels = array_map(static fn(array $row): string => (string) $row['snapshot_date'], $history);
$chartMarket = array_map(static fn(array $row): float => round((float) $row['market_value'], 2), $history);
$chartCost = array_map(static fn(array $row): float => round((float) $row['cost_basis'], 2), $history);
$chartPnl = array_map(static fn(array $row): float => round((float) $row['unrealized_pnl'], 2), $history);
$chartWeight = array_map(static fn(array $row): float => round(((float) $row['weight_pct']) * 100, 4), $history);
$chartQuantity = array_map(static fn(array $row): float => round((float) $row['quantity'], 6), $history);

$assetName = (string) $asset['name'];
$identityBits = array_filter([
    (string) ($asset['asset_type'] ?? ''),
    (string) ($asset['ticker'] ?? ''),
    (string) ($asset['isin'] ?? ''),
    (string) ($asset['currency'] ?? ''),
], static fn(string $value): bool => trim($value) !== '');

if (($_GET['export'] ?? '') === 'xlsx') {
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        http_response_code(500);
        exit('PhpSpreadsheet non disponibile. Esegui composer install e carica vendor/.');
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('Portfolio Investimenti')
        ->setTitle('Dettaglio titolo - ' . $assetName)
        ->setSubject('Storico snapshot titolo');

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(mb_substr(preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $assetName), 0, 31) ?: 'Dettaglio titolo');

    $generatedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i');
    $sheet->setCellValue('A1', 'Dettaglio titolo - ' . $assetName);
    $sheet->setCellValue('A2', implode(' - ', $identityBits));
    $sheet->setCellValue('A3', 'Generato il ' . $generatedAt . ' - Snapshot: ' . count($history));

    $headers = ['Data', 'Quantita', 'PMC', 'Prezzo', 'Valore carico', 'Valore mercato', 'P/L', 'P/L %', 'Peso %'];
    $headerRow = 5;
    foreach ($headers as $index => $header) {
        $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1) . $headerRow, $header);
    }

    $dataRow = 6;
    foreach (array_reverse($history) as $row) {
        $rowCost = (float) $row['cost_basis'];
        $rowPnl = (float) $row['unrealized_pnl'];
        $rowPnlPct = $rowCost > 0 ? ($rowPnl / $rowCost) * 100 : 0.0;

        $sheet->setCellValue('A' . $dataRow, (string) $row['snapshot_date']);
        $sheet->setCellValueExplicit('B' . $dataRow, (float) $row['quantity'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $sheet->setCellValueExplicit('C' . $dataRow, (float) $row['avg_load_price'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $sheet->setCellValueExplicit('D' . $dataRow, (float) $row['current_price'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $sheet->setCellValueExplicit('E' . $dataRow, $rowCost, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $sheet->setCellValueExplicit('F' . $dataRow, (float) $row['market_value'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $sheet->setCellValueExplicit('G' . $dataRow, $rowPnl, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $sheet->setCellValueExplicit('H' . $dataRow, $rowPnlPct, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $sheet->setCellValueExplicit('I' . $dataRow, ((float) $row['weight_pct']) * 100, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $dataRow++;
    }

    $lastDataRow = max($headerRow, $dataRow - 1);
    $sheet->mergeCells('A1:I1');
    $sheet->mergeCells('A2:I2');
    $sheet->mergeCells('A3:I3');
    $sheet->freezePane('A6');
    $sheet->setAutoFilter('A' . $headerRow . ':I' . $lastDataRow);

    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A2:A3')->getFont()->setSize(10)->getColor()->setARGB('FF647681');
    $sheet->getStyle('A' . $headerRow . ':I' . $headerRow)->getFont()->setBold(true);
    $sheet->getStyle('A' . $headerRow . ':I' . $headerRow)
        ->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()
        ->setARGB('FFE7F0F4');
    $sheet->getStyle('A' . $headerRow . ':I' . $lastDataRow)
        ->getBorders()
        ->getAllBorders()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
        ->getColor()
        ->setARGB('FFD8E2E8');

    $sheet->getStyle('B6:B' . $lastDataRow)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('C6:D' . $lastDataRow)->getNumberFormat()->setFormatCode('#,##0.0000');
    $sheet->getStyle('E6:G' . $lastDataRow)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle('H6:I' . $lastDataRow)->getNumberFormat()->setFormatCode('#,##0.00');

    foreach (range('A', 'I') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $assetName);
    $filename = 'dettaglio_titolo_' . trim((string) $safeName, '_') . '_' . (new DateTimeImmutable('now'))->format('Ymd_His') . '.xlsx';
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
<section class="panel asset-hero">
    <div class="panel-head asset-hero-head">
        <div>
            <h2><?= e($assetName) ?></h2>
            <p class="tiny-note"><?= e(implode(' - ', $identityBits)) ?></p>
        </div>
        <div class="asset-actions">
            <a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/assets.php">Torna agli strumenti</a>
            <a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/andamento.php">Andamento</a>
            <?php if ((string) ($asset['asset_type'] ?? '') === 'AZIONE'): ?>
                <a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/earnings.php?asset_id=<?= (int) $assetId ?>#nuova-trimestrale">Trimestrali</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="cards asset-kpi-grid">
    <article class="card">
        <h3>Ultimo valore</h3>
        <p class="big money-big"><span>EUR</span><strong><?= number_format($latestMarket, 2, ',', '.') ?></strong></p>
        <p class="tiny-note">Snapshot <?= e((string) $latest['snapshot_date']) ?></p>
    </article>
    <article class="card">
        <h3>P/L ultimo</h3>
        <p class="big money-big <?= $latestPnl >= 0 ? 'pos' : 'neg' ?>"><span>EUR</span><strong><?= number_format($latestPnl, 2, ',', '.') ?></strong></p>
        <p class="tiny-note"><?= e($formatPct($latestPnlPct)) ?> sul valore di carico</p>
    </article>
    <article class="card">
        <h3>Peso ultimo</h3>
        <p class="big"><?= e($formatPct($latestWeight)) ?></p>
        <p class="tiny-note">Massimo <?= e($formatPct((float) ($maxWeight['value'] ?? 0.0))) ?> il <?= e((string) ($maxWeight['date'] ?? '-')) ?></p>
    </article>
    <article class="card">
        <h3>Distanza max P/L</h3>
        <p class="big money-big <?= $distanceFromMaxPnl <= 0.00001 ? 'pos' : '' ?>"><span>EUR</span><strong><?= number_format($distanceFromMaxPnl, 2, ',', '.') ?></strong></p>
        <p class="tiny-note">Max <?= $maxPnl ? e($formatMoney((float) $maxPnl['value'])) . ' il ' . e((string) $maxPnl['date']) : '-' ?> - <?= e($formatPct($distanceFromMaxPnlPct)) ?></p>
    </article>
</section>

<section class="panel">
    <div class="panel-head">
        <h3>Indicatori storico</h3>
        <span class="muted-small"><?= count($history) ?> snapshot disponibili</span>
    </div>
    <div class="asset-metric-grid">
        <div class="asset-metric"><span>Delta valore dal primo snapshot</span><strong class="<?= $deltaMarket >= 0 ? 'pos' : 'neg' ?>"><?= e($formatMoney($deltaMarket)) ?></strong></div>
        <div class="asset-metric"><span>Delta P/L dal primo snapshot</span><strong class="<?= $deltaPnl >= 0 ? 'pos' : 'neg' ?>"><?= e($formatMoney($deltaPnl)) ?></strong></div>
        <div class="asset-metric"><span>Delta peso dal primo snapshot</span><strong class="<?= $deltaWeight >= 0 ? 'pos' : 'neg' ?>"><?= e($formatPct($deltaWeight)) ?></strong></div>
        <div class="asset-metric"><span>Max drawdown valore mercato</span><strong><?= e($formatPct($maxDrawdown)) ?></strong></div>
        <div class="asset-metric"><span>Massimo valore</span><strong><?= $maxMarket ? e($formatMoney((float) $maxMarket['value'])) : '-' ?></strong><small><?= e((string) ($maxMarket['date'] ?? '')) ?></small></div>
        <div class="asset-metric"><span>Minimo valore</span><strong><?= $minMarket ? e($formatMoney((float) $minMarket['value'])) : '-' ?></strong><small><?= e((string) ($minMarket['date'] ?? '')) ?></small></div>
        <div class="asset-metric"><span>Massimo P/L</span><strong class="<?= $maxPnl && (float) $maxPnl['value'] >= 0 ? 'pos' : 'neg' ?>"><?= $maxPnl ? e($formatMoney((float) $maxPnl['value'])) : '-' ?></strong><small><?= e((string) ($maxPnl['date'] ?? '')) ?></small></div>
        <div class="asset-metric"><span>Minimo P/L</span><strong class="<?= $minPnl && (float) $minPnl['value'] >= 0 ? 'pos' : 'neg' ?>"><?= $minPnl ? e($formatMoney((float) $minPnl['value'])) : '-' ?></strong><small><?= e((string) ($minPnl['date'] ?? '')) ?></small></div>
    </div>
</section>

<section class="panel">
    <div class="panel-head">
        <h3>Grafici titolo</h3>
        <span class="tiny-note">Valori calcolati dagli snapshot importati</span>
    </div>
    <div class="asset-chart-grid">
        <article class="chart-card">
            <h3>Valore mercato e carico</h3>
            <div class="chart-wrap chart-wrap-compact"><canvas id="assetValueChart"></canvas></div>
        </article>
        <article class="chart-card">
            <h3>P/L</h3>
            <div class="chart-wrap chart-wrap-compact"><canvas id="assetPnlChart"></canvas></div>
        </article>
        <article class="chart-card">
            <h3>Peso nel portafoglio</h3>
            <div class="chart-wrap chart-wrap-compact"><canvas id="assetWeightChart"></canvas></div>
        </article>
        <article class="chart-card">
            <h3>Quantita</h3>
            <div class="chart-wrap chart-wrap-compact"><canvas id="assetQuantityChart"></canvas></div>
        </article>
    </div>
</section>

<section class="panel">
    <div class="panel-head">
        <h3>Storico snapshot</h3>
        <span class="tiny-note">Dal <?= e((string) $first['snapshot_date']) ?> al <?= e((string) $latest['snapshot_date']) ?></span>
    </div>
    <table class="asset-history-table">
        <thead>
            <tr>
                <th>Data</th>
                <th>Q.ta</th>
                <th>PMC</th>
                <th>Prezzo</th>
                <th>Valore carico</th>
                <th>Valore mercato</th>
                <th>P/L</th>
                <th>P/L %</th>
                <th>Peso</th>
                <?php if ($accountTrackerReady): ?><th>Dossier</th><?php endif; ?>
                <th>Snapshot</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (array_reverse($history) as $row): ?>
                <?php
                $rowCost = (float) $row['cost_basis'];
                $rowPnl = (float) $row['unrealized_pnl'];
                $rowPnlPct = $rowCost > 0 ? ($rowPnl / $rowCost) * 100 : 0.0;
                $isMaxPnl = $maxPnl !== null && abs($rowPnl - (float) $maxPnl['value']) < 0.00001;
                $isMinPnl = $minPnl !== null && abs($rowPnl - (float) $minPnl['value']) < 0.00001;
                $rowClass = $isMaxPnl ? 'snapshot-row-max' : ($isMinPnl ? 'snapshot-row-min' : '');
                ?>
                <tr class="<?= e($rowClass) ?>">
                    <td><?= e((string) $row['snapshot_date']) ?></td>
                    <td><?= e($formatNumber((float) $row['quantity'], 2)) ?></td>
                    <td><?= e($formatNumber((float) $row['avg_load_price'], 4)) ?></td>
                    <td><?= e($formatNumber((float) $row['current_price'], 4)) ?></td>
                    <td><?= e($formatMoney($rowCost)) ?></td>
                    <td><?= e($formatMoney((float) $row['market_value'])) ?></td>
                    <td class="<?= $rowPnl >= 0 ? 'pos' : 'neg' ?>">
                        <?= e($formatMoney($rowPnl)) ?>
                        <?php if ($isMaxPnl): ?>
                            <span class="snapshot-extreme-badge snapshot-extreme-max">Massimo P/L</span>
                        <?php elseif ($isMinPnl): ?>
                            <span class="snapshot-extreme-badge snapshot-extreme-min">Minimo P/L</span>
                        <?php endif; ?>
                    </td>
                    <td class="<?= $rowPnlPct >= 0 ? 'pos' : 'neg' ?>"><?= e($formatPct($rowPnlPct)) ?></td>
                    <td><?= e($formatPct(((float) $row['weight_pct']) * 100)) ?></td>
                    <?php if ($accountTrackerReady): ?><td><?= e((string) ($row['dossier_names'] ?? 'Ordinario')) ?></td><?php endif; ?>
                    <td><a class="btn-link" href="<?= e(app_base_path()) ?>/snapshot.php?id=<?= (int) $row['snapshot_id'] ?>">Apri</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="andamento-export-actions asset-export-actions">
        <a class="btn export-btn export-btn-excel" href="<?= e(app_base_path()) ?>/asset.php?id=<?= $assetId ?>&amp;export=xlsx">
            <span class="export-file-icon" aria-hidden="true">XLS</span>
            <span>Esporta Excel</span>
        </a>
        <button type="button" class="btn export-btn export-btn-pdf" id="assetPrintButton">
            <span class="export-file-icon" aria-hidden="true">PDF</span>
            <span>Stampa PDF</span>
        </button>
    </div>
</section>

<script src="<?= e(app_base_path()) ?>/assets/vendor/chart.umd.min.js"></script>
<script>
(() => {
    const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
    const marketData = <?= json_encode($chartMarket) ?>;
    const costData = <?= json_encode($chartCost) ?>;
    const pnlData = <?= json_encode($chartPnl) ?>;
    const weightData = <?= json_encode($chartWeight) ?>;
    const quantityData = <?= json_encode($chartQuantity) ?>;
    const eurIt = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' });
    const numIt = new Intl.NumberFormat('it-IT', { maximumFractionDigits: 6 });
    const textColor = '#334650';
    const gridColor = 'rgba(70, 92, 104, 0.16)';
    const tooltipBg = 'rgba(15, 31, 41, 0.94)';

    const baseOptions = (axisTitle, formatter) => ({
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { labels: { color: textColor } },
            tooltip: {
                backgroundColor: tooltipBg,
                titleColor: '#ffffff',
                bodyColor: '#f2fbff',
                borderColor: 'rgba(255,255,255,0.25)',
                borderWidth: 1,
                callbacks: { label: (context) => `${context.dataset.label}: ${formatter(context.parsed.y)}` }
            }
        },
        scales: {
            x: { ticks: { color: textColor }, grid: { color: gridColor } },
            y: {
                beginAtZero: false,
                ticks: { color: textColor, callback: (value) => formatter(value) },
                grid: { color: gridColor },
                title: { display: true, text: axisTitle, color: textColor }
            }
        }
    });

    const makeLineChart = (canvasId, datasets, axisTitle, formatter) => {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        new Chart(canvas, { type: 'line', data: { labels, datasets }, options: baseOptions(axisTitle, formatter) });
    };

    makeLineChart('assetValueChart', [
        { label: 'Valore mercato', data: marketData, borderColor: '#0f9488', backgroundColor: 'rgba(15,148,136,0.12)', tension: 0.25, borderWidth: 2, pointRadius: 3 },
        { label: 'Valore carico', data: costData, borderColor: '#2f5fbb', backgroundColor: 'rgba(47,95,187,0.10)', tension: 0.25, borderWidth: 2, pointRadius: 3 }
    ], 'EUR', (value) => eurIt.format(value));

    makeLineChart('assetPnlChart', [
        { label: 'P/L', data: pnlData, borderColor: '#137a3d', backgroundColor: 'rgba(19,122,61,0.12)', tension: 0.25, borderWidth: 2, pointRadius: 3 }
    ], 'P/L EUR', (value) => eurIt.format(value));

    makeLineChart('assetWeightChart', [
        { label: 'Peso %', data: weightData, borderColor: '#d97706', backgroundColor: 'rgba(217,119,6,0.12)', tension: 0.25, borderWidth: 2, pointRadius: 3 }
    ], 'Peso %', (value) => `${Number(value).toFixed(2).replace('.', ',')}%`);

    makeLineChart('assetQuantityChart', [
        { label: 'Quantita', data: quantityData, borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,0.10)', tension: 0.25, borderWidth: 2, pointRadius: 3 }
    ], 'Quantita', (value) => numIt.format(value));

    document.getElementById('assetPrintButton')?.addEventListener('click', () => {
        let restored = false;
        const restorePrintMode = () => {
            if (restored) return;
            restored = true;
            document.body.classList.remove('asset-print-mode');
            window.removeEventListener('afterprint', restorePrintMode);
        };

        document.body.classList.add('asset-print-mode');
        window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
        window.addEventListener('afterprint', restorePrintMode);
        window.print();
        setTimeout(restorePrintMode, 1200);
    });
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
