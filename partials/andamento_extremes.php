<?php
$andamentoLatest = $orderedRows[array_key_last($orderedRows)];
$andamentoToday = new DateTimeImmutable('today');
$andamentoSigned = static function (float $value, string $suffix = ''): string {
    if (abs($value) < 0.005) { $value = 0.0; }
    return ($value > 0 ? '+' : ($value < 0 ? '-' : '')) . number_format(abs($value), 2, ',', '.') . $suffix;
};
?>
<section class="panel andamento-extremes" aria-labelledby="andamento-extremes-title">
    <div class="panel-head"><div><h2 id="andamento-extremes-title">Massimi e minimi per titolo</h2>
        <p class="tiny-note">Valori delle posizioni e P/L, non prezzi unitari. Seleziona un importo per date e distanza dall'ultimo snapshot (<?= e((string) $andamentoLatest['snapshot_date']) ?>).</p></div></div>
    <div class="andamento-extremes-table-wrap"><table class="andamento-extremes-table">
    <thead><tr><th scope="col">Titolo</th><th scope="col">Max valore</th><th scope="col">Min valore</th><th scope="col">Max P/L</th><th scope="col">Min P/L</th></tr></thead>
    <tbody>
    <?php foreach ($columns as $column): ?>
        <?php $assetId = (int) $column['id']; $latestValue = $andamentoLatest['values'][$assetId] ?? null; ?>
        <tr><th scope="row"><a href="<?= e(app_base_path()) ?>/asset.php?id=<?= $assetId ?>"><?= e((string) $column['label']) ?></a></th>
            <?php foreach (['market_value' => 'Valore', 'unrealized_pnl' => 'P/L'] as $metric => $metricLabel): ?>
                <?php foreach (['max' => 'Massimo', 'min' => 'Minimo'] as $direction => $directionLabel): ?>
                    <?php
                    $extreme = $assetExtremes[$assetId][$metric][$direction] ?? null;
                    $current = isset($latestValue[$metric]) ? (float) $latestValue[$metric] : null;
                    $distance = andamento_extreme_distance($current, $extreme, $andamentoToday);
                    ?>
                    <td>
                        <?php if ($extreme === null): ?>
                            N/D
                        <?php else: ?>
                            <details class="andamento-extreme-detail andamento-extreme-<?= e($direction) ?>">
                                <summary aria-label="<?= e($directionLabel . ' ' . $metricLabel . ' di ' . $column['label'] . ': ' . $formatMoney((float) $extreme['value']) . '. Mostra dettagli') ?>"><?= e($formatMoney((float) $extreme['value'])) ?></summary>
                                <div class="andamento-extreme-popover">
                                    <strong><?= e($directionLabel . ' ' . $metricLabel) ?></strong>
                                    <p><?= count($extreme['dates']) > 1 ? 'Ultima data: ' : 'Data: ' ?><?= e((string) $distance['date']) ?><?php if ($distance['days'] !== null): ?> &middot; <?= $distance['days'] === 0 ? 'oggi' : (int) $distance['days'] . ($distance['days'] === 1 ? ' giorno fa' : ' giorni fa') ?><?php endif; ?></p>
                                    <?php $extremeQuantity = $extreme['quantities'][$distance['date']] ?? null; ?>
                                    <p>Quantit&agrave; nello snapshot: <?= $extremeQuantity === null ? 'N/D' : e($formatQuantity((float) $extremeQuantity)) ?></p>
                                    <p>Scarto dall'ultimo snapshot (<?= e((string) $andamentoLatest['snapshot_date']) ?>): <?= $distance['delta'] === null ? 'N/D: titolo non presente' : 'EUR ' . e($andamentoSigned((float) $distance['delta'])) ?><?php if ($distance['delta'] !== null): ?> (<?= $distance['percent'] === null ? 'N/D %: estremo zero' : e($andamentoSigned((float) $distance['percent'], '%')) ?>)<?php endif; ?></p>
                                    <p class="andamento-extreme-explanation">Scarto = ultimo valore meno estremo; percentuale sul valore assoluto dell'estremo. Giorni riferiti al <?= e($andamentoToday->format('Y-m-d')) ?>.</p>
                                    <?php if (count($extreme['dates']) > 1): ?><details class="andamento-extreme-dates"><summary>Tutte le date (<?= count($extreme['dates']) ?>)</summary><p><?php foreach ($extreme['dates'] as $recordDate): ?><?php $recordQuantity = $extreme['quantities'][$recordDate] ?? null; ?><?= e((string) $recordDate) ?> &middot; quantit&agrave;: <?= $recordQuantity === null ? 'N/D' : e($formatQuantity((float) $recordQuantity)) ?><br><?php endforeach; ?></p></details><?php endif; ?>
                                    <button type="button" class="andamento-extreme-close">Chiudi</button>
                                </div>
                            </details>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<script src="<?= e(app_base_path()) ?>/assets/andamento-extremes.js?v=<?= e((string) filemtime(__DIR__ . '/../assets/andamento-extremes.js')) ?>" defer></script>
