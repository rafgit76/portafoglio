<?php
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Dettaglio titolo';
$pdo = db();

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

$historyStmt = $pdo->prepare(
    'SELECT
        s.id AS snapshot_id,
        s.snapshot_date,
        s.total_market_value AS snapshot_total_market_value,
        p.quantity,
        p.avg_load_price,
        p.current_price,
        p.cost_basis,
        p.market_value,
        p.unrealized_pnl,
        p.weight_pct,
        p.currency
     FROM pf_snapshot_positions p
     JOIN pf_snapshots s ON s.id = p.snapshot_id
     WHERE p.asset_id = :asset_id
     ORDER BY s.snapshot_date ASC, s.id ASC'
);
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
                    <td><a class="btn-link" href="<?= e(app_base_path()) ?>/snapshot.php?id=<?= (int) $row['snapshot_id'] ?>">Apri</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
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
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
