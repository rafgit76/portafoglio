<?php
$appName = (string) app_setting('public_app_name', 'Portfolio Investimenti');
$targetPhpVersion = (string) app_setting('target_php_version', '8.4');
$showRuntimeSummary = (bool) app_setting('show_runtime_summary', false);
$showRuntimeDetails = (bool) app_setting('show_runtime_details', false);
$dbConfig = app_config()['db'] ?? [];
$dbName = (string) ($dbConfig['database'] ?? 'N/D');

$dbVersion = 'N/D';
$opcacheEnabled = false;
$runtimeDetails = [];

if ($showRuntimeSummary || $showRuntimeDetails) {
    try {
        $pdo = db();
        $dbVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    } catch (Throwable $e) {
        // Il footer deve restare visibile anche se il DB non e' raggiungibile.
    }

    if (function_exists('opcache_get_status')) {
        $opcacheStatus = @opcache_get_status(false);
        $opcacheEnabled = is_array($opcacheStatus) && !empty($opcacheStatus['opcache_enabled']);
    } else {
        $opcacheEnabled = (bool) filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL);
    }

    $runtimeDetails = [
        'Versione PHP' => PHP_VERSION,
        'SAPI' => PHP_SAPI,
        'Timezone runtime' => date_default_timezone_get(),
        'Database' => $dbName,
        'Versione DB' => $dbVersion,
        'Memory limit' => (string) (ini_get('memory_limit') ?: 'N/D'),
        'Max execution time' => (string) (ini_get('max_execution_time') ?: 'N/D') . 's',
    ];
}
?>
    <footer class="runtime-footer">
        <div class="runtime-summary-bar">
            <strong><?= e($appName) ?></strong>
            <span class="runtime-chip">Target PHP <?= e($targetPhpVersion) ?></span>
            <span class="runtime-chip">DB <?= e($dbName !== '' ? $dbName : 'N/D') ?></span>
            <?php if ($showRuntimeSummary): ?>
                <span class="runtime-chip">Runtime PHP <?= e(PHP_VERSION) ?></span>
                <span class="runtime-chip">MySQL <?= e($dbVersion) ?></span>
                <span class="runtime-chip"><?= e(PHP_SAPI) ?></span>
                <span class="runtime-chip"><?= $opcacheEnabled ? 'OPcache ON' : 'OPcache OFF' ?></span>
            <?php endif; ?>
        </div>

        <?php if ($showRuntimeDetails): ?>
            <details class="runtime-details">
                <summary>Dettagli ambiente runtime</summary>
                <div class="runtime-details-grid">
                    <?php foreach ($runtimeDetails as $label => $value): ?>
                        <div class="runtime-detail-card">
                            <div class="runtime-detail-label"><?= e($label) ?></div>
                            <div class="runtime-detail-value"><?= e((string) $value) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    </footer>
</main>
</body>
</html>
