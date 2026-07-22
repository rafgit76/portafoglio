<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/ai_report.php';

$pageTitle = 'Centro AI';
$error = null;
$customTitle = trim((string) ($_POST['custom_title'] ?? ''));
$customPrompt = trim((string) ($_POST['custom_prompt'] ?? ''));
$geminiConfig = app_config()['gemini'] ?? [];
$configuredModel = (string) (($geminiConfig['model'] ?? '') ?: 'gemini-3.5-flash-lite');
$thinkingLevel = (string) (($geminiConfig['thinking_level'] ?? '') ?: 'medium');
$maxOutputTokens = (int) ($geminiConfig['max_output_tokens'] ?? 20000);
$apiKey = (string) ($geminiConfig['api_key'] ?? '');
$apiConfigured = $apiKey !== '' && !str_contains($apiKey, 'INSERISCI_CHIAVE');
$isAsyncRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

$reports = ai_report_archive_list();
if ($reports === [] && ai_report_load_cache() !== null) {
    try {
        ai_report_import_legacy_cache();
        $reports = ai_report_archive_list();
    } catch (Throwable $e) {
        $error = 'Il vecchio report non e stato importato nell\'archivio: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_valid_csrf_token(is_string($token) ? $token : null)) {
            throw new RuntimeException('Richiesta non valida (token).');
        }

        $action = (string) ($_POST['action'] ?? '');
        if (!in_array($action, ['generate_general', 'generate_custom'], true)) {
            throw new RuntimeException('Azione AI non riconosciuta.');
        }

        if ((string) ($_POST['confirmed'] ?? '') !== '1') {
            throw new RuntimeException('Conferma la generazione prima di avviare la chiamata AI.');
        }

        if (!$apiConfigured) {
            throw new RuntimeException('Chiave Gemini non configurata.');
        }

        if ($action === 'generate_custom') {
            if ($customPrompt === '') {
                throw new RuntimeException('Inserisci una richiesta per il report personalizzato.');
            }
            if (strlen($customPrompt) > 3000) {
                throw new RuntimeException('La richiesta personalizzata non puo superare 3.000 caratteri.');
            }
            if (strlen($customTitle) > 120) {
                throw new RuntimeException('Il titolo non puo superare 120 caratteri.');
            }
        }

        $built = ai_report_build_payload(db());
        if ($action === 'generate_custom') {
            $generated = ai_report_generate_custom_with_gemini($built['payload'], $customPrompt);
            $title = $customTitle !== '' ? $customTitle : 'Analisi personalizzata';
            $record = ai_report_archive_generated($generated, $built, 'custom', $title, $customPrompt);
            set_flash('success', 'Report personalizzato generato e archiviato.');
        } else {
            $generated = ai_report_generate_with_gemini($built['payload']);
            $record = ai_report_archive_generated($generated, $built, 'general', 'Report complessivo del portafoglio');
            set_flash('success', 'Report complessivo generato e archiviato.');
        }

        $reportUrl = app_base_path() . '/ai_report_view.php?id=' . rawurlencode((string) $record['id']);
        if ($isAsyncRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'redirect' => $reportUrl,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        redirect('/ai_report_view.php?id=' . rawurlencode((string) $record['id']));
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($isAsyncRequest) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => $error,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    $reports = ai_report_archive_list();
}

$archiveStats = ai_report_archive_stats($reports);
$promptTemplate = ai_report_build_prompt(
    '[DATI STORICI INSERITI AUTOMATICAMENTE DALL\'APP]',
    '[FATTI NUMERICI VERIFICATI E FORMATTATI AUTOMATICAMENTE DA PHP]'
);

include __DIR__ . '/partials/header.php';
?>
<section class="panel ai-hub-hero">
    <div class="panel-head">
        <div>
            <h2>Centro AI</h2>
            <p class="tiny-note">Genera analisi soltanto quando lo richiedi, consulta l'archivio e controlla modello, prompt e consumi.</p>
        </div>
        <span class="ai-status <?= $apiConfigured ? 'ai-status-ready' : 'ai-status-error' ?>">
            <?= $apiConfigured ? 'Gemini configurato' : 'API non configurata' ?>
        </span>
    </div>
</section>

<?php if ($error): ?>
    <div class="flash flash-error"><?= e($error) ?></div>
<?php endif; ?>

<section class="cards ai-service-cards" aria-label="Informazioni servizio AI">
    <article class="card">
        <h3>Modello</h3>
        <p class="ai-meta-big"><?= e($configuredModel) ?></p>
    </article>
    <article class="card">
        <h3>Ragionamento</h3>
        <p class="ai-meta-big"><?= e(ucfirst($thinkingLevel)) ?></p>
        <p class="tiny-note">Budget massimo <?= number_format($maxOutputTokens, 0, ',', '.') ?> token</p>
    </article>
    <article class="card">
        <h3>Report archiviati</h3>
        <p class="big"><?= number_format((int) $archiveStats['reports'], 0, ',', '.') ?></p>
    </article>
    <article class="card">
        <h3>Token complessivi</h3>
        <p class="big"><?= number_format((int) $archiveStats['total_tokens'], 0, ',', '.') ?></p>
        <p class="tiny-note">Consumo storico archiviato</p>
    </article>
</section>

<?php if ($reports !== []): ?>
    <section class="ai-usage" aria-label="Utilizzo cumulativo API Gemini">
        <div>
            <span>Token input consumati</span>
            <strong><?= number_format((int) $archiveStats['prompt_tokens'], 0, ',', '.') ?></strong>
        </div>
        <div>
            <span>Token risposta generati</span>
            <strong><?= number_format((int) $archiveStats['output_tokens'], 0, ',', '.') ?></strong>
        </div>
        <div>
            <span>Token ragionamento</span>
            <strong><?= number_format((int) $archiveStats['thinking_tokens'], 0, ',', '.') ?></strong>
        </div>
        <div>
            <span>Costo listino stimato</span>
            <strong>$ <?= number_format((float) $archiveStats['estimated_cost_usd'], 5, ',', '.') ?></strong>
        </div>
    </section>
<?php endif; ?>

<section class="ai-generator-grid">
    <article class="panel ai-generator-panel">
        <div>
            <span class="ai-generator-kicker">Analisi standard</span>
            <h2>Report complessivo</h2>
            <p>Analizza tutti gli snapshot con la struttura e i controlli numerici del prompt madre.</p>
        </div>
        <form method="post" id="general-report-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="generate_general">
            <details class="ai-inline-confirm">
                <summary class="<?= !$apiConfigured ? 'ai-action-disabled' : '' ?>">Genera report complessivo</summary>
                <div class="ai-inline-confirm-body">
                    <strong>Conferma generazione</strong>
                    <p>Gemini analizzera tutti gli snapshot disponibili. Se la prima bozza non supera i controlli numerici, l'app potra effettuare un secondo e ultimo tentativo correttivo: entrambi consumano token API.</p>
                    <button type="submit" name="confirmed" value="1" <?= !$apiConfigured ? 'disabled' : '' ?>>Conferma e genera</button>
                </div>
            </details>
        </form>
    </article>

    <article class="panel ai-generator-panel">
        <div>
            <span class="ai-generator-kicker">Analisi su richiesta</span>
            <h2>Report personalizzato</h2>
            <p>Formula una domanda sullo storico. Gemini ricevera gli stessi dati e le stesse regole di accuratezza.</p>
        </div>
        <form method="post" id="custom-report-form" class="ai-custom-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="generate_custom">
            <label>
                Titolo facoltativo
                <input type="text" name="custom_title" maxlength="120" value="<?= e($customTitle) ?>" placeholder="Es. Analisi della concentrazione">
            </label>
            <label>
                La tua richiesta
                <textarea name="custom_prompt" rows="5" maxlength="3000" required placeholder="Es. Confronta l'evoluzione del peso e del P/L delle tre posizioni principali, senza formulare raccomandazioni."><?= e($customPrompt) ?></textarea>
            </label>
            <details class="ai-inline-confirm">
                <summary class="<?= !$apiConfigured ? 'ai-action-disabled' : '' ?>">Genera report personalizzato</summary>
                <div class="ai-inline-confirm-body">
                    <strong>Conferma generazione</strong>
                    <p>Gemini usera la richiesta inserita e tutti gli snapshot disponibili. Se la prima bozza non supera i controlli, l'app potra effettuare un secondo e ultimo tentativo correttivo: entrambi consumano token API.</p>
                    <button type="submit" name="confirmed" value="1" <?= !$apiConfigured ? 'disabled' : '' ?>>Conferma e genera</button>
                </div>
            </details>
        </form>
    </article>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Archivio report</h2>
            <p class="tiny-note">I report sono conservati sul server e non vengono inclusi nel repository Git o nelle cartelle FTP.</p>
        </div>
        <?php if ($reports !== []): ?>
            <span class="runtime-chip"><?= number_format((int) $archiveStats['output_tokens'], 0, ',', '.') ?> token risposta</span>
        <?php endif; ?>
    </div>

    <?php if ($reports === []): ?>
        <div class="ai-empty-state">
            <strong>Nessun report archiviato</strong>
            <p>Il primo report comparira qui dopo una generazione esplicita.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap ai-archive-wrap">
            <table class="ai-archive-table">
                <thead>
                <tr>
                    <th>Generato</th>
                    <th>Tipo</th>
                    <th>Report</th>
                    <th>Periodo</th>
                    <th>Token</th>
                    <th>Costo stimato</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($reports as $report): ?>
                    <?php
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
                    ?>
                    <tr>
                        <td class="mono-cell ai-archive-date"><?= e($generatedLabel) ?></td>
                        <td><span class="ai-type-badge ai-type-<?= e((string) ($report['type'] ?? 'general')) ?>"><?= ($report['type'] ?? 'general') === 'custom' ? 'Personalizzato' : 'Complessivo' ?></span></td>
                        <td>
                            <strong><?= e((string) ($report['title'] ?? 'Report AI')) ?></strong>
                            <span class="ai-archive-model"><?= e((string) ($report['model'] ?? '')) ?></span>
                        </td>
                        <td><?= e($period !== '' ? $period : '-') ?></td>
                        <td class="mono-cell"><?= number_format((int) ($usage['total_tokens'] ?? 0), 0, ',', '.') ?></td>
                        <td class="mono-cell">$ <?= number_format((float) ($report['estimated_cost_usd'] ?? 0), 5, ',', '.') ?></td>
                        <td><a class="ai-open-link" href="<?= e(app_base_path()) ?>/ai_report_view.php?id=<?= e(rawurlencode((string) $report['id'])) ?>">Apri</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel ai-prompt-panel">
    <div class="panel-head">
        <div>
            <h2>Prompt madre</h2>
            <p class="tiny-note">Versione <?= e(ai_report_prompt_version()) ?>. E il contratto usato per il report complessivo.</p>
        </div>
    </div>
    <details class="ai-prompt-details">
        <summary>Mostra system instruction e struttura del report</summary>
        <div class="ai-prompt-block">
            <h3>System instruction</h3>
            <pre><?= e(ai_report_system_instruction()) ?></pre>
        </div>
        <div class="ai-prompt-block">
            <h3>Prompt del report complessivo</h3>
            <pre><?= e($promptTemplate) ?></pre>
        </div>
    </details>
</section>

<div class="ai-generation-overlay" id="ai-generation-overlay" hidden>
    <section class="ai-generation-dialog" role="dialog" aria-modal="true" aria-labelledby="ai-generation-title" aria-describedby="ai-generation-message">
        <div class="ai-generation-indicator" id="ai-generation-indicator" aria-hidden="true"></div>
        <span class="ai-generator-kicker">Elaborazione AI</span>
        <h2 id="ai-generation-title">Generazione del report in corso</h2>
        <p id="ai-generation-message">Preparazione degli snapshot da inviare a Gemini.</p>
        <div class="ai-generation-progress" id="ai-generation-progress" role="progressbar" aria-label="Avanzamento stimato" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
            <span id="ai-generation-progress-bar"></span>
        </div>
        <div class="ai-generation-progress-meta">
            <span id="ai-generation-phase">Preparazione dati</span>
            <strong id="ai-generation-percent">0%</strong>
        </div>
        <p class="ai-generation-note">Avanzamento stimato. La durata dipende da Gemini e dai controlli numerici; non chiudere questa scheda.</p>
        <button type="button" class="btn btn-secondary" id="ai-generation-close" hidden>Chiudi</button>
    </section>
</div>

<script src="<?= e(app_base_path()) ?>/assets/ai-report.js" defer></script>
<?php include __DIR__ . '/partials/footer.php'; ?>
