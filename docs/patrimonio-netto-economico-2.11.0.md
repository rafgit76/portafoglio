# Patrimonio Netto Economico v2.0 (2.11.0)

## Ambito e metodo

Versione precedente: **2.10.7**, commit `e282658`. Versione **2.11.0** pubblicata con WinSCP il 28/11/2026. Il calcolo e in sola lettura: nessuna migrazione, scrittura sugli snapshot o sui report AI gia salvati, ne operazione bancaria. Il riferimento di ripristino locale e `backup/pne-before-2.11.0-20261128`.

Il precedente netto rettificato sottrae la disponibilita residua di credito, non tutto il credito incluso dal prospetto. Di conseguenza, quando il prospetto include interamente l'accordato, il legacy contiene ancora la quota di credito utilizzata. Saldare di nuovo il debito contabile a valle del PNE introdurrebbe invece una doppia sottrazione.

| Voce | Formula |
| --- | --- |
| Netto legacy | Patrimonio Fineco meno fido disponibile |
| Liquidita legacy | Liquidita Fineco meno fido disponibile |
| PNE | Patrimonio Fineco meno fido accordato effettivamente incluso |
| Liquidita economica netta | Liquidita Fineco meno fido accordato effettivamente incluso |
| Quadratura | Portafoglio consolidato piu liquidita economica netta |
| Controllo analitico | Portafoglio piu saldo contabile, prenotazioni, Multicurrency e altre componenti documentate non duplicate, conservandone i segni |

Il patrimonio osservato riguarda il perimetro finanziario Fineco, non immobili, passivita non registrate, fiscalita latente o spese future. Il portafoglio include i dossier in garanzia. Le stime interessi non sono importi contabilizzati e non vengono sottratte; non c'e evidenza sufficiente per mostrare una variante ufficiale del PNE al netto degli interessi maturati. Il valore a garanzia riconosciuto dalla banca, ratei separati e margini restano N/D quando non documentati.

La [guida Fineco all'area riservata](https://help.finecobank.com/it/conto-e-carte/area-riservata.html) distingue liquidita, portafoglio e operazioni in corso nel totale patrimonio e chiarisce che il disponibile puo incorporare il fido. Vedi anche [guida all'app Fineco](https://help.finecobank.com/it/app/fineco-app.html) e [guida Credit Lombard](https://help.finecobank.com/it/mutui-e-prestiti/credit-lombard.html). La semantica generale non prova il perimetro di ogni export storico.

Il normalizzatore usa valori dello snapshot, con le rettifiche gia conservate, il totale Fineco oppure una rilevazione delle posizioni della stessa data e l'ultimo payload patrimoniale associato. Non consulta linee correnti, fidi futuri o importi esterni per il PNE. Il perimetro del fido positivo richiede riscontro nelle due quadrature dei saldi. L'assenza va provata: fido accordato nullo, senza altri importi di fido incompatibili, non equivale a campo mancante. Componenti monetarie ulteriori non si sommano se non si puo escludere una sovrapposizione. Se disponibili separatamente, l'accordato, l'utilizzato e il residuo sono verificati senza sovrascriverli. Quando l'utilizzo e implicito dal saldo EUR, puo comprendere prenotazioni e non equivale al dato ufficiale della banca.

Calcoli e quadrature operano al centesimo, tolleranza inclusiva 0,01 EUR. **VERIFICATO** indica dati sufficienti e coerenti; **RICOSTRUITO** indica una componente documentata derivata; **DA VERIFICARE** segnala un'incoerenza o operazioni in corso che non si possono attribuire con certezza; **NON DISPONIBILE** indica dati insufficienti. Non si aggiungono o sottraggono le operazioni in corso una seconda volta. Se l'export ha un importo non trascurabile in corso o non chiude, il valore resta ipotetico e fuori da serie, delta, estremi e fatti AI del PNE. Le variazioni di valore non sono rendimenti e non identificano la causa o il tipo di flusso.

## Verifiche contabili con esempi sintetici

| Caso | PNE EUR | Liquidita netta EUR | Esito locale |
| --- | ---: | ---: | --- |
| A | 154.586,60 | -455,50 | Saldo disponibile EUR non esplicito, ricostruito a 9.053,25 |
| B | 154.570,35 | -471,75 | Utilizzo implicito 463,00, distinto dall'utilizzo ufficiale |
| A verso B | -16,25 | -16,25 | Saldo contabile invariato; nessuna attribuzione automatica alla natura della prenotazione |
| Saldi incoerenti | 152.442,33 (ipotesi) | n.d. | Scarto di 0,63 EUR tra saldo disponibile e fido disponibile, escluso dai confronti attendibili |

I test esercitano anche fidi nulli, parzialmente utilizzati o esauriti, e cambi di plafond. Le date e gli importi dei test pubblici sono simulati.

Ricalcolo storico completato su una copia isolata e in sola lettura. Gli estremi dei soli snapshot verificati coincidono con i riferimenti del collaudo. Le date escluse restano consultabili e non vengono corrette automaticamente. Il dump e il dettaglio privato del collaudo non fanno parte del repository.

## Interfacce, file e compatibilita

- Dashboard: PNE in primo piano con data, confronti, composizione, sintesi fido, grafico per periodo, minimo/massimo e serie facoltative. Conto: liquidita economica, capacita operativa, debito e quattro grafici distinti. Credit Lombard: nominale del solo BTP, valore di mercato e serie storica del dossier; stima interessi separata e monitor preesistente preservato.
- Storico: otto colonne di base, dettaglio legacy e PNE con quadrature, filtro per stato, paginazione ed estremi. Excel conserva i tre fogli precedenti e aggiunge `PNE`; la stampa usa il PNE in prima pagina e conserva l'archivio legacy. Gli export ignorano intenzionalmente filtro e paginazione per mantenere l'archivio completo.
- Diversificazione 2027: obiettivo EUR 100.000, target 30/25/20/15/10, differenze in punti percentuali, carico, mercato, P/L, confronto comparti e storico mensile. Il carico non e una misura dei versamenti; capitale versato N/D senza evidenza. I comparti non sono un'analisi look-through e non generano ordini.
- Report AI patrimoniali generale, giornaliero e settimanale: importi PNE verificati o ricostruiti forniti dal PHP; stato, lacune, credito e formule legacy distinti. Nessun valore provvisorio PNE o ipotesi sui flussi nei fatti verificati. Invariati report storici, report del solo portafoglio, snapshot posizioni, filtri strumenti e storico quantita.
- Modificati: `dashboard.php`, `account.php`, `account_history.php`, `lib/account_tracker.php`, `lib/account_history_export.php`, `lib/ai_report.php`, `partials/account_history_print.php`, `partials/credit_lombard_monitor.php`, `assets/style.css`, `config/config.php`, `config/changelog.php`, `VERSION`, `README.md`, `THREAD_MASTER.md` e le prove export preesistenti.
- Aggiunti: `lib/economic_patrimony.php`, `lib/diversification_project.php`, `partials/economic_dashboard.php`, `partials/economic_diagnostics.php`, `partials/economic_chart.php`, `partials/diversification_project.php`, `assets/economic-patrimony.js`, test e fixture `economic_patrimony_*`, questo report. Nessun file SQL cambiato.

## Collaudo e rollback

Tutti i test PHP e browser del progetto sono stati eseguiti con dati locali. Il nuovo test PHP contiene 39 controlli, inclusi i casi della specifica, fido mancante, tolleranza, linee esterne, operazioni in regolamento, trasferimenti, cambio, flussi, rettifiche manuali ed estremi. Il test SQLite verifica gli stessi importi tra dashboard, archivio, export e payload AI. La stampa PDF di prova con 49 snapshot genera 11 pagine senza sovrapposizione con il footer. Browser Edge a 1600, 390 e 320 px per dashboard, archivio e Conto, inclusi filtro, lacuna PNE e grafici. Il primo controllo visivo mobile ha evidenziato pulsanti tagliati: sono stati mandati a capo e il controllo di contenimento e stato aggiunto al test.

Verifiche online: 19 file confrontati byte per byte, home raggiungibile, dashboard dopo sblocco e report complessivo patrimoniale con Gemini Hydrogen coerenti con il ricalcolo. Verificato anche il settimanale del portafoglio, che mantiene il suo perimetro titoli. Non eseguiti: reimportazione end-to-end su MySQL o generazione dei report patrimoniali giornaliero e settimanale con Gemini. Il parser XLS/XLSX e i controlli locali sulle regole di import/override sono stati esercitati; non equivalgono a un replay della cronologia reale. I test browser producono screenshot dei dati di prova nella directory temporanea indicata nel loro output.

Il pacchetto locale e verificabile con `publish.ps1 -Mode LocalCheck` in Windows PowerShell 5.1; non contatta il server. La pubblicazione e avvenuta dopo il ricalcolo isolato, il backup del database e l'approvazione. Se occorre tornare alla 2.10.7, usare il backup applicativo WinSCP o ricostruire il pacchetto dal commit `e282658` in una copia separata, senza sovrascrivere le modifiche locali e senza ripristinare il database per il solo cambio PNE. Nessuna migrazione inversa necessaria.
