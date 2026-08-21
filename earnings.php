<?php
require_once __DIR__ . '/bootstrap.php';

set_flash('info', 'Il modulo Trimestrali e temporaneamente ritirato dall\'app in attesa di una riprogettazione. I dati esistenti non sono stati eliminati.');
redirect('/ai_report.php');

require_once __DIR__ . '/lib/earnings.php';

$pageTitle = 'Trimestrali';
$pdo = db();
$schemaReady = earnings_schema_ready($pdo);
$error = null;

if ($schemaReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_valid_csrf_token(is_string($token) ? $token : null)) {
            throw new RuntimeException('Richiesta non valida (token).');
        }
        if ((string) ($_POST['action'] ?? '') !== 'save_event') {
            throw new RuntimeException('Azione non riconosciuta.');
        }
        $eventId = earnings_save_event($pdo, $_POST);
        set_flash('success', 'Trimestrale aggiunta al calendario.');
        redirect('/earnings_event.php?id=' . $eventId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$events = $schemaReady ? earnings_list_events($pdo) : [];
$assets = $schemaReady ? earnings_significant_assets($pdo) : [];
$today = new DateTimeImmutable('today');
$upcoming = [];
$archive = [];
$analyzedCount = 0;
foreach ($events as $event) {
    $status = earnings_event_status($event, $today);
    $event['computed_status'] = $status;
    if ($status === 'SCHEDULED') {
        $upcoming[] = $event;
    } else {
        $archive[] = $event;
    }
    if ($status === 'ANALYZED') {
        $analyzedCount++;
    }
}
usort($upcoming, static fn(array $a, array $b): int => strcmp((string) $a['report_date'], (string) $b['report_date']));

$selectedAssetId = (int) ($_GET['asset_id'] ?? $_POST['asset_id'] ?? 0);
$formatMoney = static fn(float $value): string => 'EUR ' . number_format($value, 2, ',', '.');
$formatWeight = static fn(float $value): string => number_format($value * 100, 2, ',', '.') . '%';

include __DIR__ . '/partials/header.php';
?>

<section class="panel earnings-hero">
    <div class="panel-head">
        <div>
            <p class="earnings-kicker">Calendario societario</p>
            <h2>Trimestrali</h2>
            <p>Fonti ufficiali, archivio Gemini e impatto misurato sugli snapshot del portafoglio.</p>
        </div>
        <a class="btn-link" href="#nuova-trimestrale">Aggiungi evento</a>
    </div>
    <div class="earnings-stat-grid">
        <div><span>Titoli monitorabili</span><strong><?= count($assets) ?></strong></div>
        <div><span>Prossimi eventi</span><strong><?= count($upcoming) ?></strong></div>
        <div><span>Archivio</span><strong><?= count($archive) ?></strong></div>
        <div><span>Report Gemini</span><strong><?= $analyzedCount ?></strong></div>
    </div>
</section>

<?php if (!$schemaReady): ?>
    <section class="panel ai-empty-state">
        <h2>Modulo da inizializzare</h2>
        <p>Importa una sola volta <code>sql/migration_1_9_0_earnings.sql</code> nel database Aruba, quindi ricarica questa pagina.</p>
    </section>
    <?php include __DIR__ . '/partials/footer.php'; exit; ?>
<?php endif; ?>

<?php if ($error): ?>
    <div class="flash flash-error"><?= e($error) ?></div>
<?php endif; ?>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Prossime pubblicazioni</h2>
            <p>Le date stimate restano separate da quelle confermate dalla societa.</p>
        </div>
    </div>
    <?php if ($upcoming === []): ?>
        <div class="earnings-empty">Nessuna trimestrale futura in calendario.</div>
    <?php else: ?>
        <div class="table-wrap earnings-table-wrap">
            <table>
                <thead><tr><th>Data</th><th>Titolo</th><th>Periodo</th><th>Orario</th><th>Affidabilita</th><th>Peso</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($upcoming as $event): ?>
                    <tr>
                        <td><strong><?= e(ai_report_format_date_it((string) $event['report_date'])) ?></strong></td>
                        <td><?= e((string) $event['asset_name']) ?><?= $event['ticker'] ? ' · ' . e((string) $event['ticker']) : '' ?></td>
                        <td><?= e((string) $event['fiscal_period']) ?></td>
                        <td><?= e(earnings_timing_label((string) $event['release_timing'])) ?></td>
                        <td><span class="earnings-badge <?= $event['date_confidence'] === 'CONFIRMED' ? 'is-confirmed' : 'is-estimated' ?>"><?= e(earnings_confidence_label((string) $event['date_confidence'])) ?></span></td>
                        <td><?= $event['latest_weight_pct'] !== null ? e($formatWeight((float) $event['latest_weight_pct'])) : '-' ?></td>
                        <td><a class="btn-link" href="<?= e(app_base_path()) ?>/earnings_event.php?id=<?= (int) $event['id'] ?>">Apri</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Esposizioni azionarie</h2>
            <p>Ordinate per peso nell'ultimo snapshot disponibile.</p>
        </div>
    </div>
    <?php if ($assets === []): ?>
        <div class="earnings-empty">Nessun titolo di tipo AZIONE presente nell'anagrafica.</div>
    <?php else: ?>
        <div class="earnings-holdings-grid">
            <?php foreach ($assets as $asset): ?>
                <article class="earnings-holding-card">
                    <div>
                        <strong><?= e((string) $asset['name']) ?></strong>
                        <span><?= e((string) (($asset['ticker'] ?? '') ?: 'Ticker non indicato')) ?></span>
                    </div>
                    <div class="earnings-holding-values">
                        <strong><?= $asset['weight_pct'] !== null ? e($formatWeight((float) $asset['weight_pct'])) : '-' ?></strong>
                        <span><?= $asset['market_value'] !== null ? e($formatMoney((float) $asset['market_value'])) : 'Nessuna posizione recente' ?></span>
                    </div>
                    <a href="<?= e(app_base_path()) ?>/earnings.php?asset_id=<?= (int) $asset['id'] ?>#nuova-trimestrale">Pianifica</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="panel" id="archivio-trimestrali">
    <div class="panel-head">
        <div>
            <h2>Archivio</h2>
            <p>Eventi trascorsi, con stato del report e fonte conservata.</p>
        </div>
    </div>
    <?php if ($archive === []): ?>
        <div class="earnings-empty">L'archivio si popolera dopo la prima trimestrale.</div>
    <?php else: ?>
        <div class="table-wrap earnings-table-wrap">
            <table>
                <thead><tr><th>Data</th><th>Titolo</th><th>Periodo</th><th>Stato</th><th>Impatto</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($archive as $event): $impact = earnings_impact($pdo, $event); ?>
                    <tr>
                        <td><?= e(ai_report_format_date_it((string) $event['report_date'])) ?></td>
                        <td><strong><?= e((string) $event['asset_name']) ?></strong><?= $event['ticker'] ? ' · ' . e((string) $event['ticker']) : '' ?></td>
                        <td><?= e((string) $event['fiscal_period']) ?></td>
                        <td><span class="earnings-badge is-<?= strtolower((string) $event['computed_status']) ?>"><?= e(earnings_status_label((string) $event['computed_status'])) ?></span></td>
                        <td class="<?= $impact['available'] && (float) $impact['impact_eur'] >= 0 ? 'pos' : ($impact['available'] ? 'neg' : '') ?>">
                            <?= $impact['available'] ? e('EUR ' . earnings_format_number((float) $impact['impact_eur'], 2, true)) : 'In attesa degli snapshot' ?>
                        </td>
                        <td><a class="btn-link" href="<?= e(app_base_path()) ?>/earnings_event.php?id=<?= (int) $event['id'] ?>">Apri</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel" id="nuova-trimestrale">
    <div class="panel-head">
        <div>
            <h2>Nuova trimestrale</h2>
            <p>La fonte puo essere aggiunta anche in seguito, ma e obbligatoria per il report Gemini.</p>
        </div>
    </div>
    <form method="post" class="form-grid earnings-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_event">
        <label>Titolo
            <select name="asset_id" required>
                <option value="">Seleziona</option>
                <?php foreach ($assets as $asset): ?>
                    <option value="<?= (int) $asset['id'] ?>" <?= $selectedAssetId === (int) $asset['id'] ? 'selected' : '' ?>><?= e((string) $asset['name']) ?><?= $asset['ticker'] ? ' (' . e((string) $asset['ticker']) . ')' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Periodo fiscale
            <input type="text" name="fiscal_period" maxlength="64" required placeholder="Es. FY2026 Q4" value="<?= e((string) ($_POST['fiscal_period'] ?? '')) ?>">
        </label>
        <label>Data pubblicazione
            <input type="date" name="report_date" required value="<?= e((string) ($_POST['report_date'] ?? '')) ?>">
        </label>
        <label>Orario
            <select name="release_timing">
                <option value="AMC">Dopo la chiusura</option>
                <option value="BMO">Prima dell'apertura</option>
                <option value="DURING">Durante la seduta</option>
                <option value="UNKNOWN">Non indicato</option>
            </select>
        </label>
        <label>Affidabilita data
            <select name="date_confidence">
                <option value="CONFIRMED">Confermata</option>
                <option value="ESTIMATED">Stimata</option>
            </select>
        </label>
        <label class="earnings-form-wide">Fonte ufficiale HTTPS
            <input type="url" name="source_url" maxlength="1000" placeholder="https://investor..." value="<?= e((string) ($_POST['source_url'] ?? '')) ?>">
        </label>
        <label class="earnings-form-wide">Note
            <textarea name="notes" maxlength="2000" rows="3" placeholder="Aspetti da seguire, guidance o contesto personale."><?= e((string) ($_POST['notes'] ?? '')) ?></textarea>
        </label>
        <div class="earnings-form-wide"><button type="submit">Salva nel calendario</button></div>
    </form>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>
