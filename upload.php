<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/fineco_importer.php';

$pageTitle = 'Upload snapshot';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? 'import');
        if (!in_array($action, ['import', 'convert'], true)) {
            throw new RuntimeException('Azione non valida.');
        }

        $token = $_POST['csrf_token'] ?? null;
        if (!is_valid_csrf_token(is_string($token) ? $token : null)) {
            throw new RuntimeException('Richiesta non valida (token).');
        }

        if (!isset($_FILES['snapshot_file']) || $_FILES['snapshot_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Seleziona un file .xls o .xlsx valido.');
        }

        $snapshotDate = trim((string) ($_POST['snapshot_date'] ?? ''));
        $snapshotDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $snapshotDate);
        if (!$snapshotDateObj || $snapshotDateObj->format('Y-m-d') !== $snapshotDate) {
            throw new RuntimeException('Data snapshot non valida.');
        }

        $f = $_FILES['snapshot_file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xls', 'xlsx'], true)) {
            throw new RuntimeException('Formato non supportato. Carica un file .xls o .xlsx.');
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
        $generatedBaseName = 'portafoglio-export_'
            . $snapshotDateObj->format('Ymd')
            . '_'
            . $generatedAt->format('Hi');

        if ($action === 'convert') {
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
            $result = import_fineco_spreadsheet($target, $generatedFileName, $snapshotDate);
        } finally {
            @unlink($target);
        }
        set_flash(
            'success',
            'Import completato da ' . $generatedFileName . '. Posizioni caricate: ' . $result['positions']
        );
        redirect('/snapshot.php?id=' . (int) $result['snapshot_id']);
    } catch (Throwable $e) {
        set_flash('error', 'Import fallito: ' . $e->getMessage());
        redirect('/upload.php');
    }
}

include __DIR__ . '/partials/header.php';
?>
<section class="panel narrow">
    <h2>Carica snapshot Fineco</h2>
    <p>Carica direttamente l'export Fineco `.xls` o `.xlsx` (Portafoglio di sintesi). Non serve aprirlo con Excel.</p>
    <p class="tiny-note">La data resta modificabile; il nome con data e ora viene generato automaticamente dall'app.</p>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>
            Data snapshot
            <input type="date" name="snapshot_date" required value="<?= e(date('Y-m-d')) ?>">
        </label>
        <label>
            File Excel
            <input type="file" name="snapshot_file" accept=".xls,.xlsx" required>
        </label>
        <div class="upload-actions">
            <button type="submit" name="action" value="import">Importa snapshot</button>
            <button type="submit" name="action" value="convert" class="btn-secondary">Converti e scarica XLSX</button>
        </div>
    </form>
</section>
<?php include __DIR__ . '/partials/footer.php'; ?>
