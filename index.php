<?php
require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Home';
$error = null;
$privacyConfigured = privacy_is_configured();
$returnPath = privacy_safe_return_path((string) ($_POST['return'] ?? $_GET['return'] ?? '/dashboard.php'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_valid_csrf_token(is_string($token) ? $token : null)) {
            throw new RuntimeException('Richiesta non valida (token).');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'privacy_lock') {
            privacy_lock_session();
            set_flash('success', 'Dati del portafoglio bloccati.');
            redirect('/index.php');
        }

        if ($action !== 'privacy_unlock') {
            throw new RuntimeException('Azione privacy non riconosciuta.');
        }
        if (!$privacyConfigured) {
            throw new RuntimeException('PIN privacy non configurato sul server.');
        }

        $attemptStatus = privacy_attempt_status();
        if ($attemptStatus['locked']) {
            $minutes = max(1, (int) ceil(($attemptStatus['locked_until'] - time()) / 60));
            throw new RuntimeException('Troppi tentativi errati. Riprova tra circa ' . $minutes . ' minuti.');
        }

        $pin = trim((string) ($_POST['pin'] ?? ''));
        if (privacy_verify_pin($pin)) {
            privacy_clear_failures();
            privacy_unlock_session();
            set_flash('success', 'Portafoglio sbloccato per 30 minuti di inattivita.');
            header('Location: ' . app_base_path() . $returnPath);
            exit;
        }

        $attemptStatus = privacy_record_failure();
        if ($attemptStatus['locked']) {
            throw new RuntimeException('PIN errato. Accesso bloccato per 15 minuti dopo 10 tentativi falliti.');
        }

        throw new RuntimeException(
            'PIN errato. Tentativi rimasti: ' . (int) $attemptStatus['remaining_attempts'] . '.'
        );
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$privacyUnlocked = $privacyConfigured && privacy_is_unlocked();
$attemptStatus = ['locked' => false, 'locked_until' => 0, 'remaining_attempts' => 10];
if ($privacyConfigured && !$privacyUnlocked) {
    try {
        $attemptStatus = privacy_attempt_status();
    } catch (Throwable $e) {
        $error = $error ?? $e->getMessage();
    }
}

$unlockRequested = !$privacyUnlocked && (
    (string) ($_GET['unlock'] ?? '') === '1'
    || $error !== null
    || $attemptStatus['locked']
);
$lockMinutes = $attemptStatus['locked']
    ? max(1, (int) ceil(($attemptStatus['locked_until'] - time()) / 60))
    : 0;

$recentSnapshots = [];
$archiveAvailable = false;
try {
    $recentSnapshots = db()->query(
        'SELECT snapshot_date, created_at
         FROM pf_snapshots
         ORDER BY snapshot_date DESC, id DESC
         LIMIT 4'
    )->fetchAll();
    $archiveAvailable = true;
} catch (Throwable $e) {
    $archiveAvailable = false;
}

$latestSnapshotLabel = 'Nessuno snapshot';
$latestImportLabel = 'Non disponibile';
if ($recentSnapshots !== []) {
    try {
        $latestSnapshotLabel = (new DateTimeImmutable((string) $recentSnapshots[0]['snapshot_date']))->format('d/m/Y');
    } catch (Throwable $e) {
        $latestSnapshotLabel = (string) $recentSnapshots[0]['snapshot_date'];
    }
    try {
        $latestImportLabel = (new DateTimeImmutable((string) $recentSnapshots[0]['created_at']))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        $latestImportLabel = (string) $recentSnapshots[0]['created_at'];
    }
}

$geminiConfig = app_config()['gemini'] ?? [];
$geminiKey = (string) ($geminiConfig['api_key'] ?? '');
$geminiConfigured = $geminiKey !== '' && !str_contains($geminiKey, 'INSERISCI_CHIAVE');

include __DIR__ . '/partials/header.php';
?>
<section class="panel home-hero">
    <div class="home-hero-copy">
        <span class="home-kicker">Archivio investimenti</span>
        <h1>Il portafoglio resta riservato finche non decidi di aprirlo.</h1>
        <p>Da qui puoi controllare lo stato dell'archivio e avviare le funzioni principali senza mostrare importi, strumenti o risultati.</p>
        <div class="home-hero-actions">
            <?php if ($privacyUnlocked): ?>
                <a class="btn" href="<?= e(app_base_path()) ?>/dashboard.php">Apri dashboard</a>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="privacy_lock">
                    <button type="submit" class="btn-secondary">Blocca dati</button>
                </form>
            <?php else: ?>
                <a class="btn" href="<?= e(app_base_path()) ?>/index.php?unlock=1&amp;return=<?= e(rawurlencode('/dashboard.php')) ?>#privacy-unlock">Sblocca portafoglio</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="home-privacy-state <?= $privacyUnlocked ? 'is-unlocked' : 'is-locked' ?>">
        <span class="home-privacy-symbol" aria-hidden="true"></span>
        <div>
            <strong><?= $privacyUnlocked ? 'Dati sbloccati' : 'Dati protetti' ?></strong>
            <span><?= $privacyUnlocked ? 'Blocco automatico dopo 30 minuti di inattivita' : 'Nessun valore e visibile nella home' ?></span>
        </div>
    </div>
</section>

<?php if (!$privacyConfigured): ?>
    <div class="flash flash-error">PIN privacy non configurato. Le pagine operative restano bloccate.</div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="flash flash-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($unlockRequested): ?>
    <section class="panel privacy-unlock-panel" id="privacy-unlock">
        <div>
            <span class="home-kicker">Accesso riservato</span>
            <h2>Sblocca il portafoglio</h2>
            <p>Inserisci il PIN di 4 cifre. Dopo 10 errori l'accesso viene sospeso per 15 minuti.</p>
        </div>

        <?php if ($attemptStatus['locked']): ?>
            <div class="privacy-lockout-message">
                <strong>Accesso temporaneamente bloccato</strong>
                <span>Riprova tra circa <?= (int) $lockMinutes ?> minuti.</span>
            </div>
        <?php else: ?>
            <form method="post" class="privacy-unlock-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="privacy_unlock">
                <input type="hidden" name="return" value="<?= e($returnPath) ?>">
                <label for="privacy-pin">PIN</label>
                <input
                    id="privacy-pin"
                    name="pin"
                    type="password"
                    inputmode="numeric"
                    pattern="[0-9]{4}"
                    minlength="4"
                    maxlength="4"
                    autocomplete="off"
                    required
                    autofocus
                    aria-describedby="privacy-pin-note"
                >
                <button type="submit">Sblocca</button>
                <span id="privacy-pin-note" class="tiny-note">Sblocco valido fino a 30 minuti dall'ultima attivita.</span>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="home-section">
    <div class="panel-head">
        <div>
            <h2>Azioni rapide</h2>
            <p class="tiny-note">Quando i dati sono bloccati, il PIN viene richiesto prima di aprire la funzione.</p>
        </div>
    </div>
    <div class="home-action-grid">
        <a class="home-action" href="<?= e(app_base_path()) ?>/upload.php">
            <span class="home-action-index">01</span>
            <strong>Carica file Fineco</strong>
            <span>Importa il nuovo report XLS o XLSX e crea lo snapshot.</span>
        </a>
        <a class="home-action" href="<?= e(app_base_path()) ?>/dashboard.php">
            <span class="home-action-index">02</span>
            <strong>Apri dashboard</strong>
            <span>Consulta valori complessivi, grafici ed estremi storici.</span>
        </a>
        <a class="home-action" href="<?= e(app_base_path()) ?>/andamento.php">
            <span class="home-action-index">03</span>
            <strong>Consulta andamento</strong>
            <span>Esplora lo storico per titolo con paginazione ed export.</span>
        </a>
        <a class="home-action" href="<?= e(app_base_path()) ?>/ai_report.php">
            <span class="home-action-index">04</span>
            <strong>Centro AI</strong>
            <span>Genera analisi controllate e consulta l'archivio dei report.</span>
        </a>
    </div>
</section>

<section class="home-status-grid" aria-label="Stato applicazione">
    <article class="home-status-item">
        <span>Ultimo snapshot</span>
        <strong><?= e($latestSnapshotLabel) ?></strong>
        <small>Nessun importo mostrato</small>
    </article>
    <article class="home-status-item">
        <span>Ultimo caricamento</span>
        <strong><?= e($latestImportLabel) ?></strong>
        <small>Attivita archivio</small>
    </article>
    <article class="home-status-item">
        <span>Archivio</span>
        <strong><?= $archiveAvailable ? 'Disponibile' : 'Non raggiungibile' ?></strong>
        <small>Connessione database</small>
    </article>
    <article class="home-status-item">
        <span>Report AI</span>
        <strong><?= $geminiConfigured ? 'Configurato' : 'Non configurato' ?></strong>
        <small><?= e((string) ($geminiConfig['model'] ?? 'Gemini')) ?></small>
    </article>
</section>

<?php if ($recentSnapshots !== []): ?>
    <section class="panel home-activity-panel">
        <div class="panel-head">
            <div>
                <h2>Attivita recente</h2>
                <p class="tiny-note">Sono mostrate soltanto date e orari, mai i valori del portafoglio.</p>
            </div>
            <a class="btn-link" href="<?= e(app_base_path()) ?>/snapshots.php">Apri storico</a>
        </div>
        <div class="home-activity-list">
            <?php foreach ($recentSnapshots as $snapshot): ?>
                <?php
                try {
                    $snapshotDate = (new DateTimeImmutable((string) $snapshot['snapshot_date']))->format('d/m/Y');
                } catch (Throwable $e) {
                    $snapshotDate = (string) $snapshot['snapshot_date'];
                }
                try {
                    $createdAt = (new DateTimeImmutable((string) $snapshot['created_at']))->format('H:i');
                } catch (Throwable $e) {
                    $createdAt = (string) $snapshot['created_at'];
                }
                ?>
                <div>
                    <span class="home-activity-dot" aria-hidden="true"></span>
                    <strong>Snapshot del <?= e($snapshotDate) ?></strong>
                    <span>Registrato alle <?= e($createdAt) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
