<?php
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Dettaglio snapshot';
$pdo = db();

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

$posSt = $pdo->prepare(
    'SELECT p.*, a.name, a.isin, a.ticker, a.asset_type
     FROM pf_snapshot_positions p
     JOIN pf_assets a ON a.id = p.asset_id
     WHERE p.snapshot_id = :id
     ORDER BY p.market_value DESC'
);
$posSt->execute([':id' => $id]);
$positions = $posSt->fetchAll();

include __DIR__ . '/partials/header.php';
?>
<section class="panel">
    <div class="panel-head">
        <h2>Snapshot <?= e($snapshot['snapshot_date']) ?></h2>
        <a class="btn" href="<?= e(app_base_path()) ?>/snapshots.php">Torna allo storico</a>
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
                <td><?= e($p['name']) ?></td>
                <td><?= e($p['asset_type']) ?></td>
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
