<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/fineco_xlsx_parser.php';

function import_fineco_spreadsheet(string $uploadedPath, string $originalFileName, string $snapshotDate): array
{
    $parser = new FinecoXlsxParser();
    $parsedPositions = $parser->parse($uploadedPath);

    $positionsRaw = [];
    foreach ($parsedPositions as $position) {
        $title = $position->title;
        $isin = $position->isin;
        $assetType = map_instrument_type($position->instrument);
        $ticker = $position->ticker;
        $currency = $position->currency;
        $qty = $position->quantity;
        $avg = $position->avgLoadPrice;
        $cur = $position->currentPrice;
        $cost = $position->costBasis;
        $market = $position->marketValue;
        $gain = $position->unrealizedPnl;
        $varPct = $position->varPct;

        $key = $isin !== '' ? $isin : sha1($title . '|' . ($ticker ?? ''));

        if (!isset($positionsRaw[$key])) {
            $positionsRaw[$key] = [
                'asset_type' => $assetType,
                'name' => $title,
                'isin' => $isin !== '' ? $isin : null,
                'ticker' => $ticker,
                'currency' => $currency,
                'quantity' => 0.0,
                'cost_basis' => 0.0,
                'market_value' => 0.0,
                'unrealized_pnl' => 0.0,
                'weighted_var_component' => 0.0,
                'has_avg' => false,
                'sum_avg_x_qty' => 0.0,
                'sum_current_x_qty' => 0.0,
            ];
        }

        $positionsRaw[$key]['quantity'] += $qty;
        $positionsRaw[$key]['cost_basis'] += $cost;
        $positionsRaw[$key]['market_value'] += $market;
        $positionsRaw[$key]['unrealized_pnl'] += $gain;
        $positionsRaw[$key]['weighted_var_component'] += ($varPct * $market);
        $positionsRaw[$key]['sum_avg_x_qty'] += ($avg * $qty);
        $positionsRaw[$key]['sum_current_x_qty'] += ($cur * $qty);
        $positionsRaw[$key]['has_avg'] = true;
    }

    if (empty($positionsRaw)) {
        throw new RuntimeException('Nessuna riga valida trovata nel file.');
    }

    $totalMarket = 0.0;
    foreach ($positionsRaw as $p) {
        $totalMarket += $p['market_value'];
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmtSnapshot = $pdo->prepare(
            'INSERT INTO pf_snapshots (snapshot_date, source_file_name, notes, total_market_value, total_cost_basis, total_unrealized_pnl)
             VALUES (:snapshot_date, :source_file_name, :notes, 0, 0, 0)
             ON DUPLICATE KEY UPDATE
               id = LAST_INSERT_ID(id),
               source_file_name = VALUES(source_file_name),
               notes = VALUES(notes),
               created_at = CURRENT_TIMESTAMP'
        );

        $stmtSnapshot->execute([
            ':snapshot_date' => $snapshotDate,
            ':source_file_name' => $originalFileName,
            ':notes' => 'Import automatico Fineco',
        ]);

        $snapshotId = (int) $pdo->lastInsertId();
        if ($snapshotId <= 0) {
            $q = $pdo->prepare('SELECT id FROM pf_snapshots WHERE snapshot_date = :d LIMIT 1');
            $q->execute([':d' => $snapshotDate]);
            $snapshotId = (int) $q->fetchColumn();
        }

        $pdo->prepare('DELETE FROM pf_snapshot_positions WHERE snapshot_id = :id')->execute([':id' => $snapshotId]);

        $stmtFindAssetByIsin = $pdo->prepare('SELECT id FROM pf_assets WHERE isin = :isin LIMIT 1');
        $stmtInsertAsset = $pdo->prepare(
            'INSERT INTO pf_assets (asset_type, name, isin, ticker, currency)
             VALUES (:asset_type, :name, :isin, :ticker, :currency)'
        );
        $stmtUpdateAsset = $pdo->prepare(
            'UPDATE pf_assets
             SET asset_type = :asset_type, name = :name, ticker = :ticker, currency = :currency
             WHERE id = :id'
        );

        $stmtInsertPos = $pdo->prepare(
            'INSERT INTO pf_snapshot_positions
            (snapshot_id, asset_id, quantity, avg_load_price, current_price, cost_basis, market_value, unrealized_pnl, weight_pct, currency, imported_gain_value, imported_weight_pct)
            VALUES
            (:snapshot_id, :asset_id, :quantity, :avg_load_price, :current_price, :cost_basis, :market_value, :unrealized_pnl, :weight_pct, :currency, :imported_gain_value, :imported_weight_pct)'
        );

        $sumCost = 0.0;
        $sumPnl = 0.0;
        $positionsByAssetId = [];

        foreach ($positionsRaw as $p) {
            $assetId = null;

            if (!empty($p['isin'])) {
                $stmtFindAssetByIsin->execute([':isin' => $p['isin']]);
                $assetId = (int) $stmtFindAssetByIsin->fetchColumn();
            }

            if (!$assetId) {
                $stmtInsertAsset->execute([
                    ':asset_type' => $p['asset_type'],
                    ':name' => $p['name'],
                    ':isin' => $p['isin'],
                    ':ticker' => $p['ticker'],
                    ':currency' => $p['currency'],
                ]);
                $assetId = (int) $pdo->lastInsertId();
            } else {
                $stmtUpdateAsset->execute([
                    ':asset_type' => $p['asset_type'],
                    ':name' => $p['name'],
                    ':ticker' => $p['ticker'],
                    ':currency' => $p['currency'],
                    ':id' => $assetId,
                ]);
            }

            if (!isset($positionsByAssetId[$assetId])) {
                $positionsByAssetId[$assetId] = [
                    'quantity' => 0.0,
                    'cost_basis' => 0.0,
                    'market_value' => 0.0,
                    'unrealized_pnl' => 0.0,
                    'sum_avg_x_qty' => 0.0,
                    'sum_current_x_qty' => 0.0,
                    'currency' => $p['currency'],
                ];
            }

            $positionsByAssetId[$assetId]['quantity'] += $p['quantity'];
            $positionsByAssetId[$assetId]['cost_basis'] += $p['cost_basis'];
            $positionsByAssetId[$assetId]['market_value'] += $p['market_value'];
            $positionsByAssetId[$assetId]['unrealized_pnl'] += $p['unrealized_pnl'];
            $positionsByAssetId[$assetId]['sum_avg_x_qty'] += $p['sum_avg_x_qty'];
            $positionsByAssetId[$assetId]['sum_current_x_qty'] += $p['sum_current_x_qty'];
        }

        foreach ($positionsByAssetId as $assetId => $p) {
            $qty = max(0.000001, $p['quantity']);
            $avgLoadPrice = $p['sum_avg_x_qty'] / $qty;
            $currentPrice = $p['sum_current_x_qty'] / $qty;
            $weight = $totalMarket > 0 ? ($p['market_value'] / $totalMarket) : 0.0;

            $stmtInsertPos->execute([
                ':snapshot_id' => $snapshotId,
                ':asset_id' => (int) $assetId,
                ':quantity' => $p['quantity'],
                ':avg_load_price' => $avgLoadPrice,
                ':current_price' => $currentPrice,
                ':cost_basis' => $p['cost_basis'],
                ':market_value' => $p['market_value'],
                ':unrealized_pnl' => $p['unrealized_pnl'],
                ':weight_pct' => $weight,
                ':currency' => $p['currency'],
                ':imported_gain_value' => $p['unrealized_pnl'],
                ':imported_weight_pct' => $weight,
            ]);

            $sumCost += $p['cost_basis'];
            $sumPnl += $p['unrealized_pnl'];
        }

        $stmtUpdateSnapshot = $pdo->prepare(
            'UPDATE pf_snapshots
             SET total_market_value = :mv,
                 total_cost_basis = :cb,
                 total_unrealized_pnl = :pnl
             WHERE id = :id'
        );

        $stmtUpdateSnapshot->execute([
            ':mv' => $totalMarket,
            ':cb' => $sumCost,
            ':pnl' => $sumPnl,
            ':id' => $snapshotId,
        ]);

        $stmtImportJob = $pdo->prepare(
            'INSERT INTO pf_import_jobs
            (snapshot_id, file_name, source_type, status, rows_total, rows_ok, rows_error, error_summary, created_at, started_at, finished_at)
            VALUES
            (:snapshot_id, :file_name, :source_type, :status, :rows_total, :rows_ok, :rows_error, :error_summary, NOW(), NOW(), NOW())'
        );

        $stmtImportJob->execute([
            ':snapshot_id' => $snapshotId,
            ':file_name' => $originalFileName,
            ':source_type' => 'EXCEL',
            ':status' => 'COMPLETED',
        ':rows_total' => count($parsedPositions),
        ':rows_ok' => count($positionsRaw),
            ':rows_error' => 0,
            ':error_summary' => null,
        ]);

        $pdo->commit();

        return [
            'snapshot_id' => $snapshotId,
            'positions' => count($positionsRaw),
            'total_market_value' => $totalMarket,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function import_fineco_xlsx(string $uploadedPath, string $originalFileName, string $snapshotDate): array
{
    return import_fineco_spreadsheet($uploadedPath, $originalFileName, $snapshotDate);
}

function normalize_headers(array $headerRow): array
{
    $map = [];
    foreach ($headerRow as $idx => $name) {
        $norm = normalize_key((string) $name);
        if ($norm !== '') {
            $map[$norm] = $idx;
        }
    }

    if (isset($map['varpct']) && !isset($map['var_pct'])) {
        $map['var_pct'] = $map['varpct'];
    }

    if (isset($map['vareuro']) && !isset($map['var_euro'])) {
        $map['var_euro'] = $map['vareuro'];
    }

    if (isset($map['var_e']) && !isset($map['var_euro'])) {
        $map['var_euro'] = $map['var_e'];
    }

    if (isset($map['var']) && !isset($map['var_euro'])) {
        $map['var_euro'] = $map['var'];
    }

    if (isset($map['valore_di_mercato']) && !isset($map['valore_di_mercato_euro'])) {
        $map['valore_di_mercato_euro'] = $map['valore_di_mercato'];
    }

    if (isset($map['quantit']) && !isset($map['quantita'])) {
        $map['quantita'] = $map['quantit'];
    }

    if (isset($map['pzomediodicarico']) && !isset($map['p_zo_medio_di_carico'])) {
        $map['p_zo_medio_di_carico'] = $map['pzomediodicarico'];
    }

    if (isset($map['p_zo_medio_di_carico']) && !isset($map['pzomediodicarico'])) {
        $map['pzomediodicarico'] = $map['p_zo_medio_di_carico'];
    }

    if (isset($map['pzodimercato']) && !isset($map['p_zo_di_mercato'])) {
        $map['p_zo_di_mercato'] = $map['pzodimercato'];
    }

    if (isset($map['p_zo_di_mercato']) && !isset($map['pzodimercato'])) {
        $map['pzodimercato'] = $map['p_zo_di_mercato'];
    }

    return $map;
}

function normalize_key(string $value): string
{
    $value = trim($value);
    $value = mb_strtolower($value, 'UTF-8');
    $value = str_replace(["\u{00A0}", '€', '%'], [' ', ' euro ', ' pct '], $value);
    $value = normalize_ascii($value);
    $value = strtr($value, [
        '.' => '_',
        ' ' => '_',
        '-' => '_',
        '/' => '_',
        '\'' => '_',
    ]);
    $value = preg_replace('/[^a-z0-9_]+/', '_', $value);
    $value = preg_replace('/_+/', '_', $value);
    $value = trim($value, '_');

    return $value;
}

function get_col(array $line, array $headers, string $key)
{
    $idx = $headers[$key] ?? null;
    if ($idx === null) {
        return null;
    }

    return $line[$idx] ?? null;
}

function map_instrument_type(string $instrument): string
{
    $i = mb_strtolower(trim($instrument), 'UTF-8');
    if ($i === 'azione') {
        return 'AZIONE';
    }
    if ($i === 'etf') {
        return 'ETF';
    }
    if ($i === 'obbligazione') {
        return 'BTP';
    }
    if (strpos($i, 'pac') !== false || strpos($i, 'fondo') !== false) {
        return 'PAC';
    }

    return 'AZIONE';
}
