<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/account_tracker.php';

$pageTitle = 'Dettaglio snapshot';
$pdo = db();
$accountTrackerReady = account_tracker_schema_ready($pdo);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    set_flash('error', 'Snapshot non valido.');
    redirect('/snapshots.php');
}

$st = $pdo->prepare('SELECT * FROM pf_snapshots WHERE id = :id LIMIT 1');
$st->execute([':id' => $id]);
$snapshot = $st->fetch();

if (!$snapshot) {
    set_flash('error', 'Snapshot non trovato.');
    redirect('/snapshots.php');
}

$positionSql = 'SELECT p.*, a.id AS asset_id, a.name, a.isin, a.ticker, a.asset_type';
if ($accountTrackerReady) {
    $positionSql .= ', d.name AS dossier_name, d.code AS dossier_origin, p.data_origin';
}
$positionSql .= ' FROM pf_snapshot_positions p JOIN pf_assets a ON a.id = p.asset_id';
if ($accountTrackerReady) {
    $positionSql .= ' LEFT JOIN pf_dossiers d ON d.id = p.dossier_id';
}
$positionSql .= ' WHERE p.snapshot_id = :id ORDER BY p.market_value DESC';
$posSt = $pdo->prepare($positionSql);
$posSt->execute([':id' => $id]);
$positions = $posSt->fetchAll();

include __DIR__ . '/partials/header.php';
?>
<section class="panel">
    <div class="panel-head">
        <h2>Snapshot <?= e($snapshot['snapshot_date']) ?></h2>
        <div class="actions-inline">
            <a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/ai_report.php#ai-changes">Cosa e cambiato?</a>
            <a class="btn" href="<?= e(app_base_path()) ?>/snapshots.php">Torna allo storico</a>
        </div>
    </div>

    <div class="cards">
        <article class="card"><h3>Valore di carico</h3><p class="big">EUR <?= number_format((float) $snapshot['total_cost_basis'], 2, ',', '.') ?></p></article>
        <article class="card"><h3>Valore di mercato</h3><p class="big">EUR <?= number_format((float) $snapshot['total_market_value'], 2, ',', '.') ?></p></article>
        <article class="card"><h3>P/L</h3><p class="big <?= (float) $snapshot['total_unrealized_pnl'] >= 0 ? 'pos' : 'neg' ?>">EUR <?= number_format((float) $snapshot['total_unrealized_pnl'], 2, ',', '.') ?></p></article>
        <article class="card"><h3>Posizioni</h3><p class="big"><?= count($positions) ?></p></article>
    </div>
</section>

<section class="panel">
    <h3>Posizioni</h3>
    <table>
        <thead>
            <tr>
                <th>Titolo</th>
                <th>Tipo</th>
                <?php if ($accountTrackerReady): ?><th>Dossier</th><?php endif; ?>
                <th>ISIN</th>
                <th>Q.ta</th>
                <th>PMC</th>
                <th>Prezzo attuale</th>
                <th>Valore carico</th>
                <th>Valore mercato</th>
                <th>P/L</th>
                <th>Peso</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($positions as $p): ?>
            <tr>
                <td><a class="btn-link" href="<?= e(app_base_path()) ?>/asset.php?id=<?= (int) $p['asset_id'] ?>"><?= e($p['name']) ?></a></td>
                <td><?= e($p['asset_type']) ?></td>
                <?php if ($accountTrackerReady): ?><td><?= e((string) ($p['dossier_name'] ?? 'Ordinario')) ?><br><span class="tiny-note"><?= e((string) ($p['data_origin'] ?? 'IMPORT_FINECO')) ?></span></td><?php endif; ?>
                <td><?= e($p['isin'] ?? '-') ?></td>
                <td><?= number_format((float) $p['quantity'], 2, ',', '.') ?></td>
                <td><?= number_format((float) $p['avg_load_price'], 4, ',', '.') ?></td>
                <td><?= number_format((float) $p['current_price'], 4, ',', '.') ?></td>
                <td>EUR <?= number_format((float) $p['cost_basis'], 2, ',', '.') ?></td>
                <td>EUR <?= number_format((float) $p['market_value'], 2, ',', '.') ?></td>
                <td class="<?= (float) $p['unrealized_pnl'] >= 0 ? 'pos' : 'neg' ?>">EUR <?= number_format((float) $p['unrealized_pnl'], 2, ',', '.') ?></td>
                <td><?= number_format(((float) $p['weight_pct']) * 100, 2, ',', '.') ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php include __DIR__ . '/partials/footer.php'; ?>
