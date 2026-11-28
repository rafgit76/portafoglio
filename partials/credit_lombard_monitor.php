<?php
$lombardRows = is_array($lombardMonitor['rows'] ?? null) ? $lombardMonitor['rows'] : [];
$lombardLatest = $lombardRows !== [] ? $lombardMonitor['latest'] : null;
$lombardRate = is_array($lombardLatest['rate'] ?? null) ? $lombardLatest['rate'] : null;
$lombardUsage = $lombardLatest['usage'] ?? null;
$lombardLimit = isset($lombardRate['credit_limit']) ? (float) $lombardRate['credit_limit'] : null;
$lombardPercent = $lombardUsage !== null && $lombardLimit !== null && $lombardLimit > 0 ? $lombardUsage / $lombardLimit * 100 : null;
$lombardIncomplete = ($lombardMonitor['missing_days'] ?? 0) > 0 || ($lombardMonitor['missing_rates'] ?? 0) > 0;
$yearIncomplete = ($lombardMonitor['year_missing_days'] ?? 0) > 0 || ($lombardMonitor['year_missing_rates'] ?? 0) > 0;
$lombardPercentText = static fn(?float $value): string => $value === null ? 'N/D' : number_format($value, 2, ',', '.') . '%';
$lastRate = $lombardRates !== [] ? $lombardRates[array_key_last($lombardRates)] : null;
?>
<section class="panel lombard-monitor" id="credit-lombard-monitor">
    <div class="panel-head"><div><p class="eyebrow">MONITORAGGIO INDICATIVO</p><h2>Credit Lombard</h2><p>Stima dal saldo contabile degli snapshot, separata dall'utilizzo ufficiale Fineco.</p></div></div>
    <p class="tiny-note">Il saldo contabile negativo non identifica necessariamente il solo Credit Lombard. Il calcolo usa i giorni osservati e il divisore 365 anche negli anni bisestili; date valuta, movimenti infragiornalieri e altre linee possono produrre differenze rispetto alla banca. La quota oltre plafond usa il tasso indicato solo come ipotesi da verificare contrattualmente.</p>
    <?php if (!empty($lombardCollateral)): ?>
        <details class="lombard-history"><summary>Dossier a garanzia nello snapshot <?= e($metrics['snapshot']['snapshot_date']) ?></summary>
            <p>Valore di mercato <?= e($money((float) $lombardCollateral['market'])) ?> · Nominale dei soli BTP <?= e($money($lombardCollateral['bond_nominal'] !== null ? (float) $lombardCollateral['bond_nominal'] : null)) ?>.</p>
            <p class="tiny-note">Ratei separati, valore di garanzia riconosciuto dalla banca e rapporto utilizzo/garanzia: N/D. Il valore del dossier resta nel portafoglio.</p>
        </details>
    <?php endif; ?>
    <?php if (array_filter(array_column($lombardCollateralHistory ?? [], 'market'), static fn($value): bool => $value !== null) !== []): ?>
        <h3>Valore di mercato del dossier a garanzia</h3>
        <?php
        $economicChart = ['id' => 'pne-lombard-collateral', 'label' => 'Andamento del valore di mercato nel dossier a garanzia', 'dates' => array_column($lombardCollateralHistory, 'snapshot_date'), 'series' => [
            ['label' => 'Valore di mercato del dossier', 'borderColor' => '#246bad', 'data' => array_map(static fn(array $row): ?float => $row['market'] === null ? null : (float) $row['market'], $lombardCollateralHistory)],
        ]];
        include __DIR__ . '/economic_chart.php';
        ?>
        <p class="tiny-note">Valori osservati nelle posizioni importate; non sono i valori riconosciuti dalla banca. Le date senza posizioni rilevate non sono valorizzate a zero.</p>
    <?php endif; ?>
    <?php if ($lombardError !== null): ?>
        <div class="flash flash-error"><?= e($lombardError) ?></div>
    <?php elseif ($lombardRows === []): ?>
        <p>Nessuno snapshot patrimoniale disponibile dalla data di attivazione della linea.</p>
    <?php else: ?>
        <?php if ($lombardLatest['date'] !== date('Y-m-d')): ?><div class="account-reconciliation account-reconciliation-warning">Ultima rilevazione <?= e($lombardLatest['date']) ?>: utilizzo e proiezioni non rappresentano necessariamente la situazione odierna.</div><?php endif; ?>
        <?php if ($lombardIncomplete): ?><div class="account-reconciliation account-reconciliation-warning"><strong>Storico incompleto:</strong> <?= (int) $lombardMonitor['missing_days'] ?> giorni senza saldo e <?= (int) $lombardMonitor['missing_rates'] ?> giorni con utilizzo ma senza tasso. Totali e proiezioni sono parziali, non importi dovuti alla banca.</div><?php endif; ?>
        <?php if ($lombardPercent !== null && $lombardPercent > 100): ?><div class="flash flash-error lombard-overlimit"><strong>ATTENZIONE: UTILIZZO STIMATO OLTRE PLAFOND.</strong> La quota eccedente usa un tasso ipotetico distinto: verifica condizioni e importi con Fineco.</div><?php endif; ?>
        <?php if ($lombardRate !== null && isset($activeLombardProfiles[0]['amount_granted']) && abs((float) $activeLombardProfiles[0]['amount_granted'] - (float) $lombardRate['credit_limit']) > 0.01): ?><div class="account-reconciliation account-reconciliation-warning">Il plafond nelle condizioni storiche differisce da quello della linea registrata. Verifica le decorrenze prima di usare la stima.</div><?php endif; ?>
        <div class="cards">
            <article class="card"><h3>Utilizzo stimato</h3><p class="big"><?= e($money($lombardUsage !== null ? (float) $lombardUsage : null)) ?></p><p class="tiny-note">Su <?= e($money($lombardLimit)) ?> - <?= e($lombardPercentText($lombardPercent)) ?> - <?= e($lombardLatest['date']) ?></p>
                <?php if ($lombardPercent !== null): ?><?php $level = $lombardPercent > 100 ? 'over' : ($lombardPercent >= 75 ? 'high' : ($lombardPercent >= 50 ? 'mid' : ($lombardPercent >= 25 ? 'soft' : 'low'))); ?><div class="lombard-progress lombard-progress-<?= e($level) ?>" role="progressbar" aria-label="Utilizzo stimato del plafond" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) min(100, max(0, round($lombardPercent, 2)))) ?>"><span style="width:<?= e((string) min(100, max(0, $lombardPercent))) ?>%"></span></div><?php endif; ?>
            </article>
            <article class="card"><h3>Costo stimato del giorno</h3><p class="big"><?= e($money($lombardLatest['interest'])) ?></p><p class="tiny-note">Giorno snapshot <?= e($lombardLatest['date']) ?></p></article>
            <article class="card"><h3>Interessi osservati<?= $lombardIncomplete ? ' parziali' : '' ?></h3><p class="big"><?= e($money((float) $lombardMonitor['total'])) ?></p><p class="tiny-note">Somma dei soli giorni rilevati dall'attivazione</p></article>
            <article class="card"><h3>Giorni con utilizzo</h3><p class="big"><?= (int) $lombardMonitor['positive_days'] ?></p><p class="tiny-note">Osservati, non necessariamente tutti i giorni effettivi</p></article>
            <article class="card"><h3>Consecutivi sotto zero</h3><p class="big"><?= $lombardMonitor['streak'] === null ? 'N/D' : (!empty($lombardMonitor['streak_incomplete']) ? 'Almeno ' : '') . (int) $lombardMonitor['streak'] ?></p><p class="tiny-note">La sequenza si interrompe in assenza di dati</p></article>
            <article class="card"><h3>Massimo osservato</h3><p class="big"><?= e($money(isset($lombardMonitor['max']['usage']) ? (float) $lombardMonitor['max']['usage'] : null)) ?></p><p class="tiny-note"><?php if ($lombardMonitor['max'] !== null): ?><?= e((string) $lombardMonitor['max']['date']) ?> - <?= e($lombardPercentText(!empty($lombardMonitor['max']['limit']) ? 100 * $lombardMonitor['max']['usage'] / $lombardMonitor['max']['limit'] : null)) ?><?php else: ?>Nessun utilizzo osservato<?php endif; ?></p></article>
            <article class="card"><h3>Costo medio osservato</h3><p class="big"><?= e($money($lombardMonitor['positive_days'] > 0 && $lombardMonitor['missing_rates'] === 0 ? $lombardMonitor['total'] / $lombardMonitor['positive_days'] : null)) ?></p><p class="tiny-note"><?= $lombardMonitor['missing_rates'] > 0 ? 'Non disponibile: mancano tassi per alcuni giorni' : 'Per giorno rilevato con utilizzo' . ($lombardIncomplete ? ' - parziale' : '') ?></p></article>
            <article class="card"><h3>TAN ordinario</h3><p class="big"><?= e($lombardPercentText(isset($lombardRate['tan']) ? (float) $lombardRate['tan'] * 100 : null)) ?></p><p class="tiny-note"><?= e((string) ($lombardRate['benchmark'] ?? 'Condizioni non disponibili')) ?><?= isset($lombardRate['spread']) ? ' + spread ' . e($lombardPercentText((float) $lombardRate['spread'] * 100)) : '' ?></p></article>
        </div>
        <h3>Stima interessi al 31/12/<?= e($lombardMonitor['year']) ?></h3>
        <p class="tiny-note">Scenari indicativi dal giorno successivo allo snapshot (<?= (int) $lombardMonitor['remaining_days'] ?> giorni), con tasso e plafond dello snapshot invariati. Conteggio annuo al 31 dicembre; esigibilita indicata: 1 marzo successivo. Non sono importi certi.</p>
        <?php if ($yearIncomplete): ?><p class="tiny-note neg">Base annua parziale: <?= (int) $lombardMonitor['year_missing_days'] ?> giorni senza saldo, <?= (int) $lombardMonitor['year_missing_rates'] ?> con tasso mancante.</p><?php endif; ?>
        <div class="cards">
            <article class="card"><h3>Saldo attuale costante<?= $yearIncomplete ? ' - parziale' : '' ?></h3><p class="big"><?= e($money($lombardMonitor['fixed_projection'])) ?></p></article>
            <article class="card"><h3>Stima dinamica<?= $yearIncomplete ? ' - parziale' : '' ?></h3><p class="big"><?= e($money($lombardMonitor['dynamic_projection'])) ?></p><p class="tiny-note"><?php if ($lombardMonitor['dynamic'] !== null): ?>Media utilizzo <?= e($money($lombardMonitor['dynamic']['average'])) ?> su <?= (int) $lombardMonitor['dynamic']['window'] ?> giorni consecutivi completi.<?php else: ?>Non disponibile: servono almeno 7 giorni consecutivi con saldo noto.<?php endif; ?></p></article>
        </div>
        <p class="tiny-note">Interessi osservati nell'anno: <?= e($money((float) $lombardMonitor['year_total'])) ?><?= $yearIncomplete ? ' (parziali)' : '' ?>. Il saldo costante non e necessariamente lo scenario piu prudente.</p>
        <h3>Utilizzo e interessi osservati</h3><p class="tiny-note">Le interruzioni del grafico indicano giorni senza dati. Gli interessi cumulati sono calcolati solo sulle rilevazioni disponibili.</p>
        <div class="chart-card lombard-chart"><canvas id="lombard-chart" aria-label="Utilizzo stimato Credit Lombard e interessi cumulati osservati" role="img"></canvas></div>
        <details class="lombard-history"><summary>Mostra storico giornaliero osservato</summary>
        <div class="andamento-export-actions">
            <a class="btn export-btn export-btn-excel" href="<?= e(app_base_path()) ?>/account.php?lombard_export=xlsx"><span class="export-file-icon" aria-hidden="true">XLS</span><span>Esporta Excel</span></a>
            <a class="btn export-btn export-btn-pdf" href="<?= e(app_base_path()) ?>/account.php?lombard_export=pdf" target="_blank" rel="noopener"><span class="export-file-icon" aria-hidden="true">PDF</span><span>Stampa PDF</span></a>
        </div>
        <div class="table-wrap"><table><thead><tr><th>Data</th><th>Saldo contabile</th><th>Utilizzo stimato</th><th>% plafond</th><th>TAN</th><th>Interesse stimato</th><th>Cumulato osservato</th></tr></thead><tbody>
        <?php foreach (array_reverse($lombardRows) as $row): ?><?php $limit = isset($row['rate']['credit_limit']) ? (float) $row['rate']['credit_limit'] : null; ?><tr><td><?= e($row['date']) ?></td><td><?= e($money($row['balance'])) ?></td><td><?= e($money($row['usage'])) ?></td><td><?= e($lombardPercentText($row['usage'] !== null && $limit !== null && $limit > 0 ? $row['usage'] / $limit * 100 : null)) ?></td><td><?= e($lombardPercentText(isset($row['rate']['tan']) ? (float) $row['rate']['tan'] * 100 : null)) ?></td><td><?= e($money($row['interest'])) ?></td><td><?= e($money($row['cumulative'])) ?><?= $lombardIncomplete ? ' *' : '' ?></td></tr><?php endforeach; ?>
        </tbody></table></div></details>
    <?php endif; ?>
    <?php if ($lombardSchemaReady): ?>
        <details class="lombard-history"><summary>Condizioni e storico tassi</summary>
        <p class="tiny-note">Inserire una nuova decorrenza non modifica gli snapshot, ma ricalcola le stime da quella data. Non aggiorna automaticamente Euribor. Confermare le condizioni con la banca.</p>
        <div class="table-wrap"><table><thead><tr><th>Dal</th><th>TAN</th><th>Spread</th><th>Tasso eccedenza</th><th>Plafond</th><th>Benchmark / note</th></tr></thead><tbody><?php foreach (array_reverse($lombardRates) as $rate): ?><tr><td><?= e((string) $rate['valid_from']) ?></td><td><?= e($lombardPercentText((float) $rate['tan'] * 100)) ?></td><td><?= e($lombardPercentText((float) $rate['spread'] * 100)) ?></td><td><?= e($lombardPercentText((float) $rate['overdraft_tan'] * 100)) ?></td><td><?= e($money((float) $rate['credit_limit'])) ?></td><td><?= e((string) $rate['benchmark']) ?><?= !empty($rate['notes']) ? ' - ' . e((string) $rate['notes']) : '' ?></td></tr><?php endforeach; ?></tbody></table></div>
        <form method="post" class="form-grid account-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_lombard_rate">
            <label>Decorrenza<input type="date" name="valid_from" required></label>
            <label>TAN ordinario %<input type="text" inputmode="decimal" name="tan_percent" required value="<?= e($lastRate !== null ? number_format((float) $lastRate['tan'] * 100, 2, ',', '') : '') ?>"></label>
            <label>Spread %<input type="text" inputmode="decimal" name="spread_percent" required value="<?= e($lastRate !== null ? number_format((float) $lastRate['spread'] * 100, 2, ',', '') : '') ?>"></label>
            <label>Tasso eccedenza %<input type="text" inputmode="decimal" name="overdraft_percent" required value="<?= e($lastRate !== null ? number_format((float) $lastRate['overdraft_tan'] * 100, 2, ',', '') : '') ?>"></label>
            <label>Plafond EUR<input type="text" inputmode="decimal" name="credit_limit" required value="<?= e($lastRate !== null ? number_format((float) $lastRate['credit_limit'], 2, ',', '') : '') ?>"></label>
            <label>Benchmark<input type="text" name="benchmark" maxlength="120" required value="<?= e((string) ($lastRate['benchmark'] ?? '')) ?>"></label>
            <label>Note<input type="text" name="rate_notes" maxlength="500"></label>
            <label class="checkbox-label"><input type="checkbox" name="confirm_rate" value="1" required> Confermo le condizioni e la decorrenza; una data retroattiva ricalcola lo storico.</label><button type="submit">Registra condizioni</button>
        </form></details>
    <?php endif; ?>
</section>
<?php if ($lombardRows !== []): ?>
<?php
$lombardChart = [];
$byChartDate = [];
foreach ($lombardRows as $row) { $byChartDate[$row['date']] = $row; }
$chartStart = lombard_date($lombardRows[0]['date']);
$chartEnd = lombard_date($lombardLatest['date']);
for ($day = $chartStart; $day <= $chartEnd; $day = $day->modify('+1 day')) {
    $date = $day->format('Y-m-d');
    $row = $byChartDate[$date] ?? null;
    $rate = lombard_rate_for_date($lombardRates, $date);
    $lombardChart[] = ['date' => $date, 'usage' => $row['usage'] ?? null, 'interest' => $row !== null && $row['interest'] !== null ? $row['cumulative'] : null, 'limit' => isset($rate['credit_limit']) ? (float) $rate['credit_limit'] : null];
}
?>
<script type="application/json" id="lombard-chart-data"><?= json_encode($lombardChart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="<?= e(app_base_path()) ?>/assets/vendor/chart.umd.min.js"></script>
<script src="<?= e(app_base_path()) ?>/assets/credit-lombard.js?v=<?= e((string) filemtime(__DIR__ . '/../assets/credit-lombard.js')) ?>" defer></script>
<?php endif; ?>
