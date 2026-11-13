<?php
declare(strict_types=1);

function lombard_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}

function lombard_rate_for_date(array $rates, string $date): ?array
{
    $selected = null;
    foreach ($rates as $rate) {
        $from = (string) ($rate['valid_from'] ?? '');
        if ($from !== '' && $from <= $date && ($selected === null || $from > (string) $selected['valid_from'])) {
            $selected = $rate;
        }
    }
    return $selected;
}

function lombard_daily_interest(float $usage, ?array $rate): ?float
{
    if ($usage <= 0) {
        return 0.0;
    }
    if ($rate === null || !is_numeric($rate['tan'] ?? null) || !is_numeric($rate['overdraft_tan'] ?? null) || !is_numeric($rate['credit_limit'] ?? null)) {
        return null;
    }
    $limit = (float) $rate['credit_limit'];
    if ($limit <= 0 || (float) $rate['tan'] < 0 || (float) $rate['overdraft_tan'] < 0) {
        return null;
    }
    return (min($usage, $limit) * (float) $rate['tan'] + max(0.0, $usage - $limit) * (float) $rate['overdraft_tan']) / 365;
}

/** Uses observed dates only: no balance is carried across missing days. */
function lombard_monitor(array $snapshots, array $rates, string $activationDate): array
{
    $activation = lombard_date($activationDate);
    if ($activation === null) {
        throw new InvalidArgumentException('Data di attivazione Credit Lombard non disponibile.');
    }
    usort($snapshots, static fn(array $a, array $b): int => strcmp((string) ($a['snapshot_date'] ?? ''), (string) ($b['snapshot_date'] ?? '')));
    $rows = [];
    $byDate = [];
    $total = 0.0;
    $yearTotals = [];
    $missingRate = 0;
    $positiveDays = 0;
    $firstUse = null;
    $max = null;
    foreach ($snapshots as $snapshot) {
        $date = (string) ($snapshot['snapshot_date'] ?? '');
        if (lombard_date($date) === null) {
            throw new InvalidArgumentException('Data snapshot non valida.');
        }
        if ($date < $activationDate) {
            continue;
        }
        if (isset($byDate[$date])) {
            throw new InvalidArgumentException('Snapshot patrimoniali duplicati per la stessa data.');
        }
        $balance = $snapshot['accounting_balance'] ?? null;
        $balance = $balance !== null && is_numeric($balance) ? (float) $balance : null;
        $usage = $balance === null ? null : max(0.0, -$balance);
        $rate = lombard_rate_for_date($rates, $date);
        $interest = $usage === null ? null : lombard_daily_interest($usage, $rate);
        if ($usage !== null && $usage > 0) {
            $positiveDays++;
            $firstUse ??= $date;
            if ($max === null || $usage > $max['usage']) {
                $max = ['date' => $date, 'usage' => $usage, 'limit' => isset($rate['credit_limit']) ? (float) $rate['credit_limit'] : null];
            }
            if ($interest === null) {
                $missingRate++;
            }
        }
        if ($interest !== null) {
            $total += $interest;
            $year = substr($date, 0, 4);
            $yearTotals[$year] = ($yearTotals[$year] ?? 0.0) + $interest;
        }
        $row = ['date' => $date, 'balance' => $balance, 'usage' => $usage, 'rate' => $rate, 'interest' => $interest, 'cumulative' => $total];
        $rows[] = $row;
        $byDate[$date] = $row;
    }
    if ($rows === []) {
        return ['rows' => [], 'activation' => $activationDate];
    }
    $latest = $rows[array_key_last($rows)];
    $lastDate = lombard_date($latest['date']);
    $calendarDays = (int) $activation->diff($lastDate)->format('%a') + 1;
    $knownBalanceDays = count(array_filter($rows, static fn(array $row): bool => $row['balance'] !== null));
    $missingDays = max(0, $calendarDays - $knownBalanceDays);
    $year = substr($latest['date'], 0, 4);
    $yearStart = max($activationDate, $year . '-01-01');
    $yearCalendarDays = (int) lombard_date($yearStart)->diff($lastDate)->format('%a') + 1;
    $yearKnown = count(array_filter($rows, static fn(array $row): bool => $row['date'] >= $yearStart && $row['balance'] !== null));
    $yearMissingRates = count(array_filter($rows, static fn(array $row): bool => $row['date'] >= $yearStart && $row['usage'] !== null && $row['usage'] > 0 && $row['interest'] === null));
    $yearMissingDays = max(0, $yearCalendarDays - $yearKnown);
    $consecutive = $latest['usage'] === null ? null : 0;
    $streakIncomplete = false;
    for ($day = $lastDate; $day >= $activation; $day = $day->modify('-1 day')) {
        $row = $byDate[$day->format('Y-m-d')] ?? null;
        if ($row === null || $row['usage'] === null) {
            $streakIncomplete = $consecutive !== null && $consecutive > 0;
            break;
        }
        if ($row['usage'] <= 0) {
            break;
        }
        $consecutive++;
    }
    $dynamic = null;
    foreach ([30, 14, 7] as $window) {
        $values = [];
        for ($i = 0; $i < $window; $i++) {
            $row = $byDate[$lastDate->modify('-' . $i . ' days')->format('Y-m-d')] ?? null;
            if ($row === null || $row['usage'] === null) {
                break;
            }
            $values[] = $row['usage'];
        }
        if (count($values) === $window) {
            $dynamic = ['window' => $window, 'average' => array_sum($values) / $window];
            break;
        }
    }
    $remaining = (int) $lastDate->diff(new DateTimeImmutable($year . '-12-31'))->format('%a');
    $yearInterest = $yearTotals[$year] ?? 0.0;
    $fixedDaily = $latest['usage'] === null ? null : lombard_daily_interest($latest['usage'], $latest['rate']);
    $dynamicDaily = $dynamic === null ? null : lombard_daily_interest($dynamic['average'], $latest['rate']);
    return [
        'rows' => $rows, 'latest' => $latest, 'activation' => $activationDate,
        'total' => $total, 'year_total' => $yearInterest, 'year' => $year,
        'missing_days' => $missingDays, 'missing_rates' => $missingRate,
        'year_missing_days' => $yearMissingDays, 'year_missing_rates' => $yearMissingRates,
        'positive_days' => $positiveDays, 'first_use' => $firstUse, 'max' => $max,
        'streak' => $consecutive, 'streak_incomplete' => $streakIncomplete,
        'dynamic' => $dynamic, 'remaining_days' => $remaining,
        'fixed_projection' => $fixedDaily === null ? null : $yearInterest + $fixedDaily * $remaining,
        'dynamic_projection' => $dynamicDaily === null ? null : $yearInterest + $dynamicDaily * $remaining,
    ];
}
