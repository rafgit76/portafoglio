<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/account_tracker.php';

$pageTitle = 'Dossier e posizioni manuali';
$pdo = db();
if (!account_tracker_schema_ready($pdo)) {
    include __DIR__ . '/partials/header.php';
    ?>
    <section class="panel empty-state"><h2>Modulo conto da inizializzare</h2><p>Se il modulo non era mai stato installato, importa prima <code>sql/migration_2_2_0_account_tracker.sql</code>; poi importa <code>sql/migration_2_4_0_credit_lombard_semantics.sql</code> dopo un export completo del database.</p></section>
    <?php include __DIR__ . '/partials/footer.php'; exit;
}

$dossiers = account_tracker_dossiers($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_valid_csrf_token(is_string($token) ? $token : null)) {
            throw new RuntimeException('Richiesta non valida (token).');
        }
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
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('error', $e->getMessage());
        redirect('/dossiers.php');
    }
}

$assets = $pdo->query('SELECT id, name, isin, ticker, asset_type, currency FROM pf_assets ORDER BY name')->fetchAll();
$latest = $pdo->query('SELECT id, snapshot_date FROM pf_snapshots ORDER BY snapshot_date DESC, id DESC LIMIT 1')->fetch();
include __DIR__ . '/partials/header.php';
?>
<section class="panel account-hero"><div class="panel-head"><div><p class="eyebrow">DOSSIER</p><h2>Dossier e posizioni manuali</h2><p>Usa l'import Fineco quando disponibile. Questa scheda serve solo per integrare un dossier non esportato.</p></div><a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/account.php">Conto e patrimonio</a></div></section>
<section class="panel"><div class="panel-head"><div><h2>Aggiungi posizione a un dossier</h2><p class="tiny-note">Per uno snapshot esistente viene creato un backup logico prima del salvataggio.</p></div></div>
<form method="post" class="form-grid account-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<label>Data snapshot<input type="date" name="snapshot_date" required value="<?= e((string) ($latest['snapshot_date'] ?? date('Y-m-d'))) ?>"></label><label>Dossier<select name="dossier_id" required><?php foreach ($dossiers as $dossier): ?><option value="<?= (int) $dossier['id'] ?>" <?= $dossier['code']==='credit_lombard' ? 'selected' : '' ?>><?= e((string) $dossier['name']) ?></option><?php endforeach; ?></select></label>
<label class="form-full">Strumento esistente<select name="asset_id"><option value="">Nuovo strumento o nessuna selezione</option><?php foreach ($assets as $asset): ?><option value="<?= (int) $asset['id'] ?>"><?= e((string) $asset['name']) ?><?= $asset['isin'] ? ' - '.e((string) $asset['isin']) : '' ?></option><?php endforeach; ?></select></label>
<label>Nuovo titolo<input name="asset_name" placeholder="Solo se non esiste nell'elenco"></label><label>ISIN<input name="isin" placeholder="Es. IT0005696320"></label><label>Tipo<input name="asset_type" value="BTP"></label><label>Valuta<input name="currency" value="EUR"></label>
<label>Quantita<input name="quantity" inputmode="decimal" required></label><label>PMC<input name="avg_load_price" inputmode="decimal"></label><label>Prezzo attuale<input name="current_price" inputmode="decimal"></label><label>Valore di carico<input name="cost_basis" inputmode="decimal" required></label><label>Valore di mercato<input name="market_value" inputmode="decimal" required></label>
<label class="form-full checkbox-label"><input type="checkbox" name="confirm_snapshot_change" value="1"> Ho verificato i valori: se la data esiste gia, aggiungo questa posizione al dossier indicato senza cancellare dati precedenti.</label><div class="form-full"><button type="submit">Salva posizione manuale</button></div></form></section>
<section class="panel"><h2>Dossier disponibili</h2><div class="account-dossier-grid"><?php foreach ($dossiers as $dossier): ?><article class="account-dossier-card"><strong><?= e((string) $dossier['name']) ?></strong><span><?= e((string) $dossier['code']) ?></span><b><?= e((string) $dossier['dossier_type']) ?></b></article><?php endforeach; ?></div></section>
<?php include __DIR__ . '/partials/footer.php'; ?>
