<?php
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Strumenti';
$pdo = db();

$latestSnapshot = $pdo->query('SELECT id, snapshot_date, total_market_value FROM pf_snapshots ORDER BY snapshot_date DESC, id DESC LIMIT 1')->fetch();
$latestSnapshotId = $latestSnapshot ? (int) $latestSnapshot['id'] : 0;

$compositionRows = [];
if ($latestSnapshotId > 0) {
    $stmtComp = $pdo->prepare(
        'SELECT a.asset_type,
                SUM(p.market_value) AS total_market_value,
                COUNT(*) AS positions_count
         FROM pf_snapshot_positions p
         JOIN pf_assets a ON a.id = p.asset_id
         WHERE p.snapshot_id = :snapshot_id
         GROUP BY a.asset_type
         ORDER BY total_market_value DESC'
    );
    $stmtComp->execute([':snapshot_id' => $latestSnapshotId]);
    $compositionRows = $stmtComp->fetchAll();
}

$totalComposition = 0.0;
foreach ($compositionRows as $cr) {
    $totalComposition += (float) $cr['total_market_value'];
}

$pieLabels = [];
$pieValues = [];
$piePercents = [];
$pieColors = [
    '#0f766e',
    '#1d4ed8',
    '#16a34a',
    '#d97706',
    '#b91c1c',
    '#7c3aed',
    '#0891b2',
];

foreach ($compositionRows as $idx => $cr) {
    $value = (float) $cr['total_market_value'];
    $pct = $totalComposition > 0 ? ($value / $totalComposition) * 100 : 0.0;
    $pieLabels[] = $cr['asset_type'];
    $pieValues[] = round($value, 2);
    $piePercents[] = round($pct, 4);
}

$sql = 'SELECT a.id, a.asset_type, a.name, a.isin, a.ticker, a.currency,
               MAX(s.snapshot_date) AS last_snapshot,
               SUM(CASE WHEN s.id = (SELECT id FROM pf_snapshots ORDER BY snapshot_date DESC, id DESC LIMIT 1) THEN p.market_value ELSE 0 END) AS latest_market_value
        FROM pf_assets a
        LEFT JOIN pf_snapshot_positions p ON p.asset_id = a.id
        LEFT JOIN pf_snapshots s ON s.id = p.snapshot_id
        GROUP BY a.id, a.asset_type, a.name, a.isin, a.ticker, a.currency
        ORDER BY a.asset_type, a.name';

$rows = $pdo->query($sql)->fetchAll();

include __DIR__ . '/partials/header.php';
?>

<section class="panel">
    <div class="panel-head">
        <h2>Composizione portafoglio</h2>
        <?php if ($latestSnapshot): ?>
            <span class="muted-small">Ultimo snapshot: <?= e($latestSnapshot['snapshot_date']) ?></span>
        <?php endif; ?>
    </div>

    <?php if (!$latestSnapshot || !$compositionRows): ?>
        <p>Nessun dato di composizione disponibile.</p>
    <?php else: ?>
        <div class="composition-grid">
            <div class="panel" style="margin: 0;">
                <h3>Tabella per categoria</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Categoria</th>
                            <th>Posizioni</th>
                            <th>Valore</th>
                            <th>Peso %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($compositionRows as $cr): ?>
                            <?php $weight = $totalComposition > 0 ? ((float) $cr['total_market_value'] / $totalComposition) * 100 : 0.0; ?>
                            <tr>
                                <td><?= e($cr['asset_type']) ?></td>
                                <td><?= (int) $cr['positions_count'] ?></td>
                                <td>EUR <?= number_format((float) $cr['total_market_value'], 2, ',', '.') ?></td>
                                <td><?= number_format($weight, 2, ',', '.') ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Totale</th>
                            <th></th>
                            <th>EUR <?= number_format($totalComposition, 2, ',', '.') ?></th>
                            <th>100,00%</th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="panel" style="margin: 0;">
                <h3>Grafici composizione</h3>
                <div class="chart-wrap composition-chart">
                    <canvas id="compositionChart"></canvas>
                </div>
                <div class="chart-wrap composition-bar-chart">
                    <canvas id="compositionBarChart"></canvas>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Anagrafica strumenti</h2>
    <?php if (!$rows): ?>
        <p>Nessuno strumento ancora registrato.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Tipo</th>
                <th>Nome</th>
                <th>ISIN</th>
                <th>Ticker</th>
                <th>Valuta</th>
                <th>Ultimo snapshot</th>
                <th>Valore ultimo snapshot</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['asset_type']) ?></td>
                <td><a class="btn-link" href="<?= e(app_base_path()) ?>/asset.php?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td>
                <td><?= e($r['isin'] ?? '-') ?></td>
                <td><?= e($r['ticker'] ?? '-') ?></td>
                <td><?= e($r['currency']) ?></td>
                <td><?= e($r['last_snapshot'] ?? '-') ?></td>
                <td>EUR <?= number_format((float) $r['latest_market_value'], 2, ',', '.') ?></td>
                <td><a class="btn-link" href="<?= e(app_base_path()) ?>/asset.php?id=<?= (int) $r['id'] ?>">Dettaglio</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<?php if (!empty($compositionRows)): ?>
<script src="<?= e(app_base_path()) ?>/assets/vendor/chart.umd.min.js"></script>
<script>
const compLabels = <?= json_encode($pieLabels, JSON_UNESCAPED_UNICODE) ?>;
const compValues = <?= json_encode($pieValues) ?>;
const compPercents = <?= json_encode($piePercents) ?>;
const compColors = <?= json_encode(array_slice($pieColors, 0, max(1, count($pieValues)))) ?>;
const eurIt = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' });
const compText = '#334650';
const compTooltipBg = 'rgba(15, 31, 41, 0.94)';

const compCtx = document.getElementById('compositionChart');
if (compCtx) {
    new Chart(compCtx, {
        type: 'pie',
        data: {
            labels: compLabels,
            datasets: [{
                data: compValues,
                backgroundColor: compColors,
                borderColor: 'rgba(255,255,255,0.92)',
                borderWidth: 2.5,
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: compText,
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 12,
                        padding: 16,
                        font: { size: 13, weight: '600' },
                        generateLabels: function(chart) {
                            const data = chart.data;
                            if (!data.labels || !data.datasets.length) return [];

                            const vals = data.datasets[0].data;
                            const total = vals.reduce((acc, val) => acc + val, 0);

                            return data.labels.map((label, i) => {
                                const value = Number(vals[i] || 0);
                                const pct = total > 0 ? (value / total) * 100 : 0;
                                return {
                                    text: label + ' ' + pct.toFixed(1).replace('.', ',') + '%',
                                    fillStyle: data.datasets[0].backgroundColor[i],
                                    strokeStyle: data.datasets[0].backgroundColor[i],
                                    lineWidth: 0,
                                    hidden: false,
                                    index: i,
                                    pointStyle: 'circle'
                                };
                            });
                        }
                    }
                },
                tooltip: {
                    backgroundColor: compTooltipBg,
                    titleColor: '#ffffff',
                    bodyColor: '#f2fbff',
                    borderColor: 'rgba(255,255,255,0.25)',
                    borderWidth: 1,
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((acc, val) => acc + val, 0);
                            const val = context.parsed;
                            const pct = total > 0 ? (val / total) * 100 : 0;
                            return context.label + ': ' + eurIt.format(val) + ' (' + pct.toFixed(2).replace('.', ',') + '%)';
                        }
                    }
                }
            }
        }
    });
}

const barCtx = document.getElementById('compositionBarChart');
if (barCtx) {
    new Chart(barCtx, {
        type: 'bar',
        data: {
            labels: compLabels,
            datasets: [{
                label: 'Peso %',
                data: compPercents,
                backgroundColor: compColors,
                borderColor: compColors,
                borderWidth: 1,
                borderRadius: 7,
                maxBarThickness: 22
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: compTooltipBg,
                    titleColor: '#ffffff',
                    bodyColor: '#f2fbff',
                    borderColor: 'rgba(255,255,255,0.25)',
                    borderWidth: 1,
                    callbacks: {
                        label: function(context) {
                            const idx = context.dataIndex;
                            const pct = compPercents[idx] || 0;
                            const val = compValues[idx] || 0;
                            return 'Peso: ' + pct.toFixed(2).replace('.', ',') + '% - ' + eurIt.format(val);
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        color: compText,
                        callback: function(v) { return Number(v).toFixed(0) + '%'; }
                    },
                    grid: {
                        color: 'rgba(232, 241, 248, 0.18)'
                    },
                    title: {
                        display: true,
                        text: 'Peso sul portafoglio (%)',
                        color: compText
                    }
                },
                y: {
                    ticks: {
                        color: compText,
                        font: { size: 12, weight: '600' }
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
