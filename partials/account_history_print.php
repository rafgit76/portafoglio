<?php
$printBase = $printBase ?? app_base_path();
$printRows = $report['rows'];
$printLatest = $report['latest'];
$printMoney = static fn(?float $value): string => account_history_export_money($value);
$printValue = static fn(string $field): ?float => account_history_export_value($printLatest ?? [], $field);
$printDates = static function (?array $extreme): string {
    if ($extreme === null) {
        return 'Dato non disponibile';
    }
    $dates = $extreme['dates'];
    $text = implode(', ', array_map('account_history_export_date', array_slice($dates, 0, 2)));
    return $text . (count($dates) > 2 ? ' e altre ' . (count($dates) - 2) . ' date' : '');
};
$printStats = $report['stats'];
$chartSets = [
    'wealth' => ['net_adjusted_num' => ['Netto rettificato', '#007653'], 'portfolio_market_value_num' => ['Portafoglio di riferimento', '#1769b0'], 'fineco_total_assets' => ['Patrimonio Fineco (puo includere credito)', '#986e00']],
    'balances' => ['accounting_balance' => ['Saldo contabile', '#1769b0'], 'own_liquidity_adjusted_num' => ['Liquidita propria rettificata', '#007653'], 'available_balance' => ['Saldo disponibile (include credito)', '#986e00']],
    'credit' => ['credit_agreed_amount' => ['Accordato', '#1769b0'], 'credit_used_amount' => ['Utilizzato', '#bd3753'], 'credit_available_amount' => ['Disponibile', '#986e00']],
];
$printCharts = [];
foreach ($chartSets as $id => $series) {
    $datasets = [];
    foreach ($series as $field => [$label, $color]) {
        if ($printStats[$field]['count'] === 0) {
            continue;
        }
        $datasets[] = [
            'label' => $label, 'borderColor' => $color, 'backgroundColor' => $color,
            'borderDash' => in_array($field, ['fineco_total_assets', 'available_balance', 'credit_available_amount'], true) ? [5, 4] : [],
            'data' => array_map(static fn(array $row): array => [
                'x' => (new DateTimeImmutable($row['snapshot_date'], new DateTimeZone('UTC')))->getTimestamp() * 1000,
                'y' => account_history_export_value($row, $field),
            ], $printRows),
        ];
    }
    if ($datasets !== []) {
        $printCharts[$id] = $datasets;
    }
}
$hasBalances = isset($printCharts['balances']) || isset($printCharts['credit']);
$tableGroups = [
    ['title' => 'Archivio patrimonio', 'fields' => array_slice($report['fields'], 0, 6, true), 'note' => 'Portafoglio: totale Fineco o, se assente, posizioni consolidate della stessa data. Ricostruito = portafoglio + liquidita propria.'],
    ['title' => 'Archivio saldi e credito', 'fields' => array_slice($report['fields'], 7, null, true), 'note' => 'Saldo disponibile e credito disponibile non rappresentano patrimonio proprio. I dati mancanti restano N/D.'],
];
$tablePages = [];
foreach ($tableGroups as $group) {
    foreach (array_chunk(array_reverse($printRows), 20) as $chunk) {
        $tablePages[] = $group + ['rows' => $chunk];
    }
}
$pageCount = 1 + ($hasBalances ? 1 : 0) + count($tablePages);
$pageNumber = 0;
$printHeader = static function (string $section) use ($report): void { ?>
    <header class="report-header"><strong>PORTFOLIO INVESTIMENTI</strong><span><?= e($section) ?></span></header>
<?php };
$printFooter = static function () use (&$pageNumber, $pageCount, $report): void { $pageNumber++; ?>
    <footer class="report-footer"><span>Elaborazione personale &middot; Non e un documento ufficiale Fineco</span><span><?= $pageNumber ?> / <?= $pageCount ?></span></footer>
<?php };
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Andamento patrimonio - <?= e($printRows[0]['snapshot_date'] ?? '') ?> - <?= e($printLatest['snapshot_date'] ?? '') ?></title>
    <link rel="stylesheet" href="<?= e($printBase) ?>/assets/account-history-export.css">
</head>
<body>
<nav class="print-toolbar" aria-label="Esportazione">
    <a href="<?= e($printBase) ?>/account_history.php">Torna all'andamento</a>
    <span id="print-status" role="status">Preparazione grafici...</span>
    <button type="button" id="print-report" disabled>Stampa / Salva PDF</button>
</nav>
<noscript><p class="print-warning">JavaScript e necessario per i grafici. Attivalo e ricarica prima di stampare.</p></noscript>
<main>
<section class="report-page overview">
    <?php $printHeader('PATRIMONIO'); ?>
    <div class="report-title"><p class="eyebrow">STORICO DEL CONTO</p><h1>Andamento patrimonio</h1>
        <p><?= e($report['period']) ?> <span class="separator">/</span> <?= count($printRows) ?> snapshot <span class="separator">/</span> Importi in EUR</p>
    </div>
    <section class="net-headline">
        <div><h2>Patrimonio netto rettificato</h2><p class="net-value"><?= e($printMoney($printValue('net_adjusted_num'))) ?><small>EUR</small></p><p>Ultimo snapshot: <?= e(account_history_export_date($printLatest['snapshot_date'])) ?></p></div>
        <div class="net-change"><h3>Variazione nel periodo</h3><strong><?= e($printMoney($report['net_change'])) ?></strong><p>Variazione di valore,<br>non rendimento.</p></div>
    </section>
    <div class="extreme-grid">
        <?php foreach (['max' => 'Massimo netto', 'min' => 'Minimo netto'] as $direction => $label): $extreme = $printStats['net_adjusted_num'][$direction]; ?>
        <article class="extreme <?= e($direction) ?>"><h3><?= e($label) ?></h3><strong><?= e($printMoney($extreme['value'] ?? null)) ?></strong><p><?= e($printDates($extreme)) ?></p></article>
        <?php endforeach; ?>
    </div>
    <?php if (isset($printCharts['wealth'])): ?>
    <section class="chart-section"><h2>Evoluzione del patrimonio</h2><div class="chart-box wealth-chart"><canvas id="chart-wealth" aria-label="Grafico patrimonio" role="img"></canvas></div></section>
    <?php else: ?><p class="no-data">Nessun valore patrimoniale disponibile.</p><?php endif; ?>
    <div class="value-grid">
        <?php foreach (['fineco_total_assets' => 'Patrimonio Fineco', 'portfolio_market_value_num' => 'Portafoglio di riferimento', 'own_liquidity_adjusted_num' => 'Liquidita propria rettificata'] as $field => $label): ?>
        <div><h3><?= e($label) ?></h3><strong><?= e($printMoney($printValue($field))) ?></strong></div>
        <?php endforeach; ?>
    </div>
    <aside class="method"><strong>Patrimonio, non capacita di spesa</strong><p>Netto rettificato = patrimonio Fineco - credito disponibile. Liquidita propria = liquidita Fineco - credito disponibile. Se il credito disponibile manca, le rettifiche restano N/D.</p><p>Massimi e minimi si riferiscono alle rilevazioni disponibili nel periodo, al centesimo. I pari merito sono evidenziati nell'archivio; N/D non significa zero.</p></aside>
    <?php $printFooter(); ?>
</section>
<?php if ($hasBalances): ?>
<section class="report-page balances-page">
    <?php $printHeader('SALDI E CREDITO'); ?>
    <div class="report-title"><h1>Liquidita e capacita di spesa</h1><p><?= e($report['period']) ?> <span class="separator">/</span> Importi in EUR</p></div>
    <div class="value-grid">
        <?php foreach (['accounting_balance' => 'Ultimo saldo contabile', 'available_balance' => 'Ultimo saldo disponibile', 'reserved_liquidity' => 'Liquidita prenotata'] as $field => $label): ?>
        <div><h3><?= e($label) ?></h3><strong><?= e($printMoney($printValue($field))) ?></strong></div>
        <?php endforeach; ?>
    </div>
    <?php if (isset($printCharts['balances'])): ?>
    <section class="chart-section"><h2>Saldi e liquidita propria</h2><div class="chart-box"><canvas id="chart-balances" aria-label="Grafico saldi e liquidita" role="img"></canvas></div></section>
    <?php endif; ?>
    <div class="extreme-grid compact-extremes">
        <?php foreach (['max' => 'Massimo saldo contabile', 'min' => 'Minimo saldo contabile'] as $direction => $label): $extreme = $printStats['accounting_balance'][$direction]; ?>
        <article class="extreme <?= e($direction) ?>"><h3><?= e($label) ?></h3><strong><?= e($printMoney($extreme['value'] ?? null)) ?></strong><p><?= e($printDates($extreme)) ?></p></article>
        <?php endforeach; ?>
    </div>
    <?php if (isset($printCharts['credit'])): ?>
    <section class="chart-section credit-section"><h2>Linee di credito <small>Escluse dal patrimonio proprio</small></h2><div class="chart-box"><canvas id="chart-credit" aria-label="Grafico linee di credito" role="img"></canvas></div></section>
    <?php endif; ?>
    <aside class="method"><p>Il saldo contabile e contabilizzato; il saldo disponibile e spendibile e puo comprendere fidi. Il credito non viene ricavato dalla differenza tra i saldi. I fidi qui riportati sono quelli degli snapshot, non le impostazioni correnti delle linee manuali.</p></aside>
    <?php $printFooter(); ?>
</section>
<?php endif; ?>
<?php foreach ($tablePages as $tablePage): ?>
<section class="report-page archive-page">
    <?php $printHeader('ARCHIVIO COMPLETO'); ?>
    <div class="report-title"><h1><?= e($tablePage['title']) ?></h1><p><?= e($report['period']) ?> <span class="separator">/</span> Importi in EUR <span class="separator">/</span> Date piu recenti per prime</p></div>
    <p class="table-legend"><span class="max-key">Massimo</span><span class="min-key">Minimo</span><span class="both-key">Min = max</span><span>Estremi per colonna sull'intero archivio</span></p>
    <table class="history-print-table">
        <thead><tr><th scope="col">Data / Origine</th><?php foreach ($tablePage['fields'] as $label): ?><th scope="col"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($tablePage['rows'] as $row): ?>
        <tr><th scope="row"><?= e(account_history_export_date($row['snapshot_date'])) ?><small><?= e(account_history_export_origin($row)) ?></small></th>
            <?php foreach ($tablePage['fields'] as $field => $label):
                $value = account_history_export_value($row, $field);
                $isMax = $value !== null && round($value, 2) === $printStats[$field]['max']['value'];
                $isMin = $value !== null && round($value, 2) === $printStats[$field]['min']['value'];
                $kind = $isMax && $isMin ? 'both' : ($isMax ? 'max' : ($isMin ? 'min' : ''));
            ?><td class="<?= e($kind) ?>"><?= e($printMoney($value)) ?></td><?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="table-note"><?= e($tablePage['note']) ?> L'origine indica lo snapshot; netto, liquidita propria e ricostruito sono calcolati. Dettagli numerici e riepilogo di tutti gli estremi nell'export Excel.</p>
    <?php $printFooter(); ?>
</section>
<?php endforeach; ?>
</main>
<script id="report-chart-data" type="application/json"><?= json_encode($printCharts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="<?= e($printBase) ?>/assets/vendor/chart.umd.min.js"></script>
<script src="<?= e($printBase) ?>/assets/account-history-export.js"></script>
</body>
</html>
