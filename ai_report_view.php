<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/ai_report.php';

$id = trim((string) ($_GET['id'] ?? ''));
$report = ai_report_archive_load($id);

if ($report === null) {
    http_response_code(404);
    $pageTitle = 'Report non trovato';
    include __DIR__ . '/partials/header.php';
    ?>
    <section class="panel">
        <h2>Report non trovato</h2>
        <p>Il report richiesto non esiste oppure l'identificativo non e valido.</p>
        <a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/ai_report.php">Torna al Centro AI</a>
    </section>
    <?php
    include __DIR__ . '/partials/footer.php';
    exit;
}

$pageTitle = (string) ($report['title'] ?? 'Report AI');
$usage = is_array($report['usage'] ?? null) ? $report['usage'] : [];
$generatedLabel = (string) ($report['generated_at'] ?? '-');
try {
    $generatedLabel = (new DateTimeImmutable($generatedLabel))->format('d/m/Y H:i');
} catch (Throwable $e) {
}

$period = trim((string) ($report['date_from'] ?? ''));
if ($period !== '' && trim((string) ($report['date_to'] ?? '')) !== '') {
    $period .= ' / ' . (string) $report['date_to'];
} elseif (trim((string) ($report['date_to'] ?? '')) !== '') {
    $period = 'fino al ' . (string) $report['date_to'];
}

include __DIR__ . '/partials/header.php';
$reportType = ai_report_type_class((string) ($report['type'] ?? 'general'));
?>
<section class="panel ai-report-view-head">
    <div class="panel-head">
        <div>
            <span class="ai-type-badge ai-type-<?= e($reportType) ?>"><?= e(ai_report_type_label($reportType)) ?></span>
            <h2><?= e((string) ($report['title'] ?? 'Report AI')) ?></h2>
            <p class="tiny-note">Generato il <?= e($generatedLabel) ?> con <?= e((string) ($report['model'] ?? 'Gemini')) ?> in <?= max(1, (int) ($report['generation_attempts'] ?? 1)) ?> tentativo/i.</p>
        </div>
        <div class="ai-report-actions no-print">
            <a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/ai_report.php">Centro AI</a>
            <button type="button" onclick="window.print()">Stampa PDF</button>
        </div>
    </div>
</section>

<?php if ($reportType === 'custom' && trim((string) ($report['custom_prompt'] ?? '')) !== ''): ?>
    <section class="panel ai-request-panel">
        <h3>Richiesta utilizzata</h3>
        <p><?= nl2br(e((string) $report['custom_prompt'])) ?></p>
    </section>
<?php endif; ?>

<section class="cards ai-report-meta-cards">
    <article class="card">
        <h3>Snapshot analizzati</h3>
        <p class="big"><?= number_format((int) ($report['snapshot_count'] ?? 0), 0, ',', '.') ?></p>
    </article>
    <article class="card">
        <h3>Periodo</h3>
        <p class="ai-meta-big"><?= e($period !== '' ? $period : 'Non disponibile') ?></p>
    </article>
    <article class="card">
        <h3>Modello</h3>
        <p class="ai-meta-big"><?= e((string) ($report['model'] ?? 'Gemini')) ?></p>
    </article>
    <article class="card">
        <h3>Ragionamento</h3>
        <p class="ai-meta-big"><?= e(ucfirst((string) ($report['thinking_level'] ?? 'N/D'))) ?></p>
    </article>
</section>

<section class="ai-usage" aria-label="Utilizzo API Gemini">
    <div>
        <span>Token input</span>
        <strong><?= number_format((int) ($usage['prompt_tokens'] ?? 0), 0, ',', '.') ?></strong>
    </div>
    <div>
        <span>Token risposta</span>
        <strong><?= number_format((int) ($usage['output_tokens'] ?? 0), 0, ',', '.') ?></strong>
    </div>
    <div>
        <span>Token ragionamento</span>
        <strong><?= number_format((int) ($usage['thinking_tokens'] ?? 0), 0, ',', '.') ?></strong>
    </div>
    <div>
        <span>Costo listino stimato</span>
        <strong>$ <?= number_format((float) ($report['estimated_cost_usd'] ?? 0), 5, ',', '.') ?></strong>
    </div>
</section>

<section class="panel ai-report-panel">
    <?= ai_report_render_markdown((string) ($report['report_markdown'] ?? '')) ?>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>
