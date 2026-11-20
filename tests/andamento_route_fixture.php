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
    $pdo->exec("INSERT INTO pf_assets VALUES (1,'AZIONE','Azione test','AZ','ISIN1'),(2,'BTP','BTP test','BT','ISIN2')");
    $pdo->exec("INSERT INTO pf_snapshots VALUES (1,'2026-11-15'),(2,'2026-11-16'),(3,'2026-11-17')");
    $pdo->exec('INSERT INTO pf_snapshot_positions VALUES (1,1,4,40,4),(1,1,6,60,6),(1,2,1000,1000,0),(2,2,1500,1500,0),(3,1,12.5,125,12.5),(3,2,1500,1500,0)');
    return $pdo;
}
$mode = $argv[1] ?? 'main';
$_GET = $mode === 'quantity' ? ['view' => 'quantity'] : ($mode === 'filtered' ? ['view' => 'quantity', 'asset' => '2'] : []);
$root = dirname(__DIR__);
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
