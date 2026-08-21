<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/account_tracker.php';

$pageTitle = 'Dossier e posizioni manuali';
$pdo = db();
if (!account_tracker_schema_ready($pdo)) {
    include __DIR__ . '/partials/header.php';
    ?>
    <section class="panel empty-state"><h2>Modulo conto da inizializzare</h2><p>Se il modulo non era mai stato installato, importa prima <code>sql/migration_2_2_0_account_tracker.sql</code>; quindi applica in ordine <code>sql/migration_2_4_0_credit_lombard_semantics.sql</code> e <code>sql/migration_2_5_0_documented_internal_transfers.sql</code> dopo un export completo del database.</p></section>
    <?php include __DIR__ . '/partials/footer.php'; exit;
}

$dossiers = account_tracker_dossiers($pdo);
$transferPreview = null;
$transferDraft = [
    'asset_id' => '',
    'source_dossier_id' => '',
    'target_dossier_id' => '',
    'effective_snapshot_date' => '',
    'execution_date' => '',
    'settlement_date' => '',
    'quantity' => '',
    'operation_code' => 'GIRO INT PAR INT',
    'source_document_name' => '',
    'gross_accrual' => '',
    'withholding_tax' => '',
    'total_amount' => '0',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_valid_csrf_token(is_string($token) ? $token : null)) {
            throw new RuntimeException('Richiesta non valida (token).');
        }
        $action = (string) ($_POST['action'] ?? '');
        if (in_array($action, ['preview_documented_transfer', 'apply_documented_transfer'], true)) {
            foreach (array_keys($transferDraft) as $field) {
                $transferDraft[$field] = trim((string) ($_POST[$field] ?? $transferDraft[$field]));
            }
            $transferInput = [
                'asset_id' => (int) $transferDraft['asset_id'],
                'source_dossier_id' => (int) $transferDraft['source_dossier_id'],
                'target_dossier_id' => (int) $transferDraft['target_dossier_id'],
                'effective_snapshot_date' => $transferDraft['effective_snapshot_date'],
                'execution_date' => $transferDraft['execution_date'],
                'settlement_date' => $transferDraft['settlement_date'],
                'quantity' => account_tracker_optional_decimal($transferDraft['quantity'], 'quantita trasferita'),
                'operation_code' => $transferDraft['operation_code'],
                'source_document_name' => $transferDraft['source_document_name'],
                'gross_accrual' => account_tracker_optional_decimal($transferDraft['gross_accrual'], 'rateo lordo'),
                'withholding_tax' => account_tracker_optional_decimal($transferDraft['withholding_tax'], 'ritenuta rateo'),
                'total_amount' => account_tracker_optional_decimal($transferDraft['total_amount'], 'totale contabile'),
                'confirm_sole_target_position' => ($_POST['confirm_sole_target_position'] ?? '') === '1',
            ];
            if ($transferInput['quantity'] === null) {
                throw new RuntimeException('Indica la quantita trasferita.');
            }
            if ($action === 'preview_documented_transfer') {
                $transferPreview = account_tracker_documented_transfer_preview($pdo, $transferInput);
            } else {
                $result = account_tracker_apply_documented_transfer($pdo, $transferInput);
                set_flash('success', 'Trasferimento interno documentato applicato a ' . $result['corrected_snapshots'] . ' snapshot. I dati importati originali sono conservati nei backup logici.');
                redirect('/dossiers.php#transfer-documentato');
            }
        } else {
        $date = trim((string) ($_POST['snapshot_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('Data snapshot non valida.');
        }
        $dossierId = (int) ($_POST['dossier_id'] ?? 0);
        $dossier = null;
        foreach ($dossiers as $row) { if ((int) $row['id'] === $dossierId) { $dossier = $row; break; } }
        if ($dossier === null) { throw new RuntimeException('Seleziona un dossier valido.'); }

        $assetId = (int) ($_POST['asset_id'] ?? 0);
        $assetName = trim((string) ($_POST['asset_name'] ?? ''));
        $isin = strtoupper(trim((string) ($_POST['isin'] ?? '')));
        if ($assetId <= 0 && $assetName === '') {
            throw new RuntimeException('Seleziona uno strumento esistente o inserisci un nuovo titolo.');
        }
        $quantity = account_tracker_optional_decimal($_POST['quantity'] ?? null, 'quantita');
        $costBasis = account_tracker_optional_decimal($_POST['cost_basis'] ?? null, 'valore di carico');
        $marketValue = account_tracker_optional_decimal($_POST['market_value'] ?? null, 'valore di mercato');
        if ($quantity === null || $quantity < 0 || $costBasis === null || $marketValue === null) {
            throw new RuntimeException('Quantita, valore di carico e valore di mercato sono obbligatori.');
        }
        $avgLoad = account_tracker_optional_decimal($_POST['avg_load_price'] ?? null, 'PMC');
        $currentPrice = account_tracker_optional_decimal($_POST['current_price'] ?? null, 'prezzo attuale');
        $avgLoad ??= $quantity > 0 ? $costBasis / $quantity : 0.0;
        $currentPrice ??= $quantity > 0 ? $marketValue / $quantity : 0.0;
        $assetType = strtoupper(trim((string) ($_POST['asset_type'] ?? 'BTP'))) ?: 'BTP';
        if (!in_array($assetType, ['AZIONE', 'PAC', 'BTP', 'ETF'], true)) {
            throw new RuntimeException('Tipo strumento non valido. Usa AZIONE, PAC, BTP o ETF.');
        }
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'EUR'))) ?: 'EUR';

        $pdo->beginTransaction();
        if ($assetId <= 0) {
            $find = $isin !== '' ? $pdo->prepare('SELECT id FROM pf_assets WHERE isin=:isin LIMIT 1') : null;
            if ($find) { $find->execute([':isin' => $isin]); $assetId = (int) $find->fetchColumn(); }
            if ($assetId <= 0) {
                $insertAsset = $pdo->prepare('INSERT INTO pf_assets (isin, name, asset_type, currency) VALUES (:isin,:name,:asset_type,:currency)');
                $insertAsset->execute([':isin' => $isin !== '' ? $isin : null, ':name' => $assetName, ':asset_type' => $assetType, ':currency' => $currency]);
                $assetId = (int) $pdo->lastInsertId();
            }
        }
        $snapshotId = account_tracker_get_or_create_snapshot($pdo, $date, null, 'Posizione manuale dossier ' . $dossier['name']);
        $exists = $pdo->prepare('SELECT id FROM pf_snapshot_positions WHERE snapshot_id=:snapshot_id AND asset_id=:asset_id AND dossier_id=:dossier_id LIMIT 1');
        $exists->execute([':snapshot_id' => $snapshotId, ':asset_id' => $assetId, ':dossier_id' => $dossierId]);
        if ($exists->fetchColumn()) { throw new RuntimeException('La posizione esiste gia in questo dossier per la data indicata. Usa un import Fineco oppure scegli una data diversa.'); }

        $snapshotExists = $pdo->prepare('SELECT COUNT(*) FROM pf_snapshot_positions WHERE snapshot_id=:id');
        $snapshotExists->execute([':id' => $snapshotId]);
        if ((int) $snapshotExists->fetchColumn() > 0 && ($_POST['confirm_snapshot_change'] ?? '') !== '1') {
            throw new RuntimeException('Questa data contiene gia uno snapshot: spunta la conferma dopo avere verificato la modifica proposta. Nessun dato e stato salvato.');
        }
        $revisionId = account_tracker_backup_snapshot($pdo, $snapshotId, 'MANUAL_DOSSIER_POSITION', ['dossier' => $dossier['code'], 'asset_id' => $assetId, 'planned' => ['quantity' => $quantity, 'cost_basis' => $costBasis, 'market_value' => $marketValue]]);
        $insert = $pdo->prepare('INSERT INTO pf_snapshot_positions (snapshot_id,dossier_id,asset_id,quantity,avg_load_price,current_price,cost_basis,market_value,unrealized_pnl,weight_pct,imported_weight_pct,currency,data_origin) VALUES (:snapshot_id,:dossier_id,:asset_id,:quantity,:avg_load_price,:current_price,:cost_basis,:market_value,:unrealized_pnl,0,0,:currency,"MANUALE")');
        $insert->execute([':snapshot_id'=>$snapshotId, ':dossier_id'=>$dossierId, ':asset_id'=>$assetId, ':quantity'=>$quantity, ':avg_load_price'=>$avgLoad, ':current_price'=>$currentPrice, ':cost_basis'=>$costBasis, ':market_value'=>$marketValue, ':unrealized_pnl'=>$marketValue-$costBasis, ':currency'=>$currency]);
        account_tracker_recalculate_snapshot($pdo, $snapshotId);
        account_tracker_finalize_snapshot_backup($pdo, $revisionId, $snapshotId);
        account_tracker_detect_transfer_candidates($pdo, $snapshotId, $dossierId);
        $pdo->commit();
        set_flash('success', 'Posizione manuale salvata con backup logico dello snapshot. Eventuali trasferimenti restano da confermare.');
        redirect('/dossiers.php');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (($action ?? '') === 'preview_documented_transfer') {
            $transferPreview = ['error' => $e->getMessage(), 'ready' => [], 'skipped' => []];
        } else {
            set_flash('error', $e->getMessage());
            redirect('/dossiers.php');
        }
    }
}

$assets = $pdo->query('SELECT id, name, isin, ticker, asset_type, currency FROM pf_assets ORDER BY name')->fetchAll();
$latest = $pdo->query('SELECT id, snapshot_date FROM pf_snapshots ORDER BY snapshot_date DESC, id DESC LIMIT 1')->fetch();
$ordinaryDossier = null;
$creditLombardDossier = null;
foreach ($dossiers as $dossier) {
    if ($dossier['code'] === 'ordinario') { $ordinaryDossier = $dossier; }
    if ($dossier['code'] === 'credit_lombard') { $creditLombardDossier = $dossier; }
}
$transferDraft['source_dossier_id'] = $transferDraft['source_dossier_id'] !== '' ? $transferDraft['source_dossier_id'] : (string) ($ordinaryDossier['id'] ?? '');
$transferDraft['target_dossier_id'] = $transferDraft['target_dossier_id'] !== '' ? $transferDraft['target_dossier_id'] : (string) ($creditLombardDossier['id'] ?? '');
$transferDraft['effective_snapshot_date'] = $transferDraft['effective_snapshot_date'] !== '' ? $transferDraft['effective_snapshot_date'] : (string) ($latest['snapshot_date'] ?? '');
include __DIR__ . '/partials/header.php';
?>
<section class="panel account-hero"><div class="panel-head"><div><p class="eyebrow">DOSSIER</p><h2>Dossier e posizioni manuali</h2><p>Usa l'import Fineco quando disponibile. Questa scheda serve solo per integrare un dossier non esportato.</p></div><a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/account.php">Conto e patrimonio</a></div></section>
<section class="panel" id="transfer-documentato">
    <div class="panel-head"><div><p class="eyebrow">CONTABILE FINECO</p><h2>Registra giro interno documentato</h2><p>Usa questa procedura solo per una contabile con codice <strong>GIRO INT PAR INT</strong>. Non e una vendita e non genera P/L realizzato.</p></div></div>
    <form method="post" class="form-grid account-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label class="form-full">Titolo<select name="asset_id" required><option value="">Seleziona il titolo</option><?php foreach ($assets as $asset): ?><option value="<?= (int) $asset['id'] ?>" <?= (string) $asset['id'] === $transferDraft['asset_id'] ? 'selected' : '' ?>><?= e((string) $asset['name']) ?><?= $asset['isin'] ? ' - ' . e((string) $asset['isin']) : '' ?></option><?php endforeach; ?></select></label>
        <label>Dossier di origine<select name="source_dossier_id" required><?php foreach ($dossiers as $dossier): ?><option value="<?= (int) $dossier['id'] ?>" <?= (string) $dossier['id'] === $transferDraft['source_dossier_id'] ? 'selected' : '' ?>><?= e((string) $dossier['name']) ?></option><?php endforeach; ?></select></label>
        <label>Dossier di destinazione<select name="target_dossier_id" required><?php foreach ($dossiers as $dossier): ?><option value="<?= (int) $dossier['id'] ?>" <?= (string) $dossier['id'] === $transferDraft['target_dossier_id'] ? 'selected' : '' ?>><?= e((string) $dossier['name']) ?></option><?php endforeach; ?></select></label>
        <label>Primo snapshot da correggere<input type="date" name="effective_snapshot_date" required value="<?= e($transferDraft['effective_snapshot_date']) ?>"></label>
        <label>Quantita trasferita<input name="quantity" inputmode="decimal" required value="<?= e($transferDraft['quantity']) ?>" placeholder="Es. 20000"></label>
        <label>Data esecuzione<input type="date" name="execution_date" value="<?= e($transferDraft['execution_date']) ?>"></label>
        <label>Valuta regolamento<input type="date" name="settlement_date" value="<?= e($transferDraft['settlement_date']) ?>"></label>
        <label>Codice operazione<input name="operation_code" readonly value="<?= e($transferDraft['operation_code']) ?>"></label>
        <label>Riferimento contabile PDF<input name="source_document_name" value="<?= e($transferDraft['source_document_name']) ?>" placeholder="Es. FB_Contabiline_ammininistrato.pdf"></label>
        <label>Rateo lordo<input name="gross_accrual" inputmode="decimal" value="<?= e($transferDraft['gross_accrual']) ?>"></label>
        <label>Ritenuta rateo<input name="withholding_tax" inputmode="decimal" value="<?= e($transferDraft['withholding_tax']) ?>"></label>
        <label>Totale economico<input name="total_amount" inputmode="decimal" value="<?= e($transferDraft['total_amount']) ?>"></label>
        <div class="form-full"><button type="submit" name="action" value="preview_documented_transfer">Mostra correzione proposta</button></div>
    </form>
    <p class="tiny-note">L'app conserva il riferimento alla contabile e i dati tecnici dichiarati. Non esegue OCR dei PDF sul server Aruba: nessun documento viene interpretato automaticamente come vendita.</p>

    <?php if ($transferPreview !== null): ?>
        <div class="transfer-preview <?= isset($transferPreview['error']) ? 'transfer-preview-error' : '' ?>">
            <?php if (isset($transferPreview['error'])): ?>
                <strong>Anteprima non disponibile</strong><span><?= e((string) $transferPreview['error']) ?></span>
            <?php else: ?>
                <div class="panel-head"><div><h3>Correzione proposta</h3><p class="tiny-note"><?= e((string) $transferPreview['asset']['name']) ?> - costo storico conservato: EUR <?= number_format((float) $transferPreview['cost_basis'], 2, ',', '.') ?></p></div><span class="status-badge">Nessun salvataggio eseguito</span></div>
                <?php if ($transferPreview['ready']): ?>
                    <div class="table-wrap"><table><thead><tr><th>Snapshot</th><th>Fonte valore dossier a pegno</th><th>Valore mercato aggiunto</th><th>Carico conservato</th><th>P/L consolidato</th></tr></thead><tbody><?php foreach ($transferPreview['ready'] as $candidate): ?><tr><td><?= e((string) $candidate['snapshot_date']) ?></td><td><?= e((string) $candidate['source_label']) ?></td><td>EUR <?= number_format((float) $candidate['market_value'], 2, ',', '.') ?></td><td>EUR <?= number_format((float) $transferPreview['cost_basis'], 2, ',', '.') ?></td><td class="<?= ((float) $candidate['market_value'] - (float) $transferPreview['cost_basis']) >= 0 ? 'pos' : 'neg' ?>">EUR <?= number_format((float) $candidate['market_value'] - (float) $transferPreview['cost_basis'], 2, ',', '.') ?></td></tr><?php endforeach; ?></tbody></table></div>
                    <form method="post" class="transfer-confirm-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <?php foreach ($transferDraft as $field => $value): ?><input type="hidden" name="<?= e($field) ?>" value="<?= e((string) $value) ?>"><?php endforeach; ?>
                        <label class="checkbox-label"><input type="checkbox" name="confirm_sole_target_position" value="1" required> Confermo che, per gli snapshot proposti, il controvalore del dossier a pegno si riferisce a questo solo titolo.</label>
                        <button type="submit" name="action" value="apply_documented_transfer">Conferma e applica correzione</button>
                    </form>
                <?php else: ?>
                    <p>Nessuno snapshot puo essere corretto senza un controvalore Fineco verificabile del dossier a garanzia.</p>
                <?php endif; ?>
                <?php if ($transferPreview['skipped']): ?><p class="tiny-note">Snapshot non modificati: <?php foreach ($transferPreview['skipped'] as $skipped): ?><?= e((string) $skipped['snapshot_date']) ?> (<?= e((string) $skipped['reason']) ?>)<?php endforeach; ?></p><?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<section class="panel"><div class="panel-head"><div><h2>Aggiungi posizione a un dossier</h2><p class="tiny-note">Per uno snapshot esistente viene creato un backup logico prima del salvataggio.</p></div></div>
<form method="post" class="form-grid account-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<label>Data snapshot<input type="date" name="snapshot_date" required value="<?= e((string) ($latest['snapshot_date'] ?? date('Y-m-d'))) ?>"></label><label>Dossier<select name="dossier_id" required><?php foreach ($dossiers as $dossier): ?><option value="<?= (int) $dossier['id'] ?>" <?= $dossier['code']==='credit_lombard' ? 'selected' : '' ?>><?= e((string) $dossier['name']) ?></option><?php endforeach; ?></select></label>
<label class="form-full">Strumento esistente<select name="asset_id"><option value="">Nuovo strumento o nessuna selezione</option><?php foreach ($assets as $asset): ?><option value="<?= (int) $asset['id'] ?>"><?= e((string) $asset['name']) ?><?= $asset['isin'] ? ' - '.e((string) $asset['isin']) : '' ?></option><?php endforeach; ?></select></label>
<label>Nuovo titolo<input name="asset_name" placeholder="Solo se non esiste nell'elenco"></label><label>ISIN<input name="isin" placeholder="Es. IT0005696320"></label><label>Tipo<input name="asset_type" value="BTP"></label><label>Valuta<input name="currency" value="EUR"></label>
<label>Quantita<input name="quantity" inputmode="decimal" required></label><label>PMC<input name="avg_load_price" inputmode="decimal"></label><label>Prezzo attuale<input name="current_price" inputmode="decimal"></label><label>Valore di carico<input name="cost_basis" inputmode="decimal" required></label><label>Valore di mercato<input name="market_value" inputmode="decimal" required></label>
<label class="form-full checkbox-label"><input type="checkbox" name="confirm_snapshot_change" value="1"> Ho verificato i valori: se la data esiste gia, aggiungo questa posizione al dossier indicato senza cancellare dati precedenti.</label><div class="form-full"><button type="submit">Salva posizione manuale</button></div></form></section>
<section class="panel"><h2>Dossier disponibili</h2><div class="account-dossier-grid"><?php foreach ($dossiers as $dossier): ?><article class="account-dossier-card"><strong><?= e((string) $dossier['name']) ?></strong><span><?= e((string) $dossier['code']) ?></span><b><?= e((string) $dossier['dossier_type']) ?></b></article><?php endforeach; ?></div></section>
<?php include __DIR__ . '/partials/footer.php'; ?>
