<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/earnings.php';

$pdo = db();
if (!earnings_schema_ready($pdo)) {
    set_flash('error', 'Importa prima la migrazione del modulo Trimestrali.');
    redirect('/earnings.php');
}

$eventId = (int) ($_GET['id'] ?? $_POST['event_id'] ?? 0);
$event = $eventId > 0 ? earnings_find_event($pdo, $eventId) : null;
if ($event === null) {
    http_response_code(404);
    $pageTitle = 'Trimestrale non trovata';
    include __DIR__ . '/partials/header.php';
    echo '<section class="panel"><h2>Trimestrale non trovata</h2><p><a class="btn-link" href="' . e(app_base_path()) . '/earnings.php">Torna al calendario</a></p></section>';
    include __DIR__ . '/partials/footer.php';
    exit;
}

$pageTitle = 'Trimestrale ' . (string) $event['asset_name'];
$error = null;
$isAsyncRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_valid_csrf_token(is_string($token) ? $token : null)) {
            throw new RuntimeException('Richiesta non valida (token).');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'update_event') {
            earnings_save_event($pdo, $_POST, $eventId);
            set_flash('success', 'Dati della trimestrale aggiornati.');
            redirect('/earnings_event.php?id=' . $eventId);
        }
        if ($action === 'delete_event') {
            if ((string) ($_POST['confirmed'] ?? '') !== '1') {
                throw new RuntimeException('Conferma l\'eliminazione dell\'evento.');
            }
            earnings_delete_event($pdo, $eventId);
            set_flash('success', 'Trimestrale eliminata dall\'archivio.');
            redirect('/earnings.php');
        }
        if ($action !== 'generate_earnings') {
            throw new RuntimeException('Azione non riconosciuta.');
        }
        if ((string) ($_POST['confirmed'] ?? '') !== '1') {
            throw new RuntimeException('Conferma la generazione prima di avviare Gemini.');
        }

        $generated = earnings_generate_report($pdo, $event);
        earnings_store_report($pdo, $eventId, $generated);
        set_flash('success', 'Report della trimestrale generato e archiviato.');
        $redirectUrl = app_base_path() . '/earnings_event.php?id=' . $eventId . '#report-trimestrale';
        if ($isAsyncRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'redirect' => $redirectUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        redirect('/earnings_event.php?id=' . $eventId . '#report-trimestrale');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($isAsyncRequest) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
    $event = earnings_find_event($pdo, $eventId) ?? $event;
}

$impact = earnings_impact($pdo, $event);
$status = earnings_event_status($event);
$assets = earnings_significant_assets($pdo);
$reportAvailable = trim((string) ($event['report_markdown'] ?? '')) !== '';
$formatMoney = static fn(?float $value, bool $sign = false): string => $value === null
    ? '-'
    : 'EUR ' . earnings_format_number($value, 2, $sign);
$formatPct = static fn(?float $value, int $decimals = 2): string => $value === null
    ? '-'
    : earnings_format_number($value, $decimals, true) . '%';

include __DIR__ . '/partials/header.php';
?>

<?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

<section class="panel earnings-event-head">
    <div class="panel-head">
        <div>
            <p class="earnings-kicker"><?= e((string) (($event['ticker'] ?? '') ?: 'Titolo azionario')) ?> · <?= e((string) $event['fiscal_period']) ?></p>
            <h2><?= e((string) $event['asset_name']) ?></h2>
            <p><?= e(ai_report_format_date_it((string) $event['report_date'])) ?> · <?= e(earnings_timing_label((string) $event['release_timing'])) ?></p>
        </div>
        <div class="earnings-head-actions">
            <span class="earnings-badge is-<?= strtolower($status) ?>"><?= e(earnings_status_label($status)) ?></span>
            <a class="btn-link" href="<?= e(app_base_path()) ?>/earnings.php">Calendario</a>
        </div>
    </div>
</section>

<section class="panel">
    <div class="panel-head"><div><h2>Impatto sul portafoglio</h2><p>Finestra determinata dall'orario della pubblicazione e dagli snapshot Fineco disponibili.</p></div></div>
    <?php if (!$impact['available']): ?>
        <div class="earnings-impact-pending">
            <strong>Impatto non ancora calcolabile</strong>
            <p>
                <?php if (($impact['window_type'] ?? '') === 'AFTER_CLOSE'): ?>
                    <?php if ($impact['pre'] === null): ?>Serve il primo snapshot del mattino successivo, che rappresenta la chiusura precedente alla pubblicazione.<?php endif; ?>
                    <?php if ($impact['pre'] !== null && $impact['post'] === null): ?>Lo snapshot del <?= e(ai_report_format_date_it((string) $impact['pre']['snapshot_date'])) ?> e il riferimento prima dell'annuncio. Il calcolo comparira con lo snapshot successivo alla prima seduta completa di reazione.<?php endif; ?>
                <?php else: ?>
                    <?php if ($impact['pre'] === null): ?>Manca uno snapshot precedente all'evento.<?php endif; ?>
                    <?php if ($impact['post'] === null): ?>Manca uno snapshot successivo all'evento.<?php endif; ?>
                    Il calcolo comparira automaticamente dopo il prossimo import utile.
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="earnings-impact-grid">
            <div><span>Impatto stimato</span><strong class="<?= (float) $impact['impact_eur'] >= 0 ? 'pos' : 'neg' ?>"><?= e($formatMoney((float) $impact['impact_eur'], true)) ?></strong></div>
            <div><span>Rendimento finestra</span><strong class="<?= (float) $impact['asset_return_pct'] >= 0 ? 'pos' : 'neg' ?>"><?= e($formatPct((float) $impact['asset_return_pct'])) ?></strong></div>
            <div><span>Contributo portafoglio</span><strong class="<?= (float) $impact['portfolio_contribution_pct'] >= 0 ? 'pos' : 'neg' ?>"><?= e($formatPct((float) $impact['portfolio_contribution_pct'], 3)) ?></strong></div>
            <div><span>Delta peso</span><strong class="<?= (float) $impact['weight_delta_pct'] >= 0 ? 'pos' : 'neg' ?>"><?= e($formatPct((float) $impact['weight_delta_pct'])) ?></strong></div>
        </div>
        <div class="earnings-window">
            <div><span>Prima</span><strong><?= e(ai_report_format_date_it((string) $impact['pre']['snapshot_date'])) ?></strong><small><?= e($formatMoney((float) $impact['pre']['market_value'])) ?> · peso <?= e(earnings_format_number((float) $impact['pre']['weight_pct'] * 100)) ?>%</small></div>
            <div class="earnings-window-arrow">→</div>
            <div><span>Dopo</span><strong><?= e(ai_report_format_date_it((string) $impact['post']['snapshot_date'])) ?></strong><small><?= e($formatMoney((float) $impact['post']['market_value'])) ?> · peso <?= e(earnings_format_number((float) $impact['post']['weight_pct'] * 100)) ?>%</small></div>
        </div>
        <?php if (abs((float) $impact['quantity_delta']) > 0.000001): ?>
            <p class="tiny-note">La quantita e cambiata di <?= e(earnings_format_number((float) $impact['quantity_delta'], 4, true)) ?> unita nella finestra. L'impatto usa la quantita precedente per isolare la variazione unitaria, cambio incluso.</p>
        <?php else: ?>
            <p class="tiny-note"><?= e((string) $impact['method']) ?></p>
        <?php endif; ?>
        <?php if (($impact['window_type'] ?? '') === 'AFTER_CLOSE'): ?><p class="tiny-note">Per una pubblicazione dopo la chiusura, il primo snapshot del mattino successivo e il riferimento pre-evento; quello seguente include la prima seduta completa di reazione.</p><?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel" id="report-trimestrale">
    <div class="panel-head">
        <div>
            <h2>Report Gemini</h2>
            <p>Analisi basata esclusivamente sulla fonte ufficiale e sui dati di impatto verificati dall'app.</p>
        </div>
        <?php if ($event['source_url']): ?><a class="btn-link" href="<?= e((string) $event['source_url']) ?>" target="_blank" rel="noopener noreferrer">Apri fonte</a><?php endif; ?>
    </div>

    <?php if ($reportAvailable): ?>
        <div class="ai-report-panel earnings-report-body"><?= ai_report_render_markdown((string) $event['report_markdown']) ?></div>
        <div class="earnings-ai-meta">
            <span><?= e((string) $event['ai_model']) ?></span>
            <span><?= number_format((int) $event['ai_total_tokens'], 0, ',', '.') ?> token</span>
            <span><?= $event['ai_generated_at'] ? e(ai_report_format_date_it(substr((string) $event['ai_generated_at'], 0, 10))) : '-' ?></span>
            <?php if ($event['ai_estimated_cost_usd'] !== null): ?><span>USD <?= e(number_format((float) $event['ai_estimated_cost_usd'], 4, ',', '.')) ?></span><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="earnings-empty">Nessun report archiviato per questa trimestrale.</div>
    <?php endif; ?>

    <details class="ai-inline-confirm earnings-generate-confirm">
        <summary><?= $reportAvailable ? 'Rigenera report' : 'Genera report' ?></summary>
        <div class="ai-inline-confirm-body">
            <?php if (!$event['source_url']): ?>
                <p>Aggiungi prima l'URL ufficiale nella sezione Modifica evento.</p>
            <?php else: ?>
                <p>Gemini recuperera la pagina ufficiale indicata e consumera token API. Un report esistente verra sostituito.</p>
                <form method="post" id="earnings-report-form" class="earnings-ai-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <input type="hidden" name="action" value="generate_earnings">
                    <label><input type="checkbox" name="confirmed" value="1" required> Confermo la generazione del report Gemini.</label>
                    <button type="submit">Avvia analisi</button>
                </form>
            <?php endif; ?>
        </div>
    </details>
</section>

<section class="panel">
    <details class="earnings-edit-details">
        <summary>Modifica evento e fonte</summary>
        <form method="post" class="form-grid earnings-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <input type="hidden" name="action" value="update_event">
            <label>Titolo
                <select name="asset_id" required>
                    <?php foreach ($assets as $asset): ?><option value="<?= (int) $asset['id'] ?>" <?= (int) $event['asset_id'] === (int) $asset['id'] ? 'selected' : '' ?>><?= e((string) $asset['name']) ?><?= $asset['ticker'] ? ' (' . e((string) $asset['ticker']) . ')' : '' ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Periodo fiscale<input type="text" name="fiscal_period" maxlength="64" required value="<?= e((string) $event['fiscal_period']) ?>"></label>
            <label>Data pubblicazione<input type="date" name="report_date" required value="<?= e((string) $event['report_date']) ?>"></label>
            <label>Orario
                <select name="release_timing">
                    <?php foreach (['AMC', 'BMO', 'DURING', 'UNKNOWN'] as $value): ?><option value="<?= $value ?>" <?= $event['release_timing'] === $value ? 'selected' : '' ?>><?= e(earnings_timing_label($value)) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Affidabilita data
                <select name="date_confidence"><option value="CONFIRMED" <?= $event['date_confidence'] === 'CONFIRMED' ? 'selected' : '' ?>>Confermata</option><option value="ESTIMATED" <?= $event['date_confidence'] === 'ESTIMATED' ? 'selected' : '' ?>>Stimata</option></select>
            </label>
            <label class="earnings-form-wide">Fonte ufficiale HTTPS<input type="url" name="source_url" maxlength="1000" value="<?= e((string) ($event['source_url'] ?? '')) ?>"></label>
            <label class="earnings-form-wide">Note<textarea name="notes" maxlength="2000" rows="3"><?= e((string) ($event['notes'] ?? '')) ?></textarea></label>
            <div class="earnings-form-wide"><button type="submit">Salva modifiche</button></div>
        </form>
    </details>
</section>

<section class="panel earnings-danger-zone">
    <details>
        <summary>Elimina trimestrale</summary>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <input type="hidden" name="action" value="delete_event">
            <label><input type="checkbox" name="confirmed" value="1" required> Elimina evento e report archiviato.</label>
            <button type="submit" class="btn-danger">Elimina definitivamente</button>
        </form>
    </details>
</section>

<div id="ai-generation-overlay" class="ai-generation-overlay" hidden>
    <div class="ai-generation-dialog" role="dialog" aria-modal="true" aria-labelledby="ai-generation-title">
        <div id="ai-generation-indicator" class="ai-generation-indicator" aria-hidden="true"></div>
        <h2 id="ai-generation-title">Analisi della trimestrale</h2>
        <p id="ai-generation-message">Gemini sta recuperando e analizzando la fonte ufficiale.</p>
        <div id="ai-generation-progress" class="ai-generation-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="8"><span id="ai-generation-progress-bar"></span></div>
        <div class="ai-generation-progress-meta"><span id="ai-generation-phase">Preparazione fonte</span><strong id="ai-generation-percent">8%</strong></div>
        <p class="ai-generation-note">Non chiudere la pagina durante l'elaborazione.</p>
        <button type="button" id="ai-generation-close" hidden>Chiudi</button>
    </div>
</div>
<script src="<?= e(app_base_path()) ?>/assets/ai-report.js?v=<?= e((string) filemtime(__DIR__ . '/assets/ai-report.js')) ?>"></script>

<?php include __DIR__ . '/partials/footer.php'; ?>
