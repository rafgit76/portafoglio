<?php
declare(strict_types=1);

/** Uses the same unrounded values and tolerance as the historical table. */
function andamento_extremes(array $rows, array $columns): array
{
    $extremes = [];
    foreach ($columns as $column) {
        $assetId = (int) $column['id'];
        foreach (['market_value', 'unrealized_pnl'] as $metric) {
            $values = [];
            foreach ($rows as $row) {
                $value = $row['values'][$assetId][$metric] ?? null;
                if ($value !== null && is_numeric($value)) {
                    $quantity = $row['values'][$assetId]['quantity'] ?? null;
                    $values[] = [
                        'date' => (string) $row['snapshot_date'],
                        'value' => (float) $value,
                        'quantity' => $quantity !== null && is_numeric($quantity) ? (float) $quantity : null,
                    ];
                }
            }
            foreach (['min', 'max'] as $direction) {
                if ($values === []) {
                    $extremes[$assetId][$metric][$direction] = null;
                    continue;
                }
                $numbers = array_column($values, 'value');
                $extreme = $direction === 'min' ? min($numbers) : max($numbers);
                $dates = [];
                $quantities = [];
                foreach ($values as $entry) {
                    if (abs($entry['value'] - $extreme) < 0.00001) {
                        $dates[$entry['date']] = $entry['date'];
                        $quantities[$entry['date']] = $entry['quantity'];
                    }
                }
                $dates = array_values($dates);
                sort($dates, SORT_STRING);
                $extremes[$assetId][$metric][$direction] = ['value' => $extreme, 'dates' => $dates, 'quantities' => $quantities];
            }
        }
    }
    return $extremes;
}

/** Monetary distance uses the latest snapshot; elapsed days use today. */
function andamento_extreme_distance(?float $latest, ?array $extreme, DateTimeImmutable $today): array
{
    if ($extreme === null || ($extreme['dates'] ?? []) === []) {
        return ['delta' => null, 'percent' => null, 'days' => null, 'date' => null];
    }
    $date = (string) $extreme['dates'][array_key_last($extreme['dates'])];
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $today->getTimezone());
    $today = $today->setTime(0, 0);
    $days = $parsed && $parsed->format('Y-m-d') === $date && $parsed <= $today
        ? (int) $parsed->diff($today)->format('%a') : null;
    $value = (float) $extreme['value'];
    $delta = $latest === null ? null : $latest - $value;
    return [
        'delta' => $delta,
        'percent' => $delta !== null && abs($value) > 0.00001 ? $delta / abs($value) * 100 : null,
        'days' => $days,
        'date' => $date,
    ];
}
