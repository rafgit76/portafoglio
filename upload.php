<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/fineco_importer.php';
require_once __DIR__ . '/lib/account_tracker.php';
require_once __DIR__ . '/lib/fineco_patrimony_importer.php';

$pageTitle = 'Upload snapshot';
$pdo = db();
$accountTrackerEnabled = account_tracker_schema_ready($pdo);
$dossiers = $accountTrackerEnabled ? account_tracker_dossiers($pdo) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? 'import');
        if (!in_array($action, ['import', 'convert', 'import_patrimony'], true)) {
            throw new RuntimeException('Azione non valida.');
        }

        $token = $_POST['csrf_token'] ?? null;
        if (!is_valid_csrf_token(is_string($token) ? $token : null)) {
            throw new RuntimeException('Richiesta non valida (token).');
        }

        $fileField = $action === 'import_patrimony' ? 'patrimony_file' : 'snapshot_file';
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
        $generatedBaseName = ($action === 'import_patrimony' ? 'patrimonio-fineco_' : 'portafoglio-export_')
            . $snapshotDateObj->format('Ymd')
            . '_'
            . $generatedAt->format('Hi');

        if ($action === 'convert') {
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
            if ($action === 'import_patrimony') {
                $result = import_fineco_patrimony_spreadsheet($target, $generatedFileName, $snapshotDate);
            } else {
                $dossierCode = $accountTrackerEnabled ? trim((string) ($_POST['dossier_code'] ?? 'ordinario')) : 'ordinario';
                $result = import_fineco_spreadsheet($target, $generatedFileName, $snapshotDate, $dossierCode);
            }
        } finally {
            @unlink($target);
        }
        if ($action === 'import_patrimony') {
            $message = 'Patrimonio Fineco importato da ' . $generatedFileName . '. Dossier rilevati: ' . (int) $result['dossiers_count'] . '. I fidi restano separati dal patrimonio.';
            set_flash('success', $message);
            redirect('/account.php?date=' . rawurlencode((string) $result['snapshot_date']));
        }
        $message = 'Import completato da ' . $generatedFileName . '. Posizioni caricate: ' . $result['positions'];
        if (!empty($result['transfer_candidates'])) { $message .= '. Rilevato un possibile trasferimento interno: verifica la proposta nel modulo Conto.'; }
        set_flash('success', $message);
        redirect('/snapshot.php?id=' . (int) $result['snapshot_id']);
    } catch (Throwable $e) {
        set_flash('error', 'Import fallito: ' . $e->getMessage());
        redirect('/upload.php');
    }
}

include __DIR__ . '/partials/header.php';
?>
<section class="panel narrow">
    <h2>Import portafoglio Fineco</h2>
    <p>Carica direttamente l'export Fineco `.xls`, `.xlsx` o `.csv` (Portafoglio di sintesi). Non serve aprirlo con Excel.</p>
    <p class="tiny-note">La data resta modificabile; il nome con data e ora viene generato automaticamente dall'app.</p>
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
        <?php if ($accountTrackerEnabled): ?>
            <label>
                Dossier di provenienza
                <select name="dossier_code">
                    <?php foreach ($dossiers as $dossier): ?>
                        <option value="<?= e((string) $dossier['code']) ?>" <?= (string) $dossier['code'] === 'ordinario' ? 'selected' : '' ?>>
                            <?= e((string) $dossier['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="tiny-note">Ogni import aggiorna soltanto il dossier selezionato e ricalcola il totale consolidato.</span>
            </label>
        <?php endif; ?>
        <div class="upload-actions">
            <button type="submit" name="action" value="import">Importa snapshot</button>
            <button type="submit" name="action" value="convert" class="btn-secondary">Converti e scarica XLSX</button>
        </div>
    </form>
</section>
<?php if ($accountTrackerEnabled): ?>
<section class="panel narrow">
    <h2>Import patrimonio Fineco</h2>
    <p>Opzionale. Carica l'export Patrimonio Fineco per aggiornare automaticamente patrimonio, saldi, liquidita, fidi e valori dei dossier.</p>
    <p class="tiny-note">Il credito disponibile non viene mai sommato al patrimonio. I dati manuali gia registrati per la stessa data restano come override.</p>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Data snapshot patrimonio<input type="date" name="snapshot_date" required value="<?= e(date('Y-m-d')) ?>"></label>
        <label>File Patrimonio Fineco<input type="file" name="patrimony_file" accept=".xls,.xlsx" required></label>
        <div class="upload-actions"><button type="submit" name="action" value="import_patrimony">Importa patrimonio</button></div>
    </form>
</section>
<?php endif; ?>
<?php include __DIR__ . '/partials/footer.php'; ?>
