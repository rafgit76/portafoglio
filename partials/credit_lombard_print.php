<?php
$printRows = lombard_export_rows($lombardMonitor);
$printMoney = static fn(?float $value): string => $value === null ? 'N/D' : number_format($value, 2, ',', '.');
$printPercent = static fn(?float $value): string => $value === null ? 'N/D' : number_format($value * 100, 2, ',', '.') . '%';
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Storico Credit Lombard</title>
    <link rel="stylesheet" href="<?= e(app_base_path()) ?>/assets/credit-lombard-print.css">
</head>
<body>
<nav class="print-toolbar" aria-label="Esportazione">
    <a href="<?= e(app_base_path()) ?>/account.php#credit-lombard-monitor">Torna al Credit Lombard</a>
    <button type="button" onclick="window.print()">Stampa / Salva PDF</button>
</nav>
<main>
    <header><p class="eyebrow">PORTFOLIO INVESTIMENTI</p><h1>Credit Lombard</h1><p>Storico giornaliero osservato, dal piu recente. Importi in EUR.</p></header>
    <aside><strong>Stime indicative, non importi ufficiali Fineco.</strong> Utilizzo stimato dal saldo contabile; interessi sui soli giorni osservati, con divisore 365. Date valuta, movimenti infragiornalieri e altre linee possono produrre differenze. Il tasso sull'eccedenza e un'ipotesi da verificare con la banca.<br>
    Giorni senza saldo: <?= (int) $lombardMonitor['missing_days'] ?>; giorni con utilizzo ma senza tasso: <?= (int) $lombardMonitor['missing_rates'] ?>. Il cumulato e parziale in presenza di lacune. N/D significa dato non disponibile, non zero.</aside>
    <table>
        <thead><tr><th>Data</th><th>Saldo contabile</th><th>Utilizzo stimato</th><th>% plafond</th><th>TAN</th><th>Interesse stimato</th><th>Cumulato osservato</th></tr></thead>
        <tbody><?php foreach ($printRows as $row): ?><tr>
            <td><?= e((new DateTimeImmutable($row['date']))->format('d/m/Y')) ?></td>
            <td><?= e($printMoney($row['balance'])) ?></td><td><?= e($printMoney($row['usage'])) ?></td>
            <td><?= e($printPercent($row['percentage'])) ?></td><td><?= e($printPercent($row['tan'])) ?></td>
            <td><?= e($printMoney($row['interest'])) ?></td><td><?= e($printMoney($row['cumulative'])) ?></td>
        </tr><?php endforeach; ?></tbody>
    </table>
    <p class="footnote">Elaborazione personale su snapshot disponibili; nessun dato storico modificato.</p>
</main>
</body>
</html>
