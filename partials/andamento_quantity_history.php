<?php
$quantityAssets = [];
foreach ($columns as $column) { $quantityAssets[(int) $column['id']] = $column; }
$requestedAsset = $_GET['asset'] ?? '';
$requestedAssetId = is_string($requestedAsset) && ctype_digit($requestedAsset) ? (int) $requestedAsset : 0;
$selectedAssetId = isset($quantityAssets[$requestedAssetId]) ? $requestedAssetId : 0;
$visibleChanges = $selectedAssetId === 0 ? $quantityChanges : array_values(array_filter(
    $quantityChanges,
    static fn(array $change): bool => $change['asset_id'] === $selectedAssetId
));
?>
<section class="panel">
    <div class="panel-head">
        <div><h2>Storico quantit&agrave;</h2><p>Variazioni osservate negli snapshot, per tutti i tipi di titolo.</p></div>
        <a class="btn btn-secondary" href="<?= e(app_base_path()) ?>/andamento.php">Torna ad Andamento</a>
    </div>
    <p class="tiny-note">La data indica quando la variazione &egrave; stata rilevata, non necessariamente quando &egrave; avvenuta. Le quantit&agrave; sono sommate fra i dossier; trasferimenti interni a parit&agrave; di totale non sono rilevabili. La prima osservazione e le posizioni mancanti non sono trattate come zero.</p>
    <p class="tiny-note">Una variazione non identifica da sola un acquisto o una vendita. Se tra due rilevazioni mancano snapshot del titolo, l'intervallo &egrave; segnalato e possono esserci movimenti intermedi non osservati.</p>
</section>
<section class="panel">
    <div class="andamento-toolbar">
        <p class="tiny-note"><?= count($visibleChanges) ?> variazioni osservate<?= $selectedAssetId !== 0 ? ' per il titolo selezionato' : '' ?>.</p>
        <form method="get" class="quantity-history-filter">
            <input type="hidden" name="view" value="quantity">
            <label for="quantity-asset">Titolo</label>
            <select name="asset" id="quantity-asset"><option value="">Tutti i titoli</option><?php foreach ($columns as $column): ?><option value="<?= (int) $column['id'] ?>"<?= $selectedAssetId === (int) $column['id'] ? ' selected' : '' ?>><?= e((string) $column['label']) ?></option><?php endforeach; ?></select>
            <button type="submit" class="btn btn-secondary">Filtra</button>
        </form>
    </div>
    <?php if ($visibleChanges === []): ?>
        <p>Nessuna variazione osservata negli snapshot disponibili.</p>
    <?php else: ?>
        <div class="table-wrap"><table class="quantity-history-table">
            <thead><tr><th>Rilevata il</th><th>Titolo</th><th>Rilevazione precedente</th><th>Quantit&agrave; precedente</th><th>Quantit&agrave; rilevata</th><th>Variazione</th><th>Copertura</th></tr></thead>
            <tbody><?php foreach ($visibleChanges as $change): ?><?php $asset = $quantityAssets[$change['asset_id']]; ?><tr>
                <td><?= e($change['date']) ?></td>
                <td><a href="<?= e(app_base_path()) ?>/asset.php?id=<?= (int) $change['asset_id'] ?>"><?= e((string) $asset['label']) ?></a></td>
                <td><?= e($change['previous_date']) ?></td>
                <td><?= e($formatQuantity((float) $change['previous_quantity'])) ?></td>
                <td><?= e($formatQuantity((float) $change['quantity'])) ?></td>
                <td class="<?= $change['delta'] > 0 ? 'pos' : 'neg' ?>"><?= $change['delta'] > 0 ? '+' : '-' ?><?= e($formatQuantity(abs((float) $change['delta']))) ?></td>
                <td><?php if ($change['missing_snapshots'] > 0): ?><?= (int) $change['missing_snapshots'] ?> snapshot senza dato intermedio<?php else: ?>Rilevazioni consecutive<?php endif; ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
