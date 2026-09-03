<?php
// CLI-only UI fixture: no bootstrap, private configuration, database or real archive.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'test-csrf-token'; }
function app_base_path(): string { return ''; }
function ai_report_type_class(string $type): string { return $type; }
function ai_report_type_label(string $type, bool $compact): string { return 'Report di prova'; }
function ai_report_record_estimated_cost_usd(array $report): float { return 0.02; }
function ai_report_format_cost(?float $cost): string { return '$ 0,02000'; }

$reports = [];
if (($argv[1] ?? '') !== 'empty') {
    for ($i = 0; $i < 6; $i++) {
        $reports[] = [
            'id' => 'test-report-' . $i,
            'title' => $i === 5 ? 'Report <speciale> "test" ' . str_repeat('TitoloLungo', 20) : 'Report sintetico ' . $i,
            'generated_at' => '2026-09-03T07:41:00+02:00',
            'model' => 'test-model',
            'type' => 'general',
            'date_from' => '2026-09-02',
            'date_to' => '2026-09-03',
            'usage' => ['total_tokens' => 100],
        ];
    }
}
$archiveStats = ['output_tokens' => 100];
$source = file_get_contents(__DIR__ . '/../ai_report.php');
$heading = strpos($source, '<h2>Archivio report</h2>');
$start = strrpos(substr($source, 0, $heading), '<section class="panel">');
$end = strpos($source, '<section class="panel ai-prompt-panel">', $heading);
if ($start === false || $end === false) {
    throw new RuntimeException('Markup archivio non trovato.');
}
$overlayStart = strpos($source, '<div class="ai-generation-overlay"');
$overlayEnd = strpos($source, '<?php', $overlayStart);
?>
<!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/style.css"></head><body><main class="container">
<?php eval('?>' . substr($source, $start, $end - $start)); ?>
<form method="post" id="account-general-report-form">
    <input type="hidden" name="action" value="generate_account_general">
    <button type="submit" name="confirmed" value="1">Genera prova</button>
</form>
<?= substr($source, $overlayStart, $overlayEnd - $overlayStart) ?>
</main><script src="/assets/ai-report.js" defer></script></body></html>
