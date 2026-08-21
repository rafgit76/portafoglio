<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function account_tracker_schema_ready(?PDO $pdo = null): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $pdo ??= db();
    try {
        $tables = ['pf_dossiers', 'pf_patrimonial_snapshots', 'pf_patrimonial_dossier_values', 'pf_patrimonial_import_jobs', 'pf_credit_lines', 'pf_credit_line_snapshots', 'pf_internal_transfers', 'pf_snapshot_revisions'];
        foreach ($tables as $table) {
            // SHOW TABLES LIKE cannot be parameterized with native MySQL prepares on every hosting runtime.
            // Table names are a fixed internal allow-list, so this only verifies that each required table is readable.
            $statement = $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            if ($statement === false) {
                return $ready = false;
            }
        }
        $requiredPatrimonialColumns = [
            'fineco_portfolio_total',
            'reserved_liquidity',
            'net_assets_adjusted',
            'own_liquidity_adjusted',
        ];
        $columns = $pdo->query('SHOW COLUMNS FROM `pf_patrimonial_snapshots`')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($requiredPatrimonialColumns as $column) {
            if (!in_array($column, $columns, true)) {
                return $ready = false;
            }
        }
    } catch (Throwable) {
        return $ready = false;
    }

    return $ready = true;
}

function account_tracker_dossiers(PDO $pdo, bool $includeInactive = false): array
{
    if (!account_tracker_schema_ready($pdo)) {
        return [];
    }

    $sql = 'SELECT id, code, name, dossier_type, is_active, sort_order, notes FROM pf_dossiers';
    if (!$includeInactive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, name ASC';

    return $pdo->query($sql)->fetchAll();
}

function account_tracker_find_dossier(PDO $pdo, string $code): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, code, name, dossier_type, is_active, sort_order, notes
         FROM pf_dossiers WHERE code = :code LIMIT 1'
    );
    $statement->execute([':code' => $code]);
    $dossier = $statement->fetch();

    return is_array($dossier) ? $dossier : null;
}

function account_tracker_default_dossier(PDO $pdo): ?array
{
    return account_tracker_find_dossier($pdo, 'ordinario');
}

function account_tracker_optional_decimal(mixed $value, string $label): ?float
{
    if ($value === null || trim((string) $value) === '') {
        return null;
    }

    $parsed = parse_decimal_nullable($value);
    if ($parsed === null) {
        throw new RuntimeException('Valore non valido per ' . $label . '.');
    }

    return $parsed;
}

function account_tracker_get_or_create_snapshot(PDO $pdo, string $snapshotDate, ?string $sourceFileName = null, ?string $notes = null): int
{
    $statement = $pdo->prepare('SELECT id FROM pf_snapshots WHERE snapshot_date = :snapshot_date LIMIT 1');
    $statement->execute([':snapshot_date' => $snapshotDate]);
    $existing = (int) $statement->fetchColumn();
    if ($existing > 0) {
        return $existing;
    }

    $insert = $pdo->prepare(
        'INSERT INTO pf_snapshots
            (snapshot_date, source_file_name, notes, total_market_value, total_cost_basis, total_unrealized_pnl)
         VALUES (:snapshot_date, :source_file_name, :notes, 0, 0, 0)'
    );
    $insert->execute([
        ':snapshot_date' => $snapshotDate,
        ':source_file_name' => $sourceFileName,
        ':notes' => $notes,
    ]);

    return (int) $pdo->lastInsertId();
}

function account_tracker_snapshot_payload(PDO $pdo, int $snapshotId): array
{
    $snapshotStatement = $pdo->prepare('SELECT * FROM pf_snapshots WHERE id = :id LIMIT 1');
    $snapshotStatement->execute([':id' => $snapshotId]);
    $snapshot = $snapshotStatement->fetch();

    $positionsStatement = $pdo->prepare(
        'SELECT p.*, a.name AS asset_name, a.isin, a.ticker, d.code AS dossier_origin, d.name AS dossier_name
         FROM pf_snapshot_positions p
         JOIN pf_assets a ON a.id = p.asset_id
         LEFT JOIN pf_dossiers d ON d.id = p.dossier_id
         WHERE p.snapshot_id = :snapshot_id
         ORDER BY p.id ASC'
    );
    $positionsStatement->execute([':snapshot_id' => $snapshotId]);

    return [
        'snapshot' => is_array($snapshot) ? $snapshot : null,
        'positions' => $positionsStatement->fetchAll(),
    ];
}

function account_tracker_backup_snapshot(PDO $pdo, int $snapshotId, string $revisionType, array $details = []): int
{
    $payload = json_encode(account_tracker_snapshot_payload($pdo, $snapshotId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('Impossibile creare il backup logico dello snapshot.');
    }

    $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($detailsJson)) {
        $detailsJson = '{}';
    }

    $statement = $pdo->prepare(
        'INSERT INTO pf_snapshot_revisions (snapshot_id, revision_type, before_payload, details_json)
         VALUES (:snapshot_id, :revision_type, :before_payload, :details_json)'
    );
    $statement->execute([
        ':snapshot_id' => $snapshotId,
        ':revision_type' => $revisionType,
        ':before_payload' => $payload,
        ':details_json' => $detailsJson,
    ]);

    return (int) $pdo->lastInsertId();
}

function account_tracker_finalize_snapshot_backup(PDO $pdo, int $revisionId, int $snapshotId): void
{
    if ($revisionId <= 0) {
        return;
    }

    $payload = json_encode(account_tracker_snapshot_payload($pdo, $snapshotId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('Impossibile finalizzare il backup logico dello snapshot.');
    }

    $statement = $pdo->prepare(
        'UPDATE pf_snapshot_revisions SET after_payload = :after_payload, applied_at = NOW() WHERE id = :id'
    );
    $statement->execute([':after_payload' => $payload, ':id' => $revisionId]);
}

function account_tracker_recalculate_snapshot(PDO $pdo, int $snapshotId): array
{
    $totalsStatement = $pdo->prepare(
        'SELECT
            COALESCE(SUM(cost_basis), 0) AS total_cost_basis,
            COALESCE(SUM(market_value), 0) AS total_market_value,
            COALESCE(SUM(unrealized_pnl), 0) AS total_unrealized_pnl
         FROM pf_snapshot_positions
         WHERE snapshot_id = :snapshot_id'
    );
    $totalsStatement->execute([':snapshot_id' => $snapshotId]);
    $totals = $totalsStatement->fetch() ?: [];

    $marketValue = (float) ($totals['total_market_value'] ?? 0);
    $updateSnapshot = $pdo->prepare(
        'UPDATE pf_snapshots
         SET total_market_value = :market_value,
             total_cost_basis = :cost_basis,
             total_unrealized_pnl = :unrealized_pnl
         WHERE id = :snapshot_id'
    );
    $updateSnapshot->execute([
        ':market_value' => $marketValue,
        ':cost_basis' => (float) ($totals['total_cost_basis'] ?? 0),
        ':unrealized_pnl' => (float) ($totals['total_unrealized_pnl'] ?? 0),
        ':snapshot_id' => $snapshotId,
    ]);

    $updateWeight = $pdo->prepare(
        'UPDATE pf_snapshot_positions
         SET weight_pct = CASE WHEN :weight_threshold > 0 THEN market_value / :weight_divisor ELSE 0 END,
             imported_weight_pct = CASE WHEN :imported_weight_threshold > 0 THEN market_value / :imported_weight_divisor ELSE 0 END
         WHERE snapshot_id = :snapshot_id'
    );
    $updateWeight->execute([
        ':weight_threshold' => $marketValue,
        ':weight_divisor' => $marketValue,
        ':imported_weight_threshold' => $marketValue,
        ':imported_weight_divisor' => $marketValue,
        ':snapshot_id' => $snapshotId,
    ]);

    return [
        'total_market_value' => $marketValue,
        'total_cost_basis' => (float) ($totals['total_cost_basis'] ?? 0),
        'total_unrealized_pnl' => (float) ($totals['total_unrealized_pnl'] ?? 0),
    ];
}

function account_tracker_snapshot_dossier_totals(PDO $pdo, int $snapshotId): array
{
    if (!account_tracker_schema_ready($pdo)) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT d.id, d.code, d.name, d.dossier_type,
                COALESCE(SUM(p.quantity), 0) AS total_quantity,
                COALESCE(SUM(p.cost_basis), 0) AS total_cost_basis,
                COALESCE(SUM(p.market_value), 0) AS total_market_value,
                COALESCE(SUM(p.unrealized_pnl), 0) AS total_unrealized_pnl,
                COUNT(p.id) AS positions_count
         FROM pf_dossiers d
         LEFT JOIN pf_snapshot_positions p ON p.dossier_id = d.id AND p.snapshot_id = :snapshot_id
         WHERE d.is_active = 1
         GROUP BY d.id, d.code, d.name, d.dossier_type, d.sort_order
         HAVING COUNT(p.id) > 0
         ORDER BY d.sort_order ASC, d.name ASC'
    );
    $statement->execute([':snapshot_id' => $snapshotId]);

    return $statement->fetchAll();
}

function account_tracker_consolidated_positions(PDO $pdo, int $snapshotId): array
{
    if (!account_tracker_schema_ready($pdo)) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT a.id AS asset_id, a.name, a.isin, a.ticker, a.asset_type, a.currency AS asset_currency,
                SUM(p.quantity) AS quantity,
                CASE WHEN SUM(p.quantity) <> 0 THEN SUM(p.avg_load_price * p.quantity) / SUM(p.quantity) ELSE 0 END AS avg_load_price,
                CASE WHEN SUM(p.quantity) <> 0 THEN SUM(p.current_price * p.quantity) / SUM(p.quantity) ELSE 0 END AS current_price,
                SUM(p.cost_basis) AS cost_basis,
                SUM(p.market_value) AS market_value,
                SUM(p.unrealized_pnl) AS unrealized_pnl,
                CASE WHEN s.total_market_value > 0 THEN SUM(p.market_value) / s.total_market_value ELSE 0 END AS weight_pct,
                GROUP_CONCAT(DISTINCT d.name ORDER BY d.sort_order SEPARATOR ", ") AS dossier_names,
                GROUP_CONCAT(DISTINCT d.code ORDER BY d.sort_order SEPARATOR ",") AS dossier_origins,
                COUNT(DISTINCT p.dossier_id) AS dossier_count
         FROM pf_snapshot_positions p
         JOIN pf_assets a ON a.id = p.asset_id
         JOIN pf_snapshots s ON s.id = p.snapshot_id
         JOIN pf_dossiers d ON d.id = p.dossier_id
         WHERE p.snapshot_id = :snapshot_id
         GROUP BY a.id, a.name, a.isin, a.ticker, a.asset_type, a.currency, s.total_market_value
         ORDER BY market_value DESC'
    );
    $statement->execute([':snapshot_id' => $snapshotId]);

    return $statement->fetchAll();
}

function account_tracker_latest_patrimonial_snapshot(PDO $pdo): ?array
{
    if (!account_tracker_schema_ready($pdo)) {
        return null;
    }

    $row = $pdo->query('SELECT * FROM pf_patrimonial_snapshots ORDER BY snapshot_date DESC, id DESC LIMIT 1')->fetch();
    return is_array($row) ? $row : null;
}

function account_tracker_patrimonial_dossier_values(PDO $pdo, int $patrimonialSnapshotId): array
{
    if (!account_tracker_schema_ready($pdo)) {
        return [];
    }
    $statement = $pdo->prepare(
        'SELECT v.*, d.name AS dossier_name, d.code AS dossier_code
         FROM pf_patrimonial_dossier_values v
         LEFT JOIN pf_dossiers d ON d.id = v.dossier_id
         WHERE v.patrimonial_snapshot_id = :id
         ORDER BY v.amount DESC, v.id ASC'
    );
    $statement->execute([':id' => $patrimonialSnapshotId]);
    return $statement->fetchAll();
}

function account_tracker_credit_summary(PDO $pdo, ?string $snapshotDate = null): array
{
    $empty = ['agreed_amount' => null, 'used_amount' => null, 'available_amount' => null, 'lines_count' => 0];
    if (!account_tracker_schema_ready($pdo)) {
        return $empty;
    }

    if ($snapshotDate !== null) {
        $statement = $pdo->prepare(
            'SELECT SUM(agreed_amount) AS agreed_amount, SUM(used_amount) AS used_amount, COUNT(*) AS lines_count
             FROM pf_credit_line_snapshots WHERE snapshot_date = :snapshot_date'
        );
        $statement->execute([':snapshot_date' => $snapshotDate]);
        $row = $statement->fetch();
        if ((int) ($row['lines_count'] ?? 0) > 0) {
            $agreed = (float) $row['agreed_amount'];
            $used = (float) $row['used_amount'];
            return ['agreed_amount' => $agreed, 'used_amount' => $used, 'available_amount' => $agreed - $used, 'lines_count' => (int) $row['lines_count']];
        }
    }

    $row = $pdo->query(
        "SELECT SUM(agreed_amount) AS agreed_amount, SUM(used_amount) AS used_amount, COUNT(*) AS lines_count
         FROM pf_credit_lines WHERE status = 'ACTIVE'"
    )->fetch();
    if ((int) ($row['lines_count'] ?? 0) === 0) {
        return $empty;
    }

    $agreed = (float) $row['agreed_amount'];
    $used = (float) $row['used_amount'];
    return ['agreed_amount' => $agreed, 'used_amount' => $used, 'available_amount' => $agreed - $used, 'lines_count' => (int) $row['lines_count']];
}

function account_tracker_reconstructed_wealth(?float $portfolioValue, ?float $liquidityValue, ?float $creditUsed): array
{
    $gross = $portfolioValue !== null && $liquidityValue !== null ? $portfolioValue + $liquidityValue : null;
    return [
        'gross' => $gross,
        'net_adjusted' => $gross !== null && $creditUsed !== null ? $gross - $creditUsed : null,
    ];
}

function account_tracker_snapshot_decimal(array $snapshot, string $field): ?float
{
    if (array_key_exists($field, $snapshot) && $snapshot[$field] !== null && $snapshot[$field] !== '') {
        return (float) $snapshot[$field];
    }

    // Le prime importazioni 2.2 hanno gia conservato il payload Fineco: lo
    // riutilizziamo in sola lettura per i nuovi campi, senza alterare lo storico.
    foreach (['manual_overrides_json', 'imported_values_json'] as $jsonField) {
        $decoded = json_decode((string) ($snapshot[$jsonField] ?? ''), true);
        if (is_array($decoded) && array_key_exists($field, $decoded) && $decoded[$field] !== null && $decoded[$field] !== '') {
            return (float) $decoded[$field];
        }
    }

    return null;
}

/**
 * Fineco puo includere il credito disponibile nei totali esposti. Questi valori
 * mantengono separati il dato Fineco, la liquidita depurata e il credito.
 */
function account_tracker_patrimonial_adjustments(array $values): array
{
    $finecoTotal = isset($values['fineco_total_assets']) && $values['fineco_total_assets'] !== null
        ? (float) $values['fineco_total_assets']
        : null;
    $liquidityFineco = isset($values['liquidity_total']) && $values['liquidity_total'] !== null
        ? (float) $values['liquidity_total']
        : null;
    $creditAvailable = isset($values['credit_available_amount']) && $values['credit_available_amount'] !== null
        ? (float) $values['credit_available_amount']
        : null;

    return [
        'net_assets_adjusted' => $finecoTotal !== null && $creditAvailable !== null
            ? $finecoTotal - $creditAvailable
            : null,
        'own_liquidity_adjusted' => $liquidityFineco !== null && $creditAvailable !== null
            ? $liquidityFineco - $creditAvailable
            : null,
    ];
}

function account_tracker_credit_values_from_snapshot(array $snapshot, PDO $pdo): array
{
    $hasImportedCredit = $snapshot['credit_agreed_amount'] !== null
        || $snapshot['credit_used_amount'] !== null
        || $snapshot['credit_available_amount'] !== null;
    if (!$hasImportedCredit) {
        return account_tracker_credit_summary($pdo, (string) $snapshot['snapshot_date']);
    }

    $agreed = $snapshot['credit_agreed_amount'] !== null ? (float) $snapshot['credit_agreed_amount'] : null;
    $available = $snapshot['credit_available_amount'] !== null ? (float) $snapshot['credit_available_amount'] : null;
    $used = account_tracker_effective_credit_used(
        $agreed,
        $snapshot['credit_used_amount'] !== null ? (float) $snapshot['credit_used_amount'] : null,
        $available
    );

    return [
        'agreed_amount' => $agreed,
        'used_amount' => $used,
        'available_amount' => $available,
        'lines_count' => 1,
        'data_origin' => (string) ($snapshot['data_origin'] ?? 'IMPORT_FINECO'),
    ];
}

function account_tracker_effective_credit_used(?float $agreed, ?float $used, ?float $available): ?float
{
    return $used ?? ($agreed !== null && $available !== null ? $agreed - $available : null);
}

/**
 * Restituisce un estremo di una serie storica senza inventare valori mancanti.
 * Il campo data resta quello della riga sorgente, utile per badge e grafici.
 */
function account_tracker_history_extreme(array $rows, string $field, string $direction): ?array
{
    if (!in_array($direction, ['max', 'min'], true)) {
        throw new InvalidArgumentException('Direzione estremo non valida.');
    }

    $extreme = null;
    foreach ($rows as $row) {
        if (!is_array($row) || !array_key_exists($field, $row) || $row[$field] === null || !is_numeric($row[$field])) {
            continue;
        }

        $value = (float) $row[$field];
        if ($extreme === null || ($direction === 'max' && $value > $extreme['value']) || ($direction === 'min' && $value < $extreme['value'])) {
            $extreme = [
                'value' => $value,
                'date' => (string) ($row['snapshot_date'] ?? ''),
            ];
        }
    }

    return $extreme;
}

function account_tracker_transfer_quantities_match(float $sourceQuantity, float $targetQuantity): bool
{
    $quantityTolerance = max(0.01, abs($sourceQuantity) * 0.001);
    return abs($targetQuantity - $sourceQuantity) <= $quantityTolerance;
}

function account_tracker_patrimonial_metrics(PDO $pdo): array
{
    $patrimonial = account_tracker_latest_patrimonial_snapshot($pdo);
    if ($patrimonial === null) {
        return ['snapshot' => null, 'portfolio' => null, 'credit' => account_tracker_credit_summary($pdo), 'reconstructed_gross' => null, 'net_adjusted' => null, 'difference_amount' => null, 'difference_pct' => null];
    }

    $portfolioStatement = $pdo->prepare(
        'SELECT id, snapshot_date, total_market_value FROM pf_snapshots WHERE snapshot_date = :snapshot_date LIMIT 1'
    );
    $portfolioStatement->execute([':snapshot_date' => $patrimonial['snapshot_date']]);
    $portfolio = $portfolioStatement->fetch();
    $positionPortfolioValue = $portfolio ? (float) $portfolio['total_market_value'] : null;
    $reportedPortfolioValue = account_tracker_snapshot_decimal($patrimonial, 'fineco_portfolio_total') ?? $positionPortfolioValue;
    $credit = account_tracker_credit_values_from_snapshot($patrimonial, $pdo);
    $adjustments = account_tracker_patrimonial_adjustments([
        'fineco_total_assets' => account_tracker_snapshot_decimal($patrimonial, 'fineco_total_assets'),
        'liquidity_total' => account_tracker_snapshot_decimal($patrimonial, 'liquidity_total'),
        'credit_available_amount' => $credit['available_amount'],
    ]);
    $ownLiquidity = $adjustments['own_liquidity_adjusted'];
    $wealth = account_tracker_reconstructed_wealth($reportedPortfolioValue, $ownLiquidity, null);
    $gross = $wealth['gross'];
    $net = $adjustments['net_assets_adjusted'];
    $finecoTotal = account_tracker_snapshot_decimal($patrimonial, 'fineco_total_assets');
    $difference = $net !== null && $gross !== null ? $net - $gross : null;
    $differencePct = $difference !== null && $net !== null && abs($net) > 0.000001 ? ($difference / $net) * 100 : null;

    $dossierValues = account_tracker_patrimonial_dossier_values($pdo, (int) $patrimonial['id']);
    $dossierTotal = $dossierValues === [] ? null : array_sum(array_map(static fn(array $row): float => (float) $row['amount'], $dossierValues));
    $portfolioDossierDifference = $reportedPortfolioValue !== null && $dossierTotal !== null ? $reportedPortfolioValue - $dossierTotal : null;
    $reservedLiquidity = account_tracker_snapshot_decimal($patrimonial, 'reserved_liquidity');
    $availableBalanceCheck = $patrimonial['accounting_balance'] !== null && $reservedLiquidity !== null && $credit['available_amount'] !== null
        ? (float) $patrimonial['accounting_balance'] + $reservedLiquidity + (float) $credit['available_amount']
        : null;
    $availableBalanceCheckDifference = $availableBalanceCheck !== null && $patrimonial['available_balance'] !== null
        ? (float) $patrimonial['available_balance'] - $availableBalanceCheck
        : null;

    return [
        'snapshot' => $patrimonial,
        'portfolio' => $portfolio ?: null,
        'position_portfolio_value' => $positionPortfolioValue,
        'reported_portfolio_value' => $reportedPortfolioValue,
        'credit' => $credit,
        'reconstructed_gross' => $gross,
        'net_adjusted' => $net,
        'own_liquidity_adjusted' => $ownLiquidity,
        'difference_amount' => $difference,
        'difference_pct' => $differencePct,
        'imported_dossiers' => $dossierValues,
        'imported_dossier_total' => $dossierTotal,
        'portfolio_dossier_difference' => $portfolioDossierDifference,
        'available_balance_check' => $availableBalanceCheck,
        'available_balance_check_difference' => $availableBalanceCheckDifference,
    ];
}

function account_tracker_detect_transfer_candidates(PDO $pdo, int $snapshotId, int $targetDossierId): array
{
    if (!account_tracker_schema_ready($pdo)) {
        return [];
    }

    $targetDossierStatement = $pdo->prepare('SELECT id, code FROM pf_dossiers WHERE id = :id LIMIT 1');
    $targetDossierStatement->execute([':id' => $targetDossierId]);
    $targetDossier = $targetDossierStatement->fetch();
    if (!$targetDossier || (string) $targetDossier['code'] === 'ordinario') {
        return [];
    }

    $snapshotStatement = $pdo->prepare('SELECT snapshot_date FROM pf_snapshots WHERE id = :id LIMIT 1');
    $snapshotStatement->execute([':id' => $snapshotId]);
    $snapshotDate = (string) $snapshotStatement->fetchColumn();
    if ($snapshotDate === '') {
        return [];
    }

    $targetPositions = $pdo->prepare(
        'SELECT p.id, p.asset_id, p.quantity, p.cost_basis, p.market_value
         FROM pf_snapshot_positions p
         WHERE p.snapshot_id = :snapshot_id AND p.dossier_id = :dossier_id'
    );
    $targetPositions->execute([':snapshot_id' => $snapshotId, ':dossier_id' => $targetDossierId]);

    $findPrevious = $pdo->prepare(
        'SELECT p.id, p.snapshot_id, p.dossier_id, p.quantity, p.cost_basis, p.market_value
         FROM pf_snapshot_positions p
         JOIN pf_snapshots s ON s.id = p.snapshot_id
         WHERE p.asset_id = :asset_id AND p.dossier_id <> :target_dossier_id AND s.snapshot_date < :snapshot_date
         ORDER BY s.snapshot_date DESC, s.id DESC LIMIT 1'
    );
    $existsCurrentOther = $pdo->prepare(
        'SELECT COUNT(*) FROM pf_snapshot_positions
         WHERE snapshot_id = :snapshot_id AND asset_id = :asset_id AND dossier_id <> :target_dossier_id'
    );
    $existsTransfer = $pdo->prepare(
        'SELECT id FROM pf_internal_transfers
         WHERE target_snapshot_id = :target_snapshot_id AND asset_id = :asset_id AND target_dossier_id = :target_dossier_id LIMIT 1'
    );
    $insert = $pdo->prepare(
        'INSERT INTO pf_internal_transfers
            (asset_id, source_snapshot_id, target_snapshot_id, source_dossier_id, target_dossier_id, quantity, source_cost_basis, target_cost_basis, confidence, status, detected_reason)
         VALUES
            (:asset_id, :source_snapshot_id, :target_snapshot_id, :source_dossier_id, :target_dossier_id, :quantity, :source_cost_basis, :target_cost_basis, :confidence, "PENDING", :detected_reason)'
    );

    $created = [];
    foreach ($targetPositions->fetchAll() as $target) {
        $existsCurrentOther->execute([':snapshot_id' => $snapshotId, ':asset_id' => $target['asset_id'], ':target_dossier_id' => $targetDossierId]);
        if ((int) $existsCurrentOther->fetchColumn() > 0) {
            continue;
        }

        $findPrevious->execute([':asset_id' => $target['asset_id'], ':target_dossier_id' => $targetDossierId, ':snapshot_date' => $snapshotDate]);
        $previous = $findPrevious->fetch();
        if (!$previous) {
            continue;
        }

        $quantity = (float) $target['quantity'];
        $previousQuantity = (float) $previous['quantity'];
        if (!account_tracker_transfer_quantities_match($previousQuantity, $quantity)) {
            continue;
        }

        $existsTransfer->execute([':target_snapshot_id' => $snapshotId, ':asset_id' => $target['asset_id'], ':target_dossier_id' => $targetDossierId]);
        if ((int) $existsTransfer->fetchColumn() > 0) {
            continue;
        }

        $insert->execute([
            ':asset_id' => $target['asset_id'],
            ':source_snapshot_id' => $previous['snapshot_id'],
            ':target_snapshot_id' => $snapshotId,
            ':source_dossier_id' => $previous['dossier_id'],
            ':target_dossier_id' => $targetDossierId,
            ':quantity' => $quantity,
            ':source_cost_basis' => $previous['cost_basis'],
            ':target_cost_basis' => $target['cost_basis'],
            ':confidence' => 'HIGH',
            ':detected_reason' => 'Stesso ISIN e quantita coerente tra dossier diversi in snapshot consecutivi.',
        ]);
        $created[] = (int) $pdo->lastInsertId();
    }

    return $created;
}

function account_tracker_pending_transfers(PDO $pdo): array
{
    if (!account_tracker_schema_ready($pdo)) {
        return [];
    }

    return $pdo->query(
        'SELECT t.*, a.name AS asset_name, a.isin, from_d.name AS source_dossier_name, to_d.name AS target_dossier_name,
                source_s.snapshot_date AS source_snapshot_date, target_s.snapshot_date AS target_snapshot_date
         FROM pf_internal_transfers t
         JOIN pf_assets a ON a.id = t.asset_id
         JOIN pf_dossiers from_d ON from_d.id = t.source_dossier_id
         JOIN pf_dossiers to_d ON to_d.id = t.target_dossier_id
         JOIN pf_snapshots source_s ON source_s.id = t.source_snapshot_id
         JOIN pf_snapshots target_s ON target_s.id = t.target_snapshot_id
         WHERE t.status = "PENDING"
         ORDER BY target_s.snapshot_date DESC, t.id DESC'
    )->fetchAll();
}

function account_tracker_set_transfer_status(PDO $pdo, int $transferId, string $status): void
{
    if (!in_array($status, ['CONFIRMED', 'REJECTED'], true)) {
        throw new RuntimeException('Stato trasferimento non valido.');
    }

    $statement = $pdo->prepare(
        'UPDATE pf_internal_transfers
         SET status = :status, confirmed_at = CASE WHEN :status = "CONFIRMED" THEN NOW() ELSE NULL END
         WHERE id = :id AND status = "PENDING"'
    );
    $statement->execute([':status' => $status, ':id' => $transferId]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Trasferimento non disponibile per la conferma.');
    }
}
