<?php
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Snapshot';
$pdo = db();

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

    redirect('/snapshots.php');
}

$rows = $pdo->query('SELECT id, snapshot_date, source_file_name, total_cost_basis, total_market_value, total_unrealized_pnl, created_at FROM pf_snapshots ORDER BY snapshot_date DESC, id DESC')->fetchAll();

$maxPnl = null;
$minPnl = null;
foreach ($rows as $row) {
    $pnl = (float) $row['total_unrealized_pnl'];
    if ($maxPnl === null || $pnl > $maxPnl) {
        $maxPnl = $pnl;
    }
    if ($minPnl === null || $pnl < $minPnl) {
        $minPnl = $pnl;
    }
}

include __DIR__ . '/partials/header.php';
?>
<section class="panel">
    <div class="panel-head">
        <h2>Storico snapshot</h2>
        <a class="btn" href="<?= e(app_base_path()) ?>/upload.php">Nuovo upload</a>
    </div>

    <?php if (!$rows): ?>
        <p>Nessuno snapshot disponibile.</p>
    <?php else: ?>
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
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <button type="submit" class="btn-danger btn-sm">Elimina</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/partials/footer.php'; ?>
