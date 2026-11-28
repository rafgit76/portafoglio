<?php

function economic_patrimony_cents(mixed $value): ?int
{
    return is_numeric($value) && is_finite((float) $value)
        ? (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP)
        : null;
}

function economic_patrimony_amount(?int $value): ?float
{
    return $value === null ? null : $value / 100;
}

function economic_patrimony_calculate(array $input): array
{
    $read = static fn(string $field): ?int => economic_patrimony_cents($input[$field] ?? null);
    $gross = $read('fineco_total_assets');
    $liquid = $read('liquidity_total');
    $portfolio = $read('portfolio');
    $agreed = $read('credit_agreed_amount');
    $available = $read('credit_available_amount');
    $used = $read('credit_used_amount');
    $booked = $read('accounting_balance');
    $euro = $read('available_balance');
    $reserved = $read('reserved_liquidity');
    $fx = $read('multicurrency_amount');
    $other = $read('documented_other_liquidity') ?? 0;
    $pending = $read('pending_operations');
    $scoped = $read('documented_credit_in_fineco');
    // Some exports omit the euro line but still provide its exact components and the separate fido balance.
    $euroDerived = $euro === null && $liquid !== null && $fx !== null && $available !== null;
    $euro ??= $euroDerived ? $liquid - $fx - $other : null;
    $expectedEuro = ($scoped ?? $agreed) !== null && $booked !== null && $reserved !== null ? ($scoped ?? $agreed) + $booked + $reserved : null;
    $expectedLiquid = $euro !== null && $fx !== null ? $euro + $fx + $other : null;
    $checks = [];
    $issues = [];
    $check = static function (string $label, ?int $a, ?int $b) use (&$checks, &$issues): ?int {
        $delta = $a !== null && $b !== null ? $a - $b : null;
        $checks[$label] = economic_patrimony_amount($delta);
        if ($delta !== null && abs($delta) > 1) {
            $issues[] = $label;
        }
        return $delta;
    };

    $balanceDelta = $check('Saldo disponibile vs saldo contabile + prenotazioni + fido accordato', $euro, $expectedEuro);
    $cashDelta = $check('Liquidita Fineco vs saldo disponibile + altre liquidita documentate', $liquid, $expectedLiquid);
    $check('Fido accordato vs utilizzato + disponibile', $agreed, $used !== null && $available !== null ? $used + $available : null);
    if (($scoped === null || $scoped === $agreed) && $agreed !== null) {
        if ($agreed > 0 && $euro !== null && $euro >= 0 && $euro <= $agreed) {
            $check('Saldo disponibile vs fido disponibile', $euro, $available);
        }
        if ($agreed === 0 && (($used ?? 0) !== 0 || ($available ?? 0) !== 0)) {
            $issues[] = 'Fido accordato nullo ma altre voci del fido non nulle';
        }
    }
    if ($scoped === null && ($agreed === 0 || ($agreed !== null && $agreed > 0 && $balanceDelta !== null && ($cashDelta !== null || ($pending === 0 && $liquid === null && $expectedLiquid !== null)) && abs($balanceDelta) <= 1 && abs($cashDelta ?? 0) <= 1))) {
        $scoped = $agreed;
    }
    if (($scoped !== null && $scoped < 0) || ($agreed !== null && $agreed < 0) || ($scoped !== null && $agreed !== null && $scoped > $agreed)) {
        $issues[] = 'Importo del fido incompatibile con il perimetro documentato';
        $scoped = null;
    }

    $sameCreditScope = $scoped !== null && $scoped === $agreed;
    $usedSource = $used === null ? null : 'Fido utilizzato riportato';
    if ($used === null && $agreed !== null && $available !== null && $available >= 0 && $available <= $agreed) {
        $used = $agreed - $available;
        $usedSource = 'Accordato meno fido disponibile';
    } elseif ($used === null && $available === null && $sameCreditScope && $agreed > 0 && $euro !== null && $euro >= 0 && $euro <= $agreed && $balanceDelta !== null && abs($balanceDelta) <= 1) {
        $used = $agreed - $euro;
        $usedSource = 'Implicito dal saldo disponibile EUR; include le prenotazioni';
    }
    $analyticLiquid = $scoped !== null && $booked !== null && $reserved !== null && $fx !== null ? $booked + $reserved + $fx + $other : null;
    $effectiveLiquid = $liquid ?? $expectedLiquid;
    $candidateNet = $effectiveLiquid !== null && ($scoped ?? $agreed) !== null ? $effectiveLiquid - ($scoped ?? $agreed) : null;
    $decomposed = $portfolio !== null && $candidateNet !== null ? $portfolio + $candidateNet : null;
    $rawDecomposed = $portfolio !== null && $effectiveLiquid !== null ? $portfolio + $effectiveLiquid : null;
    $candidate = $gross !== null && ($scoped ?? $agreed) !== null ? $gross - ($scoped ?? $agreed) : null;
    $analytic = $portfolio !== null && $analyticLiquid !== null ? $portfolio + $analyticLiquid : null;
    $check('Patrimonio Fineco vs portafoglio + liquidita + operazioni in corso', $gross, $rawDecomposed !== null ? $rawDecomposed + ($pending ?? 0) : null);
    $quadrature = $check('PNE vs portafoglio + liquidita economica', $candidate, $decomposed);
    $check('PNE vs componenti analitiche', $candidate, $analytic);
    if ($pending !== null && abs($pending) > 1) {
        $issues[] = 'Operazioni in corso: verificare il perimetro prima di confrontare il PNE';
    }

    $value = $candidate ?? ($pending === 0 ? $decomposed : null);
    $reconstructed = $gross === null || $liquid === null || $euroDerived;
    $inconsistent = $issues !== [];
    if ($scoped === null) {
        $issues[] = 'Fido incluso nel totale Fineco non dimostrato nello snapshot';
    } elseif ($value === null || $candidateNet === null) {
        $issues[] = 'Totali o componenti documentate insufficienti';
    }
    $status = $inconsistent ? 'DA VERIFICARE' : ($issues !== [] ? 'NON DISPONIBILE' : ($reconstructed ? 'RICOSTRUITO' : 'VERIFICATO'));
    $reliable = in_array($status, ['VERIFICATO', 'RICOSTRUITO'], true);

    return [
        'pne' => economic_patrimony_amount($reliable ? $value : null),
        'net_liquidity' => economic_patrimony_amount($reliable ? $candidateNet : null),
        'candidate' => economic_patrimony_amount($value),
        'candidate_net_liquidity' => economic_patrimony_amount($candidateNet),
        'pne_reconstructed' => economic_patrimony_amount($analytic),
        'portfolio' => economic_patrimony_amount($portfolio),
        'credit_included' => economic_patrimony_amount($scoped),
        'credit_used' => economic_patrimony_amount($used),
        'credit_used_derived' => $usedSource !== null && $usedSource !== 'Fido utilizzato riportato',
        'credit_used_source' => $usedSource,
        'fineco_total_assets' => economic_patrimony_amount($gross),
        'fineco_liquidity' => economic_patrimony_amount($liquid),
        'credit_agreed' => economic_patrimony_amount($agreed),
        'credit_available' => economic_patrimony_amount($available),
        'accounting_balance' => economic_patrimony_amount($booked),
        'available_balance' => economic_patrimony_amount($euro),
        'reserved_liquidity' => economic_patrimony_amount($reserved),
        'multicurrency' => economic_patrimony_amount($fx),
        'pending_operations' => economic_patrimony_amount($pending),
        'expected_available_balance' => economic_patrimony_amount($expectedEuro),
        'available_balance_delta' => economic_patrimony_amount($balanceDelta),
        'quadrature' => economic_patrimony_amount($quadrature),
        'checks' => $checks,
        'issues' => array_values(array_unique($issues)),
        'status' => $status,
        'reliable' => $reliable,
    ];
}

function economic_patrimony_changes(array $rows): array
{
    $latest = $rows === [] ? [] : $rows[array_key_last($rows)];
    $current = $latest['economic']['pne'] ?? null;
    $result = ['previous' => null, 'week' => null, 'month' => null, 'max' => null];
    if ($current === null) {
        return $result;
    }
    $end = new DateTimeImmutable($latest['snapshot_date']);
    $cutoffs = ['week' => $end->modify('-7 days')->format('Y-m-d'), 'month' => $end->modify('-30 days')->format('Y-m-d')];
    $references = [];
    $prior = array_slice($rows, 0, -1);
    $references['previous'] = $prior === [] ? null : $prior[array_key_last($prior)];
    foreach ($rows as $row) {
        $value = $row['economic']['pne'];
        if ($value !== null && (!isset($references['max']) || $value >= $references['max']['economic']['pne'])) {
            $references['max'] = $row;
        }
        foreach ($cutoffs as $key => $cutoff) {
            if ($row['snapshot_date'] <= $cutoff && $value !== null) {
                $references[$key] = $row;
            }
        }
    }
    foreach ($result as $key => $_) {
        $base = $references[$key]['economic']['pne'] ?? null;
        if ($base !== null) {
            $delta = round($current - $base, 2);
            $result[$key] = ['date' => $references[$key]['snapshot_date'], 'delta' => $delta, 'pct' => $base == 0 ? null : $delta / abs($base) * 100];
        }
    }
    return $result;
}
