<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/andamento_extremes.php';
require_once __DIR__ . '/../lib/andamento_quantity_history.php';

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    $count++;
    if (!$condition) { throw new RuntimeException('Test fallito: ' . $message); }
};
$columns = [['id' => 1], ['id' => 2], ['id' => 3]];
$rows = [
    ['snapshot_date' => '2026-11-15', 'values' => [1 => ['quantity' => 10.0, 'market_value' => 100.0, 'unrealized_pnl' => -10.0], 2 => ['quantity' => 2.0, 'market_value' => 0.0, 'unrealized_pnl' => 0.0]]],
    ['snapshot_date' => '2026-11-16', 'values' => [1 => ['quantity' => 10.0, 'market_value' => 120.0, 'unrealized_pnl' => 0.0], 2 => ['quantity' => 0.0, 'market_value' => 0.0, 'unrealized_pnl' => 0.0]]],
    ['snapshot_date' => '2026-11-17', 'values' => [2 => ['quantity' => 0.0, 'market_value' => 0.0, 'unrealized_pnl' => 0.0]]],
    ['snapshot_date' => '2026-11-18', 'values' => [1 => ['quantity' => 12.5, 'market_value' => 120.0, 'unrealized_pnl' => -5.0], 2 => ['quantity' => 0.0, 'market_value' => 0.0, 'unrealized_pnl' => 0.0]]],
    ['snapshot_date' => '2026-11-19', 'values' => [1 => ['quantity' => 9.5, 'market_value' => 100.0001, 'unrealized_pnl' => -10.0]]],
];
$extremes = andamento_extremes($rows, $columns);
$assert($extremes[1]['market_value']['max']['value'] === 120.0, 'massimo valore storico');
$assert($extremes[1]['market_value']['max']['dates'] === ['2026-11-16', '2026-11-18'], 'tutte le date del massimo ripetuto');
$assert($extremes[1]['market_value']['max']['quantities']['2026-11-16'] === 10.0 && $extremes[1]['market_value']['max']['quantities']['2026-11-18'] === 12.5, 'quantita di ogni data del massimo ripetuto');
$assert($extremes[2]['market_value']['min']['quantities']['2026-11-16'] === 0.0, 'quantita zero rimane zero');
$missingQuantity = andamento_extremes([['snapshot_date' => '2026-11-19', 'values' => [1 => ['market_value' => 50.0]]]], $columns);
$assert(array_key_exists('2026-11-19', $missingQuantity[1]['market_value']['max']['quantities']) && $missingQuantity[1]['market_value']['max']['quantities']['2026-11-19'] === null, 'quantita mancante non viene inventata');
$assert($extremes[1]['market_value']['min']['dates'] === ['2026-11-15'], 'precisione originale distingue valori arrotondati uguali');
$assert($extremes[1]['unrealized_pnl']['min']['dates'] === ['2026-11-15', '2026-11-19'], 'minimo P/L negativo e date ripetute');
$assert($extremes[2]['market_value']['min']['value'] === 0.0 && $extremes[2]['market_value']['max']['value'] === 0.0, 'zero e un valore osservato');
$assert($extremes[3]['market_value']['max'] === null, 'nessuna osservazione non equivale a zero');
$today = new DateTimeImmutable('2026-11-20');
$distance = andamento_extreme_distance(100.0001, $extremes[1]['market_value']['max'], $today);
$assert(abs($distance['delta'] - (-19.9999)) < 0.000001 && abs($distance['percent'] - (-16.666583333333)) < 0.000001, 'scarto monetario e percentuale dal massimo');
$assert($distance['days'] === 2 && $distance['date'] === '2026-11-18', 'giorni da ultima occorrenza rispetto a oggi');
$negative = andamento_extreme_distance(-5.0, $extremes[1]['unrealized_pnl']['min'], $today);
$assert($negative['delta'] === 5.0 && $negative['percent'] === 50.0, 'percentuale su valore assoluto del minimo negativo');
$zero = andamento_extreme_distance(1.0, $extremes[2]['market_value']['max'], $today);
$assert($zero['delta'] === 1.0 && $zero['percent'] === null, 'percentuale non calcolabile con estremo zero');
$assert(andamento_extreme_distance(null, $extremes[1]['market_value']['max'], $today)['delta'] === null, 'scarto N/D se manca l ultimo valore');
$assert(andamento_extreme_distance(1.0, ['value' => 1.0, 'dates' => ['2027-01-01']], $today)['days'] === null, 'nessun giorno trascorso negativo per data futura');
$changes = andamento_quantity_history($rows, $columns);
$assert(count($changes) === 3, 'solo variazioni tra due osservazioni');
$assert($changes[0]['asset_id'] === 1 && $changes[0]['date'] === '2026-11-19' && $changes[0]['delta'] === -3.0, 'variazione decrescente piu recente');
$assert($changes[1]['asset_id'] === 1 && $changes[1]['previous_date'] === '2026-11-16' && $changes[1]['missing_snapshots'] === 1 && $changes[1]['delta'] === 2.5, 'lacuna intermedia esplicita');
$assert($changes[2]['asset_id'] === 2 && $changes[2]['quantity'] === 0.0 && $changes[2]['delta'] === -2.0, 'zero esplicito genera variazione');
$assert(andamento_quantity_history([['snapshot_date' => '2026-11-01', 'values' => [1 => ['quantity' => 10]]]], $columns) === [], 'prima osservazione non e un movimento');
echo "OK: $count controlli estremi e storico quantita.\n";
