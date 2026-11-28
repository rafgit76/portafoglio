<?php
$economicChartId = $economicChart['id'];
$economicChartHasData = false;
foreach ($economicChart['series'] as $economicSeries) {
    if (array_filter($economicSeries['data'], static fn($value): bool => $value !== null) !== []) {
        $economicChartHasData = true;
        break;
    }
}
?>
<?php if ($economicChartHasData): ?>
    <div class="chart-wrap chart-wrap-compact"><canvas id="<?= e($economicChartId) ?>" role="img" aria-label="<?= e($economicChart['label']) ?>"></canvas></div>
    <script type="application/json" data-economic-chart="<?= e($economicChartId) ?>"><?= json_encode($economicChart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<?php else: ?><p class="tiny-note">Nessuna rilevazione disponibile per il grafico.</p><?php endif; ?>
