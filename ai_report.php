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
        if (!in_array($action, [
            'generate_general',
            'generate_custom',
            'generate_changes',
            'generate_weekly_changes',
            'generate_account_general',
            'generate_account_changes',
            'generate_account_weekly_changes',
            'delete_report',
        ], true)) {
            throw new RuntimeException('Azione AI non riconosciuta.');
        }

        if ((string) ($_POST['confirmed'] ?? '') !== '1') {
            throw new RuntimeException('Conferma la generazione prima di avviare la chiamata AI.');
        }

        if ($action === 'delete_report') {
            $reportId = trim((string) ($_POST['report_id'] ?? ''));
            ai_report_archive_delete($reportId);
            set_flash('success', 'Report eliminato dall\'archivio AI.');
            redirect('/ai_report.php');
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

        $built = match ($action) {
            'generate_changes' => ai_report_build_changes_payload(db()),
            'generate_weekly_changes' => ai_report_build_weekly_changes_payload(db()),
            'generate_account_general' => ai_report_build_account_payload(db()),
            'generate_account_changes' => ai_report_build_account_changes_payload(db()),
            'generate_account_weekly_changes' => ai_report_build_account_weekly_changes_payload(db()),
            default => ai_report_build_payload(db()),
        };
        if ($action === 'generate_custom') {
            $generated = ai_report_generate_custom_with_gemini($built['payload'], $customPrompt);
            $title = $customTitle !== '' ? $customTitle : 'Analisi personalizzata';
            $record = ai_report_archive_generated($generated, $built, 'custom', $title, $customPrompt);
            set_flash('success', 'Report personalizzato generato e archiviato.');
        } elseif ($action === 'generate_weekly_changes') {
            $generated = ai_report_generate_weekly_changes_with_gemini($built['payload']);
            $dateTo = (string) ($built['payload']['date_range']['to'] ?? '');
            $title = 'Cosa e cambiato settimanale?';
            if ($dateTo !== '') {
                $title .= ' - ' . ai_report_format_date_it($dateTo);
            }
            $record = ai_report_archive_generated($generated, $built, 'weekly_changes', $title);
            set_flash('success', 'Confronto settimanale generato e archiviato.');
        } elseif ($action === 'generate_account_general') {
            $generated = ai_report_generate_account_with_gemini($built['payload']);
            $record = ai_report_archive_generated($generated, $built, 'account_general', 'Report complessivo del patrimonio');
            set_flash('success', 'Report complessivo del patrimonio generato e archiviato.');
        } elseif ($action === 'generate_account_weekly_changes') {
            $generated = ai_report_generate_account_changes_with_gemini($built['payload'], 'weekly');
            $dateTo = (string) ($built['payload']['date_range']['to'] ?? '');
            $title = 'Cosa e cambiato nel patrimonio settimanale?';
            if ($dateTo !== '') {
                $title .= ' - ' . ai_report_format_date_it($dateTo);
            }
            $record = ai_report_archive_generated($generated, $built, 'account_weekly_changes', $title);
            set_flash('success', 'Confronto patrimoniale settimanale generato e archiviato.');
        } elseif ($action === 'generate_account_changes') {
            $generated = ai_report_generate_account_changes_with_gemini($built['payload']);
            $dateTo = (string) ($built['payload']['date_range']['to'] ?? '');
            $title = 'Cosa e cambiato nel patrimonio?';
            if ($dateTo !== '') {
                $title .= ' - ' . ai_report_format_date_it($dateTo);
            }
            $record = ai_report_archive_generated($generated, $built, 'account_changes', $title);
            set_flash('success', 'Confronto patrimoniale generato e archiviato.');
        } elseif ($action === 'generate_changes') {
            $generated = ai_report_generate_changes_with_gemini($built['payload']);
            $dateTo = (string) ($built['payload']['date_range']['to'] ?? '');
            $title = 'Cosa e cambiato?';
            if ($dateTo !== '') {
                $title .= ' - ' . ai_report_format_date_it($dateTo);
            }
            $record = ai_report_archive_generated($generated, $built, 'changes', $title);
            set_flash('success', 'Confronto degli ultimi snapshot generato e archiviato.');
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
$changesPromptTemplate = ai_report_build_changes_prompt(
    '[DATI DEGLI ULTIMI DUE SNAPSHOT INSERITI AUTOMATICAMENTE DALL\'APP]',
    '[FATTI DEL CONFRONTO CALCOLATI E FORMATTATI AUTOMATICAMENTE DA PHP]'
);
$weeklyChangesPromptTemplate = ai_report_build_changes_prompt(
    '[DATI DEI DUE SNAPSHOT DEL SABATO INSERITI AUTOMATICAMENTE DALL\'APP]',
    '[FATTI DEL CONFRONTO SETTIMANALE CALCOLATI E FORMATTATI AUTOMATICAMENTE DA PHP]',
    'weekly'
);
$accountPromptTemplate = ai_report_build_account_prompt(
    '[DATI PATRIMONIALI INSERITI AUTOMATICAMENTE DALL\'APP]',
    '[FATTI PATRIMONIALI VERIFICATI E FORMATTATI AUTOMATICAMENTE DA PHP]'
);
$accountChangesPromptTemplate = ai_report_build_account_changes_prompt(
    '[DATI DEGLI ULTIMI DUE SNAPSHOT PATRIMONIALI INSERITI AUTOMATICAMENTE DALL\'APP]',
    '[FATTI DEL CONFRONTO PATRIMONIALE CALCOLATI E FORMATTATI AUTOMATICAMENTE DA PHP]'
);
$changesBuilt = null;
$changesUnavailable = null;
try {
    $changesBuilt = ai_report_build_changes_payload(db());
} catch (Throwable $e) {
    $changesUnavailable = $e->getMessage();
}
$changesPayload = is_array($changesBuilt['payload'] ?? null) ? $changesBuilt['payload'] : [];
$changesRange = is_array($changesPayload['range_summary'] ?? null) ? $changesPayload['range_summary'] : [];
$changesSummary = is_array($changesPayload['change_summary'] ?? null) ? $changesPayload['change_summary'] : [];
$changesDateRange = is_array($changesPayload['date_range'] ?? null) ? $changesPayload['date_range'] : [];
$changesPositions = is_array($changesPayload['first_to_latest_position_changes'] ?? null)
    ? array_slice($changesPayload['first_to_latest_position_changes'], 0, 3)
    : [];
$isSaturday = (new DateTimeImmutable('today'))->format('N') === '6';
$weeklyChangesBuilt = null;
$weeklyChangesUnavailable = null;
try {
    $weeklyChangesBuilt = ai_report_build_weekly_changes_payload(db());
} catch (Throwable $e) {
    $weeklyChangesUnavailable = $e->getMessage();
}
$weeklyChangesPayload = is_array($weeklyChangesBuilt['payload'] ?? null) ? $weeklyChangesBuilt['payload'] : [];
$weeklyChangesRange = is_array($weeklyChangesPayload['range_summary'] ?? null) ? $weeklyChangesPayload['range_summary'] : [];
$weeklyChangesSummary = is_array($weeklyChangesPayload['change_summary'] ?? null) ? $weeklyChangesPayload['change_summary'] : [];
$weeklyChangesDateRange = is_array($weeklyChangesPayload['date_range'] ?? null) ? $weeklyChangesPayload['date_range'] : [];
$weeklyChangesPositions = is_array($weeklyChangesPayload['first_to_latest_position_changes'] ?? null)
    ? array_slice($weeklyChangesPayload['first_to_latest_position_changes'], 0, 3)
    : [];
$accountTrackerReady = account_tracker_schema_ready(db());
$accountGeneralBuilt = null;
$accountGeneralUnavailable = null;
$accountChangesBuilt = null;
$accountChangesUnavailable = null;
$accountWeeklyBuilt = null;
$accountWeeklyUnavailable = null;
if ($accountTrackerReady) {
    try {
        $accountGeneralBuilt = ai_report_build_account_payload(db());
    } catch (Throwable $e) {
        $accountGeneralUnavailable = $e->getMessage();
    }
    try {
        $accountChangesBuilt = ai_report_build_account_changes_payload(db());
    } catch (Throwable $e) {
        $accountChangesUnavailable = $e->getMessage();
    }
    try {
        $accountWeeklyBuilt = ai_report_build_account_weekly_changes_payload(db());
    } catch (Throwable $e) {
        $accountWeeklyUnavailable = $e->getMessage();
    }
} else {
    $accountGeneralUnavailable = 'Il modulo Conto non e inizializzato.';
    $accountChangesUnavailable = $accountGeneralUnavailable;
    $accountWeeklyUnavailable = $accountGeneralUnavailable;
}
$accountGeneralPayload = is_array($accountGeneralBuilt['payload'] ?? null) ? $accountGeneralBuilt['payload'] : [];
$accountChangesPayload = is_array($accountChangesBuilt['payload'] ?? null) ? $accountChangesBuilt['payload'] : [];
$accountWeeklyPayload = is_array($accountWeeklyBuilt['payload'] ?? null) ? $accountWeeklyBuilt['payload'] : [];
$accountMetricLabels = is_array($accountGeneralPayload['metric_labels'] ?? null)
    ? $accountGeneralPayload['metric_labels']
    : ai_report_account_metric_definitions();
$accountChangesRange = is_array($accountChangesPayload['metrics'] ?? null) ? $accountChangesPayload['metrics'] : [];
$accountWeeklyRange = is_array($accountWeeklyPayload['metrics'] ?? null) ? $accountWeeklyPayload['metrics'] : [];
$accountChangesDateRange = is_array($accountChangesPayload['date_range'] ?? null) ? $accountChangesPayload['date_range'] : [];
$accountWeeklyDateRange = is_array($accountWeeklyPayload['date_range'] ?? null) ? $accountWeeklyPayload['date_range'] : [];
$accountChangesPreviewFields = array_keys(array_filter(
    $accountMetricLabels,
    static fn(string $field): bool => ($accountChangesRange[$field]['delta'] ?? null) !== null,
    ARRAY_FILTER_USE_KEY
));
$accountWeeklyPreviewFields = array_keys(array_filter(
    $accountMetricLabels,
    static fn(string $field): bool => ($accountWeeklyRange[$field]['delta'] ?? null) !== null,
    ARRAY_FILTER_USE_KEY
));
$formatSignedMoney = static function (float $value): string {
    return 'EUR ' . ai_report_format_number_it($value, 2, true);
};
$formatAccountMoney = static function (?float $value, bool $signed = false): string {
    return $value === null ? 'Dato non disponibile' : 'EUR ' . ai_report_format_number_it($value, 2, $signed);
};

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

<section class="panel ai-changes-panel" id="ai-changes">
    <div class="panel-head">
        <div>
            <span class="ai-generator-kicker">Ultimo aggiornamento</span>
            <h2>Cosa e cambiato?</h2>
            <?php if ($changesBuilt !== null): ?>
                <p>Confronta lo snapshot del <?= e(ai_report_format_date_it((string) ($changesDateRange['to'] ?? ''))) ?> con quello del <?= e(ai_report_format_date_it((string) ($changesDateRange['from'] ?? ''))) ?>.</p>
            <?php else: ?>
                <p>Confronta automaticamente gli ultimi due snapshot e spiega le variazioni piu importanti.</p>
            <?php endif; ?>
        </div>
        <span class="ai-status <?= $changesBuilt !== null ? 'ai-status-ready' : 'ai-status-error' ?>">
            <?= $changesBuilt !== null ? 'Confronto disponibile' : 'Dati insufficienti' ?>
        </span>
    </div>

    <?php if ($changesBuilt === null): ?>
        <div class="ai-empty-state">
            <strong>Confronto non disponibile</strong>
            <p><?= e($changesUnavailable ?? 'Servono almeno due snapshot.') ?></p>
        </div>
    <?php else: ?>
        <div class="ai-change-metrics" aria-label="Anteprima variazioni ultimo snapshot">
            <?php foreach ([
                'Valore di mercato' => (float) ($changesRange['delta_market_value'] ?? 0),
                'Valore di carico' => (float) ($changesRange['delta_cost_basis'] ?? 0),
                'P/L non realizzato' => (float) ($changesRange['delta_unrealized_pnl'] ?? 0),
            ] as $label => $value): ?>
                <div>
                    <span><?= e($label) ?></span>
                    <strong class="<?= $value >= 0 ? 'pos' : 'neg' ?>"><?= e($formatSignedMoney($value)) ?></strong>
                </div>
            <?php endforeach; ?>
            <div>
                <span>Strumenti su / giu</span>
                <strong><?= (int) ($changesSummary['positions_up'] ?? 0) ?> / <?= (int) ($changesSummary['positions_down'] ?? 0) ?></strong>
            </div>
        </div>

        <div class="ai-changes-body">
            <div>
                <h3>Variazioni principali</h3>
                <ul class="ai-change-list">
                    <?php foreach ($changesPositions as $position): ?>
                        <?php $delta = (float) ($position['delta_market_value'] ?? 0); ?>
                        <li>
                            <span><?= e((string) ($position['name'] ?? 'Strumento')) ?></span>
                            <strong class="<?= $delta >= 0 ? 'pos' : 'neg' ?>"><?= e($formatSignedMoney($delta)) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="tiny-note">Questa anteprima e calcolata da PHP e non consuma token.</p>
            </div>
            <form method="post" id="changes-report-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="generate_changes">
                <details class="ai-inline-confirm">
                    <summary class="<?= !$apiConfigured ? 'ai-action-disabled' : '' ?>">Spiegami cosa e cambiato</summary>
                    <div class="ai-inline-confirm-body">
                        <strong>Conferma generazione</strong>
                        <p>Gemini ricevera soltanto gli ultimi due snapshot e i delta gia verificati da PHP. Il confronto verra salvato nell'archivio.</p>
                        <button type="submit" name="confirmed" value="1" <?= !$apiConfigured ? 'disabled' : '' ?>>Conferma e analizza</button>
                    </div>
                </details>
            </form>
        </div>
    <?php endif; ?>
</section>

<section class="panel ai-changes-panel ai-weekly-changes-panel" id="ai-weekly-changes">
    <div class="panel-head">
        <div>
            <span class="ai-generator-kicker">Riepilogo del sabato</span>
            <h2>Cosa e cambiato settimanale?</h2>
            <?php if ($weeklyChangesBuilt !== null): ?>
                <p>Confronta il sabato <?= e(ai_report_format_date_it((string) ($weeklyChangesDateRange['to'] ?? ''))) ?> con il sabato <?= e(ai_report_format_date_it((string) ($weeklyChangesDateRange['from'] ?? ''))) ?>.</p>
            <?php else: ?>
                <p>Confronta esclusivamente lo snapshot di questo sabato con quello del sabato precedente.</p>
            <?php endif; ?>
        </div>
        <?php
        $weeklyStatusClass = $weeklyChangesBuilt !== null
            ? 'ai-status-ready'
            : ($isSaturday ? 'ai-status-error' : 'ai-status-scheduled');
        $weeklyStatusLabel = $weeklyChangesBuilt !== null
            ? 'Settimana disponibile'
            : ($isSaturday ? 'Dati insufficienti' : 'Solo il sabato');
        ?>
        <span class="ai-status <?= e($weeklyStatusClass) ?>"><?= e($weeklyStatusLabel) ?></span>
    </div>

    <?php if ($weeklyChangesBuilt === null): ?>
        <div class="ai-empty-state">
            <strong>Confronto settimanale non disponibile</strong>
            <p><?= e($weeklyChangesUnavailable ?? 'La funzione si attiva soltanto il sabato.') ?></p>
        </div>
    <?php else: ?>
        <div class="ai-change-metrics" aria-label="Anteprima variazioni settimanali">
            <?php foreach ([
                'Valore di mercato' => (float) ($weeklyChangesRange['delta_market_value'] ?? 0),
                'Valore di carico' => (float) ($weeklyChangesRange['delta_cost_basis'] ?? 0),
                'P/L non realizzato' => (float) ($weeklyChangesRange['delta_unrealized_pnl'] ?? 0),
            ] as $label => $value): ?>
                <div>
                    <span><?= e($label) ?></span>
                    <strong class="<?= $value >= 0 ? 'pos' : 'neg' ?>"><?= e($formatSignedMoney($value)) ?></strong>
                </div>
            <?php endforeach; ?>
            <div>
                <span>Strumenti su / giu</span>
                <strong><?= (int) ($weeklyChangesSummary['positions_up'] ?? 0) ?> / <?= (int) ($weeklyChangesSummary['positions_down'] ?? 0) ?></strong>
            </div>
        </div>

        <div class="ai-changes-body">
            <div>
                <h3>Variazioni principali della settimana</h3>
                <ul class="ai-change-list">
                    <?php foreach ($weeklyChangesPositions as $position): ?>
                        <?php $delta = (float) ($position['delta_market_value'] ?? 0); ?>
                        <li>
                            <span><?= e((string) ($position['name'] ?? 'Strumento')) ?></span>
                            <strong class="<?= $delta >= 0 ? 'pos' : 'neg' ?>"><?= e($formatSignedMoney($delta)) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="tiny-note">Anteprima PHP gratuita. La chiamata a Gemini parte soltanto dopo la conferma.</p>
            </div>
            <form method="post" id="weekly-changes-report-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="generate_weekly_changes">
                <details class="ai-inline-confirm">
                    <summary class="<?= !$apiConfigured ? 'ai-action-disabled' : '' ?>">Spiegami la settimana</summary>
                    <div class="ai-inline-confirm-body">
                        <strong>Conferma analisi settimanale</strong>
                        <p>Gemini ricevera soltanto i due snapshot del sabato distanti sette giorni e i delta verificati da PHP.</p>
                        <button type="submit" name="confirmed" value="1" <?= !$apiConfigured ? 'disabled' : '' ?>>Conferma e analizza</button>
                    </div>
                </details>
            </form>
        </div>
    <?php endif; ?>
</section>

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

<section class="panel ai-changes-panel" id="ai-account-reports">
    <div class="panel-head">
        <div>
            <span class="ai-generator-kicker">Dati opzionali del conto</span>
            <h2>Report AI patrimonio</h2>
            <p>Analisi separate di patrimonio, liquidita, saldi e credito. Il credito disponibile non viene mai considerato patrimonio.</p>
        </div>
        <span class="ai-status <?= $accountGeneralBuilt !== null ? 'ai-status-ready' : 'ai-status-scheduled' ?>">
            <?= $accountGeneralBuilt !== null ? 'Storico disponibile' : 'In attesa di dati' ?>
        </span>
    </div>

    <?php if ($accountGeneralBuilt === null): ?>
        <div class="ai-empty-state">
            <strong>Report patrimoniale non disponibile</strong>
            <p><?= e($accountGeneralUnavailable ?? 'Servono almeno due snapshot patrimoniali con valori confrontabili.') ?></p>
        </div>
    <?php else: ?>
        <div class="ai-change-metrics" aria-label="Riepilogo ultimo snapshot patrimoniale">
            <?php foreach (['fineco_total_assets', 'liquidity_total', 'accounting_balance', 'available_balance'] as $field): ?>
                <?php
                $metric = is_array($accountGeneralPayload['metrics'][$field] ?? null)
                    ? $accountGeneralPayload['metrics'][$field]
                    : [];
                $value = $metric['latest'] ?? null;
                ?>
                <div>
                    <span><?= e((string) ($accountMetricLabels[$field] ?? $field)) ?></span>
                    <strong class="<?= $value !== null && (float) $value < 0 ? 'neg' : '' ?>"><?= e($formatAccountMoney($value !== null ? (float) $value : null)) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="tiny-note">Sono mostrate solo rilevazioni effettivamente archiviate. Le voci mancanti restano non disponibili e non vengono sostituite con zero.</p>
    <?php endif; ?>
</section>

<section class="ai-generator-grid ai-account-generator-grid">
    <article class="panel ai-generator-panel">
        <div>
            <span class="ai-generator-kicker">Analisi standard</span>
            <h2>Report complessivo patrimonio</h2>
            <p>Riepiloga lo storico disponibile mantenendo patrimonio, liquidita, saldi e credito separati.</p>
        </div>
        <?php if ($accountGeneralBuilt === null): ?>
            <p class="tiny-note"><?= e($accountGeneralUnavailable ?? 'Servono almeno due snapshot patrimoniali.') ?></p>
        <?php else: ?>
            <form method="post" id="account-general-report-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="generate_account_general">
                <details class="ai-inline-confirm">
                    <summary class="<?= !$apiConfigured ? 'ai-action-disabled' : '' ?>">Genera report patrimonio</summary>
                    <div class="ai-inline-confirm-body">
                        <strong>Conferma generazione</strong>
                        <p>Gemini ricevera soltanto gli snapshot patrimoniali e i valori verificati da PHP. Il report verra archiviato separatamente per tipo.</p>
                        <button type="submit" name="confirmed" value="1" <?= !$apiConfigured ? 'disabled' : '' ?>>Conferma e genera</button>
                    </div>
                </details>
            </form>
        <?php endif; ?>
    </article>

    <article class="panel ai-generator-panel">
        <div>
            <span class="ai-generator-kicker">Ultimo aggiornamento</span>
            <h2>Cosa e cambiato nel patrimonio?</h2>
            <?php if ($accountChangesBuilt !== null): ?>
                <p>Confronta lo snapshot del <?= e(ai_report_format_date_it((string) ($accountChangesDateRange['to'] ?? ''))) ?> con quello del <?= e(ai_report_format_date_it((string) ($accountChangesDateRange['from'] ?? ''))) ?>.</p>
            <?php else: ?>
                <p>Confronta automaticamente gli ultimi due snapshot patrimoniali disponibili.</p>
            <?php endif; ?>
        </div>
        <?php if ($accountChangesBuilt === null): ?>
            <p class="tiny-note"><?= e($accountChangesUnavailable ?? 'Servono almeno due snapshot patrimoniali.') ?></p>
        <?php else: ?>
            <div class="ai-change-list ai-account-change-list">
                <?php foreach ($accountChangesPreviewFields as $field): ?>
                    <?php
                    $metric = is_array($accountChangesRange[$field] ?? null) ? $accountChangesRange[$field] : [];
                    $delta = $metric['delta'] ?? null;
                    ?>
                    <?php if ($delta !== null): ?>
                        <div>
                            <span><?= e((string) ($accountMetricLabels[$field] ?? $field)) ?></span>
                            <strong class="<?= (float) $delta >= 0 ? 'pos' : 'neg' ?>"><?= e($formatAccountMoney((float) $delta, true)) ?></strong>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <form method="post" id="account-changes-report-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="generate_account_changes">
                <details class="ai-inline-confirm">
                    <summary class="<?= !$apiConfigured ? 'ai-action-disabled' : '' ?>">Spiegami cosa e cambiato</summary>
                    <div class="ai-inline-confirm-body">
                        <strong>Conferma analisi patrimoniale</strong>
                        <p>Gemini ricevera soltanto gli ultimi due snapshot patrimoniali e i delta verificati da PHP.</p>
                        <button type="submit" name="confirmed" value="1" <?= !$apiConfigured ? 'disabled' : '' ?>>Conferma e analizza</button>
                    </div>
                </details>
            </form>
        <?php endif; ?>
    </article>

    <article class="panel ai-generator-panel">
        <div>
            <span class="ai-generator-kicker">Riepilogo del sabato</span>
            <h2>Cosa e cambiato nel patrimonio settimanale?</h2>
            <?php if ($accountWeeklyBuilt !== null): ?>
                <p>Confronta il sabato <?= e(ai_report_format_date_it((string) ($accountWeeklyDateRange['to'] ?? ''))) ?> con il sabato <?= e(ai_report_format_date_it((string) ($accountWeeklyDateRange['from'] ?? ''))) ?>.</p>
            <?php else: ?>
                <p>Disponibile soltanto il sabato, con i due snapshot patrimoniali distanti 7 giorni.</p>
            <?php endif; ?>
        </div>
        <?php if ($accountWeeklyBuilt === null): ?>
            <p class="tiny-note"><?= e($accountWeeklyUnavailable ?? 'La funzione si attiva soltanto il sabato.') ?></p>
        <?php else: ?>
            <div class="ai-change-list ai-account-change-list">
                <?php foreach ($accountWeeklyPreviewFields as $field): ?>
                    <?php
                    $metric = is_array($accountWeeklyRange[$field] ?? null) ? $accountWeeklyRange[$field] : [];
                    $delta = $metric['delta'] ?? null;
                    ?>
                    <?php if ($delta !== null): ?>
                        <div>
                            <span><?= e((string) ($accountMetricLabels[$field] ?? $field)) ?></span>
                            <strong class="<?= (float) $delta >= 0 ? 'pos' : 'neg' ?>"><?= e($formatAccountMoney((float) $delta, true)) ?></strong>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <form method="post" id="account-weekly-changes-report-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="generate_account_weekly_changes">
                <details class="ai-inline-confirm">
                    <summary class="<?= !$apiConfigured ? 'ai-action-disabled' : '' ?>">Spiegami la settimana</summary>
                    <div class="ai-inline-confirm-body">
                        <strong>Conferma analisi patrimoniale settimanale</strong>
                        <p>Gemini ricevera soltanto i due snapshot patrimoniali del sabato e i delta verificati da PHP.</p>
                        <button type="submit" name="confirmed" value="1" <?= !$apiConfigured ? 'disabled' : '' ?>>Conferma e analizza</button>
                    </div>
                </details>
            </form>
        <?php endif; ?>
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
                    <th>Azioni</th>
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
                        <?php $reportType = ai_report_type_class((string) ($report['type'] ?? 'general')); ?>
                        <td><span class="ai-type-badge ai-type-<?= e($reportType) ?>"><?= e(ai_report_type_label($reportType, true)) ?></span></td>
                        <td>
                            <strong><?= e((string) ($report['title'] ?? 'Report AI')) ?></strong>
                            <span class="ai-archive-model"><?= e((string) ($report['model'] ?? '')) ?></span>
                        </td>
                        <td><?= e($period !== '' ? $period : '-') ?></td>
                        <td class="mono-cell"><?= number_format((int) ($usage['total_tokens'] ?? 0), 0, ',', '.') ?></td>
                        <td class="mono-cell">$ <?= number_format((float) ($report['estimated_cost_usd'] ?? 0), 5, ',', '.') ?></td>
                        <td>
                            <div class="ai-archive-actions">
                                <a class="ai-open-link" href="<?= e(app_base_path()) ?>/ai_report_view.php?id=<?= e(rawurlencode((string) $report['id'])) ?>">Apri</a>
                                <details class="ai-archive-delete">
                                    <summary>Elimina</summary>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_report">
                                        <input type="hidden" name="report_id" value="<?= e((string) $report['id']) ?>">
                                        <p>Eliminare solo questo report archiviato?</p>
                                        <button type="submit" name="confirmed" value="1" class="btn-danger">Conferma elimina</button>
                                    </form>
                                </details>
                            </div>
                        </td>
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
    <details class="ai-prompt-details">
        <summary>Mostra il prompt Cosa e cambiato?</summary>
        <div class="ai-prompt-block">
            <h3>System instruction del confronto</h3>
            <pre><?= e(ai_report_changes_system_instruction()) ?></pre>
        </div>
        <div class="ai-prompt-block">
            <h3>Struttura del confronto</h3>
            <pre><?= e($changesPromptTemplate) ?></pre>
        </div>
    </details>
    <details class="ai-prompt-details">
        <summary>Mostra il prompt Cosa e cambiato settimanale?</summary>
        <div class="ai-prompt-block">
            <h3>System instruction del confronto settimanale</h3>
            <pre><?= e(ai_report_changes_system_instruction('weekly')) ?></pre>
        </div>
        <div class="ai-prompt-block">
            <h3>Struttura del confronto settimanale</h3>
            <pre><?= e($weeklyChangesPromptTemplate) ?></pre>
        </div>
    </details>
    <details class="ai-prompt-details">
        <summary>Mostra il prompt Report AI patrimonio</summary>
        <div class="ai-prompt-block">
            <h3>System instruction patrimonio</h3>
            <pre><?= e(ai_report_account_system_instruction()) ?></pre>
        </div>
        <div class="ai-prompt-block">
            <h3>Struttura del report patrimonio</h3>
            <pre><?= e($accountPromptTemplate) ?></pre>
        </div>
        <div class="ai-prompt-block">
            <h3>Struttura del confronto patrimonio</h3>
            <pre><?= e($accountChangesPromptTemplate) ?></pre>
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

<?php
$aiReportScriptPath = __DIR__ . '/assets/ai-report.js';
$aiReportScriptVersion = is_file($aiReportScriptPath) ? (string) filemtime($aiReportScriptPath) : '1';
?>
<script src="<?= e(app_base_path()) ?>/assets/ai-report.js?v=<?= e($aiReportScriptVersion) ?>" defer></script>
<?php include __DIR__ . '/partials/footer.php'; ?>
