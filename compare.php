<?php
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Confronto snapshot';
$pdo = db();

$snapshots = $pdo->query('SELECT id, snapshot_date, total_market_value, total_unrealized_pnl FROM pf_snapshots ORDER BY snapshot_date DESC, id DESC')->fetchAll();

$selectedA = 0;
$selectedB = 0;

if (count($snapshots) > 0) {
    $selectedA = (int) ($snapshots[0]['id'] ?? 0);
}
if (count($snapshots) > 1) {
    $selectedB = (int) ($snapshots[1]['id'] ?? 0);
}

if (isset($_GET['snapshot_a'])) {
    $selectedA = (int) $_GET['snapshot_a'];
}
if (isset($_GET['snapshot_b'])) {
    $selectedB = (int) $_GET['snapshot_b'];
}

$onlyChanges = isset($_GET['only_changes']) && $_GET['only_changes'] === '1';
$exportCsv = isset($_GET['export']) && $_GET['export'] === '1';

$metaA = null;
$metaB = null;
$rows = [];
$summary = [
    'delta_market_value' => 0.0,
    'delta_pnl' => 0.0,
    'delta_cost_basis' => 0.0,
    'positions_delta' => 0,
];
$error = null;

if ($selectedA > 0 && $selectedB > 0) {
    if ($selectedA === $selectedB) {
        $error = 'Seleziona due snapshot diversi.';
    } else {
        $stmtMeta = $pdo->prepare('SELECT id, snapshot_date, total_market_value, total_cost_basis, total_unrealized_pnl FROM pf_snapshots WHERE id = :id LIMIT 1');

        $stmtMeta->execute([':id' => $selectedA]);
        $metaA = $stmtMeta->fetch();

        $stmtMeta->execute([':id' => $selectedB]);
        $metaB = $stmtMeta->fetch();

        if (!$metaA || !$metaB) {
            $error = 'Uno degli snapshot selezionati non esiste.';
        } else {
            $summary['delta_market_value'] = (float) $metaA['total_market_value'] - (float) $metaB['total_market_value'];
            $summary['delta_pnl'] = (float) $metaA['total_unrealized_pnl'] - (float) $metaB['total_unrealized_pnl'];
            $summary['delta_cost_basis'] = (float) $metaA['total_cost_basis'] - (float) $metaB['total_cost_basis'];

            $sql = "
                SELECT
                    a.id AS asset_id,
                    a.name,
                    a.asset_type,
                    a.isin,
                    a.ticker,
                    COALESCE(p1.quantity, 0) AS qty_a,
                    COALESCE(p2.quantity, 0) AS qty_b,
                    COALESCE(p1.market_value, 0) AS mv_a,
                    COALESCE(p2.market_value, 0) AS mv_b,
                    COALESCE(p1.unrealized_pnl, 0) AS pnl_a,
                    COALESCE(p2.unrealized_pnl, 0) AS pnl_b,
                    COALESCE(p1.weight_pct, 0) AS w_a,
                    COALESCE(p2.weight_pct, 0) AS w_b,
                    (COALESCE(p1.quantity, 0) - COALESCE(p2.quantity, 0)) AS delta_qty,
                    (COALESCE(p1.market_value, 0) - COALESCE(p2.market_value, 0)) AS delta_mv,
                    (COALESCE(p1.unrealized_pnl, 0) - COALESCE(p2.unrealized_pnl, 0)) AS delta_pnl,
                    (COALESCE(p1.weight_pct, 0) - COALESCE(p2.weight_pct, 0)) AS delta_w
                FROM (
                    SELECT DISTINCT asset_id
                    FROM pf_snapshot_positions
                    WHERE snapshot_id IN (:snapshot_a_ids, :snapshot_b_ids)
                ) s
                JOIN pf_assets a ON a.id = s.asset_id
                LEFT JOIN (SELECT asset_id, SUM(quantity) AS quantity, SUM(market_value) AS market_value, SUM(unrealized_pnl) AS unrealized_pnl, SUM(market_value) / NULLIF((SELECT total_market_value FROM pf_snapshots WHERE id = :snapshot_a_total), 0) AS weight_pct FROM pf_snapshot_positions WHERE snapshot_id = :snapshot_a_pos GROUP BY asset_id) p1 ON p1.asset_id = s.asset_id
                LEFT JOIN (SELECT asset_id, SUM(quantity) AS quantity, SUM(market_value) AS market_value, SUM(unrealized_pnl) AS unrealized_pnl, SUM(market_value) / NULLIF((SELECT total_market_value FROM pf_snapshots WHERE id = :snapshot_b_total), 0) AS weight_pct FROM pf_snapshot_positions WHERE snapshot_id = :snapshot_b_pos GROUP BY asset_id) p2 ON p2.asset_id = s.asset_id
                ORDER BY ABS(COALESCE(p1.market_value, 0) - COALESCE(p2.market_value, 0)) DESC, a.name ASC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':snapshot_a_ids' => $selectedA,
                ':snapshot_b_ids' => $selectedB,
                ':snapshot_a_pos' => $selectedA,
                ':snapshot_b_pos' => $selectedB,
                ':snapshot_a_total' => $selectedA,
                ':snapshot_b_total' => $selectedB,
            ]);
            $rows = $stmt->fetchAll();

            if ($onlyChanges) {
                $rows = array_values(array_filter($rows, static function (array $r): bool {
                    return abs((float) $r['delta_qty']) > 0.000001
                        || abs((float) $r['delta_mv']) > 0.005;
                }));
            }

            $summary['positions_delta'] = count($rows);
        }
    }
}

if ($exportCsv && $metaA && $metaB && !$error) {
    $fileName = sprintf(
        'confronto_snapshot_%s_vs_%s.csv',
        str_replace('-', '', $metaA['snapshot_date']),
        str_replace('-', '', $metaB['snapshot_date'])
    );

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=' . $fileName);

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'snapshot_a',
        'snapshot_b',
        'titolo',
        'tipo',
        'isin',
        'ticker',
        'qta_a',
        'qta_b',
        'delta_qta',
        'valore_a',
        'valore_b',
        'delta_valore',
        'pl_a',
        'pl_b',
        'delta_pl',
        'peso_a_pct',
        'peso_b_pct',
        'delta_peso_pct',
        'stato',
    ], ';');

    foreach ($rows as $r) {
        $status = 'Invariata';
        if ((float) $r['qty_b'] == 0.0 && (float) $r['qty_a'] > 0.0) {
            $status = 'Nuova';
        } elseif ((float) $r['qty_b'] > 0.0 && (float) $r['qty_a'] == 0.0) {
            $status = 'Chiusa';
        }

        fputcsv($out, [
            $metaA['snapshot_date'],
            $metaB['snapshot_date'],
            $r['name'],
            $r['asset_type'],
            $r['isin'] ?? '',
            $r['ticker'] ?? '',
            number_format((float) $r['qty_a'], 6, '.', ''),
            number_format((float) $r['qty_b'], 6, '.', ''),
            number_format((float) $r['delta_qty'], 6, '.', ''),
            number_format((float) $r['mv_a'], 2, '.', ''),
            number_format((float) $r['mv_b'], 2, '.', ''),
            number_format((float) $r['delta_mv'], 2, '.', ''),
            number_format((float) $r['pnl_a'], 2, '.', ''),
            number_format((float) $r['pnl_b'], 2, '.', ''),
            number_format((float) $r['delta_pnl'], 2, '.', ''),
            number_format(((float) $r['w_a']) * 100, 4, '.', ''),
            number_format(((float) $r['w_b']) * 100, 4, '.', ''),
            number_format(((float) $r['delta_w']) * 100, 4, '.', ''),
            $status,
        ], ';');
    }

    fclose($out);
    exit;
}

include __DIR__ . '/partials/header.php';
?>
<section class="panel">
    <h2>Confronto snapshot</h2>
    <?php if (count($snapshots) < 2): ?>
        <p>Servono almeno 2 snapshot per fare il confronto.</p>
    <?php else: ?>
        <form method="get" class="form-grid compare-grid">
            <label>
                Snapshot A (piu' recente)
                <select name="snapshot_a" required>
                    <?php foreach ($snapshots as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $selectedA ? 'selected' : '' ?>>
                            <?= e($s['snapshot_date']) ?> - Valore EUR <?= number_format((float) $s['total_market_value'], 2, ',', '.') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Snapshot B (confronto)
                <select name="snapshot_b" required>
                    <?php foreach ($snapshots as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $selectedB ? 'selected' : '' ?>>
                            <?= e($s['snapshot_date']) ?> - Valore EUR <?= number_format((float) $s['total_market_value'], 2, ',', '.') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="compare-actions">
                <button type="submit">Confronta</button>
                <button type="submit" name="export" value="1" class="btn-secondary">Esporta CSV</button>
            </div>

            <label class="checkbox-inline">
                <input type="checkbox" name="only_changes" value="1" <?= $onlyChanges ? 'checked' : '' ?>>
                Solo variazioni (Delta Q.ta o Delta Valore)
            </label>
        </form>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="flash flash-error" style="margin-top: 0.8rem;"><?= e($error) ?></div>
    <?php endif; ?>
</section>

<?php if ($metaA && $metaB && !$error): ?>
<section class="cards">
    <article class="card">
        <h3>Delta Valore Mercato (A-B)</h3>
        <p class="big <?= $summary['delta_market_value'] >= 0 ? 'pos' : 'neg' ?>">EUR <?= number_format($summary['delta_market_value'], 2, ',', '.') ?></p>
    </article>
    <article class="card">
        <h3>Delta P/L (A-B)</h3>
        <p class="big <?= $summary['delta_pnl'] >= 0 ? 'pos' : 'neg' ?>">EUR <?= number_format($summary['delta_pnl'], 2, ',', '.') ?></p>
    </article>
    <article class="card">
        <h3>Delta Valore Carico (A-B)</h3>
        <p class="big <?= $summary['delta_cost_basis'] >= 0 ? 'pos' : 'neg' ?>">EUR <?= number_format($summary['delta_cost_basis'], 2, ',', '.') ?></p>
    </article>
    <article class="card">
        <h3>Righe visualizzate</h3>
        <p class="big"><?= (int) $summary['positions_delta'] ?></p>
    </article>
</section>

<section class="panel">
    <h3>Dettaglio per titolo (A: <?= e($metaA['snapshot_date']) ?> vs B: <?= e($metaB['snapshot_date']) ?>)</h3>
    <?php if ($onlyChanges): ?>
        <p style="margin-top: 0; color: #6b7280;">Filtro attivo: vengono mostrate solo le righe con Delta Quantita o Delta Valore diversi da zero.</p>
    <?php endif; ?>
    <table>
        <thead>
            <tr>
                <th>Titolo</th>
                <th>Tipo</th>
                <th>ISIN</th>
                <th>Q.ta A</th>
                <th>Q.ta B</th>
                <th>Delta Q.ta</th>
                <th>Valore A</th>
                <th>Valore B</th>
                <th>Delta Valore</th>
                <th>P/L A</th>
                <th>P/L B</th>
                <th>Delta P/L</th>
                <th>Peso A</th>
                <th>Peso B</th>
                <th>Delta Peso</th>
                <th>Stato</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <?php
                $status = 'Invariata';
                if ((float) $r['qty_b'] == 0.0 && (float) $r['qty_a'] > 0.0) {
                    $status = 'Nuova';
                } elseif ((float) $r['qty_b'] > 0.0 && (float) $r['qty_a'] == 0.0) {
                    $status = 'Chiusa';
                }
                ?>
                <tr>
                    <td><?= e($r['name']) ?></td>
                    <td><?= e($r['asset_type']) ?></td>
                    <td><?= e($r['isin'] ?? '-') ?></td>
                    <td><?= number_format((float) $r['qty_a'], 6, ',', '.') ?></td>
                    <td><?= number_format((float) $r['qty_b'], 6, ',', '.') ?></td>
                    <td class="<?= (float) $r['delta_qty'] >= 0 ? 'pos' : 'neg' ?>"><?= number_format((float) $r['delta_qty'], 6, ',', '.') ?></td>
                    <td>EUR <?= number_format((float) $r['mv_a'], 2, ',', '.') ?></td>
                    <td>EUR <?= number_format((float) $r['mv_b'], 2, ',', '.') ?></td>
                    <td class="<?= (float) $r['delta_mv'] >= 0 ? 'pos' : 'neg' ?>">EUR <?= number_format((float) $r['delta_mv'], 2, ',', '.') ?></td>
                    <td>EUR <?= number_format((float) $r['pnl_a'], 2, ',', '.') ?></td>
                    <td>EUR <?= number_format((float) $r['pnl_b'], 2, ',', '.') ?></td>
                    <td class="<?= (float) $r['delta_pnl'] >= 0 ? 'pos' : 'neg' ?>">EUR <?= number_format((float) $r['delta_pnl'], 2, ',', '.') ?></td>
                    <td><?= number_format(((float) $r['w_a']) * 100, 2, ',', '.') ?>%</td>
                    <td><?= number_format(((float) $r['w_b']) * 100, 2, ',', '.') ?>%</td>
                    <td class="<?= (float) $r['delta_w'] >= 0 ? 'pos' : 'neg' ?>"><?= number_format(((float) $r['delta_w']) * 100, 2, ',', '.') ?>%</td>
                    <td><?= e($status) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
