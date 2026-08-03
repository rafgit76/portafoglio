<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/diversification.php';

$pageTitle = 'Strumenti';
$pdo = db();

$latestSnapshot = $pdo->query('SELECT id, snapshot_date, total_market_value FROM pf_snapshots ORDER BY snapshot_date DESC, id DESC LIMIT 1')->fetch();
$latestSnapshotId = $latestSnapshot ? (int) $latestSnapshot['id'] : 0;
$positions = [];

if ($latestSnapshotId > 0) {
    $stmtPositions = $pdo->prepare(
        'SELECT a.id AS asset_id, a.asset_type, a.name, a.isin, a.ticker, a.currency AS asset_currency,
                p.market_value, p.weight_pct, p.currency AS position_currency
         FROM pf_snapshot_positions p
         JOIN pf_assets a ON a.id = p.asset_id
         WHERE p.snapshot_id = :snapshot_id
         ORDER BY p.market_value DESC'
    );
    $stmtPositions->execute([':snapshot_id' => $latestSnapshotId]);
    $positions = $stmtPositions->fetchAll();
}

$compositionRows = [];
$totalComposition = 0.0;
foreach ($positions as $position) {
    $type = (string) $position['asset_type'];
    $value = max(0.0, (float) $position['market_value']);
    $compositionRows[$type] = $compositionRows[$type] ?? ['asset_type' => $type, 'total_market_value' => 0.0, 'positions_count' => 0];
    $compositionRows[$type]['total_market_value'] += $value;
    ++$compositionRows[$type]['positions_count'];
    $totalComposition += $value;
}
usort($compositionRows, static fn (array $left, array $right): int => $right['total_market_value'] <=> $left['total_market_value']);

$geography = diversification_distribution($positions, 'geography');
$sector = diversification_distribution($positions, 'sector');
$currencies = diversification_currency_distribution($positions);
$concentration = diversification_concentration($positions, $totalComposition);
$sourceRows = diversification_source_rows($positions);
$catalog = diversification_catalog();

$chartColors = ['#0f9488', '#2f5fbb', '#168349', '#d97706', '#b32638', '#7c3aed', '#0891b2', '#c43a4b', '#5d8a2f', '#795548', '#607d8b', '#6b7280', '#a16207', '#0f766e', '#4338ca', '#be123c'];
$chartData = static function (array $distribution) use ($chartColors): array {
    return [
        'labels' => array_column($distribution['rows'], 'label'),
        'values' => array_map(static fn (array $row): float => round((float) $row['market_value'], 2), $distribution['rows']),
        'percents' => array_map(static fn (array $row): float => round((float) $row['weight_pct'], 4), $distribution['rows']),
        'colors' => array_slice($chartColors, 0, max(1, count($distribution['rows']))),
    ];
};

$compositionChart = [
    'labels' => array_column($compositionRows, 'asset_type'),
    'values' => array_map(static fn (array $row): float => round((float) $row['total_market_value'], 2), $compositionRows),
    'percents' => array_map(static function (array $row) use ($totalComposition): float {
        return $totalComposition > 0 ? round(((float) $row['total_market_value'] / $totalComposition) * 100, 4) : 0.0;
    }, $compositionRows),
    'colors' => array_slice($chartColors, 0, max(1, count($compositionRows))),
];

include __DIR__ . '/partials/header.php';
?>

<section class="panel instruments-hero">
    <div class="panel-head">
        <div>
            <p class="eyebrow">COMPOSIZIONE PORTAFOGLIO</p>
            <h2>Strumenti</h2>
            <p class="muted-small">Lettura descrittiva della composizione costruita sull’ultimo snapshot Fineco e sulle fonti indicate per ogni strumento.</p>
        </div>
        <?php if ($latestSnapshot): ?>
            <span class="source-chip">Snapshot <?= e(date('d/m/Y', strtotime((string) $latestSnapshot['snapshot_date']))) ?></span>
        <?php endif; ?>
    </div>
</section>

<?php if (!$latestSnapshot || !$positions): ?>
    <section class="panel">
        <h2>Nessun dato disponibile</h2>
        <p>Carica almeno uno snapshot Fineco per visualizzare composizione, classificazioni e fonti.</p>
    </section>
<?php else: ?>
    <section class="cards instruments-summary-cards">
        <article class="card">
            <h3>Valore di mercato</h3>
            <p class="big">EUR <?= number_format($totalComposition, 2, ',', '.') ?></p>
            <span class="muted-small"><?= count($positions) ?> strumenti nello snapshot</span>
        </article>
        <article class="card">
            <h3>Posizione maggiore</h3>
            <p class="big instruments-summary-name"><?= e((string) ($positions[0]['name'] ?? '-')) ?></p>
            <span class="muted-small"><?= number_format((float) ($concentration[1]['weight_pct'] ?? 0), 2, ',', '.') ?>% del valore di mercato</span>
        </article>
        <article class="card">
            <h3>Prime 3 posizioni</h3>
            <p class="big"><?= number_format((float) ($concentration[3]['weight_pct'] ?? 0), 2, ',', '.') ?>%</p>
            <span class="muted-small">EUR <?= number_format((float) ($concentration[3]['value'] ?? 0), 2, ',', '.') ?></span>
        </article>
        <article class="card">
            <h3>Catalogo fonti</h3>
            <p class="big"><?= count($sourceRows) ?></p>
            <span class="muted-small">Aggiornato il <?= e(date('d/m/Y', strtotime((string) $catalog['updated_at']))) ?></span>
        </article>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Composizione per categoria</h2>
                <p class="muted-small">Classi registrate nell’anagrafica degli strumenti.</p>
            </div>
        </div>
        <div class="instruments-grid">
            <div class="table-scroll">
                <table class="instruments-table">
                    <thead><tr><th>Categoria</th><th>Posizioni</th><th>Valore</th><th>Peso</th></tr></thead>
                    <tbody>
                    <?php foreach ($compositionRows as $row): ?>
                        <tr>
                            <td><?= e($row['asset_type']) ?></td>
                            <td><?= (int) $row['positions_count'] ?></td>
                            <td>EUR <?= number_format((float) $row['total_market_value'], 2, ',', '.') ?></td>
                            <td><?= number_format(((float) $row['total_market_value'] / $totalComposition) * 100, 2, ',', '.') ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="chart-wrap chart-wrap-compact"><canvas id="assetTypeChart"></canvas></div>
        </div>
    </section>

    <section class="instruments-grid-wide">
        <article class="panel">
            <div class="panel-head"><div><h2>Diversificazione territoriale</h2><p class="muted-small">Esposizioni look-through dove il factsheet le pubblica; altrimenti classificazione dell’intero strumento.</p></div><span class="source-chip">Copertura <?= number_format((float) $geography['coverage_pct'], 2, ',', '.') ?>%</span></div>
            <div class="chart-wrap chart-wrap-distribution"><canvas id="geographyChart"></canvas></div>
            <div class="distribution-list">
                <?php foreach ($geography['rows'] as $row): ?>
                    <div><span><?= e($row['label']) ?></span><strong><?= number_format((float) $row['weight_pct'], 2, ',', '.') ?>%</strong><small>EUR <?= number_format((float) $row['market_value'], 2, ',', '.') ?></small></div>
                <?php endforeach; ?>
            </div>
        </article>
        <article class="panel">
            <div class="panel-head"><div><h2>Esposizione settoriale</h2><p class="muted-small">I fondi con ripartizione ufficiale usano il dettaglio disponibile; gli altri restano classificati per mandato o tipologia.</p></div><span class="source-chip">Copertura <?= number_format((float) $sector['coverage_pct'], 2, ',', '.') ?>%</span></div>
            <div class="chart-wrap chart-wrap-distribution"><canvas id="sectorChart"></canvas></div>
            <div class="distribution-list">
                <?php foreach ($sector['rows'] as $row): ?>
                    <div><span><?= e($row['label']) ?></span><strong><?= number_format((float) $row['weight_pct'], 2, ',', '.') ?>%</strong><small>EUR <?= number_format((float) $row['market_value'], 2, ',', '.') ?></small></div>
                <?php endforeach; ?>
            </div>
        </article>
    </section>

    <section class="instruments-grid-wide">
        <article class="panel">
            <div class="panel-head"><div><h2>Valute di negoziazione</h2><p class="muted-small">Valuta riportata nella posizione Fineco, non esposizione valutaria look-through.</p></div></div>
            <div class="chart-wrap chart-wrap-compact"><canvas id="currencyChart"></canvas></div>
            <div class="distribution-list distribution-list-compact">
                <?php foreach ($currencies['rows'] as $row): ?>
                    <div><span><?= e($row['label']) ?></span><strong><?= number_format((float) $row['weight_pct'], 2, ',', '.') ?>%</strong><small>EUR <?= number_format((float) $row['market_value'], 2, ',', '.') ?></small></div>
                <?php endforeach; ?>
            </div>
        </article>
        <article class="panel">
            <div class="panel-head"><div><h2>Concentrazione osservata</h2><p class="muted-small">Quote calcolate direttamente dai controvalori dell’ultimo snapshot, senza giudizi qualitativi.</p></div></div>
            <div class="concentration-grid">
                <?php foreach ([1, 3, 5] as $count): ?>
                    <div class="concentration-item"><span>Prime <?= $count ?> posizioni</span><strong><?= number_format((float) ($concentration[$count]['weight_pct'] ?? 0), 2, ',', '.') ?>%</strong><small>EUR <?= number_format((float) ($concentration[$count]['value'] ?? 0), 2, ',', '.') ?></small></div>
                <?php endforeach; ?>
            </div>
            <div class="table-scroll concentration-table-wrap">
                <table class="instruments-table">
                    <thead><tr><th>Strumento</th><th>Tipo</th><th>Valore</th><th>Peso</th></tr></thead>
                    <tbody>
                    <?php foreach ($positions as $position): ?>
                        <tr>
                            <td><a class="btn-link" href="<?= e(app_base_path()) ?>/asset.php?id=<?= (int) $position['asset_id'] ?>"><?= e($position['name']) ?></a></td>
                            <td><?= e($position['asset_type']) ?></td>
                            <td>EUR <?= number_format((float) $position['market_value'], 2, ',', '.') ?></td>
                            <td><?= number_format(((float) $position['market_value'] / $totalComposition) * 100, 2, ',', '.') ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section class="panel">
        <div class="panel-head"><div><h2>Fonti e metodo</h2><p class="muted-small">Il catalogo non sostituisce i dati Fineco: assegna una classificazione documentata ai controvalori dello snapshot.</p></div></div>
        <div class="table-scroll">
            <table class="instruments-table sources-table">
                <thead><tr><th>Strumento</th><th>Valore</th><th>Fonte</th><th>Dati al</th><th>Metodo</th></tr></thead>
                <tbody>
                <?php foreach ($sourceRows as $sourceRow): ?>
                    <?php $profile = $sourceRow['profile']; ?>
                    <tr>
                        <td><?= e($sourceRow['asset_name']) ?><br><span class="muted-small"><?= e($sourceRow['isin']) ?></span></td>
                        <td>EUR <?= number_format((float) $sourceRow['market_value'], 2, ',', '.') ?></td>
                        <?php if (is_array($profile)): ?>
                            <td><a class="btn-link" href="<?= e((string) $profile['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $profile['source_name']) ?></a></td>
                            <td><?= e(date('d/m/Y', strtotime((string) $profile['source_as_of']))) ?></td>
                            <td class="source-method"><?= e((string) $profile['method']) ?></td>
                        <?php else: ?>
                            <td colspan="3">Classificazione non presente nel catalogo.</td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><div><h2>Anagrafica strumenti</h2><p class="muted-small">Dati identificativi conservati nell’app.</p></div></div>
        <div class="table-scroll">
            <table class="instruments-table">
                <thead><tr><th>Tipo</th><th>Nome</th><th>ISIN</th><th>Ticker</th><th>Valuta</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($positions as $position): ?>
                    <tr>
                        <td><?= e($position['asset_type']) ?></td>
                        <td><?= e($position['name']) ?></td>
                        <td><?= e($position['isin'] ?: '-') ?></td>
                        <td><?= e($position['ticker'] ?: '-') ?></td>
                        <td><?= e($position['asset_currency']) ?></td>
                        <td><a class="btn-link" href="<?= e(app_base_path()) ?>/asset.php?id=<?= (int) $position['asset_id'] ?>">Dettaglio</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

<script src="<?= e(app_base_path()) ?>/assets/vendor/chart.umd.min.js"></script>
<script>
const euroFormatter = new Intl.NumberFormat('it-IT', {style: 'currency', currency: 'EUR'});
const charts = <?= json_encode([
    'assetType' => $compositionChart,
    'geography' => $chartData($geography),
    'sector' => $chartData($sector),
    'currency' => $chartData($currencies),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function createDistributionChart(id, chartData, type = 'bar') {
    const element = document.getElementById(id);
    if (!element || !chartData.labels.length) return;
    new Chart(element, {
        type,
        data: { labels: chartData.labels, datasets: [{ data: chartData.values, backgroundColor: chartData.colors, borderColor: chartData.colors, borderWidth: type === 'doughnut' ? 2 : 1, borderRadius: type === 'bar' ? 5 : 0, maxBarThickness: 28 }] },
        options: {
            responsive: true, maintainAspectRatio: false, indexAxis: type === 'bar' ? 'y' : 'x',
            plugins: { legend: { display: type === 'doughnut', position: 'bottom' }, tooltip: { callbacks: { label(context) { const index = context.dataIndex; return `${chartData.labels[index]}: ${euroFormatter.format(chartData.values[index])} (${chartData.percents[index].toFixed(2).replace('.', ',')}%)`; } } } },
            scales: type === 'bar' ? { x: { beginAtZero: true, ticks: { callback(value) { return `${Number(value).toFixed(0)} EUR`; } }, grid: { color: 'rgba(216, 226, 232, 0.75)' } }, y: { grid: { display: false }, ticks: { font: { size: 12, weight: '600' } } } } : {}
        }
    });
}

createDistributionChart('assetTypeChart', charts.assetType, 'doughnut');
createDistributionChart('geographyChart', charts.geography);
createDistributionChart('sectorChart', charts.sector);
createDistributionChart('currencyChart', charts.currency, 'doughnut');
</script>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
