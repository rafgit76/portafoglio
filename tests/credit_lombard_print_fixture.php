<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require_once __DIR__ . '/../lib/credit_lombard_interest.php';
require_once __DIR__ . '/../lib/credit_lombard_export.php';
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function app_base_path(): string { return ''; }
$snapshots = [];
$start = new DateTimeImmutable('2026-01-01');
for ($i = 0; $i < 100; $i++) {
    if ($i === 17) { continue; }
    $snapshots[] = ['snapshot_date' => $start->modify('+' . $i . ' days')->format('Y-m-d'), 'accounting_balance' => $i === 8 ? null : -1000 - $i];
}
$lombardMonitor = lombard_monitor($snapshots, [['valid_from' => '2026-01-01', 'tan' => 0.034, 'overdraft_tan' => 0.1115, 'credit_limit' => 12000]], '2026-01-01');
include __DIR__ . '/../partials/credit_lombard_print.php';
