<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !in_array('sqlite', PDO::getAvailableDrivers(), true)) { exit(1); }
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function app_base_path(): string { return ''; }
function db(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE pf_assets (id INTEGER PRIMARY KEY, asset_type TEXT, name TEXT, ticker TEXT, isin TEXT)');
    $pdo->exec('CREATE TABLE pf_snapshots (id INTEGER PRIMARY KEY, snapshot_date TEXT)');
    $pdo->exec('CREATE TABLE pf_snapshot_positions (snapshot_id INTEGER, asset_id INTEGER, quantity REAL, market_value REAL, unrealized_pnl REAL)');
    $pdo->exec("INSERT INTO pf_assets VALUES (1,'AZIONE','Azione test','AZ','ISIN1'),(2,'BTP','BTP test','BT','ISIN2'),(3,'ETF','ETF test','ETX','ISIN3'),(4,'BTP','BTP-13GN27 VALORE SU','T54748.MOT','ISIN4'),(5,'BTP','BTP-13GN27 VALSU CUM','T54739.MOT','ISIN5')");
    $pdo->exec("INSERT INTO pf_snapshots VALUES (1,'2026-11-15'),(2,'2026-11-16'),(3,'2026-11-17')");
    $pdo->exec('INSERT INTO pf_snapshot_positions VALUES (1,1,4,40,4),(1,1,6,60,6),(1,2,1000,1000,0),(2,2,1500,1500,0),(3,1,12.5,125,12.5),(3,2,1500,1500,0),(3,3,7,350,25),(3,4,10,100,3),(3,5,10,100,4)');
    return $pdo;
}
$mode = $argv[1] ?? 'main';
$_GET = match ($mode) {
    'quantity' => ['view' => 'quantity'],
    'filtered' => ['view' => 'quantity', 'asset' => '2'],
    'etf' => ['asset_type' => 'ETF'],
    'btp' => ['asset_type' => 'BTP'],
    'azione' => ['asset_type' => 'AZIONE'],
    'pac' => ['asset_type' => 'PAC'],
    'invalid' => ['asset_type' => 'sconosciuto'],
    'export-etf' => ['asset_type' => 'ETF', 'export' => 'xlsx'],
    default => [],
};
$root = dirname(__DIR__);
if ($mode === 'export-etf') { require_once $root . '/vendor/autoload.php'; }
$source = file_get_contents($root . '/andamento.php');
if ($source === false) { throw new RuntimeException('Controller Andamento non disponibile.'); }
// Execute the actual controller with only the production bootstrap and layout replaced by isolated fixtures.
$source = str_replace([
    "require_once __DIR__ . '/bootstrap.php';",
    "include __DIR__ . '/partials/header.php';",
    "include __DIR__ . '/partials/footer.php';",
], '', $source);
$source = str_replace('__DIR__', var_export($root, true), $source);
eval('?>' . $source);
