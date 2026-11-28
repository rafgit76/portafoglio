<?php
$pneSnapshot = $accountMetrics['snapshot'] ?? null;
$pneMoney = static fn(?float $value): string => $value === null ? 'N/D' : 'EUR ' . number_format($value, 2, ',', '.');
$pneChanges = economic_patrimony_changes($economicHistory);
$pnePeriods = ['week' => '1 sett.', 'month' => '1 mese', 'quarter' => '3 mesi', 'semester' => '6 mesi', 'ytd' => 'Da gennaio', 'all' => 'Tutto'];
$pnePeriod = array_key_exists(is_string($_GET['pne_period'] ?? null) ? $_GET['pne_period'] : '', $pnePeriods) ? $_GET['pne_period'] : 'all';
$economicDetail = $accountMetrics['economic'] ?? economic_patrimony_calculate([]);
?>
<section class="panel pne-hero" id="pne">
    <div class="panel-head">
        <div><p class="eyebrow">SITUAZIONE PATRIMONIALE</p><h2>Patrimonio netto economico</h2></div>
        <div class="actions-inline"><a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/account_history.php">Storico patrimonio</a><a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/account.php">Conto</a></div>
    </div>
    <p class="pne-headline-value"><?= e($pneMoney($economicDetail['pne'])) ?></p>
    <?php if ($pneSnapshot === null): ?>
        <p>Nessuno snapshot patrimoniale. <a href="<?= e(app_base_path()) ?>/upload.php#patrimony-import">Importa il patrimonio Fineco</a> per calcolare il PNE.</p>
    <?php else: ?>
        <p class="pne-meta"><span class="status-pill<?= $economicDetail['reliable'] ? ' status-pill-success' : '' ?>"><?= e($economicDetail['status']) ?></span> Snapshot del <?= e($pneSnapshot['snapshot_date']) ?><?php if (!empty($pneSnapshot['updated_at'] ?? $pneSnapshot['created_at'])): ?> · Registrato <?= e((string) ($pneSnapshot['updated_at'] ?? $pneSnapshot['created_at'])) ?><?php endif; ?></p>
        <dl class="pne-deltas">
            <?php foreach (['previous' => 'Snapshot precedente', 'week' => 'Ultima settimana', 'month' => 'Ultimi 30 giorni', 'max' => 'Dal massimo'] as $key => $label): $change = $pneChanges[$key]; ?>
                <div><dt><?= e($label) ?></dt><dd class="<?= ($change['delta'] ?? 0) < 0 ? 'neg' : (($change['delta'] ?? 0) > 0 ? 'pos' : '') ?>" title="<?= e($change['date'] ?? '') ?>"><?= e($change === null ? 'N/D' : sprintf('%s%s%s', $change['delta'] > 0 ? '+' : '', number_format($change['delta'], 2, ',', '.'), $change['pct'] === null ? ' EUR' : ' EUR · ' . number_format($change['pct'], 2, ',', '.') . '%')) ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <p class="tiny-note">Variazioni di valore, non rendimenti. Per settimana e mese si usa l'ultimo riferimento attendibile alla data o prima.</p>
        <?php
        $pneOrdinary = $pneLombard = null;
        foreach ($accountMetrics['imported_dossiers'] as $dossier) {
            if ($dossier['dossier_code'] === 'ordinario') { $pneOrdinary = (float) $dossier['amount']; }
            if ($dossier['dossier_code'] === 'credit_lombard') { $pneLombard = (float) $dossier['amount']; }
        }
        if ($accountMetrics['portfolio'] !== null && ($pneOrdinary === null || $pneLombard === null)) {
            foreach (account_tracker_snapshot_dossier_totals($pdo, (int) $accountMetrics['portfolio']['id']) as $dossier) {
                if ($dossier['code'] === 'ordinario') { $pneOrdinary ??= (float) $dossier['total_market_value']; }
                if ($dossier['code'] === 'credit_lombard') { $pneLombard ??= (float) $dossier['total_market_value']; }
            }
        }
        $pneAgreed = account_tracker_snapshot_decimal($pneSnapshot, 'credit_agreed_amount');
        $pneUsage = $economicDetail['credit_used'];
        $pneUsagePercent = $pneUsage !== null && $pneAgreed > 0 ? $pneUsage / $pneAgreed * 100 : null;
        ?>
        <div class="cards pne-cards">
            <?php foreach (['Portafoglio consolidato' => $economicDetail['portfolio'], 'Dossier ordinario' => $pneOrdinary, 'Dossier Credit Lombard' => $pneLombard, 'Liquidita economica netta' => $economicDetail['net_liquidity'], 'Patrimonio Fineco' => account_tracker_snapshot_decimal($pneSnapshot, 'fineco_total_assets')] as $label => $value): ?>
                <article class="card"><h3><?= e($label) ?></h3><p class="big<?= $value < 0 ? ' neg' : '' ?>"><?= e($pneMoney($value)) ?></p></article>
            <?php endforeach; ?>
        </div>
        <div class="pne-credit-strip"><div><strong>Fido nello snapshot</strong><span>Utilizzato <?= e($pneMoney($pneUsage)) ?><?= $economicDetail['credit_used_derived'] ? ' (derivato)' : '' ?> · Accordato <?= e($pneMoney($pneAgreed)) ?> · Disponibile <?= e($pneMoney($economicDetail['credit_available'])) ?></span><?php if ($pneUsagePercent !== null): ?><progress value="<?= e((string) min(100, max(0, $pneUsagePercent))) ?>" max="100" aria-label="Fido utilizzato"></progress> <?= e(number_format($pneUsagePercent, 1, ',', '.')) ?>%<?php endif; ?><p class="tiny-note">Saldo contabile <?= e($pneMoney($economicDetail['accounting_balance'])) ?> · Prenotazioni <?= e($pneMoney($economicDetail['reserved_liquidity'])) ?> · Interessi maturati ufficiali N/D</p></div><a class="btn-link" href="<?= e(app_base_path()) ?>/account.php#credit-lombard-monitor">Monitor Credit Lombard</a></div>
        <details><summary>Metodo e quadrature</summary>
            <p>PNE = patrimonio Fineco meno il fido accordato incluso. Il saldo negativo e il fido utilizzato non si sottraggono una seconda volta. I dossier in garanzia restano nel portafoglio.</p>
            <p>Misura solo il patrimonio finanziario rilevato da Fineco: non comprende immobili, debiti esterni non registrati, imposte potenziali o spese future.</p>
            <?php include __DIR__ . '/economic_diagnostics.php'; ?>
            <p class="tiny-note">Netto rettificato legacy: <?= e($pneMoney($accountMetrics['net_adjusted'])) ?>. Non e il PNE.</p>
        </details>
        <div class="pne-chart-heading"><h3>Andamento del patrimonio</h3><nav class="actions-inline" aria-label="Periodo PNE">
            <?php foreach ($pnePeriods as $value => $label): ?><a class="btn-secondary btn-sm<?= $pnePeriod === $value ? ' pne-period-active' : '' ?>"<?= $pnePeriod === $value ? ' aria-current="true"' : '' ?> href="?<?= e(http_build_query(['period' => $period, 'pne_period' => $value])) ?>#pne"><?= e($label) ?></a><?php endforeach; ?>
        </nav></div>
        <?php
        $pneEnd = new DateTimeImmutable($pneSnapshot['snapshot_date']);
        $pneStart = match ($pnePeriod) {
            'week' => $pneEnd->modify('-7 days'), 'month' => $pneEnd->modify('-30 days'),
            'quarter' => $pneEnd->modify('-3 months'), 'semester' => $pneEnd->modify('-6 months'),
            'ytd' => new DateTimeImmutable($pneEnd->format('Y-01-01')), default => null,
        };
        $pneRows = $pneStart === null ? $economicHistory : array_values(array_filter($economicHistory, static fn(array $row): bool => $row['snapshot_date'] >= $pneStart->format('Y-m-d')));
        $pneValues = array_map(static fn(array $row): ?float => $row['economic']['pne'], $pneRows);
        $pneAvailable = array_filter($pneValues, static fn(?float $value): bool => $value !== null);
        $pneMarkers = $pneAvailable === [] ? [] : [array_search(min($pneAvailable), $pneAvailable, true), array_search(max($pneAvailable), $pneAvailable, true), array_key_last($pneAvailable)];
        $economicChart = ['id' => 'pne-dashboard-chart', 'label' => 'Andamento patrimonio netto economico', 'dates' => array_column($pneRows, 'snapshot_date'), 'series' => [
            ['label' => 'PNE', 'borderColor' => '#087a62', 'data' => $pneValues, 'pointRadius' => array_map(static fn(int $i): int => in_array($i, $pneMarkers, true) ? 5 : 1, array_keys($pneValues))],
            ['label' => 'Patrimonio Fineco', 'borderColor' => '#ab6818', 'hidden' => true, 'data' => array_map(static fn(array $row): ?float => account_tracker_snapshot_decimal($row, 'fineco_total_assets'), $pneRows)],
            ['label' => 'Portafoglio', 'borderColor' => '#2361a3', 'hidden' => true, 'data' => array_map(static fn(array $row): ?float => $row['economic']['portfolio'], $pneRows)],
            ['label' => 'Netto rettificato (legacy)', 'borderColor' => '#65748b', 'hidden' => true, 'data' => array_map(static fn(array $row): ?float => account_tracker_patrimonial_adjustments($row)['net_assets_adjusted'], $pneRows)],
        ]];
        include __DIR__ . '/economic_chart.php';
        ?>
        <p class="tiny-note">Attiva le serie di confronto dalla legenda. Le interruzioni indicano snapshot non attendibili o non disponibili.<?php if ($pneAvailable !== []): ?> Minimo <?= e($pneMoney(min($pneAvailable))) ?> · Massimo <?= e($pneMoney(max($pneAvailable))) ?> · Ultimo attendibile <?= e($pneMoney(end($pneAvailable))) ?>.<?php endif; ?></p>
    <?php endif; ?>
</section>
