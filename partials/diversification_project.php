<?php if ($projectHistory !== []):
$projectDate = array_key_last($projectHistory);
$project = $projectHistory[$projectDate];
$previousProject = array_values(array_slice($projectHistory, -2, 1))[0] ?? null;
$nonTech = $project['groups']['NON TECH'];
$projectMoney = static fn(?float $value): string => $value === null ? 'N/D' : 'EUR ' . number_format($value, 2, ',', '.');
$projectPct = static fn(?float $value): string => $value === null ? 'N/D' : number_format($value, 1, ',', '.') . '%';
?>
<section class="panel" id="diversificazione-2027">
    <div class="panel-head"><div><p class="eyebrow">NON TECH</p><h2>Progetto Diversificazione 2027</h2><p class="tiny-note">Snapshot posizioni del <?= e($projectDate) ?> · Obiettivo <?= e($projectMoney(DIVERSIFICATION_2027_TARGET)) ?></p></div></div>
    <div class="cards pne-cards">
        <article class="card"><h3>Valore di mercato</h3><p class="big"><?= e($projectMoney($nonTech['market'])) ?></p><p class="tiny-note"><?= e($projectPct($nonTech['market'] / DIVERSIFICATION_2027_TARGET * 100)) ?> dell'obiettivo</p></article>
        <article class="card"><h3>Valore di carico</h3><p class="big"><?= e($projectMoney($nonTech['cost'])) ?></p><p class="tiny-note"><?= e($projectPct($nonTech['cost'] / DIVERSIFICATION_2027_TARGET * 100)) ?> dell'obiettivo; non misura i versamenti</p></article>
        <article class="card"><h3>P/L non realizzato</h3><p class="big <?= $nonTech['pnl'] < 0 ? 'neg' : 'pos' ?>"><?= e($projectMoney($nonTech['pnl'])) ?></p></article>
        <article class="card"><h3>Capitale netto versato</h3><p class="big">N/D</p><p class="tiny-note">Serve uno storico completo dei flussi destinati al progetto.</p></article>
    </div>
    <div class="table-wrap"><table class="pne-compact-table"><thead><tr><?php foreach (['Settore', 'Mercato', 'Carico', 'P/L', 'Peso attuale', 'Obiettivo', 'Scarto (pp)', 'Mancante sul carico'] as $label): ?><th scope="col"><?= e($label) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($project['sectors'] as $ticker => $sector): ?><tr><th scope="row"><?= e($sector['label']) ?> <small><?= e($ticker) ?></small></th><td><?= e($projectMoney($sector['market'])) ?></td><td><?= e($projectMoney($sector['cost'])) ?></td><td><?= e($projectMoney($sector['pnl'])) ?></td><td><?= e($projectPct($sector['weight'])) ?></td><td><?= e($projectPct($sector['target_pct'])) ?> · <?= e($projectMoney($sector['target'])) ?></td><td><?= e($sector['gap_pct'] === null ? 'N/D' : number_format($sector['gap_pct'], 1, ',', '.')) ?></td><td><?= e($projectMoney($sector['remaining'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <p class="tiny-note">La classificazione per comparti non e un'analisi delle partecipazioni sottostanti: gli ETF globali includono anche tecnologia. Gli ETF NON TECH rimangono strumenti azionari, non liquidita.</p>
    <div class="table-wrap"><table class="pne-compact-table"><thead><tr><?php foreach (['Comparto', 'Valore di mercato', 'Peso in portafoglio', 'P/L non realizzato', 'Variazione', 'Strumenti'] as $label): ?><th scope="col"><?= e($label) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($project['groups'] as $label => $group): ?><tr><th scope="row"><?= e($label) ?></th><td><?= e($projectMoney($group['market'])) ?></td><td><?= e($projectPct($group['weight'])) ?></td><td><?= e($projectMoney($group['pnl'])) ?></td><td><?= e($projectMoney($previousProject === null || count($projectHistory) < 2 ? null : $group['market'] - $previousProject['groups'][$label]['market'])) ?></td><td><?= $group['count'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <h3>Andamento dei comparti</h3>
    <?php
    $projectMonths = [];
    foreach ($projectHistory as $date => $model) { $projectMonths[substr($date, 0, 7)] = $model['groups']; }
    $projectSeries = [];
    foreach (['NON TECH' => '#087a62', 'TECH' => '#b6384c', 'GLOBAL EQUITY' => '#246bad'] as $label => $color) { $projectSeries[] = ['label' => $label, 'borderColor' => $color, 'data' => array_map(static fn(array $month): float => $month[$label]['market'], array_values($projectMonths))]; }
    $economicChart = ['id' => 'diversification-chart', 'label' => 'Valori dei comparti all’ultimo snapshot del mese', 'dates' => array_keys($projectMonths), 'series' => $projectSeries];
    include __DIR__ . '/economic_chart.php';
    ?>
    <p class="tiny-note">Ultima rilevazione di ciascun mese. Nessun ordine o ribilanciamento automatico.</p>
</section>
<?php endif; ?>
