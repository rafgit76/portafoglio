<?php
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Snapshot';
$pdo = db();
$pageSizeOptions = [25, 50, 100];
$pageSize = (int) ($_GET['per_page'] ?? 25);
if (!in_array($pageSize, $pageSizeOptions, true)) {
    $pageSize = 25;
}

$requestedPage = max(1, (int) ($_GET['page'] ?? 1));
$snapshotListUrl = static function (int $page, int $perPage): string {
    return app_base_path() . '/snapshots.php?' . http_build_query([
        'page' => max(1, $page),
        'per_page' => $perPage,
    ]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_snapshot') {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_valid_csrf_token(is_string($token) ? $token : null)) {
        set_flash('error', 'Richiesta non valida (token).');
        redirect('/snapshots.php');
    }

    $snapshotId = (int) ($_POST['snapshot_id'] ?? 0);
    if ($snapshotId <= 0) {
        set_flash('error', 'Snapshot non valido.');
        redirect('/snapshots.php');
    }

    $del = $pdo->prepare('DELETE FROM pf_snapshots WHERE id = :id LIMIT 1');
    $del->execute([':id' => $snapshotId]);

    if ($del->rowCount() > 0) {
        set_flash('success', 'Snapshot eliminato con successo.');
    } else {
        set_flash('error', 'Snapshot non trovato.');
    }

    $returnPage = max(1, (int) ($_POST['return_page'] ?? 1));
    $returnPerPage = (int) ($_POST['return_per_page'] ?? 25);
    if (!in_array($returnPerPage, $pageSizeOptions, true)) {
        $returnPerPage = 25;
    }
    redirect('/snapshots.php?' . http_build_query([
        'page' => $returnPage,
        'per_page' => $returnPerPage,
    ]));
}

$totalSnapshots = (int) $pdo->query('SELECT COUNT(*) FROM pf_snapshots')->fetchColumn();
$totalPages = max(1, (int) ceil($totalSnapshots / $pageSize));
$currentPage = min($requestedPage, $totalPages);
$offset = ($currentPage - 1) * $pageSize;

$extremes = $pdo->query(
    'SELECT MAX(total_unrealized_pnl) AS max_pnl, MIN(total_unrealized_pnl) AS min_pnl FROM pf_snapshots'
)->fetch();
$maxPnl = $extremes['max_pnl'] !== null ? (float) $extremes['max_pnl'] : null;
$minPnl = $extremes['min_pnl'] !== null ? (float) $extremes['min_pnl'] : null;

$rowsQuery = $pdo->prepare(
    'SELECT id, snapshot_date, source_file_name, total_cost_basis, total_market_value, total_unrealized_pnl, created_at
     FROM pf_snapshots
     ORDER BY snapshot_date DESC, id DESC
     LIMIT :limit OFFSET :offset'
);
$rowsQuery->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$rowsQuery->bindValue(':offset', $offset, PDO::PARAM_INT);
$rowsQuery->execute();
$rows = $rowsQuery->fetchAll();

$firstRow = $totalSnapshots === 0 ? 0 : $offset + 1;
$lastRow = min($totalSnapshots, $offset + count($rows));

include __DIR__ . '/partials/header.php';
?>
<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Storico snapshot</h2>
            <?php if ($totalSnapshots > 0): ?>
                <p class="tiny-note">Visualizzati <?= $firstRow ?>-<?= $lastRow ?> di <?= $totalSnapshots ?> snapshot.</p>
            <?php endif; ?>
        </div>
        <a class="btn" href="<?= e(app_base_path()) ?>/upload.php">Nuovo upload</a>
    </div>

    <?php if (!$rows): ?>
        <p>Nessuno snapshot disponibile.</p>
    <?php else: ?>
    <div class="snapshots-toolbar">
        <form method="get" class="snapshot-page-size">
            <input type="hidden" name="page" value="1">
            <label for="snapshotPageSize">Righe</label>
            <select id="snapshotPageSize" name="per_page" onchange="this.form.submit()">
                <?php foreach ($pageSizeOptions as $option): ?>
                    <option value="<?= $option ?>"<?= $pageSize === $option ? ' selected' : '' ?>><?= $option ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($totalPages > 1): ?>
            <nav class="snapshots-pagination" aria-label="Paginazione snapshot">
                <?php if ($currentPage > 1): ?>
                    <a class="btn-secondary btn-sm" href="<?= e($snapshotListUrl($currentPage - 1, $pageSize)) ?>">Indietro</a>
                <?php else: ?>
                    <span class="btn-secondary btn-sm is-disabled" aria-disabled="true">Indietro</span>
                <?php endif; ?>
                <span class="snapshots-page-count">Pagina <?= $currentPage ?>/<?= $totalPages ?></span>
                <?php if ($currentPage < $totalPages): ?>
                    <a class="btn-secondary btn-sm" href="<?= e($snapshotListUrl($currentPage + 1, $pageSize)) ?>">Avanti</a>
                <?php else: ?>
                    <span class="btn-secondary btn-sm is-disabled" aria-disabled="true">Avanti</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
    <div class="table-wrap snapshots-table-wrap">
    <table class="snapshots-table">
        <thead>
            <tr>
                <th>Data</th>
                <th>File</th>
                <th>Valore di carico</th>
                <th>Valore di mercato</th>
                <th>P/L</th>
                <th>Creato il</th>
                <th>Azioni</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <?php
            $pnl = (float) $r['total_unrealized_pnl'];
            $isMaxPnl = $maxPnl !== null && abs($pnl - $maxPnl) < 0.00001;
            $isMinPnl = $minPnl !== null && abs($pnl - $minPnl) < 0.00001;
            $rowClass = $isMaxPnl ? 'snapshot-row-max' : ($isMinPnl ? 'snapshot-row-min' : '');
            ?>
            <tr class="<?= e($rowClass) ?>">
                <td><?= e($r['snapshot_date']) ?></td>
                <td class="snapshot-file-cell"><?= e($r['source_file_name'] ?? '-') ?></td>
                <td>EUR <?= number_format((float) $r['total_cost_basis'], 2, ',', '.') ?></td>
                <td>EUR <?= number_format((float) $r['total_market_value'], 2, ',', '.') ?></td>
                <td class="<?= $pnl >= 0 ? 'pos' : 'neg' ?>">
                    EUR <?= number_format($pnl, 2, ',', '.') ?>
                    <?php if ($isMaxPnl): ?>
                        <span class="snapshot-extreme-badge snapshot-extreme-max">Massimo P/L</span>
                    <?php elseif ($isMinPnl): ?>
                        <span class="snapshot-extreme-badge snapshot-extreme-min">Minimo P/L</span>
                    <?php endif; ?>
                </td>
                <td><?= e($r['created_at']) ?></td>
                <td class="snapshot-actions-cell">
                    <div class="actions-inline snapshot-actions">
                        <a class="btn-link" href="<?= e(app_base_path()) ?>/snapshot.php?id=<?= (int) $r['id'] ?>">Dettaglio</a>
                        <form method="post" onsubmit="return confirm('Confermi eliminazione snapshot <?= e($r['snapshot_date']) ?>?');">
                            <input type="hidden" name="action" value="delete_snapshot">
                            <input type="hidden" name="snapshot_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="return_page" value="<?= $currentPage ?>">
                            <input type="hidden" name="return_per_page" value="<?= $pageSize ?>">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <button type="submit" class="btn-danger btn-sm">Elimina</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($totalPages > 1): ?>
        <nav class="snapshots-pagination snapshots-pagination-bottom" aria-label="Paginazione snapshot">
            <?php if ($currentPage > 1): ?>
                <a class="btn-secondary btn-sm" href="<?= e($snapshotListUrl($currentPage - 1, $pageSize)) ?>">Indietro</a>
            <?php else: ?>
                <span class="btn-secondary btn-sm is-disabled" aria-disabled="true">Indietro</span>
            <?php endif; ?>
            <span class="snapshots-page-count">Pagina <?= $currentPage ?>/<?= $totalPages ?></span>
            <?php if ($currentPage < $totalPages): ?>
                <a class="btn-secondary btn-sm" href="<?= e($snapshotListUrl($currentPage + 1, $pageSize)) ?>">Avanti</a>
            <?php else: ?>
                <span class="btn-secondary btn-sm is-disabled" aria-disabled="true">Avanti</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/partials/footer.php'; ?>
