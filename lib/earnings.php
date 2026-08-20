<?php

require_once __DIR__ . '/ai_report.php';

function earnings_schema_ready(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'pf_earnings_events'
             LIMIT 1"
        );
        return $stmt !== false && $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function earnings_find_event(PDO $pdo, int $eventId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT e.*, a.name AS asset_name, a.ticker, a.isin, a.currency, a.asset_type
         FROM pf_earnings_events e
         JOIN pf_assets a ON a.id = e.asset_id
         WHERE e.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $eventId]);
    $event = $stmt->fetch();

    return is_array($event) ? $event : null;
}

function earnings_list_events(PDO $pdo): array
{
    $sql = 'SELECT e.*, a.name AS asset_name, a.ticker, a.isin, a.currency,
                   latest.weight_pct AS latest_weight_pct,
                   latest.market_value AS latest_market_value
            FROM pf_earnings_events e
            JOIN pf_assets a ON a.id = e.asset_id
            LEFT JOIN (
                SELECT p.asset_id, SUM(p.market_value) AS market_value,
                       SUM(p.market_value) / NULLIF((SELECT s.total_market_value FROM pf_snapshots s ORDER BY s.snapshot_date DESC, s.id DESC LIMIT 1), 0) AS weight_pct
                FROM pf_snapshot_positions p
                WHERE p.snapshot_id = (SELECT s.id FROM pf_snapshots s ORDER BY s.snapshot_date DESC, s.id DESC LIMIT 1)
                GROUP BY p.asset_id
            ) latest ON latest.asset_id = e.asset_id
            ORDER BY e.report_date DESC, e.id DESC';

    return $pdo->query($sql)->fetchAll();
}

function earnings_significant_assets(PDO $pdo): array
{
    $sql = 'SELECT a.id, a.name, a.ticker, a.isin, a.currency,
                   p.market_value, p.weight_pct, p.snapshot_date
            FROM pf_assets a
            LEFT JOIN (
                SELECT p.asset_id, SUM(p.market_value) AS market_value,
                       SUM(p.market_value) / NULLIF((SELECT s.total_market_value FROM pf_snapshots s ORDER BY s.snapshot_date DESC, s.id DESC LIMIT 1), 0) AS weight_pct,
                       (SELECT s.snapshot_date FROM pf_snapshots s ORDER BY s.snapshot_date DESC, s.id DESC LIMIT 1) AS snapshot_date
                FROM pf_snapshot_positions p
                WHERE p.snapshot_id = (SELECT latest.id FROM pf_snapshots latest ORDER BY latest.snapshot_date DESC, latest.id DESC LIMIT 1)
                GROUP BY p.asset_id
            ) p ON p.asset_id = a.id
            WHERE a.asset_type = \'AZIONE\' AND a.is_active = 1
            ORDER BY COALESCE(p.weight_pct, 0) DESC, a.name ASC';

    return $pdo->query($sql)->fetchAll();
}

function earnings_validate_date(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new RuntimeException('Data della trimestrale non valida.');
    }

    return $date;
}

function earnings_validate_source_url(string $url, bool $required = false): string
{
    $url = trim($url);
    if ($url === '') {
        if ($required) {
            throw new RuntimeException('Inserisci l\'URL della fonte ufficiale prima di generare il report.');
        }
        return '';
    }
    if (strlen($url) > 1000 || filter_var($url, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('URL della fonte non valido.');
    }

    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
        throw new RuntimeException('La fonte deve usare un indirizzo HTTPS pubblico.');
    }

    return $url;
}

function earnings_normalize_event_input(PDO $pdo, array $input): array
{
    $assetId = (int) ($input['asset_id'] ?? 0);
    $assetStmt = $pdo->prepare('SELECT id FROM pf_assets WHERE id = :id AND asset_type = \'AZIONE\' LIMIT 1');
    $assetStmt->execute([':id' => $assetId]);
    if ($assetStmt->fetchColumn() === false) {
        throw new RuntimeException('Seleziona un titolo azionario valido.');
    }

    $fiscalPeriod = trim((string) ($input['fiscal_period'] ?? ''));
    if ($fiscalPeriod === '' || mb_strlen($fiscalPeriod) > 64) {
        throw new RuntimeException('Inserisci un periodo fiscale valido, massimo 64 caratteri.');
    }

    $releaseTiming = strtoupper(trim((string) ($input['release_timing'] ?? 'UNKNOWN')));
    if (!in_array($releaseTiming, ['BMO', 'AMC', 'DURING', 'UNKNOWN'], true)) {
        throw new RuntimeException('Orario di pubblicazione non valido.');
    }

    $dateConfidence = strtoupper(trim((string) ($input['date_confidence'] ?? 'ESTIMATED')));
    if (!in_array($dateConfidence, ['CONFIRMED', 'ESTIMATED'], true)) {
        throw new RuntimeException('Stato della data non valido.');
    }

    $notes = trim((string) ($input['notes'] ?? ''));
    if (mb_strlen($notes) > 2000) {
        throw new RuntimeException('Le note non possono superare 2.000 caratteri.');
    }

    return [
        'asset_id' => $assetId,
        'fiscal_period' => $fiscalPeriod,
        'report_date' => earnings_validate_date(trim((string) ($input['report_date'] ?? ''))),
        'release_timing' => $releaseTiming,
        'date_confidence' => $dateConfidence,
        'source_url' => earnings_validate_source_url((string) ($input['source_url'] ?? '')),
        'notes' => $notes,
    ];
}

function earnings_save_event(PDO $pdo, array $input, int $eventId = 0): int
{
    $data = earnings_normalize_event_input($pdo, $input);

    if ($eventId > 0) {
        $stmt = $pdo->prepare(
            'UPDATE pf_earnings_events
             SET asset_id = :asset_id,
                 fiscal_period = :fiscal_period,
                 report_date = :report_date,
                 release_timing = :release_timing,
                 date_confidence = :date_confidence,
                 source_url = :source_url,
                 notes = :notes
             WHERE id = :id'
        );
        $stmt->execute([
            ':asset_id' => $data['asset_id'],
            ':fiscal_period' => $data['fiscal_period'],
            ':report_date' => $data['report_date'],
            ':release_timing' => $data['release_timing'],
            ':date_confidence' => $data['date_confidence'],
            ':source_url' => $data['source_url'] !== '' ? $data['source_url'] : null,
            ':notes' => $data['notes'] !== '' ? $data['notes'] : null,
            ':id' => $eventId,
        ]);
        if ($stmt->rowCount() === 0 && earnings_find_event($pdo, $eventId) === null) {
            throw new RuntimeException('Trimestrale non trovata.');
        }
        return $eventId;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO pf_earnings_events
            (asset_id, fiscal_period, report_date, release_timing, date_confidence, source_url, notes)
         VALUES
            (:asset_id, :fiscal_period, :report_date, :release_timing, :date_confidence, :source_url, :notes)'
    );
    $stmt->execute([
        ':asset_id' => $data['asset_id'],
        ':fiscal_period' => $data['fiscal_period'],
        ':report_date' => $data['report_date'],
        ':release_timing' => $data['release_timing'],
        ':date_confidence' => $data['date_confidence'],
        ':source_url' => $data['source_url'] !== '' ? $data['source_url'] : null,
        ':notes' => $data['notes'] !== '' ? $data['notes'] : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function earnings_delete_event(PDO $pdo, int $eventId): void
{
    $stmt = $pdo->prepare('DELETE FROM pf_earnings_events WHERE id = :id');
    $stmt->execute([':id' => $eventId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Trimestrale non trovata.');
    }
}

function earnings_timing_label(string $value): string
{
    return match ($value) {
        'BMO' => 'Prima dell\'apertura',
        'AMC' => 'Dopo la chiusura',
        'DURING' => 'Durante la seduta',
        default => 'Orario non indicato',
    };
}

function earnings_confidence_label(string $value): string
{
    return $value === 'CONFIRMED' ? 'Confermata' : 'Stimata';
}

function earnings_event_status(array $event, ?DateTimeImmutable $today = null): string
{
    if (trim((string) ($event['report_markdown'] ?? '')) !== '') {
        return 'ANALYZED';
    }
    $today = $today ?? new DateTimeImmutable('today');
    $reportDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($event['report_date'] ?? ''));
    if ($reportDate && $reportDate > $today) {
        return 'SCHEDULED';
    }

    return 'PUBLISHED';
}

function earnings_status_label(string $status): string
{
    return match ($status) {
        'ANALYZED' => 'Analizzata',
        'SCHEDULED' => 'In calendario',
        default => 'Da analizzare',
    };
}

function earnings_impact(PDO $pdo, array $event): array
{
    $assetId = (int) ($event['asset_id'] ?? 0);
    $reportDate = (string) ($event['report_date'] ?? '');
    $timing = (string) ($event['release_timing'] ?? 'UNKNOWN');
    $findSnapshot = static function (string $operator, string $date, string $direction) use ($pdo, $assetId): ?array {
        if (!in_array($operator, ['<=', '>'], true) || !in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Finestra snapshot non valida.');
        }

        $sql = 'SELECT s.id AS snapshot_id, s.snapshot_date, s.total_market_value,
                       SUM(p.quantity) AS quantity,
                       CASE WHEN SUM(p.quantity) <> 0 THEN SUM(p.current_price * p.quantity) / SUM(p.quantity) ELSE 0 END AS current_price,
                       SUM(p.market_value) AS market_value, SUM(p.unrealized_pnl) AS unrealized_pnl,
                       SUM(p.market_value) / NULLIF(s.total_market_value, 0) AS weight_pct,
                       MAX(p.currency) AS currency
                FROM pf_snapshot_positions p
                JOIN pf_snapshots s ON s.id = p.snapshot_id
                WHERE p.asset_id = :asset_id AND s.snapshot_date ' . $operator . ' :snapshot_date
                GROUP BY s.id, s.snapshot_date, s.total_market_value
                ORDER BY s.snapshot_date ' . $direction . ', s.id ' . $direction . '
                LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':asset_id' => $assetId, ':snapshot_date' => $date]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    };

    if ($timing === 'AMC') {
        // Gli snapshot Fineco importati al mattino riportano la chiusura della sera precedente.
        $pre = $findSnapshot('>', $reportDate, 'ASC');
        $post = $pre !== null
            ? $findSnapshot('>', (string) $pre['snapshot_date'], 'ASC')
            : null;
    } else {
        $pre = $findSnapshot('<=', $reportDate, 'DESC');
        $post = $findSnapshot('>', $reportDate, 'ASC');
    }

    $impact = earnings_calculate_impact_rows(
        is_array($pre) ? $pre : null,
        is_array($post) ? $post : null
    );
    $impact['window_type'] = $timing === 'AMC' ? 'AFTER_CLOSE' : 'SAME_SESSION';

    return $impact;
}

function earnings_calculate_impact_rows(?array $pre, ?array $post): array
{
    $result = [
        'available' => is_array($pre) && is_array($post),
        'pre' => $pre,
        'post' => $post,
        'impact_eur' => null,
        'asset_return_pct' => null,
        'portfolio_contribution_pct' => null,
        'market_value_delta' => null,
        'quantity_delta' => null,
        'weight_delta_pct' => null,
        'method' => 'Variazione del valore unitario in EUR applicata alla quantita precedente.',
    ];

    if (!$result['available']) {
        return $result;
    }

    $preQuantity = (float) ($pre['quantity'] ?? 0);
    $postQuantity = (float) ($post['quantity'] ?? 0);
    if (abs($preQuantity) < 0.0000001 || abs($postQuantity) < 0.0000001) {
        $result['available'] = false;
        return $result;
    }

    $preUnitEur = (float) $pre['market_value'] / $preQuantity;
    $postUnitEur = (float) $post['market_value'] / $postQuantity;
    $impactEur = $preQuantity * ($postUnitEur - $preUnitEur);
    $prePortfolioValue = (float) ($pre['total_market_value'] ?? 0);

    $result['impact_eur'] = $impactEur;
    $result['asset_return_pct'] = abs($preUnitEur) > 0.0000001
        ? (($postUnitEur / $preUnitEur) - 1) * 100
        : null;
    $result['portfolio_contribution_pct'] = abs($prePortfolioValue) > 0.0000001
        ? ($impactEur / $prePortfolioValue) * 100
        : null;
    $result['market_value_delta'] = (float) $post['market_value'] - (float) $pre['market_value'];
    $result['quantity_delta'] = $postQuantity - $preQuantity;
    $result['weight_delta_pct'] = ((float) $post['weight_pct'] - (float) $pre['weight_pct']) * 100;

    return $result;
}

function earnings_format_number(float $value, int $decimals = 2, bool $sign = false): string
{
    $prefix = $sign && $value > 0 ? '+' : '';
    return $prefix . number_format($value, $decimals, ',', '.');
}

function earnings_verified_impact_facts(array $impact): string
{
    if (!$impact['available']) {
        return "- Impatto sul portafoglio non ancora calcolabile: manca uno snapshot utile prima o dopo l'evento.";
    }

    $pre = $impact['pre'];
    $post = $impact['post'];
    return implode("\n", [
        '- Snapshot precedente: ' . ai_report_format_date_it((string) $pre['snapshot_date']),
        '- Snapshot successivo: ' . ai_report_format_date_it((string) $post['snapshot_date']),
        '- Quantita precedente: ' . earnings_format_number((float) $pre['quantity'], 4),
        '- Quantita successiva: ' . earnings_format_number((float) $post['quantity'], 4),
        '- Valore della posizione precedente: EUR ' . earnings_format_number((float) $pre['market_value']),
        '- Valore della posizione successivo: EUR ' . earnings_format_number((float) $post['market_value']),
        '- Impatto stimato della variazione unitaria, cambio incluso: EUR ' . earnings_format_number((float) $impact['impact_eur'], 2, true),
        '- Rendimento della posizione nella finestra: ' . earnings_format_number((float) $impact['asset_return_pct'], 2, true) . '%',
        '- Contributo stimato al portafoglio: ' . earnings_format_number((float) $impact['portfolio_contribution_pct'], 3, true) . '%',
        '- Delta del peso in portafoglio: ' . earnings_format_number((float) $impact['weight_delta_pct'], 2, true) . ' punti percentuali',
    ]);
}

function earnings_generate_report(PDO $pdo, array $event): array
{
    $sourceUrl = earnings_validate_source_url((string) ($event['source_url'] ?? ''), true);
    $geminiConfig = app_config()['gemini'] ?? [];
    $apiKey = (string) ($geminiConfig['api_key'] ?? '');
    $model = (string) ($geminiConfig['model'] ?? 'gemini-3.7-flash');
    $thinkingLevel = (string) ($geminiConfig['thinking_level'] ?? 'medium');
    $maxOutputTokens = min(12000, (int) ($geminiConfig['max_output_tokens'] ?? 12000));
    $timeoutSeconds = (int) ($geminiConfig['timeout_seconds'] ?? 90);
    if ($apiKey === '' || str_contains($apiKey, 'INSERISCI_CHIAVE')) {
        throw new RuntimeException('Chiave Gemini non configurata.');
    }

    $impact = earnings_impact($pdo, $event);
    $verifiedImpact = earnings_verified_impact_facts($impact);
    $systemInstruction = <<<'PROMPT'
Sei un analista descrittivo di risultati societari. Devi leggere esclusivamente l'URL ufficiale fornito tramite URL Context e produrre un report prudente in italiano.

REGOLE VINCOLANTI:
- La fonte ufficiale e l'unica fonte autorizzata per ricavi, utili, EPS, segmenti, guidance e dichiarazioni del management.
- Il blocco IMPATTO VERIFICATO DAL CODICE PHP e l'unica fonte autorizzata per numeri relativi al portafoglio.
- Distingui sempre dati GAAP, non-GAAP, crescita anno su anno, valuta e periodo di riferimento.
- Se un dato non compare nella fonte, scrivi che non e disponibile: non stimarlo e non completarlo con memoria o conoscenze esterne.
- Non fornire consigli di acquisto, vendita, target price o strategie personalizzate.
- Non presentare relazioni causali come certe quando la fonte non le dimostra.
- Per l'impatto sul portafoglio copia importi, percentuali e date esattamente come compaiono nel blocco verificato.
- Non menzionare il prompt, JSON, URL Context o controlli tecnici.

Produci da 650 a 1.000 parole con esattamente queste sezioni Markdown:
## Sintesi del trimestre
## Numeri e segmenti principali
## Scostamenti e fattori rilevanti
## Guidance e indicazioni del management
## Reazione e impatto sul portafoglio
## Punti da monitorare nel prossimo trimestre
PROMPT;

    $prompt = "Analizza la trimestrale seguente.\n\n"
        . 'Societa: ' . (string) $event['asset_name'] . "\n"
        . 'Ticker: ' . ((string) ($event['ticker'] ?? '') ?: 'non disponibile') . "\n"
        . 'Periodo fiscale: ' . (string) $event['fiscal_period'] . "\n"
        . 'Data di pubblicazione: ' . ai_report_format_date_it((string) $event['report_date']) . "\n"
        . 'Fonte ufficiale da leggere: ' . $sourceUrl . "\n"
        . 'Note dell\'utente: ' . (((string) ($event['notes'] ?? '')) ?: 'nessuna') . "\n\n"
        . "IMPATTO VERIFICATO DAL CODICE PHP:\n" . $verifiedImpact;

    $response = ai_report_gemini_request(
        $apiKey,
        $model,
        $systemInstruction,
        $prompt,
        $thinkingLevel,
        $maxOutputTokens,
        $timeoutSeconds,
        [['url_context' => (object) []]]
    );
    $text = ai_report_extract_text($response['decoded']);
    if ($text === '') {
        throw new RuntimeException('Gemini non ha restituito un report utile.');
    }
    if ((string) ($response['finish_reason'] ?? '') === 'MAX_TOKENS') {
        throw new RuntimeException('Gemini ha raggiunto il limite di token: il report incompleto non e stato salvato.');
    }

    $urlMetadata = $response['decoded']['candidates'][0]['urlContextMetadata']['urlMetadata'] ?? [];
    $sourceRetrieved = false;
    if (is_array($urlMetadata)) {
        foreach ($urlMetadata as $metadata) {
            if ((string) ($metadata['urlRetrievalStatus'] ?? '') === 'URL_RETRIEVAL_STATUS_SUCCESS') {
                $sourceRetrieved = true;
                break;
            }
        }
    }
    if (!$sourceRetrieved) {
        throw new RuntimeException('Gemini non e riuscito a recuperare la fonte ufficiale: il report non e stato salvato.');
    }

    $requiredSections = [
        '## Sintesi del trimestre',
        '## Numeri e segmenti principali',
        '## Scostamenti e fattori rilevanti',
        '## Guidance e indicazioni del management',
        '## Reazione e impatto sul portafoglio',
        '## Punti da monitorare nel prossimo trimestre',
    ];
    foreach ($requiredSections as $section) {
        if (!str_contains($text, $section)) {
            throw new RuntimeException('Gemini ha restituito un report incompleto: manca la sezione ' . $section . '.');
        }
    }

    if ($impact['available']) {
        $requiredImpactTokens = [
            ai_report_format_date_it((string) $impact['pre']['snapshot_date']),
            ai_report_format_date_it((string) $impact['post']['snapshot_date']),
            'EUR ' . earnings_format_number((float) $impact['impact_eur'], 2, true),
            earnings_format_number((float) $impact['portfolio_contribution_pct'], 3, true) . '%',
        ];
        foreach ($requiredImpactTokens as $token) {
            if (!str_contains($text, $token)) {
                throw new RuntimeException('Il report non riporta correttamente tutti i valori di impatto verificati.');
            }
        }
    }

    $usage = [
        'prompt_tokens' => (int) ($response['prompt_tokens'] ?? 0),
        'output_tokens' => (int) ($response['output_tokens'] ?? 0),
        'thinking_tokens' => (int) ($response['thinking_tokens'] ?? 0),
        'total_tokens' => (int) ($response['total_tokens'] ?? 0),
    ];
    $cost = ai_report_estimate_cost_usd(
        $model,
        $usage['prompt_tokens'],
        $usage['output_tokens'],
        $usage['thinking_tokens']
    );

    return [
        'report_markdown' => $text,
        'model' => $model,
        'thinking_level' => $thinkingLevel,
        'prompt_tokens' => $usage['prompt_tokens'],
        'output_tokens' => $usage['output_tokens'],
        'thinking_tokens' => $usage['thinking_tokens'],
        'total_tokens' => $usage['total_tokens'],
        'estimated_cost_usd' => $cost,
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

function earnings_store_report(PDO $pdo, int $eventId, array $generated): void
{
    $stmt = $pdo->prepare(
        'UPDATE pf_earnings_events
         SET report_markdown = :report_markdown,
             ai_model = :ai_model,
             ai_thinking_level = :ai_thinking_level,
             ai_prompt_tokens = :ai_prompt_tokens,
             ai_output_tokens = :ai_output_tokens,
             ai_thinking_tokens = :ai_thinking_tokens,
             ai_total_tokens = :ai_total_tokens,
             ai_estimated_cost_usd = :ai_estimated_cost_usd,
             ai_generated_at = :ai_generated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':report_markdown' => $generated['report_markdown'],
        ':ai_model' => $generated['model'],
        ':ai_thinking_level' => $generated['thinking_level'],
        ':ai_prompt_tokens' => $generated['prompt_tokens'],
        ':ai_output_tokens' => $generated['output_tokens'],
        ':ai_thinking_tokens' => $generated['thinking_tokens'],
        ':ai_total_tokens' => $generated['total_tokens'],
        ':ai_estimated_cost_usd' => $generated['estimated_cost_usd'],
        ':ai_generated_at' => $generated['generated_at'],
        ':id' => $eventId,
    ]);
}
