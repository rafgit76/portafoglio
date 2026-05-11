<?php
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Changelog';
$appVersion = (string) app_setting('app_version', '1.0.0');
$changelogPath = __DIR__ . '/config/changelog.php';
$releases = is_file($changelogPath) ? require $changelogPath : [];
$releases = is_array($releases) ? $releases : [];

include __DIR__ . '/partials/header.php';
?>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Changelog</h2>
            <p class="tiny-note">Storico sintetico delle versioni rilasciate dell'app.</p>
        </div>
        <span class="runtime-chip">Versione corrente <?= e($appVersion) ?></span>
    </div>
</section>

<section class="panel changelog-panel">
    <?php if (!$releases): ?>
        <p>Nessuna release registrata nel changelog.</p>
    <?php else: ?>
        <div class="changelog-list">
            <?php foreach ($releases as $release): ?>
                <?php
                $version = (string) ($release['version'] ?? 'N/D');
                $date = (string) ($release['date'] ?? '');
                $title = (string) ($release['title'] ?? 'Release');
                $changes = $release['changes'] ?? [];
                $changes = is_array($changes) ? $changes : [];
                ?>
                <article class="changelog-release<?= $version === $appVersion ? ' changelog-release-current' : '' ?>">
                    <div class="changelog-release-head">
                        <div>
                            <h3><?= e($title) ?></h3>
                            <div class="muted-small">Versione <?= e($version) ?><?= $date !== '' ? ' - ' . e($date) : '' ?></div>
                        </div>
                        <?php if ($version === $appVersion): ?>
                            <span class="runtime-chip">Corrente</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($changes): ?>
                        <ul class="changelog-changes">
                            <?php foreach ($changes as $change): ?>
                                <li><?= e((string) $change) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>
