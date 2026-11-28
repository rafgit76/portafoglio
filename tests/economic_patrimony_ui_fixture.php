<?php

// Synthetic in-memory data; never load bootstrap or the deployment database.
if (PHP_SAPI !== 'cli' || !in_array('sqlite', PDO::getAvailableDrivers(), true)) { exit(1); }
require_once __DIR__ . '/../lib/account_tracker.php';

final class EconomicFixtureDatabase extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (preg_match('/^SHOW COLUMNS FROM `([a-z_]+)`$/', $query, $match)) {
            $query = "SELECT name FROM pragma_table_info('{$match[1]}')";
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

function economic_fixture_database(): PDO
{
    $pdo = new EconomicFixtureDatabase('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE pf_snapshots (id INTEGER PRIMARY KEY, snapshot_date TEXT, total_market_value REAL, total_cost_basis REAL, total_unrealized_pnl REAL)');
    $pdo->exec('CREATE TABLE pf_assets (id INTEGER PRIMARY KEY, ticker TEXT, isin TEXT, asset_type TEXT, name TEXT)');
    $pdo->exec('CREATE TABLE pf_dossiers (id INTEGER PRIMARY KEY, code TEXT, name TEXT, dossier_type TEXT, sort_order INTEGER, is_active INTEGER)');
    $pdo->exec('CREATE TABLE pf_snapshot_positions (id INTEGER PRIMARY KEY, snapshot_id INTEGER, asset_id INTEGER, dossier_id INTEGER, quantity REAL, market_value REAL, cost_basis REAL, unrealized_pnl REAL)');
    $pdo->exec('CREATE TABLE pf_patrimonial_snapshots (id INTEGER PRIMARY KEY, snapshot_date TEXT, fineco_total_assets REAL, fineco_portfolio_total REAL, liquidity_total REAL, accounting_balance REAL, available_balance REAL, reserved_liquidity REAL, multicurrency_amount REAL, credit_agreed_amount REAL, credit_used_amount REAL, credit_available_amount REAL, net_assets_adjusted REAL, own_liquidity_adjusted REAL, data_origin TEXT, created_at TEXT)');
    $pdo->exec('CREATE TABLE pf_patrimonial_import_jobs (id INTEGER PRIMARY KEY, patrimonial_snapshot_id INTEGER, parsed_payload_json TEXT)');
    $pdo->exec('CREATE TABLE pf_patrimonial_dossier_values (id INTEGER PRIMARY KEY, patrimonial_snapshot_id INTEGER, dossier_id INTEGER, amount REAL)');
    $pdo->exec('CREATE TABLE pf_internal_transfers (id, asset_id, source_dossier_id, target_dossier_id, source_snapshot_id, target_snapshot_id, status, execution_date, settlement_date, effective_snapshot_date, operation_code, source_type, source_document_name, gross_accrual, withholding_tax, total_amount)');
    $pdo->exec('CREATE TABLE pf_credit_lombard_rates (valid_from, tan, spread, overdraft_tan, credit_limit, benchmark, notes)');
    foreach (['pf_credit_lines', 'pf_credit_line_snapshots', 'pf_snapshot_revisions'] as $table) { $pdo->exec("CREATE TABLE $table (id INTEGER)"); }
    $pdo->exec("INSERT INTO pf_assets VALUES (1,'XDWH.MI','','ETF','Salute'),(2,'VWCE.MI','IE00BK5BQT80','ETF','Azionario globale'),(3,'BTP','','BTP','BTP demo')");
    $pdo->exec("INSERT INTO pf_dossiers VALUES (1,'ordinario','Dossier ordinario','ORDINARIO',1,1),(2,'credit_lombard','Dossier Credit Lombard','CREDIT_LOMBARD',2,1)");
    $insertWealth = $pdo->prepare("INSERT INTO pf_patrimonial_snapshots VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,'IMPORT_FINECO','2025-04-18 09:00:00')");
    $insertSnapshot = $pdo->prepare('INSERT INTO pf_snapshots VALUES (?,?,?,?,?)');
    $insertPosition = $pdo->prepare('INSERT INTO pf_snapshot_positions VALUES (NULL,?,?,?,?,?,?,?)');
    $insertPayload = $pdo->prepare('INSERT INTO pf_patrimonial_import_jobs VALUES (NULL,?,?)');
    $fixtures = [
        [1, '2025-04-10', 159200, 150000, 9200, -300, 9200, 0, 0, 9500, 300, 9200],
        [2, '2025-04-13', 161942.33, 154589.38, 7352.95, -2147.05, 7352.95, 0, 0, 9500, 2147.68, 7352.32],
        [3, '2025-04-17', 164086.60, 155042.10, 9044.50, -410.25, 9053.25, -36.50, -8.75, 9500, 446.75, 9053.25],
        [4, '2025-04-18', 164070.35, 155042.10, 9028.25, -410.25, 9037.00, -52.75, -8.75, 9500, null, null],
    ];
    foreach ($fixtures as $values) {
        [$id, $date, , $portfolio] = $values;
        $insertWealth->execute($values);
        $insertSnapshot->execute([$id, $date, $portfolio, $portfolio - 5000, 5000]);
        $insertPosition->execute([$id, 1, 1, 80, 4600 + $id, 4400, 200 + $id]);
        $insertPosition->execute([$id, 2, 1, 100, $portfolio - 18850.30 - $id, $portfolio - 18850.30 - $id - 4800, 4800]);
        $insertPosition->execute([$id, 3, 2, 15000, 14250.30, 14250.30, 0]);
        $insertPayload->execute([$id, '{"details":{"totale operazioni in corso":0}}']);
    }
    return $pdo;
}

$mode = $argv[1] ?? 'dashboard';
$root = dirname(__DIR__);
if ($mode === 'integration') {
    require_once $root . '/lib/ai_report.php';
    require_once $root . '/lib/account_history_export.php';
    $pdo = economic_fixture_database();
    $history = account_tracker_economic_history($pdo);
    $metrics = account_tracker_patrimonial_metrics($pdo, $history);
    $archive = account_history_export_model($history);
    $report = ai_report_build_account_payload($pdo)['payload'];
    if ($metrics['economic']['pne'] !== 154570.35 || $metrics['economic']['pne'] !== $archive['latest']['pne_num'] || $metrics['economic']['pne'] !== $report['metrics']['pne']['latest'] || $history[1]['economic']['pne'] !== null) {
        throw new RuntimeException('Valori centralizzati incoerenti.');
    }
    echo "OK: dashboard, archivio, export e report AI condividono il PNE letto dallo stesso database isolato.\n";
    exit;
}
$_SERVER['REQUEST_METHOD'] = 'GET';
if ($mode === 'history-detail') { $_GET = ['view' => 'detail', 'status' => 'DA VERIFICARE']; }
if ($mode === 'dashboard-week') { $_GET = ['pne_period' => 'week']; }
$entry = str_starts_with($mode, 'history') ? 'account_history.php' : ($mode === 'account' ? 'account.php' : 'dashboard.php');
$source = file_get_contents($root . '/' . $entry);
$source = str_replace([
    "require_once __DIR__ . '/bootstrap.php';", '$pdo = db();', "include __DIR__ . '/partials/header.php';", "include __DIR__ . '/partials/footer.php';",
    "app_setting('enable_fx', true)", "app_setting('enable_news', false)", "app_setting('account_reconciliation_warn_pct', null)", 'app_base_path()',
], ['', '$pdo = economic_fixture_database();', '', '', 'false', 'false', 'null', "''"], $source);
$source = str_replace('__DIR__', var_export($root, true), $source);
ob_start();
echo '<!doctype html><html lang="it"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/assets/style.css"><body><main class="container layout-dashboard">';
eval('?>' . $source);
echo '</main></body></html>';
ob_end_flush();
