<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/andamento_data.php';
require_once __DIR__ . '/../lib/andamento_quantity_history.php';
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP: PDO SQLite non disponibile per il test isolato delle query.\n";
    exit(0);
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE pf_assets (id INTEGER PRIMARY KEY, asset_type TEXT, name TEXT, ticker TEXT, isin TEXT)');
$pdo->exec('CREATE TABLE pf_snapshots (id INTEGER PRIMARY KEY, snapshot_date TEXT)');
$pdo->exec('CREATE TABLE pf_snapshot_positions (snapshot_id INTEGER, asset_id INTEGER, quantity REAL, market_value REAL, unrealized_pnl REAL)');
$pdo->exec("INSERT INTO pf_assets VALUES (1,'AZIONE','Azione test','AZ','ISIN1'),(2,'BTP','BTP test','BT','ISIN2')");
$pdo->exec("INSERT INTO pf_snapshots VALUES (1,'2026-11-15'),(2,'2026-11-16'),(3,'2026-11-17'),(4,'2026-11-18')");
$pdo->exec('INSERT INTO pf_snapshot_positions VALUES (1,1,4,40,4),(1,1,6,60,6),(1,2,1000,1000,0),(2,2,1500,1500,0),(3,1,12.5,125,12.5),(3,2,1500,1500,0)');
$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    $count++;
    if (!$condition) { throw new RuntimeException('Test fallito: ' . $message); }
};
$default = andamento_load_data($pdo, false);
$assert(count($default['columns']) === 1 && $default['columns'][0]['asset_type'] === 'AZIONE', 'Andamento mantiene il filtro precedente');
$assert(count($default['rows']) === 3, 'Andamento mantiene il trattamento precedente degli snapshot con soli BTP');
$assert($default['rows'][0]['values'][1]['quantity'] === 10.0 && $default['rows'][0]['values'][1]['market_value'] === 100.0, 'somma posizioni tra dossier');
$all = andamento_load_data($pdo, true);
$assert(count($all['columns']) === 2 && count($all['rows']) === 4, 'storico quantita include BTP e snapshot senza posizioni');
$assert($all['rows'][1]['snapshot_date'] === '2026-11-16' && !isset($all['rows'][1]['values'][1]), 'assenza di un titolo resta assenza');
$changes = andamento_quantity_history($all['rows'], $all['columns']);
$assert(count($changes) === 2, 'variazioni ricostruite per entrambi i tipi');
$assert($changes[0]['asset_id'] === 1 && $changes[0]['delta'] === 2.5 && $changes[0]['missing_snapshots'] === 1, 'variazione azione con lacuna');
$assert($changes[1]['asset_id'] === 2 && $changes[1]['delta'] === 500.0 && $changes[1]['missing_snapshots'] === 0, 'variazione BTP');
echo "OK: $count controlli query e aggregazione isolati in SQLite.\n";
