<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/account_tracker.php';
require_once __DIR__ . '/lib/account_history_export.php';

$pageTitle = 'Andamento patrimonio';
$pdo = db();

if (!account_tracker_schema_ready($pdo)) {
    include __DIR__ . '/partials/header.php';
    ?>
    <section class="panel empty-state">
        <h2>Modulo conto da inizializzare</h2>
        <p>Se il modulo Conto non era mai stato installato, importa prima <code>sql/migration_2_2_0_account_tracker.sql</code>; poi, dopo un export completo del database, importa una sola volta <code>sql/migration_2_4_0_credit_lombard_semantics.sql</code>.</p>
        <p class="tiny-note">Fino alla migrazione l'app continua a funzionare come tracker del solo portafoglio.</p>
    </section>
    <?php
    include __DIR__ . '/partials/footer.php';
    exit;
}

$pageSizeOptions = [25, 50, 100];
$pageSize = (int) ($_GET['per_page'] ?? 25);
if (!in_array($pageSize, $pageSizeOptions, true)) {
    $pageSize = 25;
}
$requestedPage = max(1, (int) ($_GET['page'] ?? 1));
$detail = ($_GET['view'] ?? '') === 'detail';
$statusOptions = ['VERIFICATO', 'RICOSTRUITO', 'DA VERIFICARE', 'NON DISPONIBILE'];
$statusFilter = in_array($_GET['status'] ?? '', $statusOptions, true) ? $_GET['status'] : '';
$historyUrl = static function (int $page, int $perPage) use ($detail, $statusFilter): string {
    return app_base_path() . '/account_history.php?' . http_build_query([
        'page' => max(1, $page),
        'per_page' => $perPage,
        'view' => $detail ? 'detail' : 'compact',
        'status' => $statusFilter,
    ]);
};

$history = account_history_prepare(account_tracker_economic_history($pdo));

if ($history !== [] && in_array($_GET['export'] ?? '', ['xlsx', 'pdf'], true)) {
    $report = account_history_export_model($history);
    if ($_GET['export'] === 'pdf') {
        include __DIR__ . '/partials/account_history_print.php';
        exit;
    }
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        http_response_code(503);
        exit('Esportazione Excel non disponibile: manca PhpSpreadsheet.');
    }
    $book = account_history_export_workbook($report);
    // Build before sending download headers, so a writer error cannot return a corrupt workbook.
    $temporary = fopen('php://temp/maxmemory:5242880', 'w+b');
    try {
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($temporary);
        rewind($temporary);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="andamento_patrimonio_' . date('Ymd_His') . '.xlsx"');
        header('Cache-Control: private, no-store');
        fpassthru($temporary);
    } finally {
        fclose($temporary);
        $book->disconnectWorksheets();
    }
    exit;
}

$archiveRows = $statusFilter === '' ? $history : array_values(array_filter($history, static fn(array $row): bool => $row['economic']['status'] === $statusFilter));
$totalSnapshots = count($archiveRows);
$totalPages = max(1, (int) ceil($totalSnapshots / $pageSize));
$currentPage = min($requestedPage, $totalPages);
$offset = ($currentPage - 1) * $pageSize;
$rows = array_slice(array_reverse($archiveRows), $offset, $pageSize);
$firstRow = $totalSnapshots === 0 ? 0 : $offset + 1;
$lastRow = min($totalSnapshots, $offset + count($rows));

$latest = $history === [] ? null : $history[array_key_last($history)];
$maxFineco = account_tracker_history_extreme($history, 'fineco_total_assets', 'max');
$minFineco = account_tracker_history_extreme($history, 'fineco_total_assets', 'min');
$maxNetAdjusted = account_tracker_history_extreme($history, 'pne_num', 'max');
$minNetAdjusted = account_tracker_history_extreme($history, 'pne_num', 'min');
$maxAvailable = account_tracker_history_extreme($history, 'available_balance', 'max');
$minAccounting = account_tracker_history_extreme($history, 'accounting_balance', 'min');

$chartLabels = array_map(static fn(array $row): string => (new DateTimeImmutable((string) $row['snapshot_date']))->format('d/m/Y'), $history);
$chartFineco = array_map(static fn(array $row): ?float => $row['fineco_total_assets'] !== null ? round((float) $row['fineco_total_assets'], 2) : null, $history);
$chartNetAdjusted = array_map(static fn(array $row): ?float => $row['net_adjusted_num'] !== null ? round((float) $row['net_adjusted_num'], 2) : null, $history);
$chartPortfolio = array_map(static fn(array $row): ?float => $row['portfolio_market_value_num'] !== null ? round((float) $row['portfolio_market_value_num'], 2) : null, $history);
$chartReconstructed = array_map(static fn(array $row): ?float => $row['reconstructed_gross_num'] !== null ? round((float) $row['reconstructed_gross_num'], 2) : null, $history);
$chartAccounting = array_map(static fn(array $row): ?float => $row['accounting_balance'] !== null ? round((float) $row['accounting_balance'], 2) : null, $history);
$chartAvailable = array_map(static fn(array $row): ?float => $row['available_balance'] !== null ? round((float) $row['available_balance'], 2) : null, $history);
$chartLiquidity = array_map(static fn(array $row): ?float => $row['liquidity_total'] !== null ? round((float) $row['liquidity_total'], 2) : null, $history);
$chartOwnLiquidity = array_map(static fn(array $row): ?float => $row['own_liquidity_adjusted_num'] !== null ? round((float) $row['own_liquidity_adjusted_num'], 2) : null, $history);
$chartPne = array_column($history, 'pne_num');
$chartNetCash = array_column($history, 'economic_liquidity_num');
$hasPatrimonyChart = count(array_filter(array_merge($chartPne, $chartFineco, $chartNetAdjusted, $chartPortfolio, $chartReconstructed), static fn($value): bool => $value !== null)) > 0;
$hasBalancesChart = count(array_filter(array_merge($chartNetCash, $chartAccounting, $chartAvailable, $chartLiquidity, $chartOwnLiquidity), static fn($value): bool => $value !== null)) > 0;

$money = static function (?float $value): string {
    return $value === null ? 'Dato non disponibile' : 'EUR ' . number_format($value, 2, ',', '.');
};
$originLabel = static function (?string $origin): string {
    return match ($origin) {
        'IMPORT_FINECO' => 'Import Fineco',
        'CALCOLATO' => 'Calcolato',
        default => 'Manuale',
    };
};
$sameExtreme = static function (?float $value, ?array $extreme): bool {
    return $value !== null && $extreme !== null && abs($value - (float) $extreme['value']) < 0.00001;
};

include __DIR__ . '/partials/header.php';
?>
<section class="panel account-hero account-history-hero">
    <div class="panel-head">
        <div>
            <p class="eyebrow">ARCHIVIO PATRIMONIALE</p>
            <h2>Andamento patrimonio</h2>
            <p>Patrimonio netto economico, liquidita e saldi. Solo il PNE verificato o ricostruito entra nei confronti.</p>
        </div>
        <div class="actions-inline">
            <a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/account.php">Torna al conto</a>
            <a class="btn" href="<?= e(app_base_path()) ?>/upload.php#patrimony-import">Importa patrimonio</a>
        </div>
    </div>
    <?php if ($history !== []): ?>
    <div class="andamento-export-actions">
        <a class="btn export-btn export-btn-excel" href="<?= e(app_base_path()) ?>/account_history.php?export=xlsx"><span class="export-file-icon" aria-hidden="true">XLS</span><span>Esporta Excel</span></a>
        <a class="btn export-btn export-btn-pdf" href="<?= e(app_base_path()) ?>/account_history.php?export=pdf" target="_blank" rel="noopener"><span class="export-file-icon" aria-hidden="true">PDF</span><span>Stampa PDF</span></a>
    </div>
    <?php endif; ?>
</section>

<?php if ($history === []): ?>
    <section class="panel empty-state">
        <h2>Nessuno snapshot patrimoniale disponibile</h2>
        <p>Il portafoglio continua a funzionare normalmente. Per iniziare lo storico, carica l'export Patrimonio Fineco oppure registra uno snapshot manuale nella pagina Conto.</p>
        <div class="actions-inline">
            <a class="btn" href="<?= e(app_base_path()) ?>/upload.php#patrimony-import">Carica export Patrimonio Fineco</a>
            <a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/account.php#patrimonial-snapshot">Apri Conto</a>
        </div>
    </section>
<?php else: ?>
    <section class="cards">
        <article class="card"><h3>Snapshot patrimoniali</h3><p class="big"><?= count($history) ?></p></article>
        <article class="card"><h3>Periodo</h3><p class="big account-period-value"><?= e((new DateTimeImmutable((string) $history[0]['snapshot_date']))->format('d/m/Y')) ?><br><span>al <?= e((new DateTimeImmutable((string) $latest['snapshot_date']))->format('d/m/Y')) ?></span></p></article>
        <article class="card"><h3>Ultimo patrimonio Fineco</h3><p class="big"><?= e($money($latest['fineco_total_assets'] !== null ? (float) $latest['fineco_total_assets'] : null)) ?></p><p class="tiny-note">Puo includere credito</p></article>
        <article class="card"><h3>Ultimo PNE</h3><p class="big"><?= e($money($latest['pne_num'])) ?></p><p class="tiny-note"><?= e($latest['economic']['status']) ?></p></article>
        <article class="card"><h3>Ultimo saldo contabile</h3><p class="big <?= (float) ($latest['accounting_balance'] ?? 0) < 0 ? 'neg' : '' ?>"><?= e($money($latest['accounting_balance'] !== null ? (float) $latest['accounting_balance'] : null)) ?></p></article>
    </section>

    <?php if ($maxFineco !== null || $minFineco !== null || $maxNetAdjusted !== null || $minNetAdjusted !== null || $maxAvailable !== null || $minAccounting !== null): ?>
    <section class="panel">
        <div class="panel-head">
            <h2>Massimi e minimi storici</h2>
            <span class="tiny-note">Calcolati su tutti gli snapshot patrimoniali disponibili.</span>
        </div>
        <div class="cards">
            <article class="card"><h3>Massimo patrimonio Fineco</h3><p class="big pos"><?= e($money($maxFineco['value'] ?? null)) ?></p><p class="tiny-note"><?= $maxFineco !== null ? 'Raggiunto il ' . e((new DateTimeImmutable($maxFineco['date']))->format('d/m/Y')) : 'Dato non disponibile' ?></p></article>
            <article class="card"><h3>Minimo patrimonio Fineco</h3><p class="big <?= (($minFineco['value'] ?? 0) < 0) ? 'neg' : '' ?>"><?= e($money($minFineco['value'] ?? null)) ?></p><p class="tiny-note"><?= $minFineco !== null ? 'Raggiunto il ' . e((new DateTimeImmutable($minFineco['date']))->format('d/m/Y')) : 'Dato non disponibile' ?></p></article>
            <article class="card"><h3>Massimo PNE</h3><p class="big pos"><?= e($money($maxNetAdjusted['value'] ?? null)) ?></p><p class="tiny-note"><?= $maxNetAdjusted !== null ? 'Raggiunto il ' . e((new DateTimeImmutable($maxNetAdjusted['date']))->format('d/m/Y')) : 'Dato non disponibile' ?></p></article>
            <article class="card"><h3>Minimo PNE</h3><p class="big <?= (($minNetAdjusted['value'] ?? 0) < 0) ? 'neg' : '' ?>"><?= e($money($minNetAdjusted['value'] ?? null)) ?></p><p class="tiny-note"><?= $minNetAdjusted !== null ? 'Raggiunto il ' . e((new DateTimeImmutable($minNetAdjusted['date']))->format('d/m/Y')) : 'Dato non disponibile' ?></p></article>
            <article class="card"><h3>Massimo saldo disponibile</h3><p class="big pos"><?= e($money($maxAvailable['value'] ?? null)) ?></p><p class="tiny-note"><?= $maxAvailable !== null ? 'Raggiunto il ' . e((new DateTimeImmutable($maxAvailable['date']))->format('d/m/Y')) : 'Dato non disponibile' ?></p></article>
            <article class="card"><h3>Minimo saldo contabile</h3><p class="big <?= (($minAccounting['value'] ?? 0) < 0) ? 'neg' : '' ?>"><?= e($money($minAccounting['value'] ?? null)) ?></p><p class="tiny-note"><?= $minAccounting !== null ? 'Raggiunto il ' . e((new DateTimeImmutable($minAccounting['date']))->format('d/m/Y')) : 'Dato non disponibile' ?></p></article>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($hasPatrimonyChart || $hasBalancesChart): ?>
    <section class="panel">
        <div class="panel-head">
            <h2>Grafici storici</h2>
            <span class="tiny-note">Visualizzati solo i valori effettivamente disponibili.</span>
        </div>
        <div class="split-chart-grid account-history-chart-grid">
            <?php if ($hasPatrimonyChart): ?>
            <article class="chart-card">
                <h3>Patrimonio e portafoglio</h3>
                <div class="chart-wrap chart-wrap-compact"><canvas id="patrimonyChart"></canvas></div>
            </article>
            <?php endif; ?>
            <?php if ($hasBalancesChart): ?>
            <article class="chart-card">
                <h3>Saldi e liquidita</h3>
                <div class="chart-wrap chart-wrap-compact"><canvas id="balancesChart"></canvas></div>
            </article>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Archivio snapshot patrimoniali</h2>
                <p class="tiny-note">Visualizzati <?= $firstRow ?>-<?= $lastRow ?> di <?= $totalSnapshots ?> snapshot.</p>
            </div>
        </div>
        <div class="snapshots-toolbar">
            <form method="get" class="snapshot-page-size pne-history-filter">
                <input type="hidden" name="page" value="1">
                <label for="pne-history-view">Vista</label>
                <select id="pne-history-view" name="view"><option value="compact">Compatta</option><option value="detail"<?= $detail ? ' selected' : '' ?>>Dettagliata</option></select>
                <label for="pne-history-status">Stato</label>
                <select id="pne-history-status" name="status"><option value="">Tutti</option><?php foreach ($statusOptions as $option): ?><option<?= $option === $statusFilter ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select>
                <label for="accountHistoryPageSize">Righe</label>
                <select id="accountHistoryPageSize" name="per_page" onchange="this.form.submit()">
                    <?php foreach ($pageSizeOptions as $option): ?>
                        <option value="<?= $option ?>"<?= $pageSize === $option ? ' selected' : '' ?>><?= $option ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn-secondary btn-sm" type="submit">Applica</button>
            </form>
            <?php if ($totalPages > 1): ?>
            <nav class="snapshots-pagination" aria-label="Paginazione storico patrimonio">
                <?php if ($currentPage > 1): ?><a class="btn-secondary btn-sm" href="<?= e($historyUrl($currentPage - 1, $pageSize)) ?>">Indietro</a><?php else: ?><span class="btn-secondary btn-sm is-disabled" aria-disabled="true">Indietro</span><?php endif; ?>
                <span class="snapshots-page-count">Pagina <?= $currentPage ?>/<?= $totalPages ?></span>
                <?php if ($currentPage < $totalPages): ?><a class="btn-secondary btn-sm" href="<?= e($historyUrl($currentPage + 1, $pageSize)) ?>">Avanti</a><?php else: ?><span class="btn-secondary btn-sm is-disabled" aria-disabled="true">Avanti</span><?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>
        <div class="table-wrap account-history-table-wrap">
            <table class="<?= $detail ? 'account-history-table' : 'pne-compact-table' ?>">
                <?php if (!$detail): ?>
                <thead><tr><?php foreach (['Data', 'PNE', 'Variazione', 'Portafoglio', 'Liquidita netta', 'Saldo contabile', 'Fido utilizzato', 'Stato'] as $label): ?><th scope="col"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <th scope="row"><?= e(account_history_export_date($row['snapshot_date'])) ?></th>
                        <?php foreach (['pne_num', 'pne_delta_num', 'portfolio_market_value_num', 'economic_liquidity_num', 'accounting_balance', 'economic_credit_used_num'] as $key): ?>
                        <td><?= e(account_history_export_money(account_history_export_value($row, $key))) ?></td>
                        <?php endforeach; ?>
                        <td><details><summary><?= e($row['economic']['status']) ?></summary><?php $economicDetail = $row['economic']; include __DIR__ . '/partials/economic_diagnostics.php'; ?></details></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php else: ?>
                <thead><tr><th>Data</th><th>Patrimonio Fineco</th><th>Netto rettificato (legacy)</th><th>Portafoglio Fineco</th><th>Liquidita Fineco</th><th>Liquidita propria (legacy)</th><th>Ricostruito (legacy)</th><th>Saldo contabile</th><th>Saldo disponibile</th><th>Fido disponibile</th><th>Fido utilizzato</th><th>Origine</th><th>Dettaglio PNE</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $fineco = $row['fineco_total_assets'] !== null ? (float) $row['fineco_total_assets'] : null;
                    $accounting = $row['accounting_balance'] !== null ? (float) $row['accounting_balance'] : null;
                    $available = $row['available_balance'] !== null ? (float) $row['available_balance'] : null;
                    $isMaxFineco = $sameExtreme($fineco, $maxFineco);
                    $isMinFineco = $sameExtreme($fineco, $minFineco);
                    $rowClass = $isMaxFineco ? 'snapshot-row-max' : ($isMinFineco ? 'snapshot-row-min' : '');
                    ?>
                    <tr class="<?= e($rowClass) ?>">
                        <td><?= e((new DateTimeImmutable((string) $row['snapshot_date']))->format('d/m/Y')) ?></td>
                        <td><?= e($money($fineco)) ?><?php if ($isMaxFineco): ?><span class="snapshot-extreme-badge snapshot-extreme-max">Massimo</span><?php elseif ($isMinFineco): ?><span class="snapshot-extreme-badge snapshot-extreme-min">Minimo</span><?php endif; ?></td>
                        <td><?= e($money($row['net_adjusted_num'])) ?></td>
                        <td><?= e($money($row['portfolio_market_value_num'])) ?></td>
                        <td><?= e($money($row['liquidity_total'] !== null ? (float) $row['liquidity_total'] : null)) ?></td>
                        <td><?= e($money($row['own_liquidity_adjusted_num'])) ?></td>
                        <td><?= e($money($row['reconstructed_gross_num'])) ?></td>
                        <td class="<?= $accounting !== null && $accounting < 0 ? 'neg' : '' ?>"><?= e($money($accounting)) ?><?php if ($sameExtreme($accounting, $minAccounting)): ?><span class="snapshot-extreme-badge snapshot-extreme-min">Minimo</span><?php endif; ?></td>
                        <td><?= e($money($available)) ?><?php if ($sameExtreme($available, $maxAvailable)): ?><span class="snapshot-extreme-badge snapshot-extreme-max">Massimo</span><?php endif; ?></td>
                        <td><?= e($money($row['credit_available_amount'] !== null ? (float) $row['credit_available_amount'] : null)) ?></td>
                        <td><?= e($money($row['credit_used_amount'] !== null ? (float) $row['credit_used_amount'] : null)) ?></td>
                        <td><?= e($originLabel((string) ($row['data_origin'] ?? ''))) ?></td>
                        <td><details><summary><?= e($row['economic']['status']) ?></summary>
                            <dl class="pne-diagnostics">
                            <?php foreach (account_history_economic_fields() + ['reserved_liquidity' => 'Prenotazioni', 'credit_agreed_amount' => 'Fido accordato'] as $field => $label): ?>
                                <dt><?= e($label) ?></dt><dd><?= e($money(account_history_export_value($row, $field))) ?></dd>
                            <?php endforeach; ?>
                            </dl><?php $economicDetail = $row['economic']; include __DIR__ . '/partials/economic_diagnostics.php'; ?>
                        </details></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php endif; ?>
            </table>
            <?php if ($rows === []): ?><p>Nessuno snapshot per lo stato selezionato.</p><?php endif; ?>
        </div>
        <?php if ($totalPages > 1): ?>
        <nav class="snapshots-pagination snapshots-pagination-bottom" aria-label="Paginazione storico patrimonio">
            <?php if ($currentPage > 1): ?><a class="btn-secondary btn-sm" href="<?= e($historyUrl($currentPage - 1, $pageSize)) ?>">Indietro</a><?php else: ?><span class="btn-secondary btn-sm is-disabled" aria-disabled="true">Indietro</span><?php endif; ?>
            <span class="snapshots-page-count">Pagina <?= $currentPage ?>/<?= $totalPages ?></span>
            <?php if ($currentPage < $totalPages): ?><a class="btn-secondary btn-sm" href="<?= e($historyUrl($currentPage + 1, $pageSize)) ?>">Avanti</a><?php else: ?><span class="btn-secondary btn-sm is-disabled" aria-disabled="true">Avanti</span><?php endif; ?>
        </nav>
        <?php endif; ?>
    </section>

    <?php if ($hasPatrimonyChart || $hasBalancesChart): ?>
    <script src="<?= e(app_base_path()) ?>/assets/vendor/chart.umd.min.js"></script>
    <script>
    (() => {
        const labels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const euro = new Intl.NumberFormat('it-IT', {style: 'currency', currency: 'EUR'});
        const makeChart = (canvasId, datasets) => {
            const canvas = document.getElementById(canvasId);
            if (!canvas || typeof Chart === 'undefined') return;
            new Chart(canvas, {
                type: 'line',
                data: {labels, datasets},
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {mode: 'index', intersect: false},
                    plugins: {legend: {position: 'top'}, tooltip: {callbacks: {label: (context) => `${context.dataset.label}: ${euro.format(context.parsed.y)}`}}},
                    scales: {y: {ticks: {callback: (value) => euro.format(value)}}}
                }
            });
        };
        makeChart('patrimonyChart', [
            {label: 'Patrimonio netto economico', data: <?= json_encode($chartPne) ?>, borderColor: '#087a62', tension: .18, spanGaps: false},
            {label: 'Patrimonio Fineco', data: <?= json_encode($chartFineco) ?>, borderColor: '#129e96', backgroundColor: 'rgba(18, 158, 150, .12)', tension: .22, spanGaps: true},
            {label: 'Netto rettificato (legacy)', data: <?= json_encode($chartNetAdjusted) ?>, borderColor: '#168349', hidden: true, tension: .22, spanGaps: true},
            {label: 'Portafoglio consolidato', data: <?= json_encode($chartPortfolio) ?>, borderColor: '#2f64c8', backgroundColor: 'rgba(47, 100, 200, .10)', tension: .22, spanGaps: true},
            {label: 'Patrimonio ricostruito', data: <?= json_encode($chartReconstructed) ?>, borderColor: '#65748b', backgroundColor: 'rgba(101, 116, 139, .1)', borderDash: [6, 4], tension: .22, spanGaps: true}
        ]);
        makeChart('balancesChart', [
            {label: 'Saldo contabile', data: <?= json_encode($chartAccounting) ?>, borderColor: '#cb3650', backgroundColor: 'rgba(203, 54, 80, .1)', tension: .22, spanGaps: true},
            {label: 'Saldo disponibile', data: <?= json_encode($chartAvailable) ?>, borderColor: '#3373cc', backgroundColor: 'rgba(51, 115, 204, .1)', tension: .22, spanGaps: true},
            {label: 'Liquidita Fineco', data: <?= json_encode($chartLiquidity) ?>, borderColor: '#65748b', backgroundColor: 'rgba(101, 116, 139, .1)', tension: .22, spanGaps: true},
            {label: 'Liquidita economica netta', data: <?= json_encode($chartNetCash) ?>, borderColor: '#168349', tension: .22, spanGaps: false},
            {label: 'Liquidita propria rettificata (legacy)', data: <?= json_encode($chartOwnLiquidity) ?>, borderColor: '#168349', hidden: true, tension: .22, spanGaps: true}
        ]);
    })();
    </script>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
