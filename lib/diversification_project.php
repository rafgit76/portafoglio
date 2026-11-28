<?php

const DIVERSIFICATION_2027_TARGET = 100000;

function diversification_project_targets(): array
{
    return ['XDWH' => ['Salute', 30], 'XDWS' => ['Beni di consumo primari', 25], 'XDWU' => ['Utility', 20], 'XDWF' => ['Finanziari', 15], 'XDWI' => ['Industriali', 10]];
}

function diversification_project_model(array $positions): array
{
    $targets = diversification_project_targets();
    $groups = array_fill_keys(['NON TECH', 'TECH', 'GLOBAL EQUITY', 'OBBLIGAZIONARIO', 'MONETARIO', 'NON CLASSIFICATO'], ['market' => 0, 'cost' => 0, 'pnl' => 0, 'count' => 0, 'weight' => null]);
    $sectors = array_fill_keys(array_keys($targets), ['market' => 0, 'cost' => 0, 'pnl' => 0]);
    $identities = [];
    foreach ($positions as $position) {
        $ticker = explode('.', strtoupper(trim((string) ($position['ticker'] ?? ''))), 2)[0];
        $isin = strtoupper((string) ($position['isin'] ?? ''));
        $type = $position['asset_type'] ?? '';
        $group = match (true) {
            $type === 'ETF' && isset($targets[$ticker]) => 'NON TECH',
            in_array($isin, ['US5949181045', 'US67066G1040', 'LU0171310955', 'LU0115773425'], true) => 'TECH',
            $type === 'ETF' && (in_array($ticker, ['VWCE', 'VALL'], true) || $isin === 'IE00BK5BQT80') => 'GLOBAL EQUITY',
            $type === 'BTP' => 'OBBLIGAZIONARIO',
            $type === 'ETF' && ($ticker === 'XEON' || $isin === 'LU0290358497') => 'MONETARIO',
            default => 'NON CLASSIFICATO',
        };
        foreach (['market' => 'market_value', 'cost' => 'cost_basis', 'pnl' => 'unrealized_pnl'] as $key => $field) {
            $amount = economic_patrimony_cents($position[$field] ?? null) ?? 0;
            $groups[$group][$key] += $amount;
            if ($group === 'NON TECH') { $sectors[$ticker][$key] += $amount; }
        }
        $identities[$group][($position['asset_id'] ?? null) ?: ($isin ?: ($ticker ?: (string) count($identities[$group] ?? [])))] = true;
    }
    foreach ($sectors as $ticker => &$sector) {
        [$sector['label'], $sector['target_pct']] = $targets[$ticker];
        $sector['target'] = (int) (DIVERSIFICATION_2027_TARGET * $sector['target_pct'] / 100);
        $sector['weight'] = $groups['NON TECH']['market'] > 0 ? $sector['market'] / $groups['NON TECH']['market'] * 100 : null;
        $sector['gap_pct'] = $sector['weight'] !== null ? $sector['weight'] - $sector['target_pct'] : null;
        foreach (['market', 'cost', 'pnl'] as $key) { $sector[$key] = economic_patrimony_amount($sector[$key]); }
        $sector['remaining'] = max(0, $sector['target'] - $sector['cost']);
    }
    unset($sector);
    $totalCents = array_sum(array_column($groups, 'market'));
    foreach ($groups as $group => &$values) {
        $values['count'] = count($identities[$group] ?? []);
        $values['weight'] = $totalCents > 0 ? $values['market'] / $totalCents * 100 : null;
        foreach (['market', 'cost', 'pnl'] as $key) { $values[$key] = economic_patrimony_amount($values[$key]); }
    }
    unset($values);
    return ['groups' => $groups, 'sectors' => $sectors];
}

function diversification_project_history(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT s.snapshot_date, a.id AS asset_id, a.ticker, a.isin, a.asset_type, p.market_value, p.cost_basis, p.unrealized_pnl
         FROM pf_snapshots s JOIN pf_snapshot_positions p ON p.snapshot_id = s.id JOIN pf_assets a ON a.id = p.asset_id
         ORDER BY s.snapshot_date, s.id'
    );
    $byDate = [];
    foreach ($statement as $row) { $byDate[$row['snapshot_date']][] = $row; }
    return array_map('diversification_project_model', $byDate);
}
