<?php
require_once __DIR__ . '/../lib/ai_report.php';

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Test fallito: ' . $label);
    }
    $checks++;
};
$close = static fn (?float $actual, float $expected): bool =>
    $actual !== null && abs($actual - $expected) < 0.0000001;

$assert($close(ai_report_estimate_cost_usd('gemini-3.8-flash', 1000000, 0), 0.75), 'tariffa input 3.8');
$assert($close(ai_report_estimate_cost_usd('gemini-3.8-flash', 0, 1000000), 3.75), 'tariffa output 3.8');
$assert($close(ai_report_estimate_cost_usd('gemini-3.8-flash', 0, 0, 1000000), 3.75), 'ragionamento fatturato come output');
$assert($close(ai_report_estimate_cost_usd('gemini-3.8-flash', 15000, 2000, 3000), 0.03), 'costo report inclusivo del ragionamento');
$assert($close(ai_report_estimate_cost_usd('gemini-3.8-flash', 0, 0, 0), 0.0), 'consumi zero');
$assert($close(ai_report_estimate_cost_usd('gemini-3.7-flash', 15000, 2000, 3000), 0.03), 'tariffe storiche 3.7 invariate');
$assert($close(ai_report_estimate_cost_usd('gemini-3.5-flash-lite', 1000000, 1000000), 2.80), 'tariffe storiche Flash Lite invariate');
$assert(ai_report_estimate_cost_usd('unknown-model', 1000, 1000) === null, 'modello sconosciuto senza costo inventato');

// Entrambi i tentativi sono fatturati anche quando il primo richiede correzione.
$usage = ai_report_sum_usage([
    ['prompt_tokens' => 10000, 'output_tokens' => 2000, 'thinking_tokens' => 1000, 'total_tokens' => 13000],
    ['prompt_tokens' => 12000, 'output_tokens' => 1800, 'thinking_tokens' => 1500, 'total_tokens' => 15300],
]);
$assert($usage === [
    'prompt_tokens' => 22000,
    'output_tokens' => 3800,
    'thinking_tokens' => 2500,
    'total_tokens' => 28300,
], 'consumi includono il tentativo correttivo');
$assert($close(ai_report_estimate_cost_usd(
    'gemini-3.8-flash', $usage['prompt_tokens'], $usage['output_tokens'], $usage['thinking_tokens']
), 0.040125), 'costo totale dei tentativi');

$dailyReport = [
    'model' => 'gemini-3.8-flash',
    'estimated_cost_usd' => null,
    'usage' => ['prompt_tokens' => 7500, 'output_tokens' => 2203, 'thinking_tokens' => 3347],
];
$original = $dailyReport;
$assert($close(ai_report_record_estimated_cost_usd($dailyReport), 0.0264375), 'costo giornaliero reale 3.8 ricostruito');
$assert($dailyReport === $original, 'report originale non modificato');
$assert(ai_report_format_cost(ai_report_record_estimated_cost_usd($dailyReport)) === '$ 0,02644', 'costo giornaliero non visualizzato come zero');
$wealthReport = [
    'model' => 'gemini-3.8-flash',
    'usage' => ['prompt_tokens' => 5621, 'output_tokens' => 1321, 'thinking_tokens' => 4845],
];
$assert($close(ai_report_record_estimated_cost_usd($wealthReport), 0.02733825), 'costo patrimonio reale 3.8 ricostruito');
$assert(ai_report_format_cost(ai_report_record_estimated_cost_usd($wealthReport)) === '$ 0,02734', 'formato stima patrimonio');
$assert(ai_report_record_estimated_cost_usd(array_replace($dailyReport, ['estimated_cost_usd' => 0.5])) === 0.5, 'costo storico gia presente conservato');
$assert(ai_report_record_estimated_cost_usd(array_replace($dailyReport, ['estimated_cost_usd' => 0.0])) === 0.0, 'zero esplicitamente archiviato conservato');
$unknownReport = array_replace($dailyReport, ['model' => 'unknown-model']);
$assert(ai_report_record_estimated_cost_usd($unknownReport) === null, 'modello sconosciuto non stimato');
$assert(ai_report_format_cost(null) === 'Non disponibile', 'stima mancante distinta da zero');
$assert(ai_report_format_cost(0.0) === '$ 0,00000', 'zero valido resta zero');
$missingUsage = $dailyReport;
unset($missingUsage['usage']['thinking_tokens']);
$assert(ai_report_record_estimated_cost_usd($missingUsage) === null, 'ragionamento mancante non inventato');
$assert(ai_report_record_estimated_cost_usd(['model' => 'gemini-3.8-flash']) === null, 'consumi assenti non equivalgono a zero');
$stats = ai_report_archive_stats([$dailyReport, $wealthReport, $unknownReport]);
$assert($close($stats['estimated_cost_usd'], 0.05377575) && $stats['missing_costs'] === 1, 'totale parziale con stima non disponibile');
$stats = ai_report_archive_stats([$unknownReport]);
$assert($stats['estimated_cost_usd'] === null && $stats['missing_costs'] === 1, 'totale sconosciuto non mostrato a zero');
$stats = ai_report_archive_stats([]);
$assert($stats['estimated_cost_usd'] === 0.0 && $stats['missing_costs'] === 0, 'archivio vuoto senza consumi');

$assert(ai_report_word_count('') === 0, 'conteggio testo vuoto');
$assert(ai_report_word_count("  EUR 100,00\n\t### Sintesi  ") === 4, 'conteggio include numeri e intestazioni');
$longDraft = str_repeat('dato ', 1500);
$repair = ai_report_build_repair_prompt('ORIGINAL_HISTORY_SENTINEL', $longDraft, 'Troppo lungo', 'VERIFIED_FACTS_SENTINEL', 'general');
$assert(str_contains($repair, '1500 parole'), 'correttivo contiene conteggio effettivo');
$assert(str_contains($repair, '1050-1150') && str_contains($repair, '1400'), 'correttivo compatto mantiene limite');
$assert(!str_contains($repair, 'ORIGINAL_HISTORY_SENTINEL'), 'correttivo lunghezza non reinvia tutto lo storico');
$assert(str_contains($repair, 'VERIFIED_FACTS_SENTINEL') && str_contains($repair, $longDraft), 'fatti e bozza conservati nel correttivo');
$headings = [
    '### Sintesi', '### Evoluzione dello storico', '### Principali variazioni per posizione',
    '### Analisi per asset rilevanti', '### Concentrazione e composizione',
    '### Punti di attenzione', '### Prossimi controlli utili',
];
foreach ($headings as $heading) {
    $assert(str_contains($repair, $heading), 'correttivo conserva sezione ' . $heading);
}
$normalRepair = ai_report_build_repair_prompt('ORIGINAL_HISTORY_SENTINEL', 'Bozza breve', 'Valore errato', 'Fatti', 'general');
$assert(str_contains($normalRepair, 'ORIGINAL_HISTORY_SENTINEL'), 'altri errori conservano il correttivo originale');
$accountRepair = ai_report_build_repair_prompt('ORIGINAL_HISTORY_SENTINEL', $longDraft, 'Troppo lungo', 'Fatti', 'account_general');
$assert(str_contains($accountRepair, 'cinque sezioni') && str_contains($accountRepair, 'ORIGINAL_HISTORY_SENTINEL'), 'correttivo patrimonio invariato');

$payload = [
    'date_range' => ['from' => '2026-09-01', 'to' => '2026-09-02'],
    'range_summary' => [
        'first_market_value' => 100.0, 'latest_market_value' => 110.0, 'delta_market_value' => 10.0,
        'first_unrealized_pnl' => 10.0, 'latest_unrealized_pnl' => 15.0, 'delta_unrealized_pnl' => 5.0,
    ],
];
$requiredFacts = '01/09/2026 02/09/2026 EUR 100,00 EUR 110,00 EUR +10,00 EUR 10,00 EUR 15,00 EUR +5,00';
$base = implode("\n", $headings) . "\n" . $requiredFacts;
$reportOfLength = static fn (int $words): string => $base . str_repeat(' dato', $words - ai_report_word_count($base));
foreach ([950, 1100, 1400] as $words) {
    ai_report_validate_generated_text($reportOfLength($words), 'STOP', $payload);
    $assert(true, 'validazione lunghezza ammessa ' . $words);
}
foreach ([949, 1401] as $words) {
    $rejected = false;
    try {
        ai_report_validate_generated_text($reportOfLength($words), 'STOP', $payload);
    } catch (RuntimeException $error) {
        $rejected = true;
        if ($words === 1401) {
            $assert(str_contains($error->getMessage(), '1401'), 'errore espone lunghezza reale');
        }
    }
    $assert($rejected, 'validazione rifiuta lunghezza ' . $words);
}
$rejected = false;
try {
    ai_report_validate_generated_text($reportOfLength(1100), 'MAX_TOKENS', $payload);
} catch (RuntimeException $error) {
    $rejected = true;
}
$assert($rejected, 'testo troncato continua a essere rifiutato');
$rejected = false;
try {
    ai_report_validate_numeric_tokens('EUR 999.999,99', $requiredFacts);
} catch (RuntimeException $error) {
    $rejected = true;
}
$assert($rejected, 'cifre inventate continuano a essere rifiutate');

$settings = ['model' => 'gemini-3.8-flash', 'thinking_level' => 'medium'];
$assert(ai_report_thinking_level($settings, 'general') === 'low', 'solo complessivo 3.8 usa Low');
foreach (['changes', 'weekly_changes', 'account_general', 'account_changes', 'account_weekly_changes', 'custom'] as $type) {
    $assert(ai_report_thinking_level($settings, $type) === 'medium', 'livello invariato per ' . $type);
}
$assert(ai_report_thinking_level(array_replace($settings, ['model' => 'gemini-3.7-flash']), 'general') === 'medium', 'rollback 3.7 mantiene Medium');
$assert(ai_report_thinking_level(array_replace($settings, ['general_thinking_level' => 'medium']), 'general') === 'medium', 'override complessivo esplicito');
$assert(ai_report_thinking_level(array_replace($settings, ['general_thinking_level' => 'minimal']), 'general') === 'low', 'Minimal non supportato su 3.8');
$assert(ai_report_thinking_level(array_replace($settings, ['general_thinking_level' => 'high']), 'account_general') === 'medium', 'override non influenza patrimonio');
$assert(ai_report_thinking_level(array_replace($settings, ['thinking_level' => 'low']), 'changes') === 'low', 'configurazione esplicita altri report rispettata');

$decode = static function (int $input, int $output, int $thoughts, string $finish): array {
    return ai_report_decode_gemini_response(json_encode([
        'candidates' => [['finishReason' => $finish, 'content' => ['parts' => []]]],
        'usageMetadata' => [
            'promptTokenCount' => $input, 'candidatesTokenCount' => $output,
            'thoughtsTokenCount' => $thoughts, 'totalTokenCount' => $input + $output + $thoughts,
        ],
    ], JSON_THROW_ON_ERROR), 200);
};
$limited = $decode(19879, 2800, 17200, 'MAX_TOKENS');
$errorMessage = '';
try {
    ai_report_assert_token_limit([$limited], 'gemini-3.8-flash', 'low', 20000);
} catch (RuntimeException $error) {
    $errorMessage = $error->getMessage();
}
$assert(str_contains($errorMessage, 'limite di token'), 'limite riconosciuto anche con testo vuoto');
$assert(str_contains($errorMessage, '20000') && str_contains($errorMessage, 'ragionamento: low'), 'errore indica configurazione effettiva');
$assert(str_contains($errorMessage, 'input 19879') && str_contains($errorMessage, 'risposta 2800') && str_contains($errorMessage, 'ragionamento 17200'), 'diagnostica separa i consumi');
$assert(str_contains($errorMessage, '$ 0,08991') && str_contains($errorMessage, 'non incluso nell archivio'), 'costo tentativo fallito distinto dall archivio');
$initial = $decode(19879, 3000, 9000, 'STOP');
$second = $decode(22000, 2000, 18000, 'MAX_TOKENS');
$errorMessage = '';
try {
    ai_report_assert_token_limit([$initial, $second], 'gemini-3.8-flash', 'low', 20000);
} catch (RuntimeException $error) {
    $errorMessage = $error->getMessage();
}
$assert(str_contains($errorMessage, 'tentativi: 2') && str_contains($errorMessage, 'input 41879'), 'limite al correttivo include entrambi i tentativi');
$assert(str_contains($errorMessage, '$ 0,15141'), 'costo cumulativo dei due tentativi');
$missingMetadata = $limited;
unset($missingMetadata['decoded']['usageMetadata']);
$errorMessage = '';
try {
    ai_report_assert_token_limit([$missingMetadata], 'gemini-3.8-flash', 'low', 20000);
} catch (RuntimeException $error) {
    $errorMessage = $error->getMessage();
}
$assert(str_contains($errorMessage, 'Consumi completi non comunicati') && !str_contains($errorMessage, '$'), 'nessun costo inventato senza metadati');
ai_report_assert_token_limit([$initial], 'gemini-3.8-flash', 'low', 20000);
$assert(true, 'risposta STOP non bloccata dal controllo token');

$text = ai_report_extract_text(['candidates' => [['content' => ['parts' => [
    ['thought' => true, 'text' => 'REASONING_NOT_REPORT'],
    ['text' => '### Sintesi'],
    ['text' => 'Testo finale.'],
    ['thoughtSignature' => 'SIGNATURE_NOT_REPORT'],
]]]]]);
$assert($text === "### Sintesi\n\nTesto finale.", 'ragionamento escluso dal testo da validare e archiviare');
$assert(ai_report_extract_text(['candidates' => [['content' => ['parts' => [['thought' => true, 'text' => 'Solo ragionamento']]]]]]) === '', 'solo ragionamento non diventa un report');

echo 'OK: ' . $checks . ' controlli modello, costi, lunghezza e budget superati.' . PHP_EOL;
