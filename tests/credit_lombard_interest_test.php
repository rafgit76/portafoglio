<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/credit_lombard_interest.php';

$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('Test fallito: ' . $message);
    }
    $checks++;
};
$close = static fn(?float $actual, float $expected): bool => $actual !== null && abs($actual - $expected) < 0.00000001;
$rate = ['valid_from' => '2026-08-07', 'tan' => 0.034, 'overdraft_tan' => 0.1115, 'credit_limit' => 12000];
$assert($close(lombard_daily_interest(0, null), 0), 'zero non richiede tasso');
$assert($close(lombard_daily_interest(3071.67, $rate), 3071.67 * 0.034 / 365), 'utilizzo entro plafond');
$assert($close(lombard_daily_interest(12000, $rate), 12000 * 0.034 / 365), 'esattamente plafond');
$assert($close(lombard_daily_interest(13000, $rate), (12000 * 0.034 + 1000 * 0.1115) / 365), 'sconfinamento separato');
$assert(lombard_daily_interest(1, null) === null, 'tasso mancante non equivale a zero');
$rates = [$rate, ['valid_from' => '2026-11-03', 'tan' => 0.04, 'overdraft_tan' => 0.1115, 'credit_limit' => 12000]];
$rows = [
    ['snapshot_date' => '2026-10-31', 'accounting_balance' => -900],
    ['snapshot_date' => '2026-11-01', 'accounting_balance' => 100],
    ['snapshot_date' => '2026-11-02', 'accounting_balance' => 0],
    ['snapshot_date' => '2026-11-03', 'accounting_balance' => -1000],
    ['snapshot_date' => '2026-11-05', 'accounting_balance' => -2000],
    ['snapshot_date' => '2026-11-06', 'accounting_balance' => null],
];
$monitor = lombard_monitor($rows, $rates, '2026-11-01');
$assert(count($monitor['rows']) === 5, 'snapshot precedenti attivazione esclusi');
$assert($monitor['rows'][0]['usage'] === 0.0 && $monitor['rows'][1]['usage'] === 0.0, 'saldo positivo e zero');
$assert($close($monitor['total'], 3000 * 0.04 / 365), 'somma usa nuovo TAN dalla decorrenza');
$assert($monitor['missing_days'] === 2, 'giorno mancante e saldo nullo restano sconosciuti');
$assert($monitor['latest']['usage'] === null && $monitor['fixed_projection'] === null, 'ultimo saldo nullo non inventato');
$assert($monitor['streak'] === null, 'giorni consecutivi non disponibili senza ultimo saldo');
$assert($monitor['dynamic'] === null, 'media non riempie giorni mancanti');
$assert($monitor['positive_days'] === 2 && $monitor['max']['usage'] === 2000.0, 'massimo e giorni osservati');
$assert(lombard_rate_for_date($rates, '2026-11-02')['tan'] === 0.034 && lombard_rate_for_date($rates, '2026-11-03')['tan'] === 0.04, 'cambio tasso puntuale');
$leap = lombard_monitor([['snapshot_date' => '2028-02-29', 'accounting_balance' => -3650]], [['valid_from' => '2028-01-01', 'tan' => 0.04, 'overdraft_tan' => 0.1, 'credit_limit' => 12000]], '2028-02-29');
$assert($close($leap['total'], 0.40), 'anno bisestile divisore 365');
$streak = lombard_monitor([['snapshot_date' => '2026-11-01', 'accounting_balance' => -100], ['snapshot_date' => '2026-11-03', 'accounting_balance' => -100]], $rates, '2026-11-01');
$assert($streak['streak'] === 1 && $streak['streak_incomplete'], 'lacuna interrompe conteggio consecutivo');
$atActivation = lombard_monitor([['snapshot_date' => '2026-11-01', 'accounting_balance' => -100]], $rates, '2026-11-01');
$assert($atActivation['streak'] === 1 && !$atActivation['streak_incomplete'], 'inizio del fido non e una lacuna');
$yearBoundary = lombard_monitor([['snapshot_date' => '2026-12-31', 'accounting_balance' => -3650], ['snapshot_date' => '2027-01-01', 'accounting_balance' => -3650]], $rates, '2026-12-31');
$assert($close($yearBoundary['year_total'], 0.4) && $close($yearBoundary['total'], 0.8), 'proiezione annuale non include anno precedente');
$full = [];
for ($i = 0; $i < 30; $i++) {
    $full[] = ['snapshot_date' => (new DateTimeImmutable('2026-11-01'))->modify('+' . $i . ' days')->format('Y-m-d'), 'accounting_balance' => -1000];
}
$complete = lombard_monitor($full, $rates, '2026-11-01');
$assert($complete['dynamic']['window'] === 30 && $complete['dynamic']['average'] === 1000.0, 'finestra completa piu lunga');
$assert($complete['remaining_days'] === 31 && $complete['missing_days'] === 0, 'conteggio giorni proiezione esclude ultimo snapshot');
$assert($close($complete['fixed_projection'], $complete['year_total'] + 1000 * 0.04 / 365 * 31), 'proiezione a tasso costante');
$partialWindow = lombard_monitor(array_slice($full, -14), $rates, '2026-11-01');
$assert($partialWindow['dynamic']['window'] === 14 && $partialWindow['missing_days'] === 16, '14 giorni completi scelti senza inventare precedenti');
$weekWindow = lombard_monitor(array_slice($full, -7), $rates, '2026-11-01');
$assert($weekWindow['dynamic']['window'] === 7 && $weekWindow['missing_days'] === 23, '7 giorni completi scelti come ultima finestra');
$newYear = lombard_monitor([['snapshot_date' => '2026-12-31', 'accounting_balance' => -1000]], $rates, '2026-12-31');
$assert($newYear['remaining_days'] === 0 && $close($newYear['fixed_projection'], 1000 * 0.04 / 365), '31 dicembre nessun giorno proiettato due volte');
$missingRate = lombard_monitor([['snapshot_date' => '2026-01-01', 'accounting_balance' => -1000]], $rates, '2026-01-01');
$assert($missingRate['missing_rates'] === 1 && $missingRate['fixed_projection'] === null && $missingRate['rows'][0]['interest'] === null, 'tasso non ancora valido segnalato');
$splitScenario = lombard_monitor([['snapshot_date' => '2026-11-03', 'accounting_balance' => -13000]], $rates, '2026-11-03');
$assert($close($splitScenario['fixed_projection'], (12000 * 0.04 + 1000 * 0.1115) / 365 * ($splitScenario['remaining_days'] + 1)), 'proiezione separa eccedenza e non duplica il giorno corrente');
$duplicated = false;
try {
    lombard_monitor([$full[0], $full[0]], $rates, '2026-11-01');
} catch (InvalidArgumentException $error) {
    $duplicated = true;
}
$assert($duplicated, 'date duplicate rifiutate');
echo 'OK: ' . $checks . " controlli Credit Lombard.\n";
