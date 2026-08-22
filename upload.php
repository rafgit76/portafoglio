<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/fineco_importer.php';
require_once __DIR__ . '/lib/account_tracker.php';
require_once __DIR__ . '/lib/fineco_patrimony_importer.php';

$pageTitle = 'Upload snapshot';
$pdo = db();
$accountTrackerEnabled = account_tracker_schema_ready($pdo);

function upload_pending_import_path(string $fileName): string
{
    if ($fileName === '' || basename($fileName) !== $fileName) {
        throw new RuntimeException('Riferimento del file in attesa non valido.');
    }

    return __DIR__ . '/storage/uploads/pending_imports/' . $fileName;
}

function upload_clear_pending_import(): void
{
    $pending = $_SESSION['portfolio_import_preview'] ?? null;
    if (is_array($pending) && isset($pending['file_name']) && is_string($pending['file_name'])) {
        $path = upload_pending_import_path($pending['file_name']);
        if (is_file($path)) {
            @unlink($path);
        }
    }
    unset($_SESSION['portfolio_import_preview']);
}

$pendingImportPreview = null;
if (isset($_GET['confirm_import'])) {
    $candidate = $_SESSION['portfolio_import_preview'] ?? null;
    if (is_array($candidate) && hash_equals((string) ($candidate['token'] ?? ''), (string) $_GET['confirm_import']) && (int) ($candidate['expires_at'] ?? 0) >= time()) {
        $pendingImportPreview = $candidate;
    } elseif (is_array($candidate) && (int) ($candidate['expires_at'] ?? 0) < time()) {
        upload_clear_pending_import();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? 'import_main');
        $portfolioPreviewActions = [
            'preview_main' => 'main',
            'import_main' => 'main',
            'preview_credit_lombard' => 'credit_lombard',
            'import_credit_lombard' => 'credit_lombard',
            'import' => 'main',
        ];
        $portfolioConvertActions = [
            'convert_main' => 'main',
            'convert_credit_lombard' => 'credit_lombard',
            'convert' => 'main',
        ];
        if (!isset($portfolioPreviewActions[$action]) && !isset($portfolioConvertActions[$action]) && !in_array($action, ['import_patrimony', 'confirm_portfolio_import', 'cancel_portfolio_import'], true)) {
            throw new RuntimeException('Azione non valida.');
        }

        $token = $_POST['csrf_token'] ?? null;
        if (!is_valid_csrf_token(is_string($token) ? $token : null)) {
            throw new RuntimeException('Richiesta non valida (token).');
        }

        if ($action === 'cancel_portfolio_import') {
            $pending = $_SESSION['portfolio_import_preview'] ?? null;
            if (!is_array($pending) || !hash_equals((string) ($pending['token'] ?? ''), (string) ($_POST['preview_token'] ?? ''))) {
                throw new RuntimeException('Anteprima import non disponibile o scaduta.');
            }
            upload_clear_pending_import();
            set_flash('success', 'Anteprima import annullata. Nessuno snapshot e stato modificato.');
            redirect('/upload.php');
        }

        if ($action === 'confirm_portfolio_import') {
            $pending = $_SESSION['portfolio_import_preview'] ?? null;
            if (!is_array($pending) || !hash_equals((string) ($pending['token'] ?? ''), (string) ($_POST['preview_token'] ?? '')) || (int) ($pending['expires_at'] ?? 0) < time()) {
                upload_clear_pending_import();
                throw new RuntimeException('Anteprima import scaduta. Carica nuovamente il file.');
            }
            if (!empty($pending['requires_manual_confirmation']) && ($_POST['confirm_unverified_dossier'] ?? '') !== '1') {
                set_flash('error', 'Conferma esplicitamente lo slot del dossier prima di importare un file senza identificativo affidabile.');
                redirect('/upload.php?confirm_import=' . rawurlencode((string) $pending['token']));
            }

            $portfolioSlot = (string) $pending['slot'];
            $slotDefinition = fineco_portfolio_import_slot($portfolioSlot);
            if ($portfolioSlot === 'credit_lombard' && !$accountTrackerEnabled) {
                throw new RuntimeException('Il modulo Conto deve essere inizializzato prima di importare il Dossier Credit Lombard.');
            }
            $target = upload_pending_import_path((string) $pending['file_name']);
            if (!is_file($target)) {
                upload_clear_pending_import();
                throw new RuntimeException('Il file dell anteprima non e piu disponibile. Caricalo nuovamente.');
            }

            try {
                $result = import_fineco_spreadsheet(
                    $target,
                    (string) $pending['generated_file_name'],
                    (string) $pending['snapshot_date'],
                    (string) $slotDefinition['dossier_code']
                );
            } finally {
                upload_clear_pending_import();
            }

            $message = 'Import completato per ' . (string) $slotDefinition['label'] . '. Posizioni caricate: ' . $result['positions'];
            if (!empty($result['transfer_candidates'])) {
                $message .= '. Rilevato un possibile trasferimento interno: verifica la proposta nel modulo Conto.';
            }
            set_flash('success', $message);
            redirect('/snapshot.php?id=' . (int) $result['snapshot_id']);
        }

        $portfolioSlot = $portfolioPreviewActions[$action] ?? ($portfolioConvertActions[$action] ?? null);
        $fileField = match ($action) {
            'import_patrimony' => 'patrimony_file',
            'preview_credit_lombard', 'import_credit_lombard', 'convert_credit_lombard' => 'credit_lombard_file',
            default => 'snapshot_file',
        };
        if (!isset($_FILES[$fileField]) || $_FILES[$fileField]['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Seleziona un file .xls, .xlsx o .csv valido.');
        }

        $snapshotDate = trim((string) ($_POST['snapshot_date'] ?? ''));
        $snapshotDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $snapshotDate);
        if (!$snapshotDateObj || $snapshotDateObj->format('Y-m-d') !== $snapshotDate) {
            throw new RuntimeException('Data snapshot non valida.');
        }

        $f = $_FILES[$fileField];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xls', 'xlsx', 'csv'], true)) {
            throw new RuntimeException('Formato non supportato. Carica un file .xls, .xlsx o .csv.');
        }

        if (!isset($f['tmp_name']) || !is_string($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
            throw new RuntimeException('Upload non valido.');
        }

        $fileSize = isset($f['size']) ? (int) $f['size'] : 0;
        if ($fileSize <= 0 || $fileSize > app_upload_max_bytes()) {
            throw new RuntimeException('File troppo grande o vuoto. Verifica i limiti upload.');
        }

        $detectedExtension = uploaded_spreadsheet_type($f['tmp_name'], (string) $f['name']);
        if ($detectedExtension === null) {
            throw new RuntimeException('Il file non sembra un export Excel Fineco valido.');
        }

        $generatedAt = new DateTimeImmutable('now');
        $slotDefinition = $portfolioSlot !== null ? fineco_portfolio_import_slot($portfolioSlot) : null;
        $generatedBaseName = ($action === 'import_patrimony'
            ? 'patrimonio-fineco_'
            : (string) ($slotDefinition['file_prefix'] ?? 'portafoglio-principale_'))
            . $snapshotDateObj->format('Ymd')
            . '_'
            . $generatedAt->format('Hi');

        if (isset($portfolioConvertActions[$action])) {
            if ($detectedExtension === 'csv') {
                throw new RuntimeException('La conversione in XLSX e disponibile per file .xls o .xlsx. Il CSV puo essere importato direttamente.');
            }
            $downloadName = $generatedBaseName . '.xlsx';
            $temporaryOutput = tempnam(sys_get_temp_dir(), 'portfolio_xlsx_');
            if (!is_string($temporaryOutput) || $temporaryOutput === '') {
                throw new RuntimeException('Impossibile preparare il file convertito.');
            }

            try {
                (new FinecoXlsxParser())->parse($f['tmp_name']);
                convert_fineco_spreadsheet_to_xlsx($f['tmp_name'], $temporaryOutput);

                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $downloadName . '"');
                header('Content-Length: ' . filesize($temporaryOutput));
                header('Cache-Control: max-age=0');
                readfile($temporaryOutput);
            } finally {
                @unlink($temporaryOutput);
            }
            exit;
        }

        if (isset($portfolioPreviewActions[$action])) {
            if ($portfolioSlot === 'credit_lombard' && !$accountTrackerEnabled) {
                throw new RuntimeException('Il modulo Conto deve essere inizializzato prima di importare il Dossier Credit Lombard.');
            }

            $parser = new FinecoXlsxParser();
            $positions = $parser->parse($f['tmp_name']);
            $inspection = $parser->inspectDossierMetadata($f['tmp_name']);
            if (!empty($inspection['reliable']) && (string) $inspection['detected_slot'] !== $portfolioSlot) {
                $suggestedSlot = fineco_portfolio_import_slot((string) $inspection['detected_slot']);
                throw new RuntimeException('Il file caricato sembra appartenere a ' . $suggestedSlot['label'] . ' e non a ' . $slotDefinition['label'] . '. Usa lo slot corretto.');
            }

            $summary = ['positions' => count($positions), 'cost_basis' => 0.0, 'market_value' => 0.0, 'accrued_interest' => 0.0];
            foreach ($positions as $position) {
                if (!defined(FinecoXlsxParser::class . '::SUPPORTS_ACCRUED_INTEREST')) {
                    throw new RuntimeException('Parser Fineco non aggiornato: ricarica lib/fineco_xlsx_parser.php e riprova.');
                }
                $summary['cost_basis'] += $position->costBasis;
                $summary['market_value'] += $position->marketValue;
                $summary['accrued_interest'] += $position->accruedInterest;
            }

            upload_clear_pending_import();
            $pendingDir = __DIR__ . '/storage/uploads/pending_imports';
            ensure_directory($pendingDir);
            $pendingFileName = bin2hex(random_bytes(18)) . '.' . $detectedExtension;
            if (!move_uploaded_file($f['tmp_name'], upload_pending_import_path($pendingFileName))) {
                throw new RuntimeException('Impossibile preparare l anteprima sicura dell import.');
            }

            $previewToken = bin2hex(random_bytes(24));
            $_SESSION['portfolio_import_preview'] = [
                'token' => $previewToken,
                'expires_at' => time() + 900,
                'file_name' => $pendingFileName,
                'generated_file_name' => $generatedBaseName . '.' . $detectedExtension,
                'snapshot_date' => $snapshotDate,
                'slot' => $portfolioSlot,
                'slot_label' => (string) $slotDefinition['label'],
                'inspection' => $inspection,
                'summary' => $summary,
                'requires_manual_confirmation' => empty($inspection['reliable']),
            ];
            redirect('/upload.php?confirm_import=' . rawurlencode($previewToken));
        }

        $uploadDir = __DIR__ . '/storage/uploads';
        ensure_directory($uploadDir);

        $generatedFileName = $generatedBaseName . '.' . $detectedExtension;
        $safeName = $generatedBaseName . '_' . bin2hex(random_bytes(6)) . '.' . $detectedExtension;
        $target = $uploadDir . '/' . $safeName;
        if (!move_uploaded_file($f['tmp_name'], $target)) {
            throw new RuntimeException('Errore nel salvataggio del file caricato.');
        }

        @chmod($target, 0664);

        try {
            $result = import_fineco_patrimony_spreadsheet($target, $generatedFileName, $snapshotDate);
        } finally {
            @unlink($target);
        }
        if ($action === 'import_patrimony') {
            $message = 'Patrimonio Fineco importato da ' . $generatedFileName . '. Dossier rilevati: ' . (int) $result['dossiers_count'] . '. I fidi restano separati dal patrimonio.';
            set_flash('success', $message);
            redirect('/account.php?date=' . rawurlencode((string) $result['snapshot_date']));
        }
    } catch (Throwable $e) {
        set_flash('error', 'Import fallito: ' . $e->getMessage());
        redirect('/upload.php');
    }
}

include __DIR__ . '/partials/header.php';
?>
<?php if ($pendingImportPreview !== null): ?>
    <?php $inspection = (array) ($pendingImportPreview['inspection'] ?? []); $summary = (array) ($pendingImportPreview['summary'] ?? []); ?>
    <section class="panel narrow upload-confirmation">
        <p class="eyebrow">Verifica prima dell'import</p>
        <h2>Riepilogo dossier</h2>
        <div class="stat-grid compact-stats">
            <article class="stat-card"><span>Dossier selezionato</span><strong><?= e((string) $pendingImportPreview['slot_label']) ?></strong></article>
            <article class="stat-card"><span>Dossier rilevato</span><strong><?= e((string) ($inspection['label'] ?? 'Non rilevato')) ?></strong></article>
            <article class="stat-card"><span>Numero posizioni</span><strong><?= e((string) ($summary['positions'] ?? 0)) ?></strong></article>
            <article class="stat-card"><span>Valore di carico</span><strong>EUR <?= number_format((float) ($summary['cost_basis'] ?? 0), 2, ',', '.') ?></strong></article>
            <article class="stat-card"><span>Valore di mercato</span><strong>EUR <?= number_format((float) ($summary['market_value'] ?? 0), 2, ',', '.') ?></strong></article>
            <?php if (abs((float) ($summary['accrued_interest'] ?? 0)) >= 0.005): ?><article class="stat-card"><span>Rateo</span><strong>EUR <?= number_format((float) $summary['accrued_interest'], 2, ',', '.') ?></strong></article><?php endif; ?>
        </div>
        <?php if (!empty($inspection['evidence'])): ?>
            <p class="tiny-note">Identificativo rilevato: <?= e(implode(' | ', (array) $inspection['evidence'])) ?></p>
        <?php endif; ?>
        <?php if (empty($inspection['reliable'])): ?>
            <p class="warning-note">Il file non contiene un identificativo affidabile del dossier. Il filename non viene usato: conferma manualmente che lo slot selezionato e corretto.</p>
        <?php endif; ?>
        <form method="post" class="inline-confirm-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="preview_token" value="<?= e((string) $pendingImportPreview['token']) ?>">
            <?php if (empty($inspection['reliable'])): ?>
                <label class="confirm-check"><input type="checkbox" name="confirm_unverified_dossier" value="1" required> Confermo che il file appartiene allo slot selezionato.</label>
            <?php endif; ?>
            <div class="upload-actions">
                <button type="submit" name="action" value="confirm_portfolio_import">Conferma e importa</button>
                <button type="submit" name="action" value="cancel_portfolio_import" class="btn-secondary">Annulla</button>
            </div>
        </form>
    </section>
<?php endif; ?>
<section class="panel narrow">
    <h2>Portafoglio principale</h2>
    <p>Obbligatorio. Carica l'export Fineco `.xls`, `.xlsx` o `.csv` del dossier principale. Non serve aprirlo con Excel.</p>
    <p class="tiny-note">Il dossier e determinato da questo slot, non dal nome del file. La data resta modificabile.</p>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>
            Data snapshot
            <input type="date" name="snapshot_date" required value="<?= e(date('Y-m-d')) ?>">
        </label>
        <label>
            File Excel
            <input type="file" name="snapshot_file" accept=".xls,.xlsx,.csv" required>
        </label>
        <div class="upload-actions">
            <button type="submit" name="action" value="preview_main">Verifica portafoglio principale</button>
            <button type="submit" name="action" value="convert_main" class="btn-secondary">Converti e scarica XLSX</button>
        </div>
    </form>
</section>
<?php if ($accountTrackerEnabled): ?>
<section class="panel narrow">
    <h2>Dossier Credit Lombard</h2>
    <p>Opzionale. Carica qui l'export dei titoli vincolati a garanzia. Può avere lo stesso filename del portafoglio principale.</p>
    <p class="tiny-note">Questo slot aggiorna solo il dossier Credit Lombard e ricalcola automaticamente i valori consolidati dello stesso snapshot.</p>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>
            Data snapshot
            <input type="date" name="snapshot_date" required value="<?= e(date('Y-m-d')) ?>">
        </label>
        <label>
            File Excel Dossier Credit Lombard
            <input type="file" name="credit_lombard_file" accept=".xls,.xlsx,.csv" required>
        </label>
        <div class="upload-actions">
            <button type="submit" name="action" value="preview_credit_lombard">Verifica Dossier Credit Lombard</button>
            <button type="submit" name="action" value="convert_credit_lombard" class="btn-secondary">Converti e scarica XLSX</button>
        </div>
    </form>
</section>
<section class="panel narrow">
    <h2>Import patrimonio Fineco</h2>
    <p>Opzionale. Carica l'export Patrimonio Fineco per aggiornare automaticamente patrimonio, saldi, liquidita, fidi e valori dei dossier.</p>
    <p class="tiny-note">Fineco puo includere il credito disponibile nei propri totali: l'app lo conserva separato e calcola le metriche rettificate. I dati manuali gia registrati per la stessa data restano come override.</p>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Data snapshot patrimonio<input type="date" name="snapshot_date" required value="<?= e(date('Y-m-d')) ?>"></label>
        <label>File Patrimonio Fineco<input type="file" name="patrimony_file" accept=".xls,.xlsx" required></label>
        <div class="upload-actions"><button type="submit" name="action" value="import_patrimony">Importa patrimonio</button></div>
    </form>
</section>
<?php endif; ?>
<?php include __DIR__ . '/partials/footer.php'; ?>
