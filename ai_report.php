<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/ai_report.php';

$pageTitle = 'Report AI';
$pdo = db();
$error = null;
$notice = null;
$built = null;
$cache = ai_report_load_cache();
$configuredModel = (string) ((app_config()['gemini']['model'] ?? '') ?: 'gemini-3.1-flash-lite');
$configuredPromptVersion = ai_report_prompt_version();

try {
    $built = ai_report_build_payload($pdo);
    $isStale = !$cache
        || (($cache['data_hash'] ?? '') !== $built['hash'])
        || ((string) ($cache['model'] ?? '') !== $configuredModel)
        || ((string) ($cache['prompt_version'] ?? '') !== $configuredPromptVersion);
    $forceRegenerate = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate';

    if ($forceRegenerate) {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_valid_csrf_token(is_string($token) ? $token : null)) {
            throw new RuntimeException('Richiesta non valida (token).');
        }
    }

    if ($forceRegenerate || $isStale) {
        $generated = ai_report_generate_with_gemini($built['payload']);
        $cache = [
            'data_hash' => $built['hash'],
            'snapshot_to' => $built['payload']['date_range']['to'] ?? null,
            'generated_at' => $generated['generated_at'],
            'provider' => $generated['provider'],
            'model' => $generated['model'],
            'prompt_version' => $generated['prompt_version'],
            'usage' => [
                'prompt_tokens' => $generated['prompt_tokens'],
                'output_tokens' => $generated['output_tokens'],
                'total_tokens' => $generated['total_tokens'],
            ],
            'report_markdown' => $generated['report_markdown'],
        ];
        ai_report_save_cache($cache);
        $notice = $forceRegenerate ? 'Report AI rigenerato.' : 'Report AI aggiornato con gli ultimi snapshot.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

include __DIR__ . '/partials/header.php';
?>
<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Report AI</h2>
            <p class="tiny-note">Analisi generata da Gemini sui dati storici degli snapshot salvati in archivio.</p>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="regenerate">
            <div class="ai-report-actions">
                <button type="button" class="btn-secondary" onclick="window.print()">Versione stampabile</button>
                <button type="submit">Rigenera report</button>
            </div>
        </form>
    </div>
</section>

<?php if ($notice): ?>
    <div class="flash flash-success"><?= e($notice) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="flash flash-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($cache): ?>
    <section class="cards">
        <article class="card">
            <h3>Snapshot analizzati</h3>
            <p class="big"><?= (int) ($built['payload']['snapshots_count'] ?? 0) ?></p>
        </article>
        <article class="card">
            <h3>Periodo</h3>
            <p class="ai-meta-big"><?= e((string) ($built['payload']['date_range']['from'] ?? '-')) ?> / <?= e((string) ($built['payload']['date_range']['to'] ?? '-')) ?></p>
        </article>
        <article class="card">
            <h3>Modello</h3>
            <p class="ai-meta-big"><?= e((string) ($cache['model'] ?? 'Gemini')) ?></p>
        </article>
        <article class="card">
            <h3>Generato</h3>
            <p class="ai-meta-big">
                <?php
                $generatedLabel = (string) ($cache['generated_at'] ?? '-');
                try {
                    $generatedLabel = (new DateTimeImmutable($generatedLabel))->format('Y-m-d H:i');
                } catch (Throwable $e) {
                }
                ?>
                <?= e($generatedLabel) ?>
            </p>
        </article>
    </section>

    <section class="panel ai-report-panel">
        <?= ai_report_render_markdown((string) ($cache['report_markdown'] ?? '')) ?>
    </section>
<?php elseif (!$error): ?>
    <section class="panel">
        <p>Nessun report AI disponibile. Usa il pulsante “Rigenera report”.</p>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
