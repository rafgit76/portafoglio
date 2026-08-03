<?php

function diversification_catalog(): array
{
    static $catalog;

    if ($catalog === null) {
        $catalog = require __DIR__ . '/../config/asset_classifications.php';
    }

    return $catalog;
}

function diversification_profile(array $position): ?array
{
    $catalog = diversification_catalog();
    $isin = strtoupper(trim((string) ($position['isin'] ?? '')));

    if ($isin === '') {
        return null;
    }

    $profile = $catalog['assets'][$isin] ?? null;

    return is_array($profile) ? $profile : null;
}

function diversification_normalized_allocations(array $allocations): array
{
    $valid = [];
    $total = 0.0;
    foreach ($allocations as $label => $weight) {
        $weight = (float) $weight;
        if ($weight <= 0) {
            continue;
        }

        $valid[(string) $label] = $weight;
        $total += $weight;
    }

    if ($total <= 0) {
        return [];
    }

    foreach ($valid as $label => $weight) {
        $valid[$label] = $weight / $total;
    }

    return $valid;
}

function diversification_distribution(array $positions, string $dimension): array
{
    $totals = [];
    $portfolioValue = 0.0;
    $coveredValue = 0.0;

    foreach ($positions as $position) {
        $marketValue = max(0.0, (float) ($position['market_value'] ?? 0));
        $portfolioValue += $marketValue;
        $profile = diversification_profile($position);
        $allocations = is_array($profile['allocations'][$dimension] ?? null)
            ? diversification_normalized_allocations($profile['allocations'][$dimension])
            : [];

        if ($allocations === []) {
            $totals['Non classificato'] = ($totals['Non classificato'] ?? 0.0) + $marketValue;
            continue;
        }

        $coveredValue += $marketValue;
        foreach ($allocations as $label => $weight) {
            $totals[$label] = ($totals[$label] ?? 0.0) + ($marketValue * $weight);
        }
    }

    arsort($totals, SORT_NUMERIC);
    $rows = [];
    foreach ($totals as $label => $value) {
        $rows[] = [
            'label' => $label,
            'market_value' => $value,
            'weight_pct' => $portfolioValue > 0 ? ($value / $portfolioValue) * 100 : 0.0,
        ];
    }

    return [
        'rows' => $rows,
        'portfolio_value' => $portfolioValue,
        'covered_value' => $coveredValue,
        'coverage_pct' => $portfolioValue > 0 ? ($coveredValue / $portfolioValue) * 100 : 0.0,
    ];
}

function diversification_currency_distribution(array $positions): array
{
    $totals = [];
    $portfolioValue = 0.0;
    foreach ($positions as $position) {
        $marketValue = max(0.0, (float) ($position['market_value'] ?? 0));
        $currency = strtoupper(trim((string) ($position['position_currency'] ?? 'EUR')));
        $currency = $currency !== '' ? $currency : 'N/D';
        $totals[$currency] = ($totals[$currency] ?? 0.0) + $marketValue;
        $portfolioValue += $marketValue;
    }

    arsort($totals, SORT_NUMERIC);
    $rows = [];
    foreach ($totals as $label => $value) {
        $rows[] = [
            'label' => $label,
            'market_value' => $value,
            'weight_pct' => $portfolioValue > 0 ? ($value / $portfolioValue) * 100 : 0.0,
        ];
    }

    return ['rows' => $rows, 'portfolio_value' => $portfolioValue];
}

function diversification_concentration(array $positions, float $portfolioValue): array
{
    usort($positions, static fn (array $left, array $right): int => (float) $right['market_value'] <=> (float) $left['market_value']);
    $result = [];
    foreach ([1, 3, 5] as $count) {
        $value = array_sum(array_map(static fn (array $position): float => max(0.0, (float) $position['market_value']), array_slice($positions, 0, $count)));
        $result[$count] = [
            'value' => $value,
            'weight_pct' => $portfolioValue > 0 ? ($value / $portfolioValue) * 100 : 0.0,
        ];
    }

    return $result;
}

function diversification_source_rows(array $positions): array
{
    $rows = [];
    foreach ($positions as $position) {
        $profile = diversification_profile($position);
        $rows[] = [
            'asset_name' => (string) ($position['name'] ?? 'Strumento'),
            'isin' => (string) ($position['isin'] ?? ''),
            'market_value' => (float) ($position['market_value'] ?? 0),
            'profile' => $profile,
        ];
    }

    usort($rows, static fn (array $left, array $right): int => $right['market_value'] <=> $left['market_value']);

    return $rows;
}
