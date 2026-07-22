<?php
$flash = get_flash();
$stylePath = __DIR__ . '/../assets/style.css';
$styleVersion = is_file($stylePath) ? (string) filemtime($stylePath) : '1';
$appName = (string) app_setting('public_app_name', 'Portfolio Investimenti');
$privacyConfigured = privacy_is_configured();
$privacyUnlocked = $privacyConfigured && privacy_is_unlocked();
$privacyUnlockSeconds = privacy_config()['unlock_seconds'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Portfolio') ?></title>
    <link rel="stylesheet" href="<?= e(app_base_path()) ?>/assets/style.css?v=<?= e($styleVersion) ?>">
</head>
<body>
<header class="topbar">
    <div class="container topbar-inner">
        <a class="brand" href="<?= e(app_base_path()) ?>/index.php"><?= e($appName) ?></a>
        <nav class="menu">
            <a href="<?= e(app_base_path()) ?>/dashboard.php">Dashboard</a>
            <a href="<?= e(app_base_path()) ?>/andamento.php">Andamento</a>
            <a href="<?= e(app_base_path()) ?>/upload.php">Upload</a>
            <a href="<?= e(app_base_path()) ?>/snapshots.php">Snapshot</a>
            <a href="<?= e(app_base_path()) ?>/compare.php">Confronto</a>
            <a href="<?= e(app_base_path()) ?>/assets.php">Strumenti</a>
            <a href="<?= e(app_base_path()) ?>/ai_report.php">Report AI</a>
            <a href="<?= e(app_base_path()) ?>/changelog.php">Changelog</a>
            <?php if ($privacyUnlocked): ?>
                <form method="post" action="<?= e(app_base_path()) ?>/index.php" class="privacy-nav-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="privacy_lock">
                    <button type="submit" class="privacy-nav-control">Blocca</button>
                </form>
            <?php elseif ($privacyConfigured): ?>
                <a class="privacy-nav-control" href="<?= e(app_base_path()) ?>/index.php?unlock=1#privacy-unlock">Sblocca</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<?php if ($privacyUnlocked): ?>
    <script>
        (() => {
            const timeoutMs = <?= (int) $privacyUnlockSeconds ?> * 1000;
            const homeUrl = <?= json_encode(app_base_path() . '/index.php', JSON_UNESCAPED_SLASHES) ?>;
            const csrfToken = <?= json_encode(csrf_token()) ?>;

            window.setTimeout(() => {
                const body = new URLSearchParams({
                    csrf_token: csrfToken,
                    action: 'privacy_lock'
                });

                fetch(homeUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                    body: body.toString()
                }).finally(() => window.location.replace(homeUrl));
            }, timeoutMs);
        })();
    </script>
<?php endif; ?>
<script>
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            window.location.reload();
        }
    });
</script>
<main class="container">
    <?php if ($flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
