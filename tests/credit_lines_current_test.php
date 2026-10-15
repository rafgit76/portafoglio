<?php
// Synthetic, CLI-only regression checks: no bootstrap, credentials or database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../lib/account_tracker.php';

$contract = [
    'id' => 1,
    'name' => 'Credit Lombard Fineco',
    'bank_name' => 'Fineco',
    'credit_type' => 'CREDIT_LOMBARD',
    'agreed_amount' => '12000.0000',
    'used_amount' => '0.0000',
    'available_amount' => '12000.0000',
    'status' => 'ACTIVE',
    'validity_type' => 'REVOCABLE',
    'linked_main_account_id' => 'MAIN-TEST',
    'pledge_account_id' => 'PLEDGE-TEST',
    'pledge_dossier_id' => 'PLEDGE-TEST',
    'activation_date' => '2026-08-21',
    'data_origin' => 'fineco_acceptance_letter',
    'source_document_name' => 'acceptance-fixture.pdf',
    'dossier_id' => 2,
    'dossier_name' => 'Dossier Credit Lombard',
];
$snapshot = [
    'id' => 9,
    'snapshot_date' => '2026-10-15',
    'credit_agreed_amount' => '12000.0000',
    'credit_used_amount' => '3500.0000',
    'credit_available_amount' => '8500.0000',
    'accounting_balance' => '-960.0000',
    'available_balance' => '7400.0000',
    'data_origin' => 'IMPORT_FINECO',
];
$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Test fallito: ' . $label);
    }
    $checks++;
};
$current = static fn(array $line, ?array $values): array => account_tracker_registered_credit_lines([$line], $values)[0]['current_credit'];
$original = serialize([$contract, $snapshot]);
$registered = account_tracker_registered_credit_lines([$contract], $snapshot);
$values = $registered[0]['current_credit'];
$assert($values['used_amount'] === 3500.0 && $values['available_amount'] === 8500.0, 'accordato 12000 con utilizzo corrente non nullo');
$assert($values['snapshot_date'] === '2026-10-15', 'data fonte corrente');
$assert($values['used_source'] === 'patrimonial_snapshot' && $values['available_source'] === 'patrimonial_snapshot', 'fonte snapshot per entrambi gli importi');
$withoutCurrent = $registered[0];
unset($withoutCurrent['current_credit']);
$assert($withoutCurrent === $contract, 'tutti i valori registrati e contrattuali preservati');
$assert(account_tracker_credit_line_profile($registered[0]) === account_tracker_credit_line_profile($contract), 'profilo lettera invariato: tipo, stato, accordato, conti, dossier, validita e data');
$assert(serialize([$contract, $snapshot]) === $original, 'input originali non modificati');

$fallback = $current($contract, null);
$assert($fallback['used_amount'] === 0.0 && $fallback['available_amount'] === 12000.0 && $fallback['snapshot_date'] === null, 'nessuno snapshot: fallback statico');
$assert(account_tracker_registered_credit_lines([], $snapshot) === [], 'nessun fido registrato');
$missing = ['snapshot_date' => '2026-10-15', 'accounting_balance' => -5000, 'available_balance' => 7000];
$assert($current($contract, $missing) === $fallback, 'nessun fido esplicito: nessuna deduzione dai saldi');
$assert($current($contract, array_replace($snapshot, ['credit_agreed_amount' => null, 'credit_used_amount' => null, 'credit_available_amount' => ''])) === $fallback, 'campi vuoti e null usano dati registrati');
$manual = array_replace($contract, ['used_amount' => '125.5000']);
$assert($current($manual, null)['available_amount'] === 11874.5, 'fallback disponibile da accordato meno utilizzo registrato');
$availableMissing = $current($contract, array_replace($snapshot, ['credit_available_amount' => null]));
$assert($availableMissing['used_amount'] === 3500.0 && $availableMissing['available_amount'] === 12000.0 && $availableMissing['available_source'] === 'registered', 'fallback per singolo campo mancante');
$derivedUsed = $current($contract, array_replace($snapshot, ['credit_used_amount' => null]));
$assert($derivedUsed['used_amount'] === 3500.0 && $derivedUsed['used_source'] === 'calculated_snapshot', 'utilizzato calcolato da accordato e disponibile espliciti dello snapshot');
$explicit = $current($contract, array_replace($snapshot, ['credit_used_amount' => '3300']));
$assert($explicit['used_amount'] === 3300.0 && $explicit['available_amount'] === 8500.0, 'utilizzato esplicito prioritario sul calcolo');
$zero = $current($contract, array_replace($snapshot, ['credit_used_amount' => '0', 'credit_available_amount' => '12000', 'accounting_balance' => '-3500']));
$assert($zero['used_amount'] === 0.0 && $zero['used_source'] === 'patrimonial_snapshot', 'zero esplicito prevale anche con saldo negativo');
$full = $current($contract, array_replace($snapshot, ['credit_used_amount' => '12000', 'credit_available_amount' => '0']));
$assert($full['used_amount'] === 12000.0 && $full['available_amount'] === 0.0 && $full['available_source'] === 'patrimonial_snapshot', 'utilizzo totale e disponibile zero');
$old = $current($contract, array_replace($snapshot, ['snapshot_date' => '2026-08-20']));
$assert($old === $fallback, 'snapshot anteriore all attivazione non applicato');
$assert($current($contract, array_replace($snapshot, ['snapshot_date' => '2026-08-21']))['used_amount'] === 3500.0, 'snapshot della data di attivazione ammesso');
$assert($current($contract, array_replace($snapshot, ['credit_agreed_amount' => 15000])) === $fallback, 'totale diverso da accordato non attribuito alla singola linea');
$secondFineco = array_replace($contract, ['id' => 2, 'credit_type' => 'MINIFIDO', 'agreed_amount' => 3000, 'data_origin' => 'manual']);
$multiple = account_tracker_registered_credit_lines([$contract, $secondFineco], $snapshot);
$assert($multiple[0]['current_credit'] === $fallback && $multiple[1]['current_credit']['used_source'] === 'registered', 'totale Fineco non duplicato su piu linee attive');
$closedMinifido = array_replace($secondFineco, ['status' => 'CLOSED']);
$closedResult = account_tracker_registered_credit_lines([$contract, $closedMinifido], $snapshot);
$assert($closedResult[0]['current_credit']['used_amount'] === 3500.0 && $closedResult[1]['current_credit']['available_amount'] === 3000.0, 'Minifido chiuso non riceve utilizzo corrente');
$otherBank = array_replace($secondFineco, ['bank_name' => 'Altra banca']);
$differentBanks = account_tracker_registered_credit_lines([$contract, $otherBank], $snapshot);
$assert($differentBanks[0]['current_credit']['used_amount'] === 3500.0 && $differentBanks[1]['current_credit']['used_amount'] === 0.0, 'totali Fineco non applicati ad altra banca');
$assert($current(array_replace($contract, ['status' => 'CLOSED']), $snapshot) === $fallback, 'linea chiusa resta statica');
$legacySnapshot = ['snapshot_date' => '2026-10-15', 'imported_values_json' => json_encode(['credit_agreed_amount' => 12000, 'credit_used_amount' => 3500, 'credit_available_amount' => 8500])];
$assert($current($contract, $legacySnapshot)['used_amount'] === 3500.0, 'payload Fineco storico disponibile come fallback colonne');
$overridden = array_replace($snapshot, ['data_origin' => 'MANUALE', 'credit_used_amount' => 3600, 'credit_available_amount' => 8400, 'imported_values_json' => json_encode(['credit_used_amount' => 3500, 'credit_available_amount' => 8500])]);
$assert($current($contract, $overridden)['used_amount'] === 3600.0, 'override esplicito dello snapshot conservato come nel resto del conto');

// Render the actual registered-lines section to verify the displayed source and amounts.
$render = static function (array $registeredLines): string {
    $profileSchemaReady = true;
    $source = file_get_contents(__DIR__ . '/../credit_lines.php');
    $start = strpos($source, '<section class="panel" id="registered-credit-lines">');
    $end = strpos($source, '</section>', $start) + strlen('</section>');
    $fragment = str_replace('app_base_path()', "''", substr($source, $start, $end - $start));
    ob_start();
    eval('?>' . $fragment);
    return ob_get_clean();
};
$html = $render($registered);
$assert(str_contains($html, 'EUR 3.500,00') && str_contains($html, 'EUR 8.500,00'), 'tabella usa i valori correnti');
$assert(str_contains($html, 'EUR 12.000,00') && str_contains($html, 'Lettera di accettazione Fineco') && str_contains($html, 'Operativo'), 'tabella mantiene accordato e metadati lettera');
$assert(substr_count($html, 'Snapshot patrimonio del 15/10/2026') === 2, 'fonte e data visibili per entrambi i valori correnti');
$fallbackHtml = $render(account_tracker_registered_credit_lines([$contract], null));
$assert(substr_count($fallbackHtml, 'Dato registrato') === 2 && !str_contains($fallbackHtml, 'Snapshot patrimonio del'), 'fonte fallback visibile');
$assert(str_contains($render(account_tracker_registered_credit_lines([$contract], array_replace($snapshot, ['credit_used_amount' => null]))), 'Calcolato da snapshot del 15/10/2026'), 'calcolo esplicito distinguibile nella UI');
$assert(str_contains($render([]), 'Nessuna linea di credito configurata.'), 'nessun fido resta opzionale nella UI');

if (($argv[1] ?? '') === '--render') {
    echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/assets/style.css"></head><body><main class="container">' . $html . '</main></body></html>';
} else {
    echo "OK: {$checks} controlli valori correnti Linee registrate. Nessun database o servizio esterno utilizzato.\n";
}
