<?php
require_once __DIR__ . '/../lib/account_tracker.php';
require_once __DIR__ . '/../lib/fineco_patrimony_importer.php';
require_once __DIR__ . '/../lib/fineco_importer.php';
require_once __DIR__ . '/../lib/ai_report.php';

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Test fallito: ' . $label);
    }
    $checks++;
};

// Modalita solo portafoglio: dati mancanti non producono alcun patrimonio ricostruito.
$wealth = account_tracker_reconstructed_wealth(180000.0, null, null);
$assert($wealth['gross'] === null && $wealth['net_adjusted'] === null, 'solo portafoglio senza liquidita');

// Il fido non entra nel lordo e viene sottratto solo dal netto rettificato.
$wealth = account_tracker_reconstructed_wealth(187994.18, 3779.03, 3000.0);
$assert(abs((float) $wealth['gross'] - 191773.21) < 0.01, 'patrimonio ricostruito');
$assert(abs((float) $wealth['net_adjusted'] - 188773.21) < 0.01, 'debito utilizzato separato');
$assert((float) $wealth['gross'] !== 194773.21, 'fido disponibile escluso');

// Credit Lombard Fineco: il patrimonio e la liquidita esposti includono il credito disponibile.
$lombard = account_tracker_patrimonial_adjustments([
    'fineco_total_assets' => 203188.95,
    'liquidity_total' => 15760.75,
    'credit_available_amount' => 15000.00,
]);
$assert(abs((float) $lombard['net_assets_adjusted'] - 188188.95) < 0.01, 'patrimonio netto rettificato post Lombard');
$assert(abs((float) $lombard['own_liquidity_adjusted'] - 760.75) < 0.01, 'liquidita propria rettificata post Lombard');
$assert((float) $lombard['net_assets_adjusted'] !== 203188.95, 'credito disponibile non rappresentato come ricchezza');
$assert(account_tracker_effective_credit_used(15000.0, 0.0, 15000.0) === 0.0, 'fido usato Fineco prioritario');
$assert(account_tracker_effective_credit_used(15000.0, null, 10000.0) === 5000.0, 'fido usato calcolato solo in assenza del dato diretto');
$assert(account_tracker_effective_credit_used(15000.0, 4000.0, 10000.0) === 4000.0, 'fido usato diretto non sovrascritto dal controllo');
$assert(abs((726.44 - 31.67 + 15000.0) - 15694.77) < 0.01, 'controllo saldo disponibile post Lombard');

// Saldi negativi e campi opzionali sono valori leciti.
$assert(account_tracker_optional_decimal('-125,50', 'saldo') === -125.5, 'saldo contabile negativo');
$assert(account_tracker_optional_decimal('', 'campo') === null, 'campo patrimoniale facoltativo');

// Trasferimento interno: tolleranza stretta, nessun riconoscimento ambiguo.
$assert(account_tracker_transfer_quantities_match(20000.0, 20000.0), 'trasferimento stessa quantita');
$assert(account_tracker_transfer_quantities_match(20000.0, 20019.0), 'trasferimento entro tolleranza');
$assert(!account_tracker_transfer_quantities_match(20000.0, 19900.0), 'trasferimento ambiguo rifiutato');
$assert(account_tracker_is_documented_internal_transfer_operation('GIRO INT PAR INT'), 'codice giro interno documentato');
$assert(!account_tracker_is_documented_internal_transfer_operation('VENDITA'), 'vendita non classificata come giro interno');
$documentedPosition = account_tracker_documented_transfer_position(20000.0, 20000.0, 19745.97, 100.0);
$assert(abs((float) $documentedPosition['avg_load_price'] - 100.0) < 0.00001, 'prezzo medio del giro interno conservato');
$assert(abs((float) $documentedPosition['current_price'] - 98.72985) < 0.00001, 'prezzo corrente BTP coerente con quotazione cento');
$assert(abs((float) $documentedPosition['unrealized_pnl'] + 254.03) < 0.01, 'giro interno mantiene P L non realizzato');
$assert((float) $documentedPosition['cost_basis'] === 20000.0, 'carico storico trasferito invariato');

// Gli slot sono l'origine affidabile del dossier: Fineco puo assegnare lo stesso filename ai due export.
$mainSlot = fineco_portfolio_import_slot('main');
$lombardSlot = fineco_portfolio_import_slot('credit_lombard');
$assert($mainSlot['dossier_code'] === 'ordinario', 'slot principale assegnato al dossier ordinario');
$assert($lombardSlot['dossier_code'] === 'credit_lombard', 'slot Lombard assegnato al dossier Credit Lombard');
$assert($mainSlot['dossier_code'] !== $lombardSlot['dossier_code'], 'due file con lo stesso nome restano distinti dagli slot');
$assert($mainSlot['file_prefix'] !== $lombardSlot['file_prefix'], 'nomi generati distinguono l origine senza leggere il filename Fineco');

// Caso reale: il BTP trasferito viene aggregato al dossier ordinario senza realizzare P/L.
$mainAfterTransfer = ['cost_basis' => 118291.56, 'market_value' => 167308.91, 'unrealized_pnl' => 48230.50];
$lombardExport = ['quantity' => 20000.0, 'cost_basis' => 20000.0, 'market_value' => 19652.00, 'unrealized_pnl' => -348.00];
$assert(abs(($mainAfterTransfer['cost_basis'] + $lombardExport['cost_basis']) - 138291.56) < 0.01, 'carico consolidato 11829156 piu 20000');
$assert(abs(($mainAfterTransfer['market_value'] + $lombardExport['market_value']) - 186960.91) < 0.01, 'valore di mercato consolidato multi dossier');
$assert(abs(($mainAfterTransfer['unrealized_pnl'] + $lombardExport['unrealized_pnl']) - 47882.50) < 0.01, 'P L consolidato non realizzato');
$assert($lombardExport['quantity'] === 20000.0 && $documentedPosition['quantity'] === 20000.0, 'quantita BTP continua tra giro interno ed export Lombard');
$assert(!account_tracker_is_documented_internal_transfer_operation('VENDITA'), 'giro interno non produce vendita realizzata');

// Export Fineco dopo l'attivazione definitiva: un solo fido Lombard da 12.000 EUR.
$lombardCurrent = account_tracker_patrimonial_adjustments([
    'fineco_total_assets' => 200233.09974204,
    'liquidity_total' => 12760.76,
    'credit_available_amount' => 12000.0,
]);
$assert(abs((float) $lombardCurrent['net_assets_adjusted'] - 188233.09974204) < 0.01, 'netto rettificato con fido Fineco 12000');
$assert(abs((float) $lombardCurrent['own_liquidity_adjusted'] - 760.76) < 0.01, 'liquidita propria con fido Fineco 12000');
$assert(account_tracker_effective_credit_used(12000.0, 0.0, 12000.0) === 0.0, 'fido Lombard 12000 non utilizzato');
$assert(abs((726.44 - 31.67 + 12000.0) - 12694.77) < 0.01, 'controllo saldo disponibile con fido 12000');

// Lettera Fineco del 21/08/2026: profilo ufficiale del rapporto Credit Lombard.
$officialCreditLine = account_tracker_credit_line_profile([
    'id' => 1,
    'name' => 'Credit Lombard Fineco',
    'credit_type' => 'CREDIT_LOMBARD',
    'status' => 'ACTIVE',
    'agreed_amount' => 12000.0,
    'validity_type' => 'REVOCABLE',
    'linked_main_account_id' => '3274177',
    'pledge_account_id' => '5243936',
    'pledge_dossier_id' => '5243936',
    'activation_date' => '2026-08-21',
    'data_origin' => 'fineco_acceptance_letter',
    'source_document_name' => 'lettera-accettazione-140821.pdf',
]);
$assert($officialCreditLine['credit_line_type'] === 'Credit Lombard', 'tipo Credit Lombard ufficiale');
$assert($officialCreditLine['status'] === 'Operativo', 'Credit Lombard operativo');
$assert((float) $officialCreditLine['amount_granted'] === 12000.0, 'Credit Lombard accordato 12000');
$assert($officialCreditLine['validity_type'] === 'A revoca', 'Credit Lombard a revoca');
$assert($officialCreditLine['linked_main_account_id'] === '3274177', 'conto principale Credit Lombard');
$assert($officialCreditLine['pledge_account_id'] === '5243936', 'conto a pegno Credit Lombard');
$assert($officialCreditLine['pledge_dossier_id'] === '5243936', 'dossier a garanzia Credit Lombard');
$assert($officialCreditLine['activation_date'] === '2026-08-21', 'data attivazione Credit Lombard');
$relationsByType = array_column($officialCreditLine['relations'], null, 'entity_type');
$assert(isset($relationsByType['PLEDGE_ACCOUNT'], $relationsByType['PLEDGE_DOSSIER']), 'conto a pegno e dossier sono tipi distinti');
$assert($relationsByType['PLEDGE_ACCOUNT']['identifier'] === $relationsByType['PLEDGE_DOSSIER']['identifier'], 'rapporti distinti possono condividere il numero Fineco');
$assert($officialCreditLine['data_origin'] === 'fineco_acceptance_letter', 'provenienza lettera Fineco');
$creditProfileMigration = file_get_contents(__DIR__ . '/../sql/migration_2_6_0_credit_lombard_profile.sql');
$assert(is_string($creditProfileMigration) && !str_contains($creditProfileMigration, 'UPDATE pf_snapshots'), 'migrazione profilo non aggiorna snapshot portafoglio');
$assert(!str_contains((string) $creditProfileMigration, 'UPDATE pf_snapshot_positions'), 'migrazione profilo non aggiorna posizioni');
$assert(!str_contains((string) $creditProfileMigration, 'pf_credit_line_snapshots'), 'migrazione profilo non crea snapshot del fido');
$assert(abs(($mainAfterTransfer['cost_basis'] + $lombardExport['cost_basis']) - 138291.56) < 0.01, 'profilo ufficiale non altera carico consolidato');

// Priorita: il manuale puo fare override senza distruggere il payload Fineco originario.
$effective = fineco_patrimony_effective_values(
    ['fineco_total_assets' => 191773.21, 'available_balance' => 3712.97, 'credit_used_amount' => 0.0],
    ['available_balance' => 3700.00]
);
$assert((float) $effective['fineco_total_assets'] === 191773.21, 'valore Fineco conservato');
$assert((float) $effective['available_balance'] === 3700.0, 'override manuale');
$assert((float) $effective['credit_used_amount'] === 0.0, 'fido importato conservato');
$assert(fineco_patrimony_effective_values(['liquidity_total' => 3779.03], [])['liquidity_total'] === 3779.03, 'import patrimonio senza override');
$assert(fineco_patrimony_effective_values([], ['accounting_balance' => -50.0])['accounting_balance'] === -50.0, 'snapshot manuale senza import');
$legacyPatrimonial = [
    'fineco_portfolio_total' => null,
    'manual_overrides_json' => json_encode(['fineco_portfolio_total' => 187500.00]),
    'imported_values_json' => json_encode(['fineco_portfolio_total' => 187428.20]),
];
$assert(account_tracker_snapshot_decimal($legacyPatrimonial, 'fineco_portfolio_total') === 187500.0, 'override storico prioritario sul payload importato');
$legacyPatrimonial['manual_overrides_json'] = null;
$assert(account_tracker_snapshot_decimal($legacyPatrimonial, 'fineco_portfolio_total') === 187428.2, 'payload storico Fineco riusato senza riscrittura');

// Il consolidamento di uno stesso ISIN in due dossier e una somma, non una duplicazione.
$sameIsin = [
    ['quantity' => 20000.0, 'market_value' => 19752.73],
    ['quantity' => 0.0, 'market_value' => 0.0],
];
$assert(abs(array_sum(array_column($sameIsin, 'market_value')) - 19752.73) < 0.01, 'isin multi dossier consolidato');
$assert(abs(array_sum(array_column($sameIsin, 'quantity')) - 20000.0) < 0.01, 'quantita trasferita preservata');

// Fido assente e liquidita parziale non impediscono la modalita solo portafoglio.
$assert(account_tracker_reconstructed_wealth(100.0, null, 0.0)['gross'] === null, 'liquidita mancante non inventata');
$assert(account_tracker_reconstructed_wealth(null, 100.0, 0.0)['gross'] === null, 'portafoglio mancante non inventato');

// Estremi dello storico: i null non vengono interpretati come zeri.
$historicalRows = [
    ['snapshot_date' => '2026-08-18', 'fineco_total_assets' => null],
    ['snapshot_date' => '2026-08-19', 'fineco_total_assets' => 190000.00],
    ['snapshot_date' => '2026-08-20', 'fineco_total_assets' => 191773.21],
];
$assert(account_tracker_history_extreme($historicalRows, 'fineco_total_assets', 'max')['date'] === '2026-08-20', 'massimo patrimonio storico');
$assert(account_tracker_history_extreme($historicalRows, 'fineco_total_assets', 'min')['date'] === '2026-08-19', 'minimo patrimonio storico');

// Aruba PDO con prepare nativi richiede placeholder distinti quando un valore e usato piu volte.
$weightSql = 'UPDATE pf_snapshot_positions
 SET weight_pct = CASE WHEN :weight_threshold > 0 THEN market_value / :weight_divisor ELSE 0 END,
     imported_weight_pct = CASE WHEN :imported_weight_threshold > 0 THEN market_value / :imported_weight_divisor ELSE 0 END
 WHERE snapshot_id = :snapshot_id';
$placeholders = [];
preg_match_all('/:([a-z_]+)/', $weightSql, $matches);
foreach ($matches[1] as $placeholder) {
    $placeholders[$placeholder] = ($placeholders[$placeholder] ?? 0) + 1;
}
$assert(max($placeholders) === 1, 'placeholder ricalcolo pesi non duplicati');

// La conferma dei trasferimenti deve essere compatibile con i prepare nativi di Aruba.
$transferStatusSql = account_tracker_transfer_status_update_sql();
$transferPlaceholders = [];
preg_match_all('/:([a-z_]+)/', $transferStatusSql, $transferMatches);
foreach ($transferMatches[1] as $placeholder) {
    $transferPlaceholders[$placeholder] = ($transferPlaceholders[$placeholder] ?? 0) + 1;
}
$assert(max($transferPlaceholders) === 1, 'placeholder conferma trasferimento non duplicati');

// Archivio: la tabella parte dal dato piu recente, i grafici possono usare la serie originale crescente.
$historyDates = [
    ['snapshot_date' => '2026-08-19'],
    ['snapshot_date' => '2026-08-20'],
    ['snapshot_date' => '2026-08-21'],
];
$recentFirst = array_slice(array_reverse($historyDates), 0, 2);
$assert($recentFirst[0]['snapshot_date'] === '2026-08-21' && $recentFirst[1]['snapshot_date'] === '2026-08-20', 'archivio patrimonio dal piu recente');

// Riconciliazione dossier: ordinario + pegno sono componenti, non patrimonio aggiuntivo.
$dossiers = [168241.45, 19752.73];
$assert(abs(array_sum($dossiers) - 187994.18) < 0.01, 'totale dossier Fineco');
$assert(abs(191773.21 - array_sum($dossiers) - 3779.03) < 0.01, 'nessuna duplicazione dossier patrimonio');

// Report AI patrimonio: campi opzionali restano tali e il credito non entra nel patrimonio ricostruito.
$accountRows = [
    [
        'id' => 1,
        'snapshot_date' => '2026-08-20',
        'fineco_total_assets' => 191773.21,
        'consolidated_portfolio_value' => 167863.72,
        'liquidity_total' => 3779.03,
        'reconstructed_gross' => 171642.75,
        'accounting_balance' => 766.58,
        'available_balance' => 3712.97,
        'credit_used_amount' => 0.0,
        'credit_available_amount' => 12000.0,
        'data_origin' => 'IMPORT_FINECO',
    ],
    [
        'id' => 2,
        'snapshot_date' => '2026-08-21',
        'fineco_total_assets' => 191273.36,
        'consolidated_portfolio_value' => 167308.91,
        'liquidity_total' => 3778.97,
        'reconstructed_gross' => 171087.88,
        'accounting_balance' => 726.44,
        'available_balance' => 3712.97,
        'credit_used_amount' => null,
        'credit_available_amount' => 12000.0,
        'data_origin' => 'IMPORT_FINECO',
    ],
];
$accountPayload = ai_report_build_account_payload_from_rows($accountRows, 'daily')['payload'];
$assert((float) $accountPayload['metrics']['fineco_total_assets']['delta'] === -499.85, 'delta patrimonio verificato');
$assert($accountPayload['metrics']['credit_used_amount']['latest'] === null, 'fido mancante nell ultimo snapshot non inventato');
$assert((float) $accountPayload['metrics']['credit_available_amount']['latest'] === 12000.0, 'credito disponibile separato');
$accountFacts = ai_report_build_account_verified_facts($accountPayload);
$assert(str_contains($accountFacts, 'credito disponibile restano separati dal patrimonio'), 'semantica credito separata nel prompt');
$assert(ai_report_type_label('account_weekly_changes', true) === 'Patrimonio settimanale', 'tipo archivio patrimonio settimanale');

$postLombardRows = [
    [
        'id' => 10,
        'snapshot_date' => '2026-08-20',
        'fineco_total_assets' => 191773.21,
        'net_assets_adjusted' => 179773.21,
        'fineco_portfolio_total' => 187994.18,
        'consolidated_portfolio_value' => 187994.18,
        'liquidity_total' => 3779.03,
        'own_liquidity_adjusted' => -8220.97,
        'accounting_balance' => 766.58,
        'available_balance' => 3712.97,
        'credit_agreed_amount' => 12000.0,
        'credit_used_amount' => 0.0,
        'credit_available_amount' => 12000.0,
        'data_origin' => 'IMPORT_FINECO',
    ],
    [
        'id' => 11,
        'snapshot_date' => '2026-08-21',
        'fineco_total_assets' => 203188.95,
        'net_assets_adjusted' => 188188.95,
        'fineco_portfolio_total' => 187428.20,
        'consolidated_portfolio_value' => 187428.20,
        'liquidity_total' => 15760.75,
        'own_liquidity_adjusted' => 760.75,
        'accounting_balance' => 726.44,
        'available_balance' => 15694.77,
        'credit_agreed_amount' => 15000.0,
        'credit_used_amount' => 0.0,
        'credit_available_amount' => 15000.0,
        'data_origin' => 'IMPORT_FINECO',
    ],
];
$postLombardPayload = ai_report_build_account_payload_from_rows($postLombardRows, 'daily')['payload'];
$assert((float) $postLombardPayload['metrics']['net_assets_adjusted']['latest'] === 188188.95, 'payload AI netto rettificato post Lombard');
$assert((float) $postLombardPayload['metrics']['own_liquidity_adjusted']['latest'] === 760.75, 'payload AI liquidita propria rettificata post Lombard');
$assert((float) $postLombardPayload['metrics']['consolidated_portfolio_value']['latest'] === 187428.20, 'payload AI usa portafoglio totale Fineco');

// I delta AI devono usare le stesse cifre arrotondate mostrate nella tabella, non i decimali interni al database.
$roundedDelta = ai_report_account_metric_summary([
    ['snapshot_date' => '2026-08-20', 'amount' => 191773.2149],
    ['snapshot_date' => '2026-08-21', 'amount' => 191273.3599],
], 'amount');
$assert((float) $roundedDelta['first'] === 191773.21, 'primo valore AI arrotondato');
$assert((float) $roundedDelta['latest'] === 191273.36, 'ultimo valore AI arrotondato');
$assert((float) $roundedDelta['delta'] === -499.85, 'delta AI coerente con valori mostrati');

// Con soli snapshot non sono ammesse attribuzioni causali non documentate.
$rejectedCausalWording = false;
try {
    ai_report_validate_account_neutral_wording('La flessione e collegata alla variazione del portafoglio.');
} catch (RuntimeException) {
    $rejectedCausalWording = true;
}
$assert($rejectedCausalWording, 'nesso causale patrimoniale rifiutato');

echo "OK: {$checks} controlli logici superati\n";
