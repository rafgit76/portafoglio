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

function ai_report_prompt_version(): string
{
    return '2026-05-19-detailed-report-v1';
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
        'generated_at' => date('c'),
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
    $geminiConfig = app_config()['gemini'] ?? [];
    $apiKey = (string) ($geminiConfig['api_key'] ?? '');
    $model = (string) ($geminiConfig['model'] ?? 'gemini-3.1-flash-lite');
    $timeoutSeconds = (int) ($geminiConfig['timeout_seconds'] ?? 90);

    if ($apiKey === '' || str_contains($apiKey, 'INSERISCI_CHIAVE')) {
        throw new RuntimeException('Chiave Gemini non configurata.');
    }

    $payloadJson = json_encode($historyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson)) {
        throw new RuntimeException('Impossibile serializzare il payload storico per Gemini.');
    }

    $prompt = ai_report_build_prompt($payloadJson);
    $response = ai_report_gemini_request($apiKey, $model, $prompt, $timeoutSeconds);
    $text = ai_report_extract_text($response['decoded']);

    if ($text === '') {
        throw new RuntimeException('Gemini non ha restituito testo utile.');
    }

    return [
        'provider' => 'gemini',
        'model' => $model,
        'prompt_version' => ai_report_prompt_version(),
        'generated_at' => date('c'),
        'http_code' => $response['http_code'],
        'prompt_tokens' => (int) ($response['prompt_tokens'] ?? 0),
        'output_tokens' => (int) ($response['output_tokens'] ?? 0),
        'total_tokens' => (int) ($response['total_tokens'] ?? 0),
        'report_markdown' => $text,
    ];
}

function ai_report_build_prompt(string $historyJson): string
{
    return <<<PROMPT
Sei un analista tecnico di portafoglio.

Vincoli obbligatori:
- usa esclusivamente i dati forniti nel JSON;
- non inventare quotazioni live, news o dati esterni;
- non fornire consulenza finanziaria personalizzata;
- separa osservazioni fattuali da interpretazioni;
- evidenzia massimi/minimi storici, evoluzione recente, concentrazione, variazioni per posizione e possibili anomalie nei dati;
- rispondi solo in italiano;
- non limitarti a una sintesi: produci un report narrativo approfondito, leggibile e completo;
- fornisci almeno 4-5 paragrafi discorsivi approfonditi, evitando frasi telegrafiche;
- quando citi un asset rilevante, commenta nome/ticker se disponibile, variazione del controvalore, variazione del peso percentuale e contributo al movimento complessivo;
- dedica spazio alle posizioni principali e alle posizioni che hanno cambiato peso in modo significativo;
- se i dati disponibili non bastano per spiegare una causa, dichiaralo esplicitamente invece di inventare;
- lunghezza indicativa: 1000-1400 parole, con dettagli concreti numerici dove utili.

Formato risposta obbligatorio in Markdown:
### Sintesi
### Evoluzione dello storico
### Posizioni che guidano le variazioni
### Analisi per asset rilevanti
### Concentrazione e composizione
### Punti di attenzione
### Prossimi controlli utili

JSON storico snapshot:
```json
{$historyJson}
```
PROMPT;
}

function ai_report_gemini_request(string $apiKey, string $model, string $prompt, int $timeoutSeconds): array
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $body = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                ],
            ],
        ],
        'generationConfig' => [
            'temperature' => 0.2,
            'topP' => 0.9,
            'maxOutputTokens' => 3200,
            'responseMimeType' => 'text/plain',
            'thinkingConfig' => [
                'thinkingBudget' => 0,
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
        'prompt_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
        'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
        'total_tokens' => (int) ($usage['totalTokenCount'] ?? 0),
    ];
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
