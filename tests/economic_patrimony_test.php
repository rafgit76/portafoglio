<?php

require_once __DIR__ . '/../lib/account_history_export.php';
require_once __DIR__ . '/../lib/ai_report.php';
require_once __DIR__ . '/../lib/diversification_project.php';

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('PNE: ' . $label); }
    $checks++;
};
// Synthetic amounts: exercise the same accounting identities without customer data.
$base = ['portfolio' => 155042.10, 'credit_agreed_amount' => 9500, 'accounting_balance' => -410.25, 'multicurrency_amount' => -8.75];
$caseA = $base + ['fineco_total_assets' => 164086.60, 'liquidity_total' => 9044.50, 'credit_available_amount' => 9053.25, 'credit_used_amount' => 446.75, 'reserved_liquidity' => -36.50];
$caseB = $base + ['fineco_total_assets' => 164070.35, 'liquidity_total' => 9028.25, 'available_balance' => 9037.00, 'reserved_liquidity' => -52.75, 'credit_lombard_account' => 0];
$a = economic_patrimony_calculate($caseA);
$b = economic_patrimony_calculate($caseB);
$assert($a['pne'] === 154586.60 && $a['net_liquidity'] === -455.50 && $a['status'] === 'RICOSTRUITO', 'caso A senza campo saldo EUR esplicito');
$assert($b['pne'] === 154570.35 && $b['net_liquidity'] === -471.75 && $b['status'] === 'VERIFICATO', 'caso B');
$assert($b['credit_used'] === 463.0 && $b['credit_used_derived'] && $b['credit_available'] === null, 'uso implicito distinto da fido disponibile osservato');
$assert(round($b['pne'] - $a['pne'], 2) === -16.25 && $a['accounting_balance'] === $b['accounting_balance'], 'variazione di disponibilita non ancora contabilizzata');
$assert($a['pne_reconstructed'] === $a['pne'] && $b['pne_reconstructed'] === $b['pne'], 'chiusura analitica dei due casi');
$legacy = account_tracker_patrimonial_adjustments($caseA);
$assert(round($legacy['net_assets_adjusted'], 2) === 155033.35, 'indicatore legacy invariato');

$anomaly = economic_patrimony_calculate(['fineco_total_assets' => 161942.33, 'liquidity_total' => 7352.95, 'portfolio' => 154589.38, 'credit_agreed_amount' => 9500, 'credit_used_amount' => 2147.68, 'credit_available_amount' => 7352.32, 'available_balance' => 7352.95, 'accounting_balance' => -2147.05, 'reserved_liquidity' => 0, 'multicurrency_amount' => 0]);
$assert($anomaly['checks']['Saldo disponibile vs fido disponibile'] === .63 && $anomaly['quadrature'] === 0.0, 'anomalia sui saldi distinta dalla quadratura PNE');
$assert($anomaly['status'] === 'DA VERIFICARE' && $anomaly['pne'] === null && $anomaly['candidate'] === 152442.33, 'nessuna correzione automatica');

foreach ([[12000, 12000, 200000], [9000, 12000, 197000], [0, 12000, 188000], [17000, 12000, 205000], [5000, 0, 205000], [2500, 3000, 199500]] as [$cash, $credit, $expected]) {
    $result = economic_patrimony_calculate(['fineco_total_assets' => 200000 + $cash, 'liquidity_total' => $cash, 'portfolio' => 200000, 'credit_agreed_amount' => $credit, 'credit_available_amount' => min($cash, $credit), 'available_balance' => $cash, 'accounting_balance' => $cash - $credit, 'reserved_liquidity' => 0, 'multicurrency_amount' => 0]);
    $assert($result['pne'] === (float) $expected, 'scenario liquidita ' . $cash . ' / accordato ' . $credit);
}
foreach ([[181845.25, 169845.25], [183173.86, 171173.86], [180999.08, 168999.08], [182578.51, 170578.51]] as [$raw, $expected]) {
    $result = economic_patrimony_calculate(['fineco_total_assets' => $raw, 'liquidity_total' => 12000, 'portfolio' => $expected, 'documented_credit_in_fineco' => 12000]);
    $assert($result['pne'] === $expected, 'regressione aritmetica con perimetro ipotetico esplicito');
}

$assert(economic_patrimony_calculate(['fineco_total_assets' => 200000, 'liquidity_total' => 10000])['pne'] === null, 'fido mancante non equivale a zero');
$assert(economic_patrimony_calculate(['fineco_total_assets' => 210000, 'liquidity_total' => 10000, 'credit_agreed_amount' => 12000])['status'] === 'NON DISPONIBILE', 'fido accordato non prova inclusione');
$scope = economic_patrimony_calculate(['fineco_total_assets' => 210000, 'portfolio' => 200000, 'liquidity_total' => 10000, 'credit_agreed_amount' => 20000, 'documented_credit_in_fineco' => 10000, 'accounting_balance' => 0, 'reserved_liquidity' => 0, 'multicurrency_amount' => 0, 'available_balance' => 10000]);
$assert($scope['pne'] === 200000.0, 'linee esterne non sottratte');
$assert(economic_patrimony_calculate(array_replace($caseB, ['credit_used_amount' => 400, 'credit_available_amount' => 9037.00]))['pne'] === null, 'quadratura del fido non coerente');
$assert(economic_patrimony_calculate(array_replace($caseB, ['fineco_total_assets' => 164070.36]))['reliable'], 'tolleranza di un centesimo');
$assert(!economic_patrimony_calculate(array_replace($caseB, ['fineco_total_assets' => 164070.37]))['reliable'], 'tolleranza non estesa oltre un centesimo');
$reconstructed = economic_patrimony_calculate(array_replace($caseB, ['fineco_total_assets' => null, 'pending_operations' => 0]));
$assert($reconstructed['status'] === 'RICOSTRUITO' && $reconstructed['pne'] === $b['pne'], 'ricostruzione soltanto con componenti sufficienti');
foreach ([-1250, 740] as $pending) {
    $result = economic_patrimony_calculate(array_replace($caseB, ['fineco_total_assets' => $caseB['fineco_total_assets'] + $pending, 'pending_operations' => $pending]));
    $assert($result['pne'] === null && $result['status'] === 'DA VERIFICARE', 'regolamento acquisto/vendita in corso non assimilato a perdita o profitto');
}
$move = static fn(float $portfolio, float $cash): array => ['portfolio' => $portfolio, 'fineco_total_assets' => $portfolio + $cash, 'liquidity_total' => $cash, 'credit_agreed_amount' => 0, 'available_balance' => $cash, 'accounting_balance' => $cash, 'reserved_liquidity' => 0, 'multicurrency_amount' => 0];
$assert(economic_patrimony_calculate($move(201000, 4000))['pne'] === economic_patrimony_calculate($move(200000, 5000))['pne'], 'regolamento interno gia chiuso senza perdita simulata');
$assert(economic_patrimony_calculate($move(200000, 5500))['pne'] === 205500.0 && economic_patrimony_calculate($move(200000, 4500))['pne'] === 204500.0, 'flussi esterni modificano il valore senza attribuzione causale');
$assert(economic_patrimony_calculate(array_replace($move(200000, 5000), ['available_balance' => 4500, 'accounting_balance' => 4500, 'multicurrency_amount' => 500]))['pne'] === 205000.0, 'conversione EUR/FX senza movimento PNE');
$assert(economic_patrimony_calculate(array_replace($caseB, ['estimated_interest' => 100])) === $b, 'interessi stimati non riaddebitati');

$toRow = static fn(array $input, string $date): array => $input + ['snapshot_date' => $date, 'fineco_portfolio_total' => $input['portfolio']];
$rows = [$toRow($caseA, '2025-04-16'), $toRow($caseB, '2025-04-17'), $toRow(['portfolio' => 215000, 'fineco_total_assets' => 999999, 'liquidity_total' => 0], '2025-04-18')];
$raw = serialize($rows);
$archive = account_history_export_model($rows);
$assert(serialize($rows) === $raw, 'ricalcolo senza scritture');
$assert($archive['stats']['pne_num']['max']['value'] === $a['pne'] && $archive['stats']['pne_num']['count'] === 2, 'massimo esclude non attendibili');
$assert($archive['rows'][1]['pne_delta_num'] === -16.25 && $archive['rows'][2]['pne_delta_num'] === null, 'variazione non scavalca dati mancanti');
$assert(economic_patrimony_changes(array_slice($archive['rows'], 0, 2))['previous']['delta'] === $archive['rows'][1]['pne_delta_num'], 'confronto dashboard e archivio coerenti');
$manual = account_tracker_economic_snapshot(['manual_overrides_json' => json_encode($toRow($caseA, '2025-04-16'))]);
$assert($manual['pne'] === $a['pne'] && in_array('fineco_total_assets', $manual['manual_fields'], true), 'rettifiche esistenti tracciabili');
$payload = ai_report_build_account_payload_from_rows(array_map(static fn(array $r): array => ['snapshot_date' => $r['snapshot_date'], 'pne' => $r['pne_num'], 'pne_status' => $r['economic']['status'], 'fineco_total_assets' => $r['fineco_total_assets']], $archive['rows']))['payload'];
$facts = ai_report_build_account_verified_facts($payload);
$assert($payload['metrics']['pne']['max']['value'] === $a['pne'] && $payload['metrics']['pne']['latest'] === null && str_contains($facts, 'Stato PNE iniziale'), 'report AI non promuove la stima');

$project = diversification_project_model([['asset_id' => 1, 'asset_type' => 'ETF', 'ticker' => 'XDWH.MI', 'market_value' => 5050, 'cost_basis' => 4900, 'unrealized_pnl' => 150], ['asset_id' => 1, 'asset_type' => 'ETF', 'ticker' => 'XDWH.MI', 'market_value' => 1050, 'cost_basis' => 900, 'unrealized_pnl' => 150], ['asset_id' => 2, 'asset_type' => 'ETF', 'ticker' => 'VWCE.MI', 'market_value' => 10000]]);
$assert($project['groups']['NON TECH']['count'] === 1 && $project['groups']['NON TECH']['market'] === 6100.0 && $project['sectors']['XDWH']['remaining'] === 24200.0, 'comparti, target e posizioni consolidate');
$assert(array_sum(array_column($project['sectors'], 'target_pct')) === 100, 'allocazione strategica totale');
echo "OK: $checks controlli PNE, quadrature, export, AI e diversificazione.\n";
