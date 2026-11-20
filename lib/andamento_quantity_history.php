<?php
declare(strict_types=1);

/** Compare successive observed quantities without turning missing positions into zero. */
function andamento_quantity_history(array $rows, array $columns): array
{
    $previous = [];
    $changes = [];
    $known = [];
    foreach ($columns as $column) {
        $known[(int) $column['id']] = true;
    }
    foreach (array_values($rows) as $index => $row) {
        foreach (($row['values'] ?? []) as $assetId => $values) {
            $assetId = (int) $assetId;
            if (!isset($known[$assetId]) || !isset($values['quantity']) || !is_numeric($values['quantity'])) {
                continue;
            }
            $quantity = (float) $values['quantity'];
            if (isset($previous[$assetId])) {
                $prior = $previous[$assetId];
                $delta = $quantity - $prior['quantity'];
                if (abs($delta) >= 0.0000005) {
                    $changes[] = [
                        'asset_id' => $assetId,
                        'date' => (string) $row['snapshot_date'],
                        'previous_date' => $prior['date'],
                        'previous_quantity' => $prior['quantity'],
                        'quantity' => $quantity,
                        'delta' => $delta,
                        'missing_snapshots' => $index - $prior['index'] - 1,
                    ];
                }
            }
            $previous[$assetId] = ['date' => (string) $row['snapshot_date'], 'quantity' => $quantity, 'index' => $index];
        }
    }
    usort($changes, static fn(array $a, array $b): int => strcmp($b['date'], $a['date']) ?: ($a['asset_id'] <=> $b['asset_id']));
    return $changes;
}
