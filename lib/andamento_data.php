<?php
declare(strict_types=1);

/** Andamento keeps its existing asset filter; quantity history includes every type. */
function andamento_load_data(PDO $pdo, bool $allAssetTypes): array
{
    $assetFilter = $allAssetTypes ? '' : 'WHERE a.asset_type IN ("AZIONE", "ETF", "PAC")';
    $historyFilter = $allAssetTypes ? '' : 'WHERE p.asset_id IS NULL OR a.asset_type IN ("AZIONE", "ETF", "PAC")';
    $columns = $pdo->query(
        'SELECT DISTINCT a.id, a.asset_type, a.name, a.ticker, a.isin
         FROM pf_assets a
         JOIN pf_snapshot_positions p ON p.asset_id = a.id
         JOIN pf_snapshots s ON s.id = p.snapshot_id
         ' . $assetFilter . '
         ORDER BY CASE a.asset_type WHEN "AZIONE" THEN 1 WHEN "ETF" THEN 2 WHEN "PAC" THEN 3 ELSE 99 END,
             a.name ASC, a.ticker ASC, a.id ASC'
    )->fetchAll();
    $history = $pdo->query(
        'SELECT s.snapshot_date, p.asset_id, SUM(p.quantity) AS quantity,
                SUM(p.market_value) AS market_value, SUM(p.unrealized_pnl) AS unrealized_pnl
         FROM pf_snapshots s
         LEFT JOIN pf_snapshot_positions p ON p.snapshot_id = s.id
         LEFT JOIN pf_assets a ON a.id = p.asset_id
         ' . $historyFilter . '
         GROUP BY s.id, s.snapshot_date, p.asset_id
         ORDER BY s.snapshot_date ASC, p.asset_id ASC'
    )->fetchAll();
    $rows = [];
    foreach ($history as $entry) {
        $date = (string) $entry['snapshot_date'];
        $rows[$date] ??= ['snapshot_date' => $date, 'values' => []];
        if ($entry['asset_id'] !== null) {
            $rows[$date]['values'][(int) $entry['asset_id']] = [
                'quantity' => (float) $entry['quantity'],
                'market_value' => (float) $entry['market_value'],
                'unrealized_pnl' => (float) $entry['unrealized_pnl'],
            ];
        }
    }
    return ['columns' => $columns, 'rows' => array_values($rows)];
}
