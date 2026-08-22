<?php

require_once __DIR__ . '/account_tracker.php';
require_once __DIR__ . '/fineco_patrimony_importer.php';

function credit_lombard_control_schema_ready(?PDO $pdo = null): bool
{
    $pdo ??= db();
    try {
        $columns = $pdo->query('SHOW COLUMNS FROM `pf_credit_lombard_checks`')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {
        return false;
    }

    foreach ([
        'credit_line_id',
        'check_date',
        'detected_dossier_identifier',
        'portfolio_total',
        'expected_market_value',
        'expected_accrued_interest',
        'expected_portfolio_total',
        'difference_amount',
        'check_status',
        'parsed_payload_json',
    ] as $column) {
        if (!in_array($column, $columns, true)) {
            return false;
        }
    }

    return true;
}

function credit_lombard_control_date_from_filename(string $fileName): ?string
{
    if (preg_match('/(?:^|[^0-9])(20\d{2})(\d{2})(\d{2})(?:[^0-9]|$)/', basename($fileName), $matches) !== 1) {
        return null;
    }

    $date = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date ? $date : null;
}

function credit_lombard_control_extract(array $parsed, string $expectedDossierIdentifier, ?string $fallbackDate = null): array
{
    $expectedDossierIdentifier = trim($expectedDossierIdentifier);
    if ($expectedDossierIdentifier === '') {
        throw new RuntimeException('Il profilo Credit Lombard non contiene il numero del dossier a garanzia.');
    }

    $values = is_array($parsed['values'] ?? null) ? $parsed['values'] : [];
    $dossiers = is_array($parsed['dossiers'] ?? null) ? $parsed['dossiers'] : [];
    $metadata = is_array($parsed['metadata'] ?? null) ? $parsed['metadata'] : [];
    $checkDate = trim((string) ($metadata['snapshot_date'] ?? $fallbackDate ?? ''));
    if ($checkDate === '') {
        throw new RuntimeException('Non riesco a determinare la data dell export patrimonio Credit Lombard.');
    }

    $matchedDossier = null;
    $nonZeroDossiers = [];
    foreach ($dossiers as $dossier) {
        if (abs((float) ($dossier['amount'] ?? 0)) >= 0.005) {
            $nonZeroDossiers[] = $dossier;
        }
        if ((string) ($dossier['external_identifier'] ?? '') === $expectedDossierIdentifier) {
            $matchedDossier = $dossier;
        }
    }

    if (!is_array($matchedDossier)) {
        $detected = array_values(array_filter(array_map(
            static fn(array $dossier): string => trim((string) ($dossier['external_identifier'] ?? '')),
            $dossiers
        )));
        $suffix = $detected !== [] ? ' Nel file ho rilevato: ' . implode(', ', array_unique($detected)) . '.' : '';
        throw new RuntimeException('Il file non appartiene al dossier a garanzia ' . $expectedDossierIdentifier . '.' . $suffix);
    }

    if (count($nonZeroDossiers) !== 1) {
        throw new RuntimeException('Il file contiene piu dossier valorizzati e sembra un patrimonio complessivo. Carica l export del solo rapporto Credit Lombard.');
    }

    $portfolioTotal = isset($values['fineco_portfolio_total']) ? (float) $values['fineco_portfolio_total'] : null;
    $finecoTotalAssets = isset($values['fineco_total_assets']) ? (float) $values['fineco_total_assets'] : null;
    $liquidityTotal = isset($values['liquidity_total']) ? (float) $values['liquidity_total'] : null;
    if ($portfolioTotal === null || $finecoTotalAssets === null || $liquidityTotal === null) {
        throw new RuntimeException('Nel file mancano patrimonio totale, totale portafoglio o totale liquidita del rapporto Credit Lombard.');
    }

    $dossierTotal = (float) ($matchedDossier['amount'] ?? 0);
    if (round($portfolioTotal, 2) !== round($dossierTotal, 2)) {
        throw new RuntimeException('Il totale portafoglio non coincide con il dossier a garanzia rilevato: il file non sembra dedicato al solo rapporto Credit Lombard.');
    }

    $internalDifference = round($finecoTotalAssets - $portfolioTotal - $liquidityTotal, 2);
    if (abs($internalDifference) < 0.005) {
        $internalDifference = 0.0;
    }

    return [
        'check_date' => $checkDate,
        'detected_dossier_identifier' => $expectedDossierIdentifier,
        'dossier_source_label' => (string) ($matchedDossier['source_label'] ?? ''),
        'fineco_total_assets' => $finecoTotalAssets,
        'accounting_balance' => isset($values['accounting_balance']) ? (float) $values['accounting_balance'] : null,
        'available_balance' => isset($values['available_balance']) ? (float) $values['available_balance'] : null,
        'liquidity_total' => $liquidityTotal,
        'portfolio_total' => $portfolioTotal,
        'dossier_total' => $dossierTotal,
        'internal_difference' => $internalDifference,
    ];
}

function credit_lombard_control_compare(array $control, ?array $expected): array
{
    $expectedMarketValue = is_array($expected) && isset($expected['total_market_value'])
        ? (float) $expected['total_market_value']
        : null;
    $expectedAccruedInterest = $expectedMarketValue !== null
        ? (float) ($expected['total_accrued_interest'] ?? 0)
        : null;
    $expectedTotal = $expectedMarketValue !== null
        ? round($expectedMarketValue + (float) $expectedAccruedInterest, 2)
        : null;
    $difference = $expectedTotal !== null
        ? round((float) $control['portfolio_total'] - $expectedTotal, 2)
        : null;
    if ($difference !== null && abs($difference) < 0.005) {
        $difference = 0.0;
    }

    $status = $expectedTotal === null
        ? 'NO_COMPARISON'
        : ($difference === 0.0 ? 'COHERENT' : 'DIFFERENCE');

    return $control + [
        'expected_snapshot_id' => is_array($expected) ? (int) ($expected['snapshot_id'] ?? 0) ?: null : null,
        'expected_snapshot_date' => is_array($expected) ? (string) ($expected['snapshot_date'] ?? '') ?: null : null,
        'expected_market_value' => $expectedMarketValue,
        'expected_accrued_interest' => $expectedAccruedInterest,
        'expected_portfolio_total' => $expectedTotal,
        'difference_amount' => $difference,
        'check_status' => $status,
    ];
}

function credit_lombard_control_expected_portfolio(PDO $pdo, array $creditLine, string $checkDate): ?array
{
    $dossierId = (int) ($creditLine['dossier_id'] ?? 0);
    if ($dossierId <= 0) {
        $dossier = account_tracker_find_dossier($pdo, 'credit_lombard');
        $dossierId = (int) ($dossier['id'] ?? 0);
    }
    if ($dossierId <= 0) {
        return null;
    }

    $accruedInterestSql = account_tracker_accrued_interest_schema_ready($pdo)
        ? 'SUM(p.accrued_interest)'
        : '0';
    $statement = $pdo->prepare(
        'SELECT s.id AS snapshot_id, s.snapshot_date,
                SUM(p.market_value) AS total_market_value,
                ' . $accruedInterestSql . ' AS total_accrued_interest,
                COUNT(p.id) AS positions_count
         FROM pf_snapshots s
         JOIN pf_snapshot_positions p ON p.snapshot_id = s.id
         WHERE s.snapshot_date = :snapshot_date AND p.dossier_id = :dossier_id
         GROUP BY s.id, s.snapshot_date'
    );
    $statement->execute([':snapshot_date' => $checkDate, ':dossier_id' => $dossierId]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function credit_lombard_control_save(PDO $pdo, int $creditLineId, string $sourceFileName, array $parsed, array $result): int
{
    if (!credit_lombard_control_schema_ready($pdo)) {
        throw new RuntimeException('Il controllo Credit Lombard non e aggiornato. Importa la migrazione SQL 2.7.1.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO pf_credit_lombard_checks
            (credit_line_id, check_date, source_file_name, detected_dossier_identifier, dossier_source_label,
             fineco_total_assets, accounting_balance, available_balance, liquidity_total, portfolio_total,
             dossier_total, internal_difference, expected_snapshot_id, expected_snapshot_date,
             expected_market_value, expected_accrued_interest, expected_portfolio_total,
             difference_amount, check_status, parsed_payload_json)
         VALUES
            (:credit_line_id, :check_date, :source_file_name, :detected_dossier_identifier, :dossier_source_label,
             :fineco_total_assets, :accounting_balance, :available_balance, :liquidity_total, :portfolio_total,
             :dossier_total, :internal_difference, :expected_snapshot_id, :expected_snapshot_date,
             :expected_market_value, :expected_accrued_interest, :expected_portfolio_total,
             :difference_amount, :check_status, :parsed_payload_json)'
    );
    $statement->execute([
        ':credit_line_id' => $creditLineId,
        ':check_date' => $result['check_date'],
        ':source_file_name' => basename($sourceFileName),
        ':detected_dossier_identifier' => $result['detected_dossier_identifier'],
        ':dossier_source_label' => $result['dossier_source_label'],
        ':fineco_total_assets' => $result['fineco_total_assets'],
        ':accounting_balance' => $result['accounting_balance'],
        ':available_balance' => $result['available_balance'],
        ':liquidity_total' => $result['liquidity_total'],
        ':portfolio_total' => $result['portfolio_total'],
        ':dossier_total' => $result['dossier_total'],
        ':internal_difference' => $result['internal_difference'],
        ':expected_snapshot_id' => $result['expected_snapshot_id'],
        ':expected_snapshot_date' => $result['expected_snapshot_date'],
        ':expected_market_value' => $result['expected_market_value'],
        ':expected_accrued_interest' => $result['expected_accrued_interest'],
        ':expected_portfolio_total' => $result['expected_portfolio_total'],
        ':difference_amount' => $result['difference_amount'],
        ':check_status' => $result['check_status'],
        ':parsed_payload_json' => json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return (int) $pdo->lastInsertId();
}

function credit_lombard_control_history(PDO $pdo, int $creditLineId, int $limit = 12): array
{
    if (!credit_lombard_control_schema_ready($pdo)) {
        return [];
    }

    $limit = max(1, min(100, $limit));
    $statement = $pdo->prepare(
        'SELECT * FROM pf_credit_lombard_checks
         WHERE credit_line_id = :credit_line_id
         ORDER BY check_date DESC, id DESC
         LIMIT ' . $limit
    );
    $statement->execute([':credit_line_id' => $creditLineId]);
    return $statement->fetchAll();
}

function credit_lombard_control_status_label(string $status): string
{
    return match ($status) {
        'COHERENT' => 'Coerente',
        'DIFFERENCE' => 'Differenza rilevata',
        default => 'Confronto non disponibile',
    };
}
