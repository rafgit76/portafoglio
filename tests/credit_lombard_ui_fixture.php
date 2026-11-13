<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require_once __DIR__ . '/../lib/credit_lombard_interest.php';
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string { return 'synthetic-csrf'; }
function app_base_path(): string { return ''; }
$money = static fn(?float $value): string => $value === null ? 'Dato non disponibile' : 'EUR ' . number_format($value, 2, ',', '.');
$lombardRates = [['valid_from' => '2026-08-07', 'tan' => 0.034, 'spread' => 0.01, 'overdraft_tan' => 0.1115, 'credit_limit' => 12000, 'benchmark' => 'Euribor 3M 360', 'notes' => 'Dati sintetici']];
$activeLombardProfiles = [['amount_granted' => 12000]];
$lombardMonitor = lombard_monitor([
    ['snapshot_date' => '2026-11-01', 'accounting_balance' => 500],
    ['snapshot_date' => '2026-11-02', 'accounting_balance' => -1000],
    ['snapshot_date' => '2026-11-04', 'accounting_balance' => -13000],
], $lombardRates, '2026-11-01');
$lombardSchemaReady = true;
$lombardError = null;
?><!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/style.css"></head><body><main class="container"><?php include __DIR__ . '/../partials/credit_lombard_monitor.php'; ?></main></body></html>
