<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/account_tracker.php';

function ai_report_cache_path(): string
{
    return __DIR__ . '/../storage/ai_report_cache.json';
}

function ai_report_load_cache(): ?array
{
    $path = ai_report_cache_path();
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function ai_report_save_cache(array $payload): void
{
    $path = ai_report_cache_path();
    ensure_directory(dirname($path));
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function ai_report_archive_directory(): string
{
    return __DIR__ . '/../storage/ai_reports';
}

function ai_report_archive_path(string $id): ?string
{
    if (preg_match('/^\d{8}_\d{6}_[a-f0-9]{8}$/', $id) !== 1) {
        return null;
    }

    return ai_report_archive_directory() . '/' . $id . '.json';
}

function ai_report_archive_save(array $record): array
{
    $directory = ai_report_archive_directory();
    ensure_directory($directory);

    $id = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $record['id'] = $id;
    $record['archived_at'] = date('c');

    $encoded = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Impossibile preparare il report per l\'archivio.');
    }

    $path = ai_report_archive_path($id);
    if ($path === null || file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Impossibile salvare il report nell\'archivio.');
    }

    return $record;
}

function ai_report_archive_load(string $id): ?array
{
    $path = ai_report_archive_path($id);
    if ($path === null || !is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function ai_report_archive_delete(string $id): void
{
    $path = ai_report_archive_path($id);
    if ($path === null) {
        throw new RuntimeException('Identificativo report non valido.');
    }
    if (!is_file($path)) {
        throw new RuntimeException('Il report selezionato non e piu presente nell\'archivio.');
    }
    if (!unlink($path)) {
        throw new RuntimeException('Impossibile eliminare il report dall\'archivio. Verifica i permessi della cartella storage/ai_reports.');
    }
}

function ai_report_archive_list(): array
{
    $directory = ai_report_archive_directory();
    if (!is_dir($directory)) {
        return [];
    }

    $reports = [];
    foreach (glob($directory . '/*.json') ?: [] as $path) {
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            continue;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['id'], $decoded['report_markdown'])) {
            $reports[] = $decoded;
        }
    }

    usort($reports, static function (array $a, array $b): int {
        return strcmp((string) ($b['generated_at'] ?? ''), (string) ($a['generated_at'] ?? ''));
    });

    return $reports;
}

function ai_report_archive_stats(array $reports): array
{
    $stats = [
        'reports' => count($reports),
        'prompt_tokens' => 0,
        'output_tokens' => 0,
        'thinking_tokens' => 0,
        'total_tokens' => 0,
        'estimated_cost_usd' => 0.0,
    ];

    foreach ($reports as $report) {
        $usage = is_array($report['usage'] ?? null) ? $report['usage'] : [];
        $stats['prompt_tokens'] += (int) ($usage['prompt_tokens'] ?? 0);
        $stats['output_tokens'] += (int) ($usage['output_tokens'] ?? 0);
        $stats['thinking_tokens'] += (int) ($usage['thinking_tokens'] ?? 0);
        $stats['total_tokens'] += (int) ($usage['total_tokens'] ?? 0);
        $stats['estimated_cost_usd'] += (float) ($report['estimated_cost_usd'] ?? 0.0);
    }

    return $stats;
}

function ai_report_type_label(string $type, bool $compact = false): string
{
    return match ($type) {
        'custom' => $compact ? 'Personalizzato' : 'Report personalizzato',
        'changes' => 'Cosa e cambiato?',
        'weekly_changes' => $compact ? 'Settimanale' : 'Cosa e cambiato settimanale?',
        'account_general' => $compact ? 'Patrimonio completo' : 'Report complessivo del patrimonio',
        'account_changes' => $compact ? 'Patrimonio quotidiano' : 'Cosa e cambiato nel patrimonio?',
        'account_weekly_changes' => $compact ? 'Patrimonio settimanale' : 'Cosa e cambiato nel patrimonio settimanale?',
        default => $compact ? 'Complessivo' : 'Report complessivo',
    };
}

function ai_report_type_class(string $type): string
{
    return in_array($type, [
        'general',
        'custom',
        'changes',
        'weekly_changes',
        'account_general',
        'account_changes',
        'account_weekly_changes',
    ], true) ? $type : 'general';
}

function ai_report_archive_generated(
    array $generated,
    array $built,
    string $type,
    string $title,
    string $customPrompt = ''
): array {
    $payload = is_array($built['payload'] ?? null) ? $built['payload'] : [];
    $dateRange = is_array($payload['date_range'] ?? null) ? $payload['date_range'] : [];
    $normalizedType = ai_report_type_class($type);
    $estimatedCost = ai_report_estimate_cost_usd(
        (string) ($generated['model'] ?? ''),
        (int) ($generated['prompt_tokens'] ?? 0),
        (int) ($generated['output_tokens'] ?? 0),
        (int) ($generated['thinking_tokens'] ?? 0)
    );

    return ai_report_archive_save([
        'type' => $normalizedType,
        'title' => $title,
        'custom_prompt' => $normalizedType === 'custom' ? $customPrompt : '',
        'data_hash' => (string) ($built['hash'] ?? ''),
        'snapshot_count' => (int) ($payload['snapshots_count'] ?? 0),
        'date_from' => (string) ($dateRange['from'] ?? ''),
        'date_to' => (string) ($dateRange['to'] ?? ''),
        'generated_at' => (string) ($generated['generated_at'] ?? date('c')),
        'provider' => (string) ($generated['provider'] ?? 'gemini'),
        'model' => (string) ($generated['model'] ?? ''),
        'thinking_level' => (string) ($generated['thinking_level'] ?? ''),
        'prompt_version' => (string) ($generated['prompt_version'] ?? ''),
        'generation_attempts' => max(1, (int) ($generated['generation_attempts'] ?? 1)),
        'finish_reason' => (string) ($generated['finish_reason'] ?? ''),
        'usage' => [
            'prompt_tokens' => (int) ($generated['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($generated['output_tokens'] ?? 0),
            'thinking_tokens' => (int) ($generated['thinking_tokens'] ?? 0),
            'total_tokens' => (int) ($generated['total_tokens'] ?? 0),
        ],
        'estimated_cost_usd' => $estimatedCost,
        'report_markdown' => (string) ($generated['report_markdown'] ?? ''),
    ]);
}

function ai_report_import_legacy_cache(): ?array
{
    $cache = ai_report_load_cache();
    if (!is_array($cache) || trim((string) ($cache['report_markdown'] ?? '')) === '') {
        return null;
    }

    $usage = is_array($cache['usage'] ?? null) ? $cache['usage'] : [];
    $model = (string) ($cache['model'] ?? '');
    $estimatedCost = ai_report_estimate_cost_usd(
        $model,
        (int) ($usage['prompt_tokens'] ?? 0),
        (int) ($usage['output_tokens'] ?? 0),
        (int) ($usage['thinking_tokens'] ?? 0)
    );

    return ai_report_archive_save([
        'type' => 'general',
        'title' => 'Report complessivo precedente',
        'custom_prompt' => '',
        'data_hash' => (string) ($cache['data_hash'] ?? ''),
        'snapshot_count' => 0,
        'date_from' => '',
        'date_to' => (string) ($cache['snapshot_to'] ?? ''),
        'generated_at' => (string) ($cache['generated_at'] ?? date('c')),
        'provider' => (string) ($cache['provider'] ?? 'gemini'),
        'model' => $model,
        'thinking_level' => '',
        'prompt_version' => (string) ($cache['prompt_version'] ?? ''),
        'finish_reason' => (string) ($cache['finish_reason'] ?? ''),
        'usage' => [
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'thinking_tokens' => (int) ($usage['thinking_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
        ],
        'estimated_cost_usd' => $estimatedCost,
        'report_markdown' => (string) $cache['report_markdown'],
    ]);
}

function ai_report_prompt_version(): string
{
    return '2026-08-14-gemini-3-7-general-length-v12';
}

function ai_report_build_payload(PDO $pdo): array
{
    $snapshots = $pdo->query(
        'SELECT id, snapshot_date, total_market_value, total_cost_basis, total_unrealized_pnl, created_at
         FROM pf_snapshots
         ORDER BY snapshot_date ASC, id ASC'
    )->fetchAll();

    if (count($snapshots) < 2) {
        throw new RuntimeException('Servono almeno 2 snapshot per generare un report AI storico.');
    }

    $latestSnapshot = $snapshots[count($snapshots) - 1];
    $latestSnapshotId = (int) $latestSnapshot['id'];

    $latestPositions = ai_report_latest_positions($pdo, $latestSnapshotId);

    $firstSnapshotId = (int) $snapshots[0]['id'];
    $positionChanges = ai_report_position_changes($pdo, $firstSnapshotId, $latestSnapshotId);
    $series = ai_report_series($snapshots);
    $extremes = ai_report_extremes($series);
    $allocation = ai_report_allocation($latestPositions);

    $payload = [
        'snapshots_count' => count($snapshots),
        'date_range' => [
            'from' => (string) $snapshots[0]['snapshot_date'],
            'to' => (string) $latestSnapshot['snapshot_date'],
        ],
        'series' => $series,
        'range_summary' => [
            'first_snapshot_date' => (string) $snapshots[0]['snapshot_date'],
            'latest_snapshot_date' => (string) $latestSnapshot['snapshot_date'],
            'first_market_value' => (float) $snapshots[0]['total_market_value'],
            'latest_market_value' => (float) $latestSnapshot['total_market_value'],
            'first_cost_basis' => (float) $snapshots[0]['total_cost_basis'],
            'latest_cost_basis' => (float) $latestSnapshot['total_cost_basis'],
            'first_unrealized_pnl' => (float) $snapshots[0]['total_unrealized_pnl'],
            'latest_unrealized_pnl' => (float) $latestSnapshot['total_unrealized_pnl'],
            'delta_market_value' => round((float) $latestSnapshot['total_market_value'] - (float) $snapshots[0]['total_market_value'], 2),
            'delta_unrealized_pnl' => round((float) $latestSnapshot['total_unrealized_pnl'] - (float) $snapshots[0]['total_unrealized_pnl'], 2),
            'min_market_value' => $extremes['min_market_value'],
            'max_market_value' => $extremes['max_market_value'],
            'min_unrealized_pnl' => $extremes['min_unrealized_pnl'],
            'max_unrealized_pnl' => $extremes['max_unrealized_pnl'],
        ],
        'latest_allocation' => $allocation,
        'latest_positions' => ai_report_normalize_positions($latestPositions),
        'first_to_latest_position_changes' => $positionChanges,
    ];

    return [
        'hash' => sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'payload' => $payload,
    ];
}

function ai_report_build_changes_payload(PDO $pdo): array
{
    $snapshots = $pdo->query(
        'SELECT id, snapshot_date, total_market_value, total_cost_basis, total_unrealized_pnl, created_at
         FROM pf_snapshots
         ORDER BY snapshot_date DESC, id DESC
         LIMIT 2'
    )->fetchAll();

    if (count($snapshots) < 2) {
        throw new RuntimeException('Servono almeno 2 snapshot per analizzare cosa e cambiato.');
    }

    return ai_report_build_changes_payload_from_snapshots($pdo, $snapshots[1], $snapshots[0], 'daily');
}

function ai_report_weekly_date_range(?DateTimeImmutable $today = null): array
{
    $today ??= new DateTimeImmutable('today');
    if ($today->format('N') !== '6') {
        throw new RuntimeException('Il confronto settimanale e disponibile esclusivamente il sabato.');
    }

    return [
        'from' => $today->modify('-7 days')->format('Y-m-d'),
        'to' => $today->format('Y-m-d'),
    ];
}

function ai_report_build_weekly_changes_payload(PDO $pdo, ?DateTimeImmutable $today = null): array
{
    $dateRange = ai_report_weekly_date_range($today);
    $previousDate = $dateRange['from'];
    $latestDate = $dateRange['to'];
    $stmt = $pdo->prepare(
        'SELECT id, snapshot_date, total_market_value, total_cost_basis, total_unrealized_pnl, created_at
         FROM pf_snapshots
         WHERE snapshot_date IN (:previous_date, :latest_date)
         ORDER BY snapshot_date DESC, id DESC'
    );
    $stmt->execute([
        ':previous_date' => $previousDate,
        ':latest_date' => $latestDate,
    ]);

    $snapshotsByDate = [];
    foreach ($stmt->fetchAll() as $snapshot) {
        $snapshotDate = substr((string) ($snapshot['snapshot_date'] ?? ''), 0, 10);
        if ($snapshotDate !== '' && !isset($snapshotsByDate[$snapshotDate])) {
            $snapshotsByDate[$snapshotDate] = $snapshot;
        }
    }

    if (!isset($snapshotsByDate[$latestDate])) {
        throw new RuntimeException(
            'Carica prima lo snapshot di oggi, sabato ' . ai_report_format_date_it($latestDate) . '.'
        );
    }
    if (!isset($snapshotsByDate[$previousDate])) {
        throw new RuntimeException(
            'Manca lo snapshot del sabato precedente, ' . ai_report_format_date_it($previousDate) . '.'
        );
    }

    return ai_report_build_changes_payload_from_snapshots(
        $pdo,
        $snapshotsByDate[$previousDate],
        $snapshotsByDate[$latestDate],
        'weekly'
    );
}

function ai_report_build_changes_payload_from_snapshots(
    PDO $pdo,
    array $previousSnapshot,
    array $latestSnapshot,
    string $comparisonKind
): array {
    $previousSnapshotId = (int) $previousSnapshot['id'];
    $latestSnapshotId = (int) $latestSnapshot['id'];

    $latestPositions = ai_report_latest_positions($pdo, $latestSnapshotId);

    $positionChanges = ai_report_position_changes($pdo, $previousSnapshotId, $latestSnapshotId, 0);
    $series = ai_report_series([$previousSnapshot, $latestSnapshot]);
    $extremes = ai_report_extremes($series);
    $changeSummary = [
        'positions_total' => count($positionChanges),
        'positions_up' => 0,
        'positions_down' => 0,
        'positions_unchanged' => 0,
        'positions_added' => 0,
        'positions_removed' => 0,
        'quantity_changed' => 0,
    ];

    foreach ($positionChanges as $change) {
        $deltaMarket = (float) ($change['delta_market_value'] ?? 0);
        if ($deltaMarket > 0.005) {
            $changeSummary['positions_up']++;
        } elseif ($deltaMarket < -0.005) {
            $changeSummary['positions_down']++;
        } else {
            $changeSummary['positions_unchanged']++;
        }

        if (($change['status'] ?? '') === 'added') {
            $changeSummary['positions_added']++;
        } elseif (($change['status'] ?? '') === 'removed') {
            $changeSummary['positions_removed']++;
        }

        if (abs((float) ($change['delta_quantity'] ?? 0)) > 0.000001) {
            $changeSummary['quantity_changed']++;
        }
    }

    $payload = [
        'snapshots_count' => 2,
        'comparison_kind' => $comparisonKind === 'weekly' ? 'weekly' : 'daily',
        'date_range' => [
            'from' => (string) $previousSnapshot['snapshot_date'],
            'to' => (string) $latestSnapshot['snapshot_date'],
        ],
        'series' => $series,
        'range_summary' => [
            'first_snapshot_date' => (string) $previousSnapshot['snapshot_date'],
            'latest_snapshot_date' => (string) $latestSnapshot['snapshot_date'],
            'first_market_value' => round((float) $previousSnapshot['total_market_value'], 2),
            'latest_market_value' => round((float) $latestSnapshot['total_market_value'], 2),
            'first_cost_basis' => round((float) $previousSnapshot['total_cost_basis'], 2),
            'latest_cost_basis' => round((float) $latestSnapshot['total_cost_basis'], 2),
            'first_unrealized_pnl' => round((float) $previousSnapshot['total_unrealized_pnl'], 2),
            'latest_unrealized_pnl' => round((float) $latestSnapshot['total_unrealized_pnl'], 2),
            'delta_market_value' => round((float) $latestSnapshot['total_market_value'] - (float) $previousSnapshot['total_market_value'], 2),
            'delta_cost_basis' => round((float) $latestSnapshot['total_cost_basis'] - (float) $previousSnapshot['total_cost_basis'], 2),
            'delta_unrealized_pnl' => round((float) $latestSnapshot['total_unrealized_pnl'] - (float) $previousSnapshot['total_unrealized_pnl'], 2),
            'min_market_value' => $extremes['min_market_value'],
            'max_market_value' => $extremes['max_market_value'],
            'min_unrealized_pnl' => $extremes['min_unrealized_pnl'],
            'max_unrealized_pnl' => $extremes['max_unrealized_pnl'],
        ],
        'change_summary' => $changeSummary,
        'latest_allocation' => ai_report_allocation($latestPositions),
        'latest_positions' => ai_report_normalize_positions($latestPositions),
        'first_to_latest_position_changes' => $positionChanges,
    ];

    return [
        'hash' => sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'payload' => $payload,
    ];
}

function ai_report_series(array $snapshots): array
{
    return array_map(static function (array $snapshot): array {
        $costBasis = (float) $snapshot['total_cost_basis'];
        $pnl = (float) $snapshot['total_unrealized_pnl'];

        return [
            'snapshot_id' => (int) $snapshot['id'],
            'snapshot_date' => (string) $snapshot['snapshot_date'],
            'market_value' => round((float) $snapshot['total_market_value'], 2),
            'cost_basis' => round($costBasis, 2),
            'unrealized_pnl' => round($pnl, 2),
            'unrealized_pnl_pct' => $costBasis > 0 ? round(($pnl / $costBasis) * 100, 4) : 0.0,
        ];
    }, $snapshots);
}

function ai_report_extremes(array $series): array
{
    $minMarket = null;
    $maxMarket = null;
    $minPnl = null;
    $maxPnl = null;

    foreach ($series as $row) {
        if ($minMarket === null || $row['market_value'] < $minMarket['value']) {
            $minMarket = ['date' => $row['snapshot_date'], 'value' => $row['market_value']];
        }
        if ($maxMarket === null || $row['market_value'] > $maxMarket['value']) {
            $maxMarket = ['date' => $row['snapshot_date'], 'value' => $row['market_value']];
        }
        if ($minPnl === null || $row['unrealized_pnl'] < $minPnl['value']) {
            $minPnl = ['date' => $row['snapshot_date'], 'value' => $row['unrealized_pnl']];
        }
        if ($maxPnl === null || $row['unrealized_pnl'] > $maxPnl['value']) {
            $maxPnl = ['date' => $row['snapshot_date'], 'value' => $row['unrealized_pnl']];
        }
    }

    return [
        'min_market_value' => $minMarket,
        'max_market_value' => $maxMarket,
        'min_unrealized_pnl' => $minPnl,
        'max_unrealized_pnl' => $maxPnl,
    ];
}

function ai_report_allocation(array $latestPositions): array
{
    $byType = [];
    $byCurrency = [];
    $total = 0.0;

    foreach ($latestPositions as $position) {
        $value = (float) $position['market_value'];
        $total += $value;

        $type = (string) ($position['asset_type'] ?: 'N/D');
        $currency = (string) ($position['currency'] ?: 'N/D');
        $byType[$type] = ($byType[$type] ?? 0.0) + $value;
        $byCurrency[$currency] = ($byCurrency[$currency] ?? 0.0) + $value;
    }

    $normalize = static function (array $values) use ($total): array {
        arsort($values);
        $out = [];
        foreach ($values as $label => $value) {
            $out[] = [
                'label' => $label,
                'market_value' => round((float) $value, 2),
                'weight_pct' => $total > 0 ? round(((float) $value / $total) * 100, 4) : 0.0,
            ];
        }
        return $out;
    };

    return [
        'by_asset_type' => $normalize($byType),
        'by_currency' => $normalize($byCurrency),
    ];
}

function ai_report_normalize_positions(array $positions): array
{
    return array_map(static function (array $position): array {
        $costBasis = (float) $position['cost_basis'];
        $pnl = (float) $position['unrealized_pnl'];

        return [
            'name' => (string) $position['name'],
            'isin' => (string) ($position['isin'] ?? ''),
            'ticker' => (string) ($position['ticker'] ?? ''),
            'asset_type' => (string) $position['asset_type'],
            'currency' => (string) $position['currency'],
            'quantity' => round((float) $position['quantity'], 6),
            'market_value' => round((float) $position['market_value'], 2),
            'cost_basis' => round($costBasis, 2),
            'unrealized_pnl' => round($pnl, 2),
            'unrealized_pnl_pct' => $costBasis > 0 ? round(($pnl / $costBasis) * 100, 4) : 0.0,
            'weight_pct' => round(((float) $position['weight_pct']) * 100, 4),
        ];
    }, $positions);
}

function ai_report_position_changes(PDO $pdo, int $firstSnapshotId, int $latestSnapshotId, int $limit = 12): array
{
    $stmt = $pdo->prepare(
        'SELECT a.name, a.isin, a.ticker, a.asset_type,
                CASE WHEN p_first.asset_id IS NULL THEN 0 ELSE 1 END AS present_first,
                CASE WHEN p_latest.asset_id IS NULL THEN 0 ELSE 1 END AS present_latest,
                COALESCE(p_first.quantity, 0) AS first_quantity,
                COALESCE(p_latest.quantity, 0) AS latest_quantity,
                COALESCE(p_first.current_price, 0) AS first_price,
                COALESCE(p_latest.current_price, 0) AS latest_price,
                COALESCE(p_first.cost_basis, 0) AS first_cost_basis,
                COALESCE(p_latest.cost_basis, 0) AS latest_cost_basis,
                COALESCE(p_first.market_value, 0) AS first_market_value,
                COALESCE(p_latest.market_value, 0) AS latest_market_value,
                COALESCE(p_first.unrealized_pnl, 0) AS first_pnl,
                COALESCE(p_latest.unrealized_pnl, 0) AS latest_pnl,
                COALESCE(p_first.weight_pct, 0) AS first_weight,
                COALESCE(p_latest.weight_pct, 0) AS latest_weight
         FROM (
             SELECT asset_id FROM pf_snapshot_positions WHERE snapshot_id = :first_id
             UNION
             SELECT asset_id FROM pf_snapshot_positions WHERE snapshot_id = :latest_id
         ) ids
         JOIN pf_assets a ON a.id = ids.asset_id
         LEFT JOIN (SELECT asset_id, SUM(quantity) AS quantity, CASE WHEN SUM(quantity) <> 0 THEN SUM(current_price * quantity) / SUM(quantity) ELSE 0 END AS current_price, SUM(cost_basis) AS cost_basis, SUM(market_value) AS market_value, SUM(unrealized_pnl) AS unrealized_pnl, SUM(market_value) / NULLIF((SELECT total_market_value FROM pf_snapshots WHERE id=:first_total),0) AS weight_pct FROM pf_snapshot_positions WHERE snapshot_id=:first_pos GROUP BY asset_id) p_first ON p_first.asset_id = ids.asset_id
         LEFT JOIN (SELECT asset_id, SUM(quantity) AS quantity, CASE WHEN SUM(quantity) <> 0 THEN SUM(current_price * quantity) / SUM(quantity) ELSE 0 END AS current_price, SUM(cost_basis) AS cost_basis, SUM(market_value) AS market_value, SUM(unrealized_pnl) AS unrealized_pnl, SUM(market_value) / NULLIF((SELECT total_market_value FROM pf_snapshots WHERE id=:latest_total),0) AS weight_pct FROM pf_snapshot_positions WHERE snapshot_id=:latest_pos GROUP BY asset_id) p_latest ON p_latest.asset_id = ids.asset_id'
    );
    $stmt->execute([
        ':first_id' => $firstSnapshotId,
        ':latest_id' => $latestSnapshotId,
        ':first_pos' => $firstSnapshotId,
        ':latest_pos' => $latestSnapshotId,
        ':first_total' => $firstSnapshotId,
        ':latest_total' => $latestSnapshotId,
    ]);

    $changes = [];
    foreach ($stmt->fetchAll() as $row) {
        $presentFirst = (int) $row['present_first'] === 1;
        $presentLatest = (int) $row['present_latest'] === 1;
        $status = !$presentFirst ? 'added' : (!$presentLatest ? 'removed' : 'retained');
        $changes[] = [
            'name' => (string) $row['name'],
            'isin' => (string) ($row['isin'] ?? ''),
            'ticker' => (string) ($row['ticker'] ?? ''),
            'asset_type' => (string) $row['asset_type'],
            'status' => $status,
            'first_quantity' => round((float) $row['first_quantity'], 6),
            'latest_quantity' => round((float) $row['latest_quantity'], 6),
            'delta_quantity' => round((float) $row['latest_quantity'] - (float) $row['first_quantity'], 6),
            'first_price' => round((float) $row['first_price'], 4),
            'latest_price' => round((float) $row['latest_price'], 4),
            'delta_price' => round((float) $row['latest_price'] - (float) $row['first_price'], 4),
            'first_cost_basis' => round((float) $row['first_cost_basis'], 2),
            'latest_cost_basis' => round((float) $row['latest_cost_basis'], 2),
            'delta_cost_basis' => round((float) $row['latest_cost_basis'] - (float) $row['first_cost_basis'], 2),
            'first_market_value' => round((float) $row['first_market_value'], 2),
            'latest_market_value' => round((float) $row['latest_market_value'], 2),
            'delta_market_value' => round((float) $row['latest_market_value'] - (float) $row['first_market_value'], 2),
            'first_pnl' => round((float) $row['first_pnl'], 2),
            'latest_pnl' => round((float) $row['latest_pnl'], 2),
            'delta_pnl' => round((float) $row['latest_pnl'] - (float) $row['first_pnl'], 2),
            'first_weight_pct' => round(((float) $row['first_weight']) * 100, 4),
            'latest_weight_pct' => round(((float) $row['latest_weight']) * 100, 4),
            'delta_weight_pct' => round(((float) $row['latest_weight'] - (float) $row['first_weight']) * 100, 4),
        ];
    }

    usort($changes, static fn(array $a, array $b): int => abs($b['delta_market_value']) <=> abs($a['delta_market_value']));

    return $limit > 0 ? array_slice($changes, 0, $limit) : $changes;
}

function ai_report_latest_positions(PDO $pdo, int $snapshotId): array
{
    $statement = $pdo->prepare(
        'SELECT a.name, a.isin, a.ticker, a.asset_type, a.currency,
                SUM(p.quantity) AS quantity, SUM(p.cost_basis) AS cost_basis,
                SUM(p.market_value) AS market_value, SUM(p.unrealized_pnl) AS unrealized_pnl,
                SUM(p.market_value) / NULLIF(s.total_market_value, 0) AS weight_pct
         FROM pf_snapshot_positions p
         JOIN pf_assets a ON a.id=p.asset_id
         JOIN pf_snapshots s ON s.id=p.snapshot_id
         WHERE p.snapshot_id=:snapshot_id
         GROUP BY a.id,a.name,a.isin,a.ticker,a.asset_type,a.currency,s.total_market_value
         ORDER BY market_value DESC'
    );
    $statement->execute([':snapshot_id' => $snapshotId]);
    return $statement->fetchAll();
}

function ai_report_generate_with_gemini(array $historyPayload): array
{
    $payloadJson = ai_report_encode_payload($historyPayload);
    $verifiedFacts = ai_report_build_verified_facts($historyPayload);

    return ai_report_generate_request(
        ai_report_system_instruction(),
        ai_report_build_prompt($payloadJson, $verifiedFacts),
        'general',
        $historyPayload,
        $verifiedFacts
    );
}

function ai_report_generate_custom_with_gemini(array $historyPayload, string $customPrompt): array
{
    $payloadJson = ai_report_encode_payload($historyPayload);
    $verifiedFacts = ai_report_build_verified_facts($historyPayload);

    return ai_report_generate_request(
        ai_report_custom_system_instruction(),
        ai_report_build_custom_prompt($payloadJson, $customPrompt, $verifiedFacts),
        'custom',
        $historyPayload,
        $verifiedFacts
    );
}

function ai_report_generate_changes_with_gemini(array $historyPayload): array
{
    $payloadJson = ai_report_encode_payload($historyPayload);
    $verifiedFacts = ai_report_build_changes_verified_facts($historyPayload);

    return ai_report_generate_request(
        ai_report_changes_system_instruction(),
        ai_report_build_changes_prompt($payloadJson, $verifiedFacts),
        'changes',
        $historyPayload,
        $verifiedFacts
    );
}

function ai_report_generate_weekly_changes_with_gemini(array $historyPayload): array
{
    $payloadJson = ai_report_encode_payload($historyPayload);
    $verifiedFacts = ai_report_build_changes_verified_facts($historyPayload);

    return ai_report_generate_request(
        ai_report_changes_system_instruction('weekly'),
        ai_report_build_changes_prompt($payloadJson, $verifiedFacts, 'weekly'),
        'changes',
        $historyPayload,
        $verifiedFacts
    );
}

function ai_report_account_metric_definitions(): array
{
    return [
        'fineco_total_assets' => 'Patrimonio totale Fineco',
        'net_assets_adjusted' => 'Patrimonio netto rettificato',
        'consolidated_portfolio_value' => 'Portafoglio totale Fineco',
        'liquidity_total' => 'Totale liquidita Fineco',
        'own_liquidity_adjusted' => 'Liquidita propria rettificata',
        'reconstructed_gross' => 'Patrimonio ricostruito',
        'accounting_balance' => 'Saldo contabile',
        'available_balance' => 'Saldo disponibile',
        'credit_used_amount' => 'Fido utilizzato',
        'credit_available_amount' => 'Credito disponibile',
    ];
}

function ai_report_account_snapshot_rows(PDO $pdo): array
{
    if (!account_tracker_schema_ready($pdo)) {
        throw new RuntimeException('Il modulo Conto non e inizializzato.');
    }

    $rows = $pdo->query(
        'SELECT p.*, s.total_market_value AS consolidated_portfolio_value
         FROM pf_patrimonial_snapshots p
         LEFT JOIN pf_snapshots s ON s.snapshot_date = p.snapshot_date
         ORDER BY p.snapshot_date ASC, p.id ASC'
    )->fetchAll();

    return array_map(static function (array $row): array {
        $positionPortfolio = $row['consolidated_portfolio_value'] !== null
            ? (float) $row['consolidated_portfolio_value']
            : null;
        $portfolioValue = account_tracker_snapshot_decimal($row, 'fineco_portfolio_total') ?? $positionPortfolio;
        $creditAvailable = account_tracker_snapshot_decimal($row, 'credit_available_amount');
        $creditUsed = $row['credit_used_amount'] !== null
            ? (float) $row['credit_used_amount']
            : ($row['credit_agreed_amount'] !== null && $creditAvailable !== null ? (float) $row['credit_agreed_amount'] - $creditAvailable : null);
        $adjustments = account_tracker_patrimonial_adjustments([
            'fineco_total_assets' => account_tracker_snapshot_decimal($row, 'fineco_total_assets'),
            'liquidity_total' => account_tracker_snapshot_decimal($row, 'liquidity_total'),
            'credit_available_amount' => $creditAvailable,
        ]);
        $wealth = account_tracker_reconstructed_wealth($portfolioValue, $adjustments['own_liquidity_adjusted'], null);

        return [
            'id' => (int) $row['id'],
            'snapshot_date' => (string) $row['snapshot_date'],
            'fineco_total_assets' => account_tracker_snapshot_decimal($row, 'fineco_total_assets'),
            'net_assets_adjusted' => $adjustments['net_assets_adjusted'],
            'consolidated_portfolio_value' => $portfolioValue,
            'liquidity_total' => account_tracker_snapshot_decimal($row, 'liquidity_total'),
            'own_liquidity_adjusted' => $adjustments['own_liquidity_adjusted'],
            'reconstructed_gross' => $wealth['gross'],
            'accounting_balance' => $row['accounting_balance'] !== null ? (float) $row['accounting_balance'] : null,
            'available_balance' => $row['available_balance'] !== null ? (float) $row['available_balance'] : null,
            'credit_used_amount' => $creditUsed,
            'credit_available_amount' => $row['credit_available_amount'] !== null ? (float) $row['credit_available_amount'] : null,
            'data_origin' => (string) ($row['data_origin'] ?? ''),
        ];
    }, $rows);
}

function ai_report_account_metric_summary(array $rows, string $field): array
{
    $available = array_values(array_filter($rows, static fn(array $row): bool => ($row[$field] ?? null) !== null));
    $firstRow = $rows[0] ?? null;
    $latestRow = $rows === [] ? null : $rows[count($rows) - 1];
    $first = is_array($firstRow) && ($firstRow[$field] ?? null) !== null ? $firstRow : null;
    $latest = is_array($latestRow) && ($latestRow[$field] ?? null) !== null ? $latestRow : null;
    $min = null;
    $max = null;

    foreach ($available as $row) {
        $value = (float) ($row[$field] ?? 0);
        if ($min === null || $value < $min['value']) {
            $min = ['date' => (string) $row['snapshot_date'], 'value' => round($value, 2)];
        }
        if ($max === null || $value > $max['value']) {
            $max = ['date' => (string) $row['snapshot_date'], 'value' => round($value, 2)];
        }
    }

    $firstValue = $first === null ? null : round((float) ($first[$field] ?? 0), 2);
    $latestValue = $latest === null ? null : round((float) ($latest[$field] ?? 0), 2);

    return [
        'first' => $firstValue,
        'latest' => $latestValue,
        'delta' => $firstValue !== null && $latestValue !== null ? round($latestValue - $firstValue, 2) : null,
        'min' => $min,
        'max' => $max,
    ];
}

function ai_report_build_account_payload_from_rows(array $rows, string $comparisonKind = 'general'): array
{
    if (count($rows) < 2) {
        throw new RuntimeException('Servono almeno 2 snapshot patrimoniali per generare un report AI.');
    }

    $definitions = ai_report_account_metric_definitions();
    $metrics = [];
    foreach ($definitions as $field => $label) {
        $metrics[$field] = ai_report_account_metric_summary($rows, $field);
    }

    $hasComparableMetric = false;
    foreach ($metrics as $metric) {
        if ($metric['first'] !== null && $metric['latest'] !== null) {
            $hasComparableMetric = true;
            break;
        }
    }
    if (!$hasComparableMetric) {
        throw new RuntimeException('Gli snapshot patrimoniali non contengono ancora valori confrontabili.');
    }

    $first = $rows[0];
    $latest = $rows[count($rows) - 1];
    $payload = [
        'domain' => 'patrimony',
        'comparison_kind' => $comparisonKind,
        'snapshots_count' => count($rows),
        'date_range' => [
            'from' => (string) $first['snapshot_date'],
            'to' => (string) $latest['snapshot_date'],
        ],
        'metric_labels' => $definitions,
        'metrics' => $metrics,
        'series' => $rows,
    ];

    return [
        'hash' => sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'payload' => $payload,
    ];
}

function ai_report_build_account_payload(PDO $pdo): array
{
    return ai_report_build_account_payload_from_rows(ai_report_account_snapshot_rows($pdo));
}

function ai_report_build_account_changes_payload(PDO $pdo): array
{
    $rows = ai_report_account_snapshot_rows($pdo);
    if (count($rows) < 2) {
        throw new RuntimeException('Servono almeno 2 snapshot patrimoniali per analizzare cosa e cambiato.');
    }

    return ai_report_build_account_payload_from_rows(array_slice($rows, -2), 'daily');
}

function ai_report_build_account_weekly_changes_payload(PDO $pdo, ?DateTimeImmutable $today = null): array
{
    $dateRange = ai_report_weekly_date_range($today);
    $rowsByDate = [];
    foreach (ai_report_account_snapshot_rows($pdo) as $row) {
        $rowsByDate[(string) $row['snapshot_date']] = $row;
    }

    if (!isset($rowsByDate[$dateRange['to']])) {
        throw new RuntimeException('Carica prima lo snapshot patrimoniale di oggi, sabato ' . ai_report_format_date_it($dateRange['to']) . '.');
    }
    if (!isset($rowsByDate[$dateRange['from']])) {
        throw new RuntimeException('Manca lo snapshot patrimoniale del sabato precedente, ' . ai_report_format_date_it($dateRange['from']) . '.');
    }

    return ai_report_build_account_payload_from_rows([
        $rowsByDate[$dateRange['from']],
        $rowsByDate[$dateRange['to']],
    ], 'weekly');
}

function ai_report_build_account_verified_facts(array $historyPayload): string
{
    $dateRange = is_array($historyPayload['date_range'] ?? null) ? $historyPayload['date_range'] : [];
    $labels = is_array($historyPayload['metric_labels'] ?? null) ? $historyPayload['metric_labels'] : [];
    $metrics = is_array($historyPayload['metrics'] ?? null) ? $historyPayload['metrics'] : [];
    $comparisonKind = (string) ($historyPayload['comparison_kind'] ?? 'general');
    $isComparison = in_array($comparisonKind, ['daily', 'weekly'], true);
    $lines = [
        'FATTI PATRIMONIALI VERIFICATI DAL CODICE PHP - COPIARE ESATTAMENTE LE CIFRE',
        '- Snapshot analizzati: ' . (int) ($historyPayload['snapshots_count'] ?? 0),
        '- Periodo: ' . ai_report_format_date_it((string) ($dateRange['from'] ?? ''))
            . ' - ' . ai_report_format_date_it((string) ($dateRange['to'] ?? '')),
    ];

    if ($comparisonKind === 'weekly') {
        $lines[] = '- Tipo di confronto: settimanale, da sabato a sabato.';
        $lines[] = '- Intervallo di calendario: 7 giorni.';
    }

    foreach ($labels as $field => $label) {
        $metric = is_array($metrics[$field] ?? null) ? $metrics[$field] : [];
        if ($isComparison && (($metric['first'] ?? null) === null || ($metric['latest'] ?? null) === null)) {
            continue;
        }
        if (($metric['first'] ?? null) !== null) {
            $lines[] = '- ' . $label . ' iniziale: EUR ' . ai_report_format_number_it((float) $metric['first']);
        }
        if (($metric['latest'] ?? null) !== null) {
            $lines[] = '- ' . $label . ' finale: EUR ' . ai_report_format_number_it((float) $metric['latest']);
        }
        if (($metric['delta'] ?? null) !== null) {
            $lines[] = '- Delta ' . strtolower((string) $label) . ': EUR ' . ai_report_format_number_it((float) $metric['delta'], 2, true);
        }
        foreach ($isComparison ? [] : ['min' => 'Minimo', 'max' => 'Massimo'] as $key => $prefix) {
            $extreme = is_array($metric[$key] ?? null) ? $metric[$key] : null;
            if ($extreme !== null) {
                $lines[] = '- ' . $prefix . ' ' . strtolower((string) $label) . ': EUR '
                    . ai_report_format_number_it((float) ($extreme['value'] ?? 0))
                    . ' il ' . ai_report_format_date_it((string) ($extreme['date'] ?? ''));
            }
        }
    }

    $lines[] = '';
    $lines[] = 'SEMANTICA VINCOLANTE';
    $lines[] = '- Patrimonio totale Fineco e il valore riportato dall\'export patrimoniale Fineco e puo includere credito disponibile.';
    $lines[] = '- Patrimonio netto rettificato, quando presente, e Patrimonio totale Fineco meno credito disponibile.';
    $lines[] = '- Liquidita propria rettificata, quando presente, e Totale liquidita Fineco meno credito disponibile.';
    $lines[] = '- Patrimonio ricostruito somma soltanto Portafoglio totale Fineco e Liquidita propria rettificata, quando entrambi disponibili.';
    $lines[] = '- Fido utilizzato e credito disponibile restano separati dal patrimonio e non devono essere sommati al patrimonio.';
    $lines[] = '- Saldo disponibile puo includere credito e non equivale automaticamente a patrimonio o liquidita propria.';

    return implode("\n", $lines);
}

function ai_report_account_system_instruction(string $mode = 'general'): string
{
    $comparisonRule = $mode === 'weekly'
        ? '- Confronta esclusivamente i due snapshot del sabato, distanti 7 giorni.'
        : ($mode === 'daily'
            ? '- Confronta esclusivamente i due snapshot patrimoniali forniti.'
            : '- Descrivi esclusivamente l\'evoluzione degli snapshot patrimoniali forniti.');

    return <<<PROMPT
Sei un redattore di report descrittivi su patrimonio, liquidita, saldi e linee di credito. Non sei un consulente finanziario e non devi suggerire acquisti, vendite, prestiti o strategie personali.

FONTI E NUMERI
- Il blocco FATTI PATRIMONIALI VERIFICATI DAL CODICE PHP e l'unica fonte autorizzata per cifre, date, importi e variazioni.
- Copia le cifre esattamente come fornite. Non sommare, sottrarre, arrotondare, ricostruire o correggere valori.
- Il JSON serve soltanto come contesto strutturale. Non ricavare nuove cifre dal JSON.
- Se un dato non compare nei fatti verificati, dichiaralo non disponibile oppure omettilo.

SEMANTICA OBBLIGATORIA
- Il credito disponibile e una linea di credito, non e patrimonio e non va mai sommato al patrimonio.
- Patrimonio totale Fineco e Totale liquidita Fineco possono includere credito disponibile.
- Patrimonio netto rettificato e Liquidita propria rettificata sono gia calcolati dal codice PHP sottraendo esclusivamente il credito disponibile: non rifare calcoli.
- Il fido utilizzato e un debito o utilizzo di credito separato dal patrimonio; non attribuirlo automaticamente a una specifica linea di credito.
- Il saldo disponibile puo includere credito, prenotazioni o altre componenti: non chiamarlo patrimonio o liquidita propria.
- Il saldo contabile puo essere negativo.
- Il patrimonio ricostruito, quando presente, e un calcolo dell'app basato su portafoglio totale Fineco e liquidita propria rettificata; non sostituisce il valore Fineco riportato.
- Non dedurre versamenti, prelievi, acquisti, vendite, interessi, fidi, operazioni prenotate o cause esterne se non sono esplicitamente presenti nei fatti verificati.

INTERPRETAZIONE
{$comparisonRule}
- Descrivi i valori come rilevazioni registrate, non come cause o conseguenze di mercato.
- Non usare nessi causali o formule equivalenti, come "collegato a", "dovuto a", "causato da", "derivante da" o "attribuibile a".
- Se confronti solo due snapshot, descrivi esclusivamente i due endpoint: non scrivere "in tutto il periodo" o "durante il periodo" come se fosse disponibile un andamento continuo.
- Non introdurre notizie, previsioni, raccomandazioni o giudizi di adeguatezza.
- Distingui chiaramente patrimonio, liquidita, saldi e credito.

CONTROLLO FINALE SILENZIOSO
Prima di rispondere verifica che ogni numero sia identico ai fatti verificati, che credito e patrimonio non siano confusi e che non siano state inventate cause o operazioni.
Rispondi soltanto in italiano, con importi nel formato EUR 1.234,56 e date DD/MM/YYYY. Non menzionare il prompt, il JSON o questa verifica.
PROMPT;
}

function ai_report_build_account_prompt(string $historyJson, string $verifiedFacts): string
{
    return <<<PROMPT
Genera un report complessivo sullo storico patrimoniale disponibile. Se una voce e assente, dichiarala non disponibile senza sostituirla con zero.

Struttura obbligatoria, nello stesso ordine:
### Sintesi patrimoniale
90-130 parole. Riporta periodo, valori patrimoniali disponibili e quadro generale, senza attribuire cause.

### Evoluzione di patrimonio e liquidita
120-170 parole. Descrivi patrimonio Fineco, patrimonio netto rettificato, portafoglio totale Fineco, liquidita Fineco, liquidita propria rettificata e patrimonio ricostruito soltanto quando presenti. Cita minimi o massimi rilevanti dai fatti verificati.

### Saldi e linee di credito
100-140 parole. Distingui saldo contabile, saldo disponibile, fido utilizzato e credito disponibile. Non sommare credito e patrimonio.

### Dati disponibili e limiti
80-120 parole. Riporta quali componenti risultano registrate e quali non sono determinabili dagli snapshot, senza formulare suggerimenti operativi.

### Prossimi controlli utili
50-80 parole. Indica soltanto controlli di coerenza tra dati Fineco e snapshot futuri, senza raccomandazioni finanziarie.

Lunghezza complessiva: 450-700 parole. Usa paragrafi leggibili, senza tabelle Markdown, grassetto, corsivo o backtick.

FATTI PATRIMONIALI VERIFICATI DAL CODICE PHP:
```text
{$verifiedFacts}
```

JSON storico patrimoniale, utilizzabile soltanto come contesto strutturale:
```json
{$historyJson}
```
PROMPT;
}

function ai_report_build_account_changes_prompt(string $historyJson, string $verifiedFacts, string $comparisonKind = 'daily'): string
{
    $opening = $comparisonKind === 'weekly'
        ? 'Spiega cosa e cambiato nel patrimonio tra i due snapshot del sabato.'
        : 'Spiega cosa e cambiato nel patrimonio tra gli ultimi due snapshot.';
    $periodRule = $comparisonKind === 'weekly'
        ? 'Specifica che il confronto copre 7 giorni, da sabato a sabato.'
        : 'Cita le due date esatte del confronto.';

    return <<<PROMPT
{$opening}
{$periodRule}

Struttura obbligatoria, nello stesso ordine:
### In sintesi
Massimo 60 parole. Descrivi il quadro senza attribuire cause.

### Tabella patrimoniale
Scrivi una sola frase introduttiva, poi una tabella Markdown con una riga per ciascuna voce presente nei FATTI VERIFICATI: Patrimonio totale Fineco, Patrimonio netto rettificato, Portafoglio totale Fineco, Totale liquidita Fineco, Liquidita propria rettificata, Patrimonio ricostruito, Saldo contabile, Saldo disponibile, Fido utilizzato e Credito disponibile. Ometti soltanto le voci non disponibili in entrambi gli snapshot.
| Voce | Snapshot precedente | Snapshot piu recente | Delta |
|---|---:|---:|---:|

### Lettura dei dati
80-130 parole. Distingui esplicitamente patrimonio, liquidita, saldi e credito. Non dedurre cause, operazioni o movimenti non documentati.

### Prossimo confronto
Una sola frase neutra, senza raccomandazioni.

Lunghezza complessiva: 120-240 parole. Privilegia la tabella e frasi brevi. Non usare grassetto, corsivo o backtick.

FATTI PATRIMONIALI VERIFICATI DAL CODICE PHP:
```text
{$verifiedFacts}
```

JSON dei soli snapshot patrimoniali confrontati, utilizzabile soltanto come contesto strutturale:
```json
{$historyJson}
```
PROMPT;
}

function ai_report_generate_account_with_gemini(array $historyPayload): array
{
    $payloadJson = ai_report_encode_payload($historyPayload);
    $verifiedFacts = ai_report_build_account_verified_facts($historyPayload);
    return ai_report_generate_request(
        ai_report_account_system_instruction(),
        ai_report_build_account_prompt($payloadJson, $verifiedFacts),
        'account_general',
        $historyPayload,
        $verifiedFacts
    );
}

function ai_report_generate_account_changes_with_gemini(array $historyPayload, string $comparisonKind = 'daily'): array
{
    $payloadJson = ai_report_encode_payload($historyPayload);
    $verifiedFacts = ai_report_build_account_verified_facts($historyPayload);
    $type = $comparisonKind === 'weekly' ? 'account_weekly_changes' : 'account_changes';
    return ai_report_generate_request(
        ai_report_account_system_instruction($comparisonKind),
        ai_report_build_account_changes_prompt($payloadJson, $verifiedFacts, $comparisonKind),
        $type,
        $historyPayload,
        $verifiedFacts
    );
}

function ai_report_encode_payload(array $historyPayload): string
{
    $payloadJson = json_encode($historyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson)) {
        throw new RuntimeException('Impossibile serializzare il payload storico per Gemini.');
    }

    return $payloadJson;
}

function ai_report_generate_request(
    string $systemInstruction,
    string $prompt,
    string $reportType,
    array $historyPayload,
    string $verifiedFacts
): array
{
    $geminiConfig = app_config()['gemini'] ?? [];
    $apiKey = (string) ($geminiConfig['api_key'] ?? '');
    $model = (string) ($geminiConfig['model'] ?? 'gemini-3.7-flash');
    $thinkingLevel = (string) ($geminiConfig['thinking_level'] ?? 'medium');
    $maxOutputTokens = (int) ($geminiConfig['max_output_tokens'] ?? 20000);
    $timeoutSeconds = (int) ($geminiConfig['timeout_seconds'] ?? 90);

    if ($apiKey === '' || str_contains($apiKey, 'INSERISCI_CHIAVE')) {
        throw new RuntimeException('Chiave Gemini non configurata.');
    }

    $responses = [];
    $response = ai_report_gemini_request(
        $apiKey,
        $model,
        $systemInstruction,
        $prompt,
        $thinkingLevel,
        $maxOutputTokens,
        $timeoutSeconds
    );
    $responses[] = $response;
    $text = ai_report_extract_text($response['decoded']);

    if ($text === '') {
        throw new RuntimeException('Gemini non ha restituito testo utile.');
    }

    try {
        ai_report_validate_response($text, (string) ($response['finish_reason'] ?? ''), $reportType, $historyPayload);
    } catch (RuntimeException $validationError) {
        if ((string) ($response['finish_reason'] ?? '') === 'MAX_TOKENS') {
            throw $validationError;
        }

        $response = ai_report_gemini_request(
            $apiKey,
            $model,
            $systemInstruction,
            ai_report_build_repair_prompt($prompt, $text, $validationError->getMessage(), $verifiedFacts, $reportType),
            $thinkingLevel,
            $maxOutputTokens,
            $timeoutSeconds
        );
        $responses[] = $response;
        $text = ai_report_extract_text($response['decoded']);

        if ($text === '') {
            throw new RuntimeException('Gemini non ha restituito testo utile nel tentativo correttivo.');
        }

        try {
            ai_report_validate_response($text, (string) ($response['finish_reason'] ?? ''), $reportType, $historyPayload);
        } catch (RuntimeException $repairError) {
            throw new RuntimeException(
                'Anche il tentativo correttivo di Gemini non ha superato i controlli: ' . $repairError->getMessage()
            );
        }
    }

    $usage = ai_report_sum_usage($responses);

    return [
        'provider' => 'gemini',
        'model' => $model,
        'thinking_level' => $thinkingLevel,
        'prompt_version' => ai_report_prompt_version(),
        'generated_at' => date('c'),
        'http_code' => $response['http_code'],
        'finish_reason' => (string) ($response['finish_reason'] ?? ''),
        'generation_attempts' => count($responses),
        'prompt_tokens' => $usage['prompt_tokens'],
        'output_tokens' => $usage['output_tokens'],
        'thinking_tokens' => $usage['thinking_tokens'],
        'total_tokens' => $usage['total_tokens'],
        'report_markdown' => $text,
    ];
}

function ai_report_validate_response(string $text, string $finishReason, string $reportType, array $historyPayload): void
{
    if ($reportType === 'account_general') {
        ai_report_validate_account_generated_text($text, $finishReason, $historyPayload);
    } elseif (in_array($reportType, ['account_changes', 'account_weekly_changes'], true)) {
        ai_report_validate_account_changes_text($text, $finishReason, $historyPayload);
    } elseif ($reportType === 'custom') {
        ai_report_validate_custom_text($text, $finishReason);
    } elseif ($reportType === 'changes') {
        ai_report_validate_changes_text($text, $finishReason, $historyPayload);
    } else {
        ai_report_validate_generated_text($text, $finishReason, $historyPayload);
    }

    $verifiedFacts = match ($reportType) {
        'changes' => ai_report_build_changes_verified_facts($historyPayload),
        'account_general', 'account_changes', 'account_weekly_changes' => ai_report_build_account_verified_facts($historyPayload),
        default => ai_report_build_verified_facts($historyPayload),
    };
    ai_report_validate_numeric_tokens($text, $verifiedFacts);
}

function ai_report_sum_usage(array $responses): array
{
    $usage = [
        'prompt_tokens' => 0,
        'output_tokens' => 0,
        'thinking_tokens' => 0,
        'total_tokens' => 0,
    ];

    foreach ($responses as $response) {
        foreach (array_keys($usage) as $key) {
            $usage[$key] += (int) ($response[$key] ?? 0);
        }
    }

    return $usage;
}

function ai_report_format_number_it(float $value, int $decimals = 2, bool $forceSign = false): string
{
    $formatted = number_format($value, $decimals, ',', '.');
    if ($forceSign && $value > 0) {
        return '+' . $formatted;
    }

    return $formatted;
}

function ai_report_format_date_it(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
    return $parsed instanceof DateTimeImmutable ? $parsed->format('d/m/Y') : $date;
}

function ai_report_build_verified_facts(array $historyPayload): string
{
    $range = is_array($historyPayload['range_summary'] ?? null) ? $historyPayload['range_summary'] : [];
    $dateRange = is_array($historyPayload['date_range'] ?? null) ? $historyPayload['date_range'] : [];
    $lines = [
        'FATTI VERIFICATI DAL CODICE PHP - COPIARE ESATTAMENTE LE CIFRE',
        '- Snapshot analizzati: ' . (int) ($historyPayload['snapshots_count'] ?? 0),
        '- Periodo: ' . ai_report_format_date_it((string) ($dateRange['from'] ?? ''))
            . ' - ' . ai_report_format_date_it((string) ($dateRange['to'] ?? '')),
        '- Valore di mercato iniziale: EUR ' . ai_report_format_number_it((float) ($range['first_market_value'] ?? 0)),
        '- Valore di mercato finale: EUR ' . ai_report_format_number_it((float) ($range['latest_market_value'] ?? 0)),
        '- Delta valore di mercato: EUR ' . ai_report_format_number_it((float) ($range['delta_market_value'] ?? 0), 2, true),
        '- Valore di carico iniziale: EUR ' . ai_report_format_number_it((float) ($range['first_cost_basis'] ?? 0)),
        '- Valore di carico finale: EUR ' . ai_report_format_number_it((float) ($range['latest_cost_basis'] ?? 0)),
        '- P/L non realizzato iniziale: EUR ' . ai_report_format_number_it((float) ($range['first_unrealized_pnl'] ?? 0)),
        '- P/L non realizzato finale: EUR ' . ai_report_format_number_it((float) ($range['latest_unrealized_pnl'] ?? 0)),
        '- Delta P/L non realizzato: EUR ' . ai_report_format_number_it((float) ($range['delta_unrealized_pnl'] ?? 0), 2, true),
    ];

    $extremeLabels = [
        'min_market_value' => 'Minimo valore di mercato',
        'max_market_value' => 'Massimo valore di mercato',
        'min_unrealized_pnl' => 'Minimo P/L non realizzato',
        'max_unrealized_pnl' => 'Massimo P/L non realizzato',
    ];
    foreach ($extremeLabels as $key => $label) {
        $extreme = is_array($range[$key] ?? null) ? $range[$key] : [];
        $lines[] = '- ' . $label . ': EUR '
            . ai_report_format_number_it((float) ($extreme['value'] ?? 0))
            . ' il ' . ai_report_format_date_it((string) ($extreme['date'] ?? ''));
    }

    $lines[] = '';
    $lines[] = 'POSIZIONI CORRENTI VERIFICATE';
    foreach (($historyPayload['latest_positions'] ?? []) as $position) {
        if (!is_array($position)) {
            continue;
        }
        $identity = (string) ($position['name'] ?? 'Strumento');
        $ticker = trim((string) ($position['ticker'] ?? ''));
        if ($ticker !== '') {
            $identity .= ' (' . $ticker . ')';
        }
        $lines[] = '- ' . $identity
            . ': valore EUR ' . ai_report_format_number_it((float) ($position['market_value'] ?? 0))
            . '; carico EUR ' . ai_report_format_number_it((float) ($position['cost_basis'] ?? 0))
            . '; P/L EUR ' . ai_report_format_number_it((float) ($position['unrealized_pnl'] ?? 0))
            . '; P/L ' . ai_report_format_number_it((float) ($position['unrealized_pnl_pct'] ?? 0)) . '%'
            . '; peso ' . ai_report_format_number_it((float) ($position['weight_pct'] ?? 0)) . '%.';
    }

    $lines[] = '';
    $lines[] = 'VARIAZIONI PRIMO-ULTIMO SNAPSHOT VERIFICATE';
    foreach (($historyPayload['first_to_latest_position_changes'] ?? []) as $position) {
        if (!is_array($position)) {
            continue;
        }
        $lines[] = '- ' . (string) ($position['name'] ?? 'Strumento')
            . ': delta valore EUR ' . ai_report_format_number_it((float) ($position['delta_market_value'] ?? 0), 2, true)
            . '; delta P/L EUR ' . ai_report_format_number_it((float) ($position['delta_pnl'] ?? 0), 2, true)
            . '; delta peso ' . ai_report_format_number_it((float) ($position['delta_weight_pct'] ?? 0), 2, true)
            . ' punti percentuali.';
    }

    $allocation = is_array($historyPayload['latest_allocation'] ?? null) ? $historyPayload['latest_allocation'] : [];
    foreach (['by_asset_type' => 'TIPOLOGIE CORRENTI', 'by_currency' => 'VALUTE CORRENTI'] as $key => $title) {
        $lines[] = '';
        $lines[] = $title;
        foreach (($allocation[$key] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $lines[] = '- ' . (string) ($item['label'] ?? 'N/D')
                . ': EUR ' . ai_report_format_number_it((float) ($item['market_value'] ?? 0))
                . '; peso ' . ai_report_format_number_it((float) ($item['weight_pct'] ?? 0)) . '%.';
        }
    }

    return implode("\n", $lines);
}

function ai_report_build_changes_verified_facts(array $historyPayload): string
{
    $range = is_array($historyPayload['range_summary'] ?? null) ? $historyPayload['range_summary'] : [];
    $dateRange = is_array($historyPayload['date_range'] ?? null) ? $historyPayload['date_range'] : [];
    $summary = is_array($historyPayload['change_summary'] ?? null) ? $historyPayload['change_summary'] : [];
    $lines = [
        'FATTI VERIFICATI DAL CODICE PHP - COPIARE ESATTAMENTE LE CIFRE',
        '- Snapshot confrontati: 2',
        '- Snapshot precedente: ' . ai_report_format_date_it((string) ($dateRange['from'] ?? '')),
        '- Snapshot piu recente: ' . ai_report_format_date_it((string) ($dateRange['to'] ?? '')),
        '- Valore di mercato precedente: EUR ' . ai_report_format_number_it((float) ($range['first_market_value'] ?? 0)),
        '- Valore di mercato piu recente: EUR ' . ai_report_format_number_it((float) ($range['latest_market_value'] ?? 0)),
        '- Delta valore di mercato: EUR ' . ai_report_format_number_it((float) ($range['delta_market_value'] ?? 0), 2, true),
        '- Valore di carico precedente: EUR ' . ai_report_format_number_it((float) ($range['first_cost_basis'] ?? 0)),
        '- Valore di carico piu recente: EUR ' . ai_report_format_number_it((float) ($range['latest_cost_basis'] ?? 0)),
        '- Delta valore di carico: EUR ' . ai_report_format_number_it((float) ($range['delta_cost_basis'] ?? 0), 2, true),
        '- P/L non realizzato precedente: EUR ' . ai_report_format_number_it((float) ($range['first_unrealized_pnl'] ?? 0)),
        '- P/L non realizzato piu recente: EUR ' . ai_report_format_number_it((float) ($range['latest_unrealized_pnl'] ?? 0)),
        '- Delta P/L non realizzato: EUR ' . ai_report_format_number_it((float) ($range['delta_unrealized_pnl'] ?? 0), 2, true),
        '- Posizioni confrontate: ' . (int) ($summary['positions_total'] ?? 0),
        '- Posizioni con controvalore aumentato: ' . (int) ($summary['positions_up'] ?? 0),
        '- Posizioni con controvalore diminuito: ' . (int) ($summary['positions_down'] ?? 0),
        '- Posizioni con controvalore invariato: ' . (int) ($summary['positions_unchanged'] ?? 0),
        '- Posizioni nuove: ' . (int) ($summary['positions_added'] ?? 0),
        '- Posizioni non piu presenti: ' . (int) ($summary['positions_removed'] ?? 0),
        '- Posizioni con quantita variata: ' . (int) ($summary['quantity_changed'] ?? 0),
    ];
    if (($historyPayload['comparison_kind'] ?? '') === 'weekly') {
        $lines[] = '- Tipo di confronto: settimanale, da sabato a sabato.';
        $lines[] = '- Intervallo di calendario: 7 giorni.';
    }
    $lines[] = '';
    $lines[] = 'VARIAZIONI PER STRUMENTO VERIFICATE';

    $statusLabels = [
        'added' => 'nuova nello snapshot piu recente',
        'removed' => 'non piu presente nello snapshot piu recente',
        'retained' => 'presente in entrambi gli snapshot',
    ];

    foreach (($historyPayload['first_to_latest_position_changes'] ?? []) as $position) {
        if (!is_array($position)) {
            continue;
        }

        $identity = (string) ($position['name'] ?? 'Strumento');
        $ticker = trim((string) ($position['ticker'] ?? ''));
        if ($ticker !== '') {
            $identity .= ' (' . $ticker . ')';
        }
        $status = (string) ($position['status'] ?? 'retained');

        $lines[] = '- ' . $identity . ' [' . ($statusLabels[$status] ?? $statusLabels['retained']) . ']'
            . ': quantita ' . ai_report_format_number_it((float) ($position['first_quantity'] ?? 0))
            . ' -> ' . ai_report_format_number_it((float) ($position['latest_quantity'] ?? 0))
            . ', delta ' . ai_report_format_number_it((float) ($position['delta_quantity'] ?? 0), 2, true)
            . '; prezzo ' . ai_report_format_number_it((float) ($position['first_price'] ?? 0), 4)
            . ' -> ' . ai_report_format_number_it((float) ($position['latest_price'] ?? 0), 4)
            . ', delta ' . ai_report_format_number_it((float) ($position['delta_price'] ?? 0), 4, true)
            . '; carico EUR ' . ai_report_format_number_it((float) ($position['first_cost_basis'] ?? 0))
            . ' -> EUR ' . ai_report_format_number_it((float) ($position['latest_cost_basis'] ?? 0))
            . ', delta EUR ' . ai_report_format_number_it((float) ($position['delta_cost_basis'] ?? 0), 2, true)
            . '; valore EUR ' . ai_report_format_number_it((float) ($position['first_market_value'] ?? 0))
            . ' -> EUR ' . ai_report_format_number_it((float) ($position['latest_market_value'] ?? 0))
            . ', delta EUR ' . ai_report_format_number_it((float) ($position['delta_market_value'] ?? 0), 2, true)
            . '; P/L EUR ' . ai_report_format_number_it((float) ($position['first_pnl'] ?? 0))
            . ' -> EUR ' . ai_report_format_number_it((float) ($position['latest_pnl'] ?? 0))
            . ', delta EUR ' . ai_report_format_number_it((float) ($position['delta_pnl'] ?? 0), 2, true)
            . '; peso ' . ai_report_format_number_it((float) ($position['first_weight_pct'] ?? 0)) . '%'
            . ' -> ' . ai_report_format_number_it((float) ($position['latest_weight_pct'] ?? 0)) . '%'
            . ', delta ' . ai_report_format_number_it((float) ($position['delta_weight_pct'] ?? 0), 2, true)
            . ' punti percentuali.';
    }

    return implode("\n", $lines);
}

function ai_report_changes_system_instruction(string $comparisonKind = 'daily'): string
{
    $opening = $comparisonKind === 'weekly'
        ? 'Sei un redattore di confronti settimanali tra due snapshot del sabato separati da 7 giorni.'
        : 'Sei un redattore di confronti descrittivi tra due snapshot consecutivi di un portafoglio.';
    $periodRule = $comparisonKind === 'weekly'
        ? '- Confronta esclusivamente lo snapshot del sabato precedente e quello del sabato piu recente, citando le date esatte e specificando che l\'intervallo e di 7 giorni.'
        : '- Confronta esclusivamente lo snapshot precedente e quello piu recente, citando le date esatte.';

    return <<<PROMPT
{$opening} Devi spiegare cosa e cambiato in modo chiaro, accurato e prudente. Non sei un consulente finanziario e non devi suggerire acquisti, vendite o strategie.

FONTI E NUMERI
- Il blocco FATTI VERIFICATI DAL CODICE PHP e l'unica fonte autorizzata per cifre, date, importi, percentuali e variazioni.
- Copia ogni cifra esattamente come fornita. Non calcolare, arrotondare, sommare o correggere alcun valore.
- Il JSON serve soltanto come contesto strutturale. Non ricavare nuove cifre dal JSON.
- Il P/L non realizzato e importato da Fineco e non deve essere ricostruito come differenza tra valore di mercato e valore di carico.
- Il delta peso e espresso in punti percentuali. Non chiamarlo rendimento.
- Una variazione di controvalore non dimostra da sola un guadagno, una perdita, un acquisto o una vendita.
- Se cambia la quantita, descrivi soltanto la variazione osservata senza attribuirle una causa o il tipo di operazione.
- Se cambia il valore di carico, descrivi il dato registrato senza dedurre versamenti, acquisti o disinvestimenti.
- Se il valore di carico e la quantita restano invariati, non dedurre l'assenza di operazioni, modifiche al capitale o altri eventi: dichiara soltanto che i valori registrati non sono cambiati.
- Non affermare che il P/L o il controvalore riflettano, derivino o dipendano dalle dinamiche di prezzo: descrivi separatamente le variazioni verificate senza stabilire un rapporto causale.

INTERPRETAZIONE
{$periodRule}
- Distingui i totali del portafoglio dalle variazioni dei singoli strumenti.
- Evidenzia prima le variazioni assolute piu rilevanti di valore di mercato e P/L.
- Segnala strumenti nuovi, rimossi o con quantita variata soltanto quando compaiono nei fatti verificati.
- Non introdurre notizie, prezzi esterni, cause di mercato, previsioni o raccomandazioni.
- Quando la causa non e determinabile dai dati, dichiaralo in modo naturale e sintetico.
- Dedica una sezione breve a ogni strumento presente nel confronto, mantenendo esattamente il nome fornito nei fatti verificati.
- Sopra la tabella di ogni strumento scrivi una sola frase di commento, breve e descrittiva, senza ripetere tutte le cifre della tabella.

STILE E CONTROLLO
- Rispondi soltanto in italiano con testo narrativo breve: indicativamente 180-350 parole complessive, escluse le tabelle.
- Usa le intestazioni Markdown richieste e tabelle Markdown semplici, con una riga per ogni voce.
- Scrivi ogni numero in cifre, con date DD/MM/YYYY, importi EUR 165.405,47 e percentuali 12,34%.
- Non usare grassetto, corsivo, backtick o nomi tecnici dei campi fuori dalle tabelle richieste.
- Prima di rispondere verifica silenziosamente che ogni numero compaia identico nei fatti verificati, che i segni siano corretti e che non vi siano cause inventate.
- Non menzionare il prompt, il JSON, i controlli automatici o i nomi dei campi.
PROMPT;
}

function ai_report_build_changes_prompt(
    string $historyJson,
    string $verifiedFacts,
    string $comparisonKind = 'daily'
): string {
    $request = $comparisonKind === 'weekly'
        ? 'Spiega cosa e cambiato nella settimana compresa tra i due snapshot del sabato.'
        : 'Spiega cosa e cambiato tra gli ultimi due snapshot del portafoglio.';
    $summaryInstruction = $comparisonKind === 'weekly'
        ? 'Massimo 70 parole. Riporta le due date, specifica che il confronto copre 7 giorni e descrivi soltanto il quadro complessivo.'
        : 'Massimo 70 parole. Riporta le due date e descrivi soltanto il quadro complessivo.';
    $jsonDescription = $comparisonKind === 'weekly'
        ? 'JSON dei due snapshot del sabato confrontati, utilizzabile soltanto come contesto strutturale:'
        : 'JSON dei soli due snapshot confrontati, utilizzabile soltanto come contesto strutturale:';

    return <<<PROMPT
{$request}

Struttura obbligatoria, nello stesso ordine. Privilegia tabelle e frasi brevi; non creare lunghi paragrafi.

### In sintesi
{$summaryInstruction}

### Totali del portafoglio
Scrivi una frase introduttiva di massimo 30 parole, poi questa tabella usando esclusivamente le cifre verificate:
| Voce | Snapshot precedente | Snapshot piu recente | Delta |
|---|---:|---:|---:|
| Valore di mercato | ... | ... | ... |
| Valore di carico | ... | ... | ... |
| P/L non realizzato | ... | ... | ... |

DETTAGLIO OBBLIGATORIO DI OGNI STRUMENTO
Per ogni strumento presente in VARIAZIONI PER STRUMENTO VERIFICATE crea, nell'ordine fornito:
- una intestazione `### NOME STRUMENTO`, usando il nome esatto;
- una sola frase di commento tra 15 e 30 parole, senza inventare cause e senza ripetere tutti i valori;
- una mini-tabella esattamente con questa struttura:
| Voce | Snapshot precedente | Snapshot piu recente | Delta |
|---|---:|---:|---:|
| Valore di mercato | ... | ... | ... |
| P/L non realizzato | ... | ... | ... |
| Peso | ... | ... | ... punti percentuali |
| Quantita | ... | ... | ... |

Includi tutti gli strumenti, ma nessun altro dato numerico. Non inserire prezzo, valore di carico del singolo strumento o percentuale P/L.

### Composizione e movimenti osservati
Scrivi massimo 50 parole e riporta i conteggi verificati di strumenti in aumento, diminuzione, nuovi, non piu presenti e con quantita variata. Non dedurre operazioni non documentate.

### Prossimo confronto
Scrivi una sola frase neutra, senza numeri, previsioni, formule prescrittive o raccomandazioni finanziarie.

FATTI VERIFICATI DAL CODICE PHP:
```text
{$verifiedFacts}
```

{$jsonDescription}
```json
{$historyJson}
```
PROMPT;
}

function ai_report_system_instruction(): string
{
    return <<<PROMPT
Sei un redattore di report descrittivi su dati di portafoglio. Devi produrre un testo accurato, prudente e verificabile. Non sei un consulente finanziario e non devi suggerire acquisti, vendite o strategie personalizzate.

GERARCHIA DELLE FONTI
1. Il blocco FATTI VERIFICATI DAL CODICE PHP e l'unica fonte autorizzata per ogni cifra, importo, percentuale, data e variazione citata nel report.
2. Copia dal blocco verificato le cifre esattamente come sono scritte. Non modificarle, non arrotondarle e non ricalcolarle.
3. Il JSON serve soltanto per osservare la sequenza temporale e contestualizzare i fatti verificati. Non usarlo per produrre nuovi calcoli.
4. Se un dato non compare nel blocco verificato, non quantificarlo. Dichiaralo non determinabile oppure omettilo.

SEMANTICA VINCOLANTE DEI DATI
- valore di mercato: controvalore registrato nello snapshot;
- valore di carico: valore di carico registrato da Fineco;
- P/L non realizzato: valore importato direttamente dal report Fineco. Non definirlo e non calcolarlo mai come differenza tra valore di mercato e valore di carico, perche puo seguire criteri specifici dell'intermediario;
- percentuale P/L: percentuale gia fornita nei dati, da riportare senza ricostruirla;
- peso percentuale: quota del portafoglio gia espressa in percentuale;
- delta peso percentuale: variazione espressa in punti percentuali;
- serie storica: descrive soltanto i totali del portafoglio nel tempo;
- posizioni correnti: descrivono soltanto l'ultimo snapshot;
- variazioni per posizione: confrontano esclusivamente primo e ultimo snapshot e non spiegano cosa sia accaduto tra le due date;
- valuta: classificazione registrata per lo strumento; non equivale automaticamente a una misura completa del rischio di cambio.

REGOLE NUMERICHE OBBLIGATORIE
- Ogni numero deve essere scritto in cifre. Non scrivere mai numeri, importi, percentuali o date in lettere.
- Usa date nel formato DD/MM/YYYY, importi come EUR 165.405,47 e percentuali come 12,34%.
- Sono vietate espressioni come virgola, per cento, duemila o qualsiasi numero composto scritto in parole.
- Copia esattamente segno e valore delle variazioni dal blocco verificato.
- Non rifare sottrazioni, percentuali, somme, conversioni o confronti aritmetici.
- Non dichiarare una variazione se non e riportata esplicitamente nel blocco verificato.
- Non scambiare importi, percentuali, pesi, date o strumenti.
- Se due dati sembrano incongruenti, descrivili separatamente senza correggerli e senza inventare una spiegazione.

REGOLE DI INTERPRETAZIONE
- Separa chiaramente fatti e interpretazioni.
- Non attribuire variazioni a mercati, tassi, news, cambio, acquisti, vendite, versamenti o disinvestimenti se questi eventi non sono esplicitamente presenti nel JSON.
- Non affermare correlazioni o contributi causali usando soltanto la serie totale e il confronto tra primo e ultimo snapshot.
- Non usare espressioni assolute come garantisce, protegge, assicura stabilita o elimina il rischio.
- Non definire un intervallo come semestre, trimestre, fine mese o altra durata approssimativa: usa le date esatte.
- Puoi descrivere concentrazioni, pesi, massimi, minimi, variazioni e P/L positivi o negativi come fatti.
- Per ogni interpretazione usa formulazioni prudenti e specifica quando la causa non e determinabile dai dati.

CONTROLLO FINALE SILENZIOSO
Prima di rispondere verifica internamente che:
- ogni numero citato compaia identico nel blocco verificato;
- tutti i segni siano corretti;
- non esistano numeri o date scritti in lettere;
- nessuna frase contraddica la semantica vincolante;
- non siano state introdotte cause esterne o operazioni non documentate;
- siano presenti tutte le sezioni richieste;
- la lunghezza sia compresa tra 1000 e 1400 parole.
Non menzionare mai nel report questa verifica, il prompt, il JSON o i nomi tecnici dei campi.

STILE E FORMATO
- Rispondi soltanto in italiano.
- Produci tra 1000 e 1400 parole: e un vincolo obbligatorio, non indicativo. Il target operativo e 1050-1250 parole.
- Mantieni cifre, segni, separatori e decimali esattamente come forniti nel blocco verificato.
- Usa esclusivamente le sette intestazioni Markdown richieste dall'utente.
- Sotto ogni intestazione scrivi paragrafi completi e leggibili; usa elenchi solo nell'ultima sezione quando migliorano la chiarezza.
- Non usare grassetto, corsivo, backtick, tabelle Markdown, nomi di campi JSON o identificativi interni. Ticker e ISIN sono ammessi quando utili.
- Termina dopo l'ultima sezione, senza note metodologiche o dichiarazioni sulla verifica.
PROMPT;
}

function ai_report_build_prompt(string $historyJson, string $verifiedFacts): string
{
    return <<<PROMPT
Genera un report sullo storico del portafoglio. Il blocco FATTI VERIFICATI e autorevole per ogni cifra; il JSON e solo contesto temporale.

Struttura obbligatoria, nello stesso ordine:
### Sintesi
120-150 parole. Riporta periodo esatto, valori iniziali e finali, delta complessivi e quadro generale senza attribuire cause non documentate.

### Evoluzione dello storico
160-190 parole. Descrivi andamento dei totali, massimi, minimi e fasi osservabili nella serie, senza attribuirle a singoli asset o eventi esterni.

### Principali variazioni per posizione
190-220 parole. Descrivi i principali delta verificati di controvalore, P/L e peso senza parlare di contributi causali e senza dedurre acquisti, vendite o cause.

### Analisi per asset rilevanti
190-220 parole. Descrivi le principali posizioni correnti con valore, peso e P/L. Distingui valori correnti e variazioni storiche.

### Concentrazione e composizione
130-150 parole. Descrivi la composizione corrente per tipologia e valuta con linguaggio neutro, senza trasformarla in una raccomandazione.

### Punti di attenzione
110-130 parole. Evidenzia concentrazioni, P/L negativi, divergenze e limiti informativi realmente presenti nei dati.

### Prossimi controlli utili
70-90 parole. Indica soltanto controlli sui dati e sul monitoraggio futuro, senza suggerire operazioni finanziarie.

BUDGET DI LUNGHEZZA VINCOLANTE
- Pianifica silenziosamente il testo prima di scriverlo e mantieni ogni sezione nel proprio intervallo.
- Il report completo deve avere tra 1050 e 1250 parole; non superare mai 1400 parole.
- Prima di restituire la risposta, conta silenziosamente le parole: se superano 1250, elimina ripetizioni e dettagli secondari mantenendo tutte le sette sezioni e i fatti richiesti.

FATTI VERIFICATI DAL CODICE PHP:
```text
{$verifiedFacts}
```

JSON storico snapshot, utilizzabile soltanto come contesto temporale:
```json
{$historyJson}
```
PROMPT;
}

function ai_report_custom_system_instruction(): string
{
    return <<<PROMPT
Sei un analista di dati di portafoglio. Rispondi a una richiesta specifica usando esclusivamente i dati storici strutturati forniti. Non sei un consulente finanziario e non devi suggerire acquisti, vendite o strategie personalizzate.

REGOLE VINCOLANTI
- Usa esclusivamente i dati forniti insieme alla richiesta dell'utente.
- Il blocco FATTI VERIFICATI DAL CODICE PHP e l'unica fonte autorizzata per cifre, importi, date, percentuali e variazioni.
- Copia esattamente i valori verificati e non rifare calcoli.
- Il P/L non realizzato e importato dal report Fineco: non definirlo e non calcolarlo come differenza tra valore di mercato e valore di carico.
- Non dedurre cause di mercato, operazioni, versamenti, acquisti, vendite, correlazioni o contributi causali non esplicitamente presenti nei dati.
- Distingui fatti e interpretazioni; quando la risposta non e determinabile dai dati, dichiaralo chiaramente.
- Scrivi ogni numero in cifre, mai in lettere. Usa date DD/MM/YYYY, importi EUR 165.405,47 e percentuali 12,34%.
- Non usare le parole virgola, per cento o duemila per esprimere numeri.
- Non inventare valori, date, strumenti o percentuali. Non trasformare la risposta in una raccomandazione finanziaria.
- Prima di rispondere verifica silenziosamente segni, importi, percentuali, date e coerenza con la richiesta. Non menzionare questa verifica, il prompt, il JSON o i nomi tecnici dei campi.

STILE
- Rispondi soltanto in italiano e concentrati sulla domanda specifica.
- Usa titoli Markdown di terzo livello quando aiutano la lettura, paragrafi completi ed eventualmente brevi elenchi.
- Usa importi con due decimali e separatori italiani; limita le percentuali a due decimali.
- Non usare tabelle Markdown, grassetto, corsivo o backtick.
- Produci una risposta sostanziale ma proporzionata alla richiesta, indicativamente tra 250 e 1000 parole.
PROMPT;
}

function ai_report_build_custom_prompt(string $historyJson, string $customPrompt, string $verifiedFacts): string
{
    return <<<PROMPT
Richiesta specifica dell'utente:
{$customPrompt}

Rispondi alla richiesta rispettando tutte le regole di accuratezza della system instruction. Per ogni cifra usa soltanto il blocco verificato.

FATTI VERIFICATI DAL CODICE PHP:
```text
{$verifiedFacts}
```

JSON storico snapshot, utilizzabile soltanto come contesto temporale:
```json
{$historyJson}
```
PROMPT;
}

function ai_report_build_repair_prompt(
    string $originalPrompt,
    string $draft,
    string $validationError,
    string $verifiedFacts,
    string $reportType
): string {
    $scope = match ($reportType) {
        'custom' => 'Riscrivi integralmente la risposta alla richiesta personalizzata.',
        'changes' => 'Riscrivi integralmente il confronto in forma compatta, con tabella dei totali e una mini-tabella per ogni strumento.',
        'account_general' => 'Riscrivi integralmente il report patrimoniale con le cinque sezioni richieste. Mantienilo tra 450 e 700 parole.',
        'account_changes', 'account_weekly_changes' => 'Riscrivi integralmente il confronto patrimoniale con le quattro sezioni richieste e una tabella patrimoniale. Mantienilo tra 120 e 240 parole.',
        default => 'Riscrivi integralmente il report con tutte le sette sezioni. Mantienilo tra 1050 e 1250 parole e non superare mai 1400 parole.',
    };

    return <<<PROMPT
La prima bozza e stata rifiutata dai controlli automatici dell'app.
Motivo: {$validationError}

{$scope}
Restituisci soltanto la versione corretta e completa, senza commentare l'errore e senza premesse.
Rispetta il budget di lunghezza e le intestazioni indicati dalla richiesta originale; pianifica silenziosamente la struttura prima di rispondere.
Ogni numero deve essere copiato in cifre dal blocco verificato. Non eseguire alcun calcolo.
Sono vietati numeri e date in lettere, inclusi i termini virgola, per cento e duemila.

FATTI VERIFICATI AUTOREVOLI:
```text
{$verifiedFacts}
```

RICHIESTA ORIGINALE:
{$originalPrompt}

BOZZA RIFIUTATA DA SOSTITUIRE:
```text
{$draft}
```
PROMPT;
}

function ai_report_gemini_request(
    string $apiKey,
    string $model,
    string $systemInstruction,
    string $prompt,
    string $thinkingLevel,
    int $maxOutputTokens,
    int $timeoutSeconds,
    array $tools = []
): array
{
    $allowedThinkingLevels = ['minimal', 'low', 'medium', 'high'];
    if (!in_array($thinkingLevel, $allowedThinkingLevels, true)) {
        $thinkingLevel = 'medium';
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $body = [
        'systemInstruction' => [
            'parts' => [
                ['text' => $systemInstruction],
            ],
        ],
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                ],
            ],
        ],
        'generationConfig' => [
            'maxOutputTokens' => max(4000, min(65536, $maxOutputTokens)),
            'responseMimeType' => 'text/plain',
            'thinkingConfig' => [
                'thinkingLevel' => $thinkingLevel,
            ],
        ],
    ];

    if ($tools !== []) {
        $body['tools'] = $tools;
    }

    $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($jsonBody)) {
        throw new RuntimeException('Impossibile preparare la richiesta Gemini.');
    }

    if (function_exists('curl_init')) {
        return ai_report_gemini_request_curl($url, $apiKey, $jsonBody, $timeoutSeconds);
    }

    return ai_report_gemini_request_stream($url, $apiKey, $jsonBody, $timeoutSeconds);
}

function ai_report_gemini_request_curl(string $url, string $apiKey, string $jsonBody, int $timeoutSeconds): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Inizializzazione cURL fallita.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $jsonBody,
        CURLOPT_TIMEOUT => max(10, $timeoutSeconds),
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Errore rete Gemini: ' . $curlError);
    }

    return ai_report_decode_gemini_response((string) $raw, $httpCode);
}

function ai_report_gemini_request_stream(string $url, string $apiKey, string $jsonBody, int $timeoutSeconds): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n" . 'x-goog-api-key: ' . $apiKey . "\r\n",
            'content' => $jsonBody,
            'timeout' => max(10, $timeoutSeconds),
            'ignore_errors' => true,
        ],
    ]);

    $raw = file_get_contents($url, false, $context);
    if (!is_string($raw)) {
        throw new RuntimeException('Errore rete Gemini.');
    }

    $httpCode = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches) === 1) {
            $httpCode = (int) $matches[1];
            break;
        }
    }

    return ai_report_decode_gemini_response($raw, $httpCode);
}

function ai_report_decode_gemini_response(string $raw, int $httpCode): array
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Risposta Gemini non valida.');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = (string) ($decoded['error']['message'] ?? 'Errore API Gemini');
        throw new RuntimeException('Gemini HTTP ' . $httpCode . ': ' . $message);
    }

    $usage = $decoded['usageMetadata'] ?? [];

    return [
        'decoded' => $decoded,
        'http_code' => $httpCode,
        'finish_reason' => (string) ($decoded['candidates'][0]['finishReason'] ?? ''),
        'prompt_tokens' => (int) ($usage['promptTokenCount'] ?? 0)
            + (int) ($usage['toolUsePromptTokenCount'] ?? 0),
        'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
        'thinking_tokens' => (int) ($usage['thoughtsTokenCount'] ?? 0),
        'total_tokens' => (int) ($usage['totalTokenCount'] ?? 0),
    ];
}

function ai_report_validate_generated_text(string $text, string $finishReason, array $historyPayload): void
{
    if ($finishReason === 'MAX_TOKENS') {
        throw new RuntimeException('Gemini ha raggiunto il limite di token: il report incompleto non e stato salvato.');
    }

    $requiredHeadings = [
        '### Sintesi',
        '### Evoluzione dello storico',
        '### Principali variazioni per posizione',
        '### Analisi per asset rilevanti',
        '### Concentrazione e composizione',
        '### Punti di attenzione',
        '### Prossimi controlli utili',
    ];
    $missingHeadings = array_values(array_filter(
        $requiredHeadings,
        static fn(string $heading): bool => !str_contains($text, $heading)
    ));

    if ($missingHeadings !== []) {
        throw new RuntimeException('Gemini ha restituito un report incompleto: mancano una o piu sezioni richieste.');
    }

    $words = preg_split('/\s+/u', trim($text)) ?: [];
    if (count($words) < 950) {
        throw new RuntimeException('Gemini ha restituito un report troppo breve: il risultato non e stato salvato.');
    }
    if (count($words) > 1400) {
        throw new RuntimeException('Gemini ha restituito un report troppo lungo: deve restare entro 1400 parole.');
    }

    ai_report_validate_numeric_style($text);

    $range = is_array($historyPayload['range_summary'] ?? null) ? $historyPayload['range_summary'] : [];
    $dateRange = is_array($historyPayload['date_range'] ?? null) ? $historyPayload['date_range'] : [];
    $requiredFacts = [
        ai_report_format_date_it((string) ($dateRange['from'] ?? '')),
        ai_report_format_date_it((string) ($dateRange['to'] ?? '')),
        'EUR ' . ai_report_format_number_it((float) ($range['first_market_value'] ?? 0)),
        'EUR ' . ai_report_format_number_it((float) ($range['latest_market_value'] ?? 0)),
        'EUR ' . ai_report_format_number_it((float) ($range['delta_market_value'] ?? 0), 2, true),
        'EUR ' . ai_report_format_number_it((float) ($range['first_unrealized_pnl'] ?? 0)),
        'EUR ' . ai_report_format_number_it((float) ($range['latest_unrealized_pnl'] ?? 0)),
        'EUR ' . ai_report_format_number_it((float) ($range['delta_unrealized_pnl'] ?? 0), 2, true),
    ];
    $missingFacts = array_values(array_filter(
        array_unique($requiredFacts),
        static fn(string $fact): bool => $fact !== '' && !str_contains($text, $fact)
    ));

    if ($missingFacts !== []) {
        throw new RuntimeException(
            'Gemini non ha riportato correttamente uno o piu valori riepilogativi verificati: '
            . implode(', ', array_slice($missingFacts, 0, 3))
            . (count($missingFacts) > 3 ? ', ...' : '')
        );
    }
}

function ai_report_validate_changes_text(string $text, string $finishReason, array $historyPayload): void
{
    if ($finishReason === 'MAX_TOKENS') {
        throw new RuntimeException('Gemini ha raggiunto il limite di token: il confronto incompleto non e stato archiviato.');
    }

    $requiredHeadings = [
        '### In sintesi',
        '### Totali del portafoglio',
        '### Composizione e movimenti osservati',
        '### Prossimo confronto',
    ];
    $missingHeadings = array_values(array_filter(
        $requiredHeadings,
        static fn(string $heading): bool => !str_contains($text, $heading)
    ));
    if ($missingHeadings !== []) {
        throw new RuntimeException('Gemini ha restituito un confronto incompleto: mancano una o piu sezioni richieste.');
    }

    $proseLines = array_values(array_filter(
        preg_split('/\R/u', trim($text)) ?: [],
        static function (string $line): bool {
            $trimmed = trim($line);
            return $trimmed !== ''
                && !str_starts_with($trimmed, '### ')
                && !str_starts_with($trimmed, '|');
        }
    ));
    $proseWords = preg_split('/\s+/u', trim(implode(' ', $proseLines))) ?: [];
    if (count($proseWords) < 80) {
        throw new RuntimeException('Gemini ha restituito un confronto troppo breve: il risultato non e stato archiviato.');
    }
    if (count($proseWords) > 500) {
        throw new RuntimeException('Gemini ha restituito un confronto troppo narrativo: il risultato non e stato archiviato.');
    }

    $positionChanges = array_values(array_filter(
        is_array($historyPayload['first_to_latest_position_changes'] ?? null)
            ? $historyPayload['first_to_latest_position_changes']
            : [],
        'is_array'
    ));
    $missingAssets = [];
    foreach ($positionChanges as $position) {
        $name = trim((string) ($position['name'] ?? ''));
        if ($name !== '' && !str_contains($text, '### ' . $name)) {
            $missingAssets[] = $name;
        }
    }
    if ($missingAssets !== []) {
        throw new RuntimeException(
            'Gemini non ha incluso la mini-tabella di uno o piu strumenti: '
            . implode(', ', array_slice($missingAssets, 0, 3))
        );
    }

    preg_match_all('/^\|\s*:?-{3,}:?(?:\s*\|\s*:?-{3,}:?)+\s*\|?\s*$/m', $text, $tableSeparators);
    $minimumTables = count($positionChanges) + 1;
    if (count($tableSeparators[0] ?? []) < $minimumTables) {
        throw new RuntimeException('Gemini non ha prodotto tutte le tabelle richieste per il confronto.');
    }

    ai_report_validate_numeric_style($text);

    $range = is_array($historyPayload['range_summary'] ?? null) ? $historyPayload['range_summary'] : [];
    $dateRange = is_array($historyPayload['date_range'] ?? null) ? $historyPayload['date_range'] : [];
    $requiredFacts = [
        ai_report_format_date_it((string) ($dateRange['from'] ?? '')),
        ai_report_format_date_it((string) ($dateRange['to'] ?? '')),
        'EUR ' . ai_report_format_number_it((float) ($range['delta_market_value'] ?? 0), 2, true),
        'EUR ' . ai_report_format_number_it((float) ($range['delta_cost_basis'] ?? 0), 2, true),
        'EUR ' . ai_report_format_number_it((float) ($range['delta_unrealized_pnl'] ?? 0), 2, true),
    ];
    $missingFacts = array_values(array_filter(
        array_unique($requiredFacts),
        static fn(string $fact): bool => $fact !== '' && !str_contains($text, $fact)
    ));
    if ($missingFacts !== []) {
        throw new RuntimeException(
            'Gemini non ha riportato correttamente uno o piu valori del confronto: '
            . implode(', ', array_slice($missingFacts, 0, 3))
        );
    }
}

function ai_report_account_required_facts(array $historyPayload, bool $includeDelta): array
{
    $dateRange = is_array($historyPayload['date_range'] ?? null) ? $historyPayload['date_range'] : [];
    $metrics = is_array($historyPayload['metrics'] ?? null) ? $historyPayload['metrics'] : [];
    $required = [
        ai_report_format_date_it((string) ($dateRange['from'] ?? '')),
        ai_report_format_date_it((string) ($dateRange['to'] ?? '')),
    ];

    foreach (['fineco_total_assets', 'net_assets_adjusted', 'consolidated_portfolio_value', 'own_liquidity_adjusted', 'accounting_balance'] as $field) {
        $metric = is_array($metrics[$field] ?? null) ? $metrics[$field] : [];
        if (($metric['first'] ?? null) !== null && ($metric['latest'] ?? null) !== null) {
            $required[] = 'EUR ' . ai_report_format_number_it((float) $metric['first']);
            $required[] = 'EUR ' . ai_report_format_number_it((float) $metric['latest']);
            if ($includeDelta && ($metric['delta'] ?? null) !== null) {
                $required[] = 'EUR ' . ai_report_format_number_it((float) $metric['delta'], 2, true);
            }
            break;
        }
    }

    return array_values(array_unique(array_filter($required, static fn(string $fact): bool => $fact !== '')));
}

function ai_report_validate_account_generated_text(string $text, string $finishReason, array $historyPayload): void
{
    if ($finishReason === 'MAX_TOKENS') {
        throw new RuntimeException('Gemini ha raggiunto il limite di token: il report patrimoniale incompleto non e stato salvato.');
    }

    $requiredHeadings = [
        '### Sintesi patrimoniale',
        '### Evoluzione di patrimonio e liquidita',
        '### Saldi e linee di credito',
        '### Dati disponibili e limiti',
        '### Prossimi controlli utili',
    ];
    $missingHeadings = array_values(array_filter(
        $requiredHeadings,
        static fn(string $heading): bool => !str_contains($text, $heading)
    ));
    if ($missingHeadings !== []) {
        throw new RuntimeException('Gemini ha restituito un report patrimoniale incompleto: mancano una o piu sezioni richieste.');
    }

    $words = preg_split('/\s+/u', trim($text)) ?: [];
    if (count($words) < 400) {
        throw new RuntimeException('Gemini ha restituito un report patrimoniale troppo breve: il risultato non e stato salvato.');
    }
    if (count($words) > 850) {
        throw new RuntimeException('Gemini ha restituito un report patrimoniale troppo lungo: deve restare entro 850 parole.');
    }

    ai_report_validate_numeric_style($text);
    ai_report_validate_account_neutral_wording($text);
    $missingFacts = array_values(array_filter(
        ai_report_account_required_facts($historyPayload, true),
        static fn(string $fact): bool => !str_contains($text, $fact)
    ));
    if ($missingFacts !== []) {
        throw new RuntimeException('Gemini non ha riportato correttamente uno o piu valori patrimoniali verificati.');
    }
}

function ai_report_validate_account_changes_text(string $text, string $finishReason, array $historyPayload): void
{
    if ($finishReason === 'MAX_TOKENS') {
        throw new RuntimeException('Gemini ha raggiunto il limite di token: il confronto patrimoniale incompleto non e stato archiviato.');
    }

    $requiredHeadings = [
        '### In sintesi',
        '### Tabella patrimoniale',
        '### Lettura dei dati',
        '### Prossimo confronto',
    ];
    $missingHeadings = array_values(array_filter(
        $requiredHeadings,
        static fn(string $heading): bool => !str_contains($text, $heading)
    ));
    if ($missingHeadings !== []) {
        throw new RuntimeException('Gemini ha restituito un confronto patrimoniale incompleto: mancano una o piu sezioni richieste.');
    }

    preg_match_all('/^\|\s*:?-{3,}:?(?:\s*\|\s*:?-{3,}:?)+\s*\|?\s*$/m', $text, $tableSeparators);
    if (count($tableSeparators[0] ?? []) < 1) {
        throw new RuntimeException('Gemini non ha prodotto la tabella richiesta per il confronto patrimoniale.');
    }

    $words = preg_split('/\s+/u', trim($text)) ?: [];
    if (count($words) < 100) {
        throw new RuntimeException('Gemini ha restituito un confronto patrimoniale troppo breve: il risultato non e stato archiviato.');
    }
    if (count($words) > 350) {
        throw new RuntimeException('Gemini ha restituito un confronto patrimoniale troppo lungo: il risultato non e stato archiviato.');
    }

    ai_report_validate_numeric_style($text);
    ai_report_validate_account_neutral_wording($text);
    $missingFacts = array_values(array_filter(
        ai_report_account_required_facts($historyPayload, true),
        static fn(string $fact): bool => !str_contains($text, $fact)
    ));
    if ($missingFacts !== []) {
        throw new RuntimeException('Gemini non ha riportato correttamente uno o piu valori del confronto patrimoniale.');
    }
}

function ai_report_validate_account_neutral_wording(string $text): void
{
    $unsupportedPatterns = [
        '/\bcollegat[oaie]\s+(?:alla?|al|ai|alle|agli)\b/iu',
        '/\b(?:dovut[oaie]|causat[oaie]|derivat[oaie]|attribuit[oaie])\s+(?:alla?|al|ai|alle|agli|da)\b/iu',
        '/\bin tutto il periodo\b/iu',
        '/\bdurante il periodo\b/iu',
    ];

    foreach ($unsupportedPatterns as $pattern) {
        if (preg_match($pattern, $text) === 1) {
            throw new RuntimeException('Gemini ha introdotto un nesso causale o temporale non supportato dagli snapshot patrimoniali disponibili.');
        }
    }
}

function ai_report_validate_custom_text(string $text, string $finishReason): void
{
    if ($finishReason === 'MAX_TOKENS') {
        throw new RuntimeException('Gemini ha raggiunto il limite di token: il report incompleto non e stato archiviato.');
    }

    $words = preg_split('/\s+/u', trim($text)) ?: [];
    if (count($words) < 80) {
        throw new RuntimeException('Gemini ha restituito una risposta troppo breve: il risultato non e stato archiviato.');
    }

    ai_report_validate_numeric_style($text);
}

function ai_report_validate_numeric_style(string $text): void
{
    if (preg_match('/\bvirgola\b|\bper\s+cento\b|\bduemila\p{L}*/iu', $text) === 1) {
        throw new RuntimeException(
            'Gemini ha scritto numeri o date in lettere invece di usare le cifre richieste.'
        );
    }
}

function ai_report_validate_numeric_tokens(string $text, string $verifiedFacts): void
{
    preg_match_all(
        '/(?<![\p{L}\d])(?:[+-]?\d{2}\/\d{2}\/\d{4}|[+-]?\d{1,3}(?:\.\d{3})*(?:,\d+)?%?|[+-]?\d+)(?![\p{L}\d])/u',
        $text,
        $matches
    );
    $unknown = [];

    foreach (array_unique($matches[0] ?? []) as $token) {
        if (!str_contains($verifiedFacts, $token)) {
            $unknown[] = $token;
        }
    }

    if ($unknown !== []) {
        throw new RuntimeException(
            'Gemini ha introdotto cifre non presenti nei fatti verificati: '
            . implode(', ', array_slice($unknown, 0, 5))
            . (count($unknown) > 5 ? ', ...' : '')
        );
    }
}

function ai_report_estimate_cost_usd(
    string $model,
    int $promptTokens,
    int $outputTokens,
    int $thinkingTokens = 0
): ?float {
    // Standard Gemini Developer API rates verified on 2026-08-14.
    $ratesPerMillionTokens = [
        'gemini-3.1-flash-lite' => ['input' => 0.25, 'output' => 1.50],
        'gemini-3.5-flash-lite' => ['input' => 0.30, 'output' => 2.50],
        'gemini-3.6-flash' => ['input' => 1.50, 'output' => 7.50],
        'gemini-3.7-flash' => ['input' => 0.75, 'output' => 3.75],
    ];

    $rates = $ratesPerMillionTokens[$model] ?? null;
    if (!is_array($rates)) {
        return null;
    }

    $inputCost = ($promptTokens / 1_000_000) * $rates['input'];
    $billableOutputTokens = max(0, $outputTokens) + max(0, $thinkingTokens);
    $outputCost = ($billableOutputTokens / 1_000_000) * $rates['output'];

    return $inputCost + $outputCost;
}

function ai_report_extract_text(array $decoded): string
{
    $parts = $decoded['candidates'][0]['content']['parts'] ?? [];
    if (!is_array($parts)) {
        return '';
    }

    $chunks = [];
    foreach ($parts as $part) {
        if (isset($part['text']) && is_string($part['text'])) {
            $chunks[] = trim($part['text']);
        }
    }

    return trim(implode("\n\n", $chunks));
}

function ai_report_render_markdown(string $markdown): string
{
    $lines = preg_split('/\R/', trim($markdown)) ?: [];
    $html = '';
    $inList = false;
    $parseTableRow = static function (string $line): array {
        $content = trim(trim($line), '|');
        return array_map('trim', explode('|', $content));
    };
    $isTableSeparator = static function (string $line) use ($parseTableRow): bool {
        $cells = $parseTableRow($line);
        if (count($cells) < 2) {
            return false;
        }

        foreach ($cells as $cell) {
            if (preg_match('/^:?-{3,}:?$/', $cell) !== 1) {
                return false;
            }
        }

        return true;
    };
    $closeList = static function () use (&$html, &$inList): void {
        if ($inList) {
            $html .= '</ul>';
            $inList = false;
        }
    };

    $lineCount = count($lines);
    for ($index = 0; $index < $lineCount; $index++) {
        $trimmed = trim($lines[$index]);
        if ($trimmed === '') {
            $closeList();
            continue;
        }

        $nextLine = $index + 1 < $lineCount ? trim($lines[$index + 1]) : '';
        if (str_starts_with($trimmed, '|') && $isTableSeparator($nextLine)) {
            $closeList();
            $headers = $parseTableRow($trimmed);
            $rowIndex = $index + 2;
            $rows = [];
            while ($rowIndex < $lineCount) {
                $rowLine = trim($lines[$rowIndex]);
                if ($rowLine === '' || !str_starts_with($rowLine, '|')) {
                    break;
                }

                $cells = $parseTableRow($rowLine);
                $cells = array_slice(array_pad($cells, count($headers), ''), 0, count($headers));
                $rows[] = $cells;
                $rowIndex++;
            }

            $html .= '<div class="ai-report-table-wrap"><table class="ai-report-table"><thead><tr>';
            foreach ($headers as $header) {
                $html .= '<th>' . e($header) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td>' . e($cell) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
            $index = $rowIndex - 1;
            continue;
        }

        if (str_starts_with($trimmed, '### ')) {
            $closeList();
            $html .= '<h3>' . e(substr($trimmed, 4)) . '</h3>';
            continue;
        }

        if (str_starts_with($trimmed, '- ')) {
            if (!$inList) {
                $html .= '<ul>';
                $inList = true;
            }
            $html .= '<li>' . e(substr($trimmed, 2)) . '</li>';
            continue;
        }

        $closeList();

        $html .= '<p>' . e($trimmed) . '</p>';
    }

    $closeList();

    return $html;
}
