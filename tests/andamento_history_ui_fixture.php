<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require_once __DIR__ . '/../lib/andamento_extremes.php';
require_once __DIR__ . '/../lib/andamento_quantity_history.php';
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function app_base_path(): string { return ''; }
$columns = [
    ['id' => 1, 'label' => 'AZIONE & TEST', 'asset_type' => 'AZIONE'],
    ['id' => 2, 'label' => 'BTP TEST', 'asset_type' => 'BTP'],
];
$orderedRows = [
    ['snapshot_date' => '2026-11-15', 'values' => [1 => ['quantity' => 10.0, 'market_value' => 100.0, 'unrealized_pnl' => -10.0], 2 => ['quantity' => 1000.0, 'market_value' => 1000.0, 'unrealized_pnl' => 0.0]]],
    ['snapshot_date' => '2026-11-16', 'values' => [1 => ['quantity' => 10.0, 'market_value' => 120.0, 'unrealized_pnl' => 0.0], 2 => ['quantity' => 2000.0, 'market_value' => 2000.0, 'unrealized_pnl' => 0.0]]],
    ['snapshot_date' => '2026-11-17', 'values' => [2 => ['quantity' => 2000.0, 'market_value' => 2000.0, 'unrealized_pnl' => 0.0]]],
    ['snapshot_date' => '2026-11-18', 'values' => [1 => ['quantity' => 12.5, 'market_value' => 120.0, 'unrealized_pnl' => -5.0]]],
];
$assetExtremes = andamento_extremes($orderedRows, $columns);
$quantityChanges = andamento_quantity_history($orderedRows, $columns);
$formatMoney = static fn(float $value): string => 'EUR ' . number_format($value, 2, ',', '.');
$formatQuantity = static fn(float $value): string => rtrim(rtrim(number_format($value, 6, ',', '.'), '0'), ',');
$mode = $argv[1] ?? 'extremes';
$_GET = $mode === 'quantity-filter' ? ['asset' => '1'] : [];
?><!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/style.css"></head><body><main class="container"><?php include __DIR__ . '/../partials/' . ($mode === 'extremes' ? 'andamento_extremes.php' : 'andamento_quantity_history.php'); ?></main></body></html>
