<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/fx.php';
require_once __DIR__ . '/lib/news.php';
require_once __DIR__ . '/lib/account_tracker.php';

$pageTitle = 'Dashboard';
$pdo = db();
$accountTrackerReady = account_tracker_schema_ready($pdo);
$accountMetrics = $accountTrackerReady ? account_tracker_patrimonial_metrics($pdo) : null;

$period = $_GET['period'] ?? 'all';
$allowedPeriods = ['1d', '1w', '1m', 'ytd', 'all'];
if (!in_array($period, $allowedPeriods, true)) {
    $period = 'all';
}

$enableFx = (bool) app_setting('enable_fx', true);
$enableNews = (bool) app_setting('enable_news', false);

$fx = $enableFx ? get_eurusd_rate(3600) : ['rate' => null, 'as_of' => null, 'error' => 'disabled'];
$fxRateLabel = $fx['rate'] !== null ? number_format((float) $fx['rate'], 4, ',', '.') : 'N/D';
$fxAsOfLabel = '-';
if (!empty($fx['as_of'])) {
    try {
        $fxAsOfLabel = (new DateTime($fx['as_of']))->format('d/m/Y');
    } catch (Throwable $e) {
        $fxAsOfLabel = (string) $fx['as_of'];
    }
}

$newsByCategory = [];
if ($enableNews) {
    $assetsForKeywords = $pdo->query('SELECT name, ticker FROM pf_assets WHERE is_active = 1')->fetchAll();
    $portfolioKeywords = build_portfolio_keywords($assetsForKeywords);
    $news = get_dashboard_news($portfolioKeywords, 1800);
    $newsByCategory = $news['data'] ?? [];
}

$stats = [
    'snapshots' => 0,
    'assets' => 0,
    'last_market_value' => 0,
    'last_pnl' => 0,
];

$stats['snapshots'] = (int) $pdo->query('SELECT COUNT(*) FROM pf_snapshots')->fetchColumn();
$stats['assets'] = (int) $pdo->query('SELECT COUNT(*) FROM pf_assets')->fetchColumn();

$last = $pdo->query('SELECT snapshot_date, total_market_value, total_unrealized_pnl FROM pf_snapshots ORDER BY snapshot_date DESC LIMIT 1')->fetch();
if ($last) {
    $stats['last_market_value'] = (float) $last['total_market_value'];
    $stats['last_pnl'] = (float) $last['total_unrealized_pnl'];
}

$history = $pdo->query('SELECT id, snapshot_date, total_market_value, total_cost_basis, total_unrealized_pnl FROM pf_snapshots ORDER BY snapshot_date DESC LIMIT 12')->fetchAll();
$allHistory = $pdo->query('SELECT snapshot_date, total_market_value, total_cost_basis, total_unrealized_pnl FROM pf_snapshots ORDER BY snapshot_date ASC')->fetchAll();
$pnlExtremes = [
    'max' => null,
    'min' => null,
];

foreach ($allHistory as $row) {
    $pnlValue = (float) $row['total_unrealized_pnl'];

    if ($pnlExtremes['max'] === null || $pnlValue > $pnlExtremes['max']['value']) {
        $pnlExtremes['max'] = [
            'value' => $pnlValue,
            'date' => $row['snapshot_date'],
        ];
    }

    if ($pnlExtremes['min'] === null || $pnlValue < $pnlExtremes['min']['value']) {
        $pnlExtremes['min'] = [
            'value' => $pnlValue,
            'date' => $row['snapshot_date'],
        ];
    }
}

$historyForChart = $allHistory;
if (!empty($allHistory) && $period !== 'all') {
    $latestDate = new DateTime(end($allHistory)['snapshot_date']);
    $startDate = clone $latestDate;

    if ($period === '1d') {
        // Keep only latest day available in history.
    } elseif ($period === '1w') {
        $startDate->modify('-6 days');
    } elseif ($period === '1m') {
        $startDate->modify('-1 month');
    } elseif ($period === 'ytd') {
        $startDate = new DateTime($latestDate->format('Y-01-01'));
    }

    $historyForChart = array_values(array_filter($allHistory, static function (array $row) use ($startDate): bool {
        $d = new DateTime($row['snapshot_date']);
        return $d >= $startDate;
    }));
}

$chartLabels = array_map(static fn($r) => $r['snapshot_date'], $historyForChart);
$chartMarket = array_map(static fn($r) => round((float) $r['total_market_value'], 2), $historyForChart);
$chartCost = array_map(static fn($r) => round((float) $r['total_cost_basis'], 2), $historyForChart);
$chartPnl = array_map(static fn($r) => round((float) $r['total_unrealized_pnl'], 2), $historyForChart);

include __DIR__ . '/partials/header.php';
?>
<section class="cards">
    <article class="card"><h3>Snapshot</h3><p class="big"><?= (int) $stats['snapshots'] ?></p></article>
    <article class="card"><h3>Strumenti</h3><p class="big"><?= (int) $stats['assets'] ?></p></article>
    <article class="card"><h3>Ultimo valore</h3><p class="big money-big"><span>EUR</span><strong><?= number_format($stats['last_market_value'], 2, ',', '.') ?></strong></p></article>
    <article class="card"><h3>Ultimo P/L</h3><p class="big money-big <?= $stats['last_pnl'] >= 0 ? 'pos' : 'neg' ?>"><span>EUR</span><strong><?= number_format($stats['last_pnl'], 2, ',', '.') ?></strong></p></article>
    <?php if ($enableFx): ?>
        <article class="card">
            <h3>EUR/USD</h3>
            <p class="big"><?= e($fxRateLabel) ?></p>
            <p class="tiny-note">Fonte ECB, riferimento <?= e($fxAsOfLabel) ?></p>
        </article>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Andamento storico</h2>
        <form method="get" class="period-toolbar">
            <button class="period-btn <?= $period === '1d' ? 'active' : '' ?>" type="submit" name="period" value="1d">1 giorno</button>
            <button class="period-btn <?= $period === '1w' ? 'active' : '' ?>" type="submit" name="period" value="1w">1 settimana</button>
            <button class="period-btn <?= $period === '1m' ? 'active' : '' ?>" type="submit" name="period" value="1m">1 mese</button>
            <button class="period-btn <?= $period === 'ytd' ? 'active' : '' ?>" type="submit" name="period" value="ytd">YTD</button>
            <button class="period-btn <?= $period === 'all' ? 'active' : '' ?>" type="submit" name="period" value="all">Tutto</button>
        </form>
    </div>

    <?php if (count($historyForChart) === 0): ?>
        <p>Nessun dato disponibile per il periodo selezionato.</p>
    <?php else: ?>
        <div class="split-chart-grid">
            <article class="chart-card">
                <h3>Valore di mercato</h3>
                <div class="chart-wrap chart-wrap-compact">
                    <canvas id="marketChart"></canvas>
                </div>
            </article>
            <article class="chart-card">
                <h3>Valore di carico</h3>
                <div class="chart-wrap chart-wrap-compact">
                    <canvas id="costChart"></canvas>
                </div>
            </article>
            <article class="chart-card">
                <h3>P/L</h3>
                <div class="chart-wrap chart-wrap-compact">
                    <canvas id="pnlChart"></canvas>
                </div>
            </article>
        </div>
    <?php endif; ?>
</section>

<?php if ($accountMetrics !== null && $accountMetrics['snapshot'] !== null): ?>
<section class="panel account-dashboard-panel">
    <div class="panel-head"><div><h2>Conto e patrimonio</h2><p class="tiny-note">Dati opzionali da export Patrimonio Fineco o inserimento manuale. Il credito non e patrimonio.</p></div><a class="btn-link" href="<?= e(app_base_path()) ?>/account.php">Apri conto</a></div>
    <div class="cards">
        <article class="card"><h3>Patrimonio Fineco</h3><p class="big"><?= $accountMetrics['snapshot']['fineco_total_assets'] !== null ? 'EUR ' . number_format((float) $accountMetrics['snapshot']['fineco_total_assets'], 2, ',', '.') : 'N/D' ?></p></article>
        <article class="card"><h3>Liquidita propria</h3><p class="big"><?= $accountMetrics['snapshot']['liquidity_total'] !== null ? 'EUR ' . number_format((float) $accountMetrics['snapshot']['liquidity_total'], 2, ',', '.') : 'N/D' ?></p></article>
        <article class="card"><h3>Saldo contabile</h3><p class="big <?= (float) ($accountMetrics['snapshot']['accounting_balance'] ?? 0) < 0 ? 'neg' : '' ?>"><?= $accountMetrics['snapshot']['accounting_balance'] !== null ? 'EUR ' . number_format((float) $accountMetrics['snapshot']['accounting_balance'], 2, ',', '.') : 'N/D' ?></p></article>
        <article class="card"><h3>Saldo disponibile</h3><p class="big"><?= $accountMetrics['snapshot']['available_balance'] !== null ? 'EUR ' . number_format((float) $accountMetrics['snapshot']['available_balance'], 2, ',', '.') : 'N/D' ?></p><p class="tiny-note">Non e patrimonio</p></article>
    </div>
    <?php if ($accountMetrics['credit']['lines_count'] > 0): ?><div class="account-credit-summary">Linee di credito: accordato <?= $accountMetrics['credit']['agreed_amount'] !== null ? 'EUR ' . number_format((float) $accountMetrics['credit']['agreed_amount'], 2, ',', '.') : 'N/D' ?>, utilizzato <?= $accountMetrics['credit']['used_amount'] !== null ? 'EUR ' . number_format((float) $accountMetrics['credit']['used_amount'], 2, ',', '.') : 'N/D' ?>, disponibile <?= $accountMetrics['credit']['available_amount'] !== null ? 'EUR ' . number_format((float) $accountMetrics['credit']['available_amount'], 2, ',', '.') : 'N/D' ?>. Escluso dal patrimonio.</div><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($pnlExtremes['max'] !== null && $pnlExtremes['min'] !== null): ?>
<section class="panel">
    <div class="panel-head">
        <h2>Estremi P/L storico</h2>
        <span class="tiny-note">Massimo e minimo P/L mai registrati negli snapshot</span>
    </div>

    <div class="cards">
        <article class="card">
            <h3>Massimo P/L</h3>
            <p class="big money-big <?= $pnlExtremes['max']['value'] >= 0 ? 'pos' : 'neg' ?>"><span>EUR</span><strong><?= number_format($pnlExtremes['max']['value'], 2, ',', '.') ?></strong></p>
            <p class="tiny-note">Raggiunto il <?= e($pnlExtremes['max']['date']) ?></p>
        </article>
        <article class="card">
            <h3>Minimo P/L</h3>
            <p class="big money-big <?= $pnlExtremes['min']['value'] >= 0 ? 'pos' : 'neg' ?>"><span>EUR</span><strong><?= number_format($pnlExtremes['min']['value'], 2, ',', '.') ?></strong></p>
            <p class="tiny-note">Raggiunto il <?= e($pnlExtremes['min']['date']) ?></p>
        </article>
    </div>
</section>
<?php endif; ?>

<section class="panel">
    <h2>Ultimi snapshot</h2>
    <?php if (!$history): ?>
        <p>Nessun dato ancora caricato. Vai su <a href="<?= e(app_base_path()) ?>/upload.php">Upload</a>.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Valore di carico</th>
                    <th>Valore di mercato</th>
                    <th>P/L</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $row): ?>
                <tr>
                    <td><?= e($row['snapshot_date']) ?></td>
                    <td>EUR <?= number_format((float) $row['total_cost_basis'], 2, ',', '.') ?></td>
                    <td>EUR <?= number_format((float) $row['total_market_value'], 2, ',', '.') ?></td>
                    <td class="<?= (float) $row['total_unrealized_pnl'] >= 0 ? 'pos' : 'neg' ?>">EUR <?= number_format((float) $row['total_unrealized_pnl'], 2, ',', '.') ?></td>
                    <td><a class="btn-link" href="<?= e(app_base_path()) ?>/snapshot.php?id=<?= (int) $row['id'] ?>">Apri</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php if ($enableNews): ?>
    <section class="panel">
        <div class="panel-head">
            <h2>News Rilevanti</h2>
            <span class="tiny-note">Selezione per finanza, politica, AI e hi-tech (con priorita' su parole chiave del tuo portafoglio)</span>
        </div>

        <div class="news-grid">
            <?php foreach ($newsByCategory as $category => $items): ?>
                <article class="news-card">
                    <h3><?= e($category) ?></h3>
                    <?php if (empty($items)): ?>
                        <p class="tiny-note">Nessuna notizia disponibile ora.</p>
                    <?php else: ?>
                        <ul class="news-list">
                            <?php foreach ($items as $item): ?>
                                <?php
                                $dateLabel = '';
                                if (!empty($item['timestamp'])) {
                                    $dateLabel = date('d/m H:i', (int) $item['timestamp']);
                                }
                                ?>
                                <li>
                                    <a href="<?= e($item['link']) ?>" target="_blank" rel="noopener noreferrer"><?= e($item['title']) ?></a>
                                    <?php if ($dateLabel !== ''): ?>
                                        <div class="tiny-note"><?= e($dateLabel) ?></div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if (count($historyForChart) > 0): ?>
<script src="<?= e(app_base_path()) ?>/assets/vendor/chart.umd.min.js"></script>
<script>
const labels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
const marketData = <?= json_encode($chartMarket) ?>;
const costData = <?= json_encode($chartCost) ?>;
const pnlData = <?= json_encode($chartPnl) ?>;

const eurIt = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' });
const chartTextColor = '#334650';
const chartGridColor = 'rgba(70, 92, 104, 0.16)';
const chartTooltipBg = 'rgba(15, 31, 41, 0.94)';

function createHistoryChart(canvasId, label, data, borderColor, backgroundColor, axisTitle) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label,
                    data,
                    borderColor,
                    backgroundColor,
                    tension: 0.25,
                    borderWidth: 2,
                    pointRadius: 3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: { labels: { color: chartTextColor } },
                tooltip: {
                    backgroundColor: chartTooltipBg,
                    titleColor: '#ffffff',
                    bodyColor: '#f2fbff',
                    borderColor: 'rgba(255,255,255,0.25)',
                    borderWidth: 1,
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + eurIt.format(context.parsed.y);
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: chartTextColor },
                    grid: { color: chartGridColor }
                },
                y: {
                    beginAtZero: false,
                    ticks: { color: chartTextColor },
                    grid: { color: chartGridColor },
                    title: { display: true, text: axisTitle, color: chartTextColor }
                }
            }
        }
    });
}

createHistoryChart('marketChart', 'Valore mercato', marketData, '#0f9488', 'rgba(15,148,136,0.12)', 'Valore mercato (EUR)');
createHistoryChart('costChart', 'Valore carico', costData, '#2f5fbb', 'rgba(47,95,187,0.10)', 'Valore carico (EUR)');
createHistoryChart('pnlChart', 'P/L', pnlData, '#137a3d', 'rgba(19,122,61,0.10)', 'P/L (EUR)');
</script>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
