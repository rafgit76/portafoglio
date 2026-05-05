<?php
$flash = get_flash();
$stylePath = __DIR__ . '/../assets/style.css';
$styleVersion = is_file($stylePath) ? (string) filemtime($stylePath) : '1';
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
        <div class="brand">Portfolio Investimenti</div>
        <nav class="menu">
            <a href="<?= e(app_base_path()) ?>/index.php">Dashboard</a>
            <a href="<?= e(app_base_path()) ?>/andamento.php">Andamento</a>
            <a href="<?= e(app_base_path()) ?>/upload.php">Upload</a>
            <a href="<?= e(app_base_path()) ?>/snapshots.php">Snapshot</a>
            <a href="<?= e(app_base_path()) ?>/compare.php">Confronto</a>
            <a href="<?= e(app_base_path()) ?>/assets.php">Strumenti</a>
            <a href="<?= e(app_base_path()) ?>/ai_report.php">Report AI</a>
        </nav>
    </div>
</header>
<main class="container">
    <?php if ($flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
