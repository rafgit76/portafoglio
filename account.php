<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/account_tracker.php';

$pageTitle = 'Conto e patrimonio';
$pdo = db();
$schemaReady = account_tracker_schema_ready($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaReady) {
    try {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_valid_csrf_token(is_string($token) ? $token : null)) {
            throw new RuntimeException('Richiesta non valida (token).');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_patrimonial_snapshot') {
            $date = trim((string) ($_POST['snapshot_date'] ?? ''));
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
                throw new RuntimeException('Data snapshot patrimoniale non valida.');
            }

            $fields = [
                'fineco_total_assets' => 'Patrimonio totale Fineco',
                'fineco_portfolio_total' => 'Portafoglio totale Fineco',
                'liquidity_total' => 'Totale liquidita Fineco',
                'reserved_liquidity' => 'Liquidita prenotata',
                'accounting_balance' => 'Saldo contabile',
                'available_balance' => 'Saldo disponibile',
                'credit_lombard_account' => 'Conto Credit Lombard',
                'multicurrency_amount' => 'Multicurrency',
                'other_liquidity' => 'Altra liquidita',
                'credit_agreed_amount' => 'Fido accordato',
                'credit_used_amount' => 'Fido utilizzato',
                'credit_available_amount' => 'Fido disponibile',
            ];
            $values = [];
            foreach ($fields as $field => $label) {
                $values[$field] = account_tracker_optional_decimal($_POST[$field] ?? null, $label);
            }
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if (mb_strlen($notes) > 2000) {
                throw new RuntimeException('Le note sono troppo lunghe.');
            }

            $existingStatement = $pdo->prepare('SELECT * FROM pf_patrimonial_snapshots WHERE snapshot_date=:snapshot_date LIMIT 1');
            $existingStatement->execute([':snapshot_date' => $date]);
            $existing = $existingStatement->fetch();
            $importedValues = [];
            $manualOverrides = [];
            if (is_array($existing)) {
                $decodedImported = json_decode((string) ($existing['imported_values_json'] ?? ''), true);
                $decodedManual = json_decode((string) ($existing['manual_overrides_json'] ?? ''), true);
                $importedValues = is_array($decodedImported) ? $decodedImported : [];
                $manualOverrides = is_array($decodedManual) ? $decodedManual : [];
            }
            foreach ($values as $field => $value) {
                if ($value !== null) {
                    $manualOverrides[$field] = $value;
                }
            }
            $effectiveValues = array_merge($importedValues, $manualOverrides);
            foreach ($fields as $field => $_label) {
                $values[$field] = $effectiveValues[$field] ?? (is_array($existing) ? ($existing[$field] ?? null) : null);
            }
            $adjustments = account_tracker_patrimonial_adjustments($values);

            $statement = $pdo->prepare(
                'INSERT INTO pf_patrimonial_snapshots
                    (snapshot_date, fineco_total_assets, fineco_portfolio_total, liquidity_total, reserved_liquidity, accounting_balance, available_balance, credit_lombard_account, multicurrency_amount, other_liquidity, credit_agreed_amount, credit_used_amount, credit_available_amount, net_assets_adjusted, own_liquidity_adjusted, imported_values_json, manual_overrides_json, notes, data_origin)
                 VALUES
                    (:snapshot_date, :fineco_total_assets, :fineco_portfolio_total, :liquidity_total, :reserved_liquidity, :accounting_balance, :available_balance, :credit_lombard_account, :multicurrency_amount, :other_liquidity, :credit_agreed_amount, :credit_used_amount, :credit_available_amount, :net_assets_adjusted, :own_liquidity_adjusted, :imported_values_json, :manual_overrides_json, :notes, "MANUALE")
                 ON DUPLICATE KEY UPDATE
                    fineco_total_assets = VALUES(fineco_total_assets),
                    fineco_portfolio_total = VALUES(fineco_portfolio_total),
                    liquidity_total = VALUES(liquidity_total),
                    reserved_liquidity = VALUES(reserved_liquidity),
                    accounting_balance = VALUES(accounting_balance),
                    available_balance = VALUES(available_balance),
                    credit_lombard_account = VALUES(credit_lombard_account),
                    multicurrency_amount = VALUES(multicurrency_amount),
                    other_liquidity = VALUES(other_liquidity),
                    credit_agreed_amount = VALUES(credit_agreed_amount),
                    credit_used_amount = VALUES(credit_used_amount),
                    credit_available_amount = VALUES(credit_available_amount),
                    net_assets_adjusted = VALUES(net_assets_adjusted),
                    own_liquidity_adjusted = VALUES(own_liquidity_adjusted),
                    imported_values_json = VALUES(imported_values_json),
                    manual_overrides_json = VALUES(manual_overrides_json),
                    notes = VALUES(notes),
                    data_origin = "MANUALE"'
            );
            $statement->execute([
                ':snapshot_date' => $date,
                ':notes' => $notes !== '' ? $notes : null,
                ':imported_values_json' => $importedValues === [] ? null : json_encode($importedValues, JSON_UNESCAPED_UNICODE),
                ':manual_overrides_json' => $manualOverrides === [] ? null : json_encode($manualOverrides, JSON_UNESCAPED_UNICODE),
                ':net_assets_adjusted' => $adjustments['net_assets_adjusted'],
                ':own_liquidity_adjusted' => $adjustments['own_liquidity_adjusted'],
            ] + $values);
            set_flash('success', 'Snapshot patrimoniale salvato. I campi vuoti restano non disponibili.');
            redirect('/account.php?date=' . rawurlencode($date));
        }

        if (in_array($action, ['confirm_transfer', 'reject_transfer'], true)) {
            $transferId = (int) ($_POST['transfer_id'] ?? 0);
            if ($transferId <= 0) {
                throw new RuntimeException('Trasferimento non valido.');
            }
            account_tracker_set_transfer_status($pdo, $transferId, $action === 'confirm_transfer' ? 'CONFIRMED' : 'REJECTED');
            set_flash('success', $action === 'confirm_transfer' ? 'Trasferimento interno confermato. I dati originali non sono stati alterati.' : 'Proposta di trasferimento ignorata.');
            redirect('/account.php#transfers');
        }

        throw new RuntimeException('Azione non valida.');
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
        redirect('/account.php');
    }
}

if (!$schemaReady) {
    include __DIR__ . '/partials/header.php';
    ?>
    <section class="panel empty-state">
        <h2>Modulo conto da inizializzare</h2>
        <p>Se il modulo Conto non era mai stato installato, importa prima <code>sql/migration_2_2_0_account_tracker.sql</code>; poi, dopo un export completo del database, applica in ordine <code>sql/migration_2_4_0_credit_lombard_semantics.sql</code> e <code>sql/migration_2_5_0_documented_internal_transfers.sql</code>.</p>
        <p class="tiny-note">Fino alla migrazione l'app continua a funzionare come tracker del solo portafoglio.</p>
    </section>
    <?php
    include __DIR__ . '/partials/footer.php';
    exit;
}

$latestPatrimonial = account_tracker_latest_patrimonial_snapshot($pdo);
$duplicate = isset($_GET['duplicate']) && $_GET['duplicate'] === '1';
$requestedDate = trim((string) ($_GET['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
    $requestedDate = date('Y-m-d');
}
$formValues = $duplicate && $latestPatrimonial ? $latestPatrimonial : [];
$formValues['snapshot_date'] = $requestedDate;
$metrics = account_tracker_patrimonial_metrics($pdo);
$latestPortfolio = $pdo->query('SELECT id, snapshot_date, total_market_value, total_cost_basis, total_unrealized_pnl FROM pf_snapshots ORDER BY snapshot_date DESC, id DESC LIMIT 1')->fetch();
$dossierTotals = $latestPortfolio ? account_tracker_snapshot_dossier_totals($pdo, (int) $latestPortfolio['id']) : [];
$pendingTransfers = account_tracker_pending_transfers($pdo);
$confirmedTransfers = account_tracker_confirmed_transfers($pdo);
$creditLombardProfiles = account_tracker_credit_lombard_profiles($pdo);
$warningThreshold = app_setting('account_reconciliation_warn_pct', null);
$showReconciliationWarning = is_numeric($warningThreshold)
    && $metrics['difference_pct'] !== null
    && abs((float) $metrics['difference_pct']) > abs((float) $warningThreshold);

$money = static function (?float $value): string {
    return $value === null ? 'Dato non disponibile' : 'EUR ' . number_format($value, 2, ',', '.');
};
$originLabel = static function (?string $origin): string {
    return match ($origin) {
        'IMPORT_FINECO' => 'Import Fineco',
        'CALCOLATO' => 'Calcolato',
        default => 'Manuale',
    };
};

include __DIR__ . '/partials/header.php';
?>
<section class="panel account-hero">
    <div class="panel-head">
        <div>
            <p class="eyebrow">TRACKER COMPLETO DEL CONTO</p>
            <h2>Patrimonio e liquidita</h2>
            <p>Il portafoglio Fineco resta indipendente. Saldi, patrimonio e fidi sono facoltativi.</p>
        </div>
        <div class="actions-inline">
            <a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/dossiers.php">Dossier</a>
            <a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/credit_lines.php">Gestione fidi</a>
            <a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/account_history.php">Andamento patrimonio</a>
            <a class="btn" href="#patrimonial-snapshot">Aggiorna dati conto</a>
        </div>
    </div>
</section>

<?php if ($latestPortfolio): ?>
<section class="panel">
    <div class="panel-head">
        <h2>Portafoglio da import posizioni</h2>
        <span class="tiny-note">Snapshot <?= e((string) $latestPortfolio['snapshot_date']) ?>, import Fineco</span>
    </div>
    <div class="cards">
        <article class="card"><h3>Valore di carico</h3><p class="big money-big"><span>EUR</span><strong><?= number_format((float) $latestPortfolio['total_cost_basis'], 2, ',', '.') ?></strong></p></article>
        <article class="card"><h3>Valore investito</h3><p class="big money-big"><span>EUR</span><strong><?= number_format((float) $latestPortfolio['total_market_value'], 2, ',', '.') ?></strong></p></article>
        <article class="card"><h3>P/L non realizzato</h3><p class="big money-big <?= (float) $latestPortfolio['total_unrealized_pnl'] >= 0 ? 'pos' : 'neg' ?>"><span>EUR</span><strong><?= number_format((float) $latestPortfolio['total_unrealized_pnl'], 2, ',', '.') ?></strong></p></article>
    </div>
    <?php if ($dossierTotals): ?>
        <div class="account-dossier-grid">
            <?php foreach ($dossierTotals as $dossier): ?>
                <article class="account-dossier-card">
                    <strong><?= e((string) $dossier['name']) ?></strong>
                    <span><?= (int) $dossier['positions_count'] ?> posizioni</span>
                    <b>EUR <?= number_format((float) $dossier['total_market_value'], 2, ',', '.') ?></b>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($metrics['snapshot'] !== null): ?>
<section class="panel">
    <div class="panel-head">
        <h2>Patrimonio</h2>
        <span class="tiny-note">Snapshot <?= e($originLabel((string) ($metrics['snapshot']['data_origin'] ?? ''))) ?> <?= e((string) $metrics['snapshot']['snapshot_date']) ?></span>
    </div>
    <div class="cards">
        <article class="card"><h3 title="Valore riportato da Fineco, puo includere credito disponibile.">Patrimonio Fineco</h3><p class="big"><?= e($money($metrics['snapshot']['fineco_total_assets'] !== null ? (float) $metrics['snapshot']['fineco_total_assets'] : null)) ?></p><p class="tiny-note">Puo includere credito disponibile</p></article>
        <article class="card"><h3 title="Patrimonio Fineco depurato dal credito o fido ancora disponibile.">Patrimonio netto rettificato</h3><p class="big"><?= e($money($metrics['net_adjusted'])) ?></p><p class="tiny-note">Fineco - credito disponibile</p></article>
        <article class="card"><h3>Portafoglio totale Fineco</h3><p class="big"><?= e($money($metrics['reported_portfolio_value'])) ?></p><p class="tiny-note">Include i dossier riportati da Fineco</p></article>
        <article class="card"><h3 title="Totale liquidita Fineco depurato dal credito ancora disponibile.">Liquidita propria rettificata</h3><p class="big"><?= e($money($metrics['own_liquidity_adjusted'])) ?></p><p class="tiny-note">Liquidita Fineco - credito disponibile</p></article>
    </div>
    <div class="cards account-secondary-cards">
        <article class="card"><h3>Saldo contabile</h3><p class="big <?= (float) ($metrics['snapshot']['accounting_balance'] ?? 0) < 0 ? 'neg' : '' ?>"><?= e($money($metrics['snapshot']['accounting_balance'] !== null ? (float) $metrics['snapshot']['accounting_balance'] : null)) ?></p><?php if ((float) ($metrics['snapshot']['accounting_balance'] ?? 0) < 0): ?><p class="tiny-note">Utilizzo di credito o fido in corso</p><?php endif; ?></article>
        <article class="card"><h3 title="Importo spendibile, comprensivo delle linee di credito.">Saldo disponibile</h3><p class="big"><?= e($money($metrics['snapshot']['available_balance'] !== null ? (float) $metrics['snapshot']['available_balance'] : null)) ?></p><p class="tiny-note">Puo includere linee di credito</p></article>
        <article class="card"><h3>Liquidita prenotata</h3><p class="big <?= (float) ($metrics['snapshot']['reserved_liquidity'] ?? 0) < 0 ? 'neg' : '' ?>"><?= e($money($metrics['snapshot']['reserved_liquidity'] !== null ? (float) $metrics['snapshot']['reserved_liquidity'] : null)) ?></p><p class="tiny-note">Voce Fineco separata</p></article>
        <article class="card"><h3>Delta disponibile-contabile</h3><p class="big"><?php $deltaBalance = $metrics['snapshot']['available_balance'] !== null && $metrics['snapshot']['accounting_balance'] !== null ? (float) $metrics['snapshot']['available_balance'] - (float) $metrics['snapshot']['accounting_balance'] : null; ?><?= e($money($deltaBalance)) ?></p><p class="tiny-note">Solo informativo</p></article>
    </div>
    <?php if ($metrics['difference_amount'] !== null): ?>
        <div class="account-reconciliation <?= $showReconciliationWarning ? 'account-reconciliation-warning' : '' ?>">
            <strong>Controllo di coerenza</strong>
            <span>Netto rettificato <?= e($money($metrics['net_adjusted'])) ?></span>
            <span>Ricostruito <?= e($money($metrics['reconstructed_gross'])) ?></span>
            <span>Differenza <?= e($money($metrics['difference_amount'])) ?> (<?= number_format((float) $metrics['difference_pct'], 2, ',', '.') ?>%)</span>
            <?php if ($showReconciliationWarning): ?><span>Superata la soglia configurata: nessun dato e stato modificato.</span><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($metrics['available_balance_check'] !== null): ?>
        <div class="account-reconciliation">
            <strong>Controllo saldo disponibile</strong>
            <span>Contabile + prenotata + credito disponibile <?= e($money($metrics['available_balance_check'])) ?></span>
            <span>Saldo disponibile <?= e($money($metrics['snapshot']['available_balance'] !== null ? (float) $metrics['snapshot']['available_balance'] : null)) ?></span>
            <span>Delta <?= e($money($metrics['available_balance_check_difference'])) ?>. Solo controllo, nessun valore e stato dedotto.</span>
        </div>
    <?php endif; ?>
    <?php if ($metrics['imported_dossiers']): ?>
        <div class="account-imported-dossiers">
            <strong>Dossier rilevati dall'export Patrimonio Fineco</strong>
            <?php foreach ($metrics['imported_dossiers'] as $dossierValue): ?>
                <span><?= e((string) $dossierValue['source_label']) ?>: EUR <?= number_format((float) $dossierValue['amount'], 2, ',', '.') ?></span>
            <?php endforeach; ?>
            <?php if ($metrics['portfolio_dossier_difference'] !== null): ?><span>Delta portafoglio importato / dossier Fineco: EUR <?= number_format((float) $metrics['portfolio_dossier_difference'], 2, ',', '.') ?>. Nessuna correzione automatica.</span><?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($metrics['credit']['lines_count'] > 0): ?>
<section class="panel">
    <div class="panel-head"><div><h2>Linee di credito</h2><?php if (($metrics['credit']['data_origin'] ?? '') === 'IMPORT_FINECO'): ?><p class="tiny-note">Valori aggregati dall'export Patrimonio Fineco: il tipo di fido non e specificato nel file.</p><?php endif; ?></div><a class="btn-link" href="<?= e(app_base_path()) ?>/credit_lines.php">Gestisci fidi</a></div>
    <div class="cards">
        <article class="card"><h3>Accordato</h3><p class="big"><?= e($money($metrics['credit']['agreed_amount'] !== null ? (float) $metrics['credit']['agreed_amount'] : null)) ?></p></article>
        <article class="card"><h3>Utilizzato</h3><p class="big <?= (float) ($metrics['credit']['used_amount'] ?? 0) > 0 ? 'neg' : '' ?>"><?= e($money($metrics['credit']['used_amount'] !== null ? (float) $metrics['credit']['used_amount'] : null)) ?></p></article>
        <article class="card"><h3>Disponibile</h3><p class="big"><?= e($money($metrics['credit']['available_amount'] !== null ? (float) $metrics['credit']['available_amount'] : null)) ?></p><p class="tiny-note">Escluso dal patrimonio</p></article>
    </div>
    <?php foreach ($creditLombardProfiles as $profile): ?>
        <div class="credit-profile">
            <div class="credit-profile-head">
                <div><p class="eyebrow">RAPPORTO UFFICIALE</p><h3><?= e($profile['credit_line_type']) ?></h3></div>
                <span class="status-pill <?= $profile['status_code'] === 'ACTIVE' ? 'status-pill-success' : '' ?>"><?= e($profile['status']) ?></span>
            </div>
            <div class="credit-profile-metrics">
                <div><span>Importo accordato</span><strong><?= $profile['amount_granted'] !== null ? 'EUR ' . number_format((float) $profile['amount_granted'], 2, ',', '.') : 'Dato non disponibile' ?></strong></div>
                <div><span>Validita</span><strong><?= e($profile['validity_type']) ?></strong></div>
                <div><span>Data attivazione</span><strong><?= e((string) ($profile['activation_date'] ?? 'Dato non disponibile')) ?></strong></div>
            </div>
            <div class="credit-profile-relations">
                <?php foreach ($profile['relations'] as $relation): ?>
                    <div data-entity-type="<?= e($relation['entity_type']) ?>"><span><?= e($relation['label']) ?></span><strong><?= e((string) ($relation['identifier'] ?? 'Dato non disponibile')) ?></strong></div>
                <?php endforeach; ?>
            </div>
            <p class="tiny-note">Fonte: <?= e($profile['data_origin_label']) ?><?= $profile['source_document_name'] ? ' - ' . e($profile['source_document_name']) : '' ?>. Il conto a pegno e il dossier a garanzia restano rapporti distinti.</p>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($confirmedTransfers): ?>
<section class="panel">
    <div class="panel-head"><div><p class="eyebrow">STORICO CONSOLIDATO</p><h2>Trasferimenti interni documentati</h2><p class="tiny-note">Sono giri interni tra dossier: non rappresentano vendite, perdite o P/L realizzato.</p></div><a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/dossiers.php#transfer-documentato">Registra trasferimento</a></div>
    <div class="table-wrap"><table><thead><tr><th>Titolo</th><th>Esecuzione</th><th>Dossier</th><th>Quantita</th><th>Carico storico</th><th>Contabile</th></tr></thead><tbody><?php foreach ($confirmedTransfers as $transfer): ?><tr><td><strong><?= e((string) $transfer['asset_name']) ?></strong><br><span class="tiny-note"><?= e((string) ($transfer['isin'] ?? '-')) ?></span></td><td><?= e((string) ($transfer['execution_date'] ?? $transfer['target_snapshot_date'])) ?><br><span class="tiny-note"><?= e((string) ($transfer['operation_code'] ?? 'Trasferimento interno')) ?></span></td><td><?= e((string) $transfer['source_dossier_name']) ?> → <?= e((string) $transfer['target_dossier_name']) ?></td><td><?= number_format((float) $transfer['quantity'], 2, ',', '.') ?></td><td>EUR <?= number_format((float) ($transfer['source_cost_basis'] ?? 0), 2, ',', '.') ?></td><td><?= e((string) ($transfer['source_document_name'] ?? 'Non indicata')) ?></td></tr><?php endforeach; ?></tbody></table></div>
</section>
<?php endif; ?>

<section class="panel" id="patrimonial-snapshot">
    <div class="panel-head">
        <div><h2>Snapshot giornaliero</h2><p class="tiny-note">Tutti i valori sono facoltativi tranne la data. I campi non compilati restano null.</p></div>
        <?php if ($latestPatrimonial): ?><a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/account.php?duplicate=1#patrimonial-snapshot">Duplica valori precedenti</a><?php endif; ?>
    </div>
    <form method="post" class="form-grid account-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_patrimonial_snapshot">
        <label>Data<input type="date" name="snapshot_date" required value="<?= e((string) $formValues['snapshot_date']) ?>"></label>
        <label>Patrimonio totale Fineco<input type="text" inputmode="decimal" name="fineco_total_assets" value="<?= e((string) ($formValues['fineco_total_assets'] ?? '')) ?>"></label>
        <label>Portafoglio totale Fineco<input type="text" inputmode="decimal" name="fineco_portfolio_total" value="<?= e((string) ($formValues['fineco_portfolio_total'] ?? '')) ?>"></label>
        <label>Totale liquidita Fineco<input type="text" inputmode="decimal" name="liquidity_total" value="<?= e((string) ($formValues['liquidity_total'] ?? '')) ?>"></label>
        <label>Liquidita prenotata<input type="text" inputmode="decimal" name="reserved_liquidity" value="<?= e((string) ($formValues['reserved_liquidity'] ?? '')) ?>"></label>
        <label>Saldo contabile<input type="text" inputmode="decimal" name="accounting_balance" value="<?= e((string) ($formValues['accounting_balance'] ?? '')) ?>"></label>
        <label>Saldo disponibile<input type="text" inputmode="decimal" name="available_balance" value="<?= e((string) ($formValues['available_balance'] ?? '')) ?>"></label>
        <label>Conto Credit Lombard<input type="text" inputmode="decimal" name="credit_lombard_account" value="<?= e((string) ($formValues['credit_lombard_account'] ?? '')) ?>"></label>
        <label>Multicurrency<input type="text" inputmode="decimal" name="multicurrency_amount" value="<?= e((string) ($formValues['multicurrency_amount'] ?? '')) ?>"></label>
        <label>Altra liquidita<input type="text" inputmode="decimal" name="other_liquidity" value="<?= e((string) ($formValues['other_liquidity'] ?? '')) ?>"></label>
        <label>Fido accordato<input type="text" inputmode="decimal" name="credit_agreed_amount" value="<?= e((string) ($formValues['credit_agreed_amount'] ?? '')) ?>"></label>
        <label>Fido utilizzato<input type="text" inputmode="decimal" name="credit_used_amount" value="<?= e((string) ($formValues['credit_used_amount'] ?? '')) ?>"></label>
        <label>Fido disponibile<input type="text" inputmode="decimal" name="credit_available_amount" value="<?= e((string) ($formValues['credit_available_amount'] ?? '')) ?>"></label>
        <label class="form-full">Note<textarea name="notes" rows="3"><?= e((string) ($formValues['notes'] ?? '')) ?></textarea></label>
        <div class="form-full"><button type="submit">Salva snapshot</button></div>
    </form>
</section>

<section class="panel" id="transfers">
    <div class="panel-head"><h2>Trasferimenti interni da verificare</h2><span class="tiny-note">Nessun trasferimento modifica P/L o patrimonio senza dati e conferma.</span></div>
    <?php if (!$pendingTransfers): ?>
        <p>Nessun trasferimento interno in attesa di conferma.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Titolo</th><th>Da</th><th>A</th><th>Quantita</th><th>Motivo</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pendingTransfers as $transfer): ?>
                <tr>
                    <td><strong><?= e((string) $transfer['asset_name']) ?></strong><br><span class="tiny-note"><?= e((string) ($transfer['isin'] ?? '-')) ?></span></td>
                    <td><?= e((string) $transfer['source_dossier_name']) ?><br><span class="tiny-note"><?= e((string) $transfer['source_snapshot_date']) ?></span></td>
                    <td><?= e((string) $transfer['target_dossier_name']) ?><br><span class="tiny-note"><?= e((string) $transfer['target_snapshot_date']) ?></span></td>
                    <td><?= number_format((float) $transfer['quantity'], 2, ',', '.') ?></td>
                    <td><?= e((string) $transfer['detected_reason']) ?></td>
                    <td>
                        <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="transfer_id" value="<?= (int) $transfer['id'] ?>"><button type="submit" name="action" value="confirm_transfer">Conferma</button><button type="submit" name="action" value="reject_transfer" class="btn-secondary">Ignora</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>
