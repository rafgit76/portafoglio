<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/fineco_importer.php';

$pageTitle = 'Upload snapshot';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_valid_csrf_token(is_string($token) ? $token : null)) {
            throw new RuntimeException('Richiesta non valida (token).');
        }

        if (!isset($_FILES['snapshot_file']) || $_FILES['snapshot_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Seleziona un file .xlsx valido.');
        }

        $snapshotDate = trim((string) ($_POST['snapshot_date'] ?? ''));
        $snapshotDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $snapshotDate);
        if (!$snapshotDateObj || $snapshotDateObj->format('Y-m-d') !== $snapshotDate) {
            throw new RuntimeException('Data snapshot non valida.');
        }

        $f = $_FILES['snapshot_file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            throw new RuntimeException('Formato non supportato. Carica un file .xlsx.');
        }

        if (!isset($f['tmp_name']) || !is_string($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
            throw new RuntimeException('Upload non valido.');
        }

        $fileSize = isset($f['size']) ? (int) $f['size'] : 0;
        if ($fileSize <= 0 || $fileSize > app_upload_max_bytes()) {
            throw new RuntimeException('File troppo grande o vuoto. Verifica i limiti upload.');
        }

        if (!uploaded_xlsx_looks_valid($f['tmp_name'])) {
            throw new RuntimeException('Il file caricato non sembra un XLSX Fineco valido.');
        }

        $uploadDir = __DIR__ . '/storage/uploads';
        ensure_directory($uploadDir);

        $safeName = $snapshotDateObj->format('Ymd') . '_' . bin2hex(random_bytes(8)) . '.xlsx';
        $target = $uploadDir . '/' . $safeName;
        if (!move_uploaded_file($f['tmp_name'], $target)) {
            throw new RuntimeException('Errore nel salvataggio del file caricato.');
        }

        @chmod($target, 0664);

        try {
            $result = import_fineco_xlsx($target, $f['name'], $snapshotDate);
        } catch (Throwable $e) {
            @unlink($target);
            throw $e;
        }
        set_flash('success', 'Import completato. Posizioni caricate: ' . $result['positions']);
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
    <p>Formato supportato: export Fineco `.xlsx` (Portafoglio di sintesi).</p>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>
            Data snapshot
            <input type="date" name="snapshot_date" required value="<?= e(date('Y-m-d')) ?>">
        </label>
        <label>
            File Excel
            <input type="file" name="snapshot_file" accept=".xlsx" required>
        </label>
        <button type="submit">Importa</button>
    </form>
</section>
<?php include __DIR__ . '/partials/footer.php'; ?>
