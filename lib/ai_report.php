<?php

require_once __DIR__ . '/helpers.php';

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

function ai_report_archive_generated(
    array $generated,
    array $built,
    string $type,
    string $title,
    string $customPrompt = ''
): array {
    $payload = is_array($built['payload'] ?? null) ? $built['payload'] : [];
    $dateRange = is_array($payload['date_range'] ?? null) ? $payload['date_range'] : [];
    $estimatedCost = ai_report_estimate_cost_usd(
        (string) ($generated['model'] ?? ''),
        (int) ($generated['prompt_tokens'] ?? 0),
        (int) ($generated['output_tokens'] ?? 0),
        (int) ($generated['thinking_tokens'] ?? 0)
    );

    return ai_report_archive_save([
        'type' => $type === 'custom' ? 'custom' : 'general',
        'title' => $title,
        'custom_prompt' => $type === 'custom' ? $customPrompt : '',
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
    return '2026-07-22-gemini-3-5-medium-verified-v6';
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

    $latestPositionsStmt = $pdo->prepare(
        'SELECT a.name, a.isin, a.ticker, a.asset_type, a.currency,
                p.quantity, p.cost_basis, p.market_value, p.unrealized_pnl, p.weight_pct
         FROM pf_snapshot_positions p
         JOIN pf_assets a ON a.id = p.asset_id
         WHERE p.snapshot_id = :snapshot_id
         ORDER BY p.market_value DESC'
    );
    $latestPositionsStmt->execute([':snapshot_id' => $latestSnapshotId]);
    $latestPositions = $latestPositionsStmt->fetchAll();

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

function ai_report_position_changes(PDO $pdo, int $firstSnapshotId, int $latestSnapshotId): array
{
    $stmt = $pdo->prepare(
        'SELECT a.name, a.isin, a.ticker, a.asset_type,
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
         LEFT JOIN pf_snapshot_positions p_first ON p_first.asset_id = ids.asset_id AND p_first.snapshot_id = :first_pos
         LEFT JOIN pf_snapshot_positions p_latest ON p_latest.asset_id = ids.asset_id AND p_latest.snapshot_id = :latest_pos'
    );
    $stmt->execute([
        ':first_id' => $firstSnapshotId,
        ':latest_id' => $latestSnapshotId,
        ':first_pos' => $firstSnapshotId,
        ':latest_pos' => $latestSnapshotId,
    ]);

    $changes = [];
    foreach ($stmt->fetchAll() as $row) {
        $changes[] = [
            'name' => (string) $row['name'],
            'isin' => (string) ($row['isin'] ?? ''),
            'ticker' => (string) ($row['ticker'] ?? ''),
            'asset_type' => (string) $row['asset_type'],
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

    return array_slice($changes, 0, 12);
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
    $model = (string) ($geminiConfig['model'] ?? 'gemini-3.5-flash-lite');
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
    if ($reportType === 'custom') {
        ai_report_validate_custom_text($text, $finishReason);
    } else {
        ai_report_validate_generated_text($text, $finishReason, $historyPayload);
    }

    ai_report_validate_numeric_tokens($text, ai_report_build_verified_facts($historyPayload));
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
- Produci tra 1000 e 1400 parole: e un vincolo obbligatorio, non indicativo.
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
120-180 parole. Riporta periodo esatto, valori iniziali e finali, delta complessivi e quadro generale senza attribuire cause non documentate.

### Evoluzione dello storico
180-220 parole. Descrivi andamento dei totali, massimi, minimi e fasi osservabili nella serie, senza attribuirle a singoli asset o eventi esterni.

### Principali variazioni per posizione
220-260 parole. Descrivi i principali delta verificati di controvalore, P/L e peso senza parlare di contributi causali e senza dedurre acquisti, vendite o cause.

### Analisi per asset rilevanti
220-260 parole. Descrivi le principali posizioni correnti con valore, peso e P/L. Distingui valori correnti e variazioni storiche.

### Concentrazione e composizione
140-180 parole. Descrivi la composizione corrente per tipologia e valuta con linguaggio neutro, senza trasformarla in una raccomandazione.

### Punti di attenzione
120-160 parole. Evidenzia concentrazioni, P/L negativi, divergenze e limiti informativi realmente presenti nei dati.

### Prossimi controlli utili
80-120 parole. Indica soltanto controlli sui dati e sul monitoraggio futuro, senza suggerire operazioni finanziarie.

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
    $scope = $reportType === 'custom'
        ? 'Riscrivi integralmente la risposta alla richiesta personalizzata.'
        : 'Riscrivi integralmente il report con tutte le sette sezioni e tra 1000 e 1400 parole.';

    return <<<PROMPT
La prima bozza e stata rifiutata dai controlli automatici dell'app.
Motivo: {$validationError}

{$scope}
Restituisci soltanto la versione corretta e completa, senza commentare l'errore e senza premesse.
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
    int $timeoutSeconds
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
        'prompt_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
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
    if (count($words) > 1550) {
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
    // Standard Gemini Developer API rates verified on 2026-07-22.
    $ratesPerMillionTokens = [
        'gemini-3.1-flash-lite' => ['input' => 0.25, 'output' => 1.50],
        'gemini-3.5-flash-lite' => ['input' => 0.30, 'output' => 2.50],
        'gemini-3.6-flash' => ['input' => 1.50, 'output' => 7.50],
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

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            if ($inList) {
                $html .= '</ul>';
                $inList = false;
            }
            continue;
        }

        if (str_starts_with($trimmed, '### ')) {
            if ($inList) {
                $html .= '</ul>';
                $inList = false;
            }
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

        if ($inList) {
            $html .= '</ul>';
            $inList = false;
        }

        $html .= '<p>' . e($trimmed) . '</p>';
    }

    if ($inList) {
        $html .= '</ul>';
    }

    return $html;
}
