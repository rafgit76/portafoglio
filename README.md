# Portfolio App (Aruba PHP/MySQL, PHP 8.4 ready)

App PHP 8.4 ready per Aruba Linux, pensata per importare direttamente gli export Fineco (`.xls` e `.xlsx`) e visualizzare lo storico del portafoglio.

Versione corrente: `2.7.4`

## Credit Lombard e trasferimenti interni documentati

Il portafoglio resta il dato obbligatorio; i dati conto e l'export Patrimonio Fineco restano opzionali. Un trasferimento con contabile Fineco `GIRO INT PAR INT` puo essere registrato dalla pagina **Dossier** come giro interno, non come vendita.

La procedura non modifica nulla fino alla conferma. Cerca soltanto gli snapshot in cui il titolo e assente dal dossier ordinario, non e gia presente nel dossier Credit Lombard e l'export Patrimonio Fineco contiene il controvalore del dossier a pegno. Per ogni snapshot applicato crea un backup logico in `pf_snapshot_revisions`, conserva costo e PMC della posizione precedente e usa il controvalore Fineco del dossier a pegno come valore di mercato.

L'export separato del dossier Credit Lombard si importa dal proprio slot in **Upload**: Fineco puo assegnare lo stesso filename ai due file, ma l'app identifica il dossier dal flusso scelto. L'import ufficiale sostituisce la sola posizione manuale gia confermata dalla relativa contabile, mantenendo il backup logico dello snapshot.

Prima dell import di entrambi i dossier l app mostra un riepilogo con dossier rilevato, numero posizioni, valore di carico e valore di mercato. Se l export contiene un riferimento affidabile incompatibile con lo slot, l import si blocca; se non contiene un identificativo affidabile, il filename non viene usato e serve una conferma manuale dello slot.

Non e previsto OCR automatico delle contabili PDF su Aruba: il riferimento al documento, le date e gli importi tecnici vengono registrati come evidenza dichiarata e verificabile.

Per aggiornare un database gia alla versione 2.4.0, esporta prima un backup completo e importa una volta `sql/migration_2_5_0_documented_internal_transfers.sql` tramite phpMyAdmin.

Per registrare il profilo ufficiale del rapporto Credit Lombard, dopo il backup importa una volta `sql/migration_2_6_0_credit_lombard_profile.sql`. La migrazione completa la linea di credito con validita, rapporti collegati e provenienza documentale senza modificare snapshot, posizioni o calcoli del portafoglio.

Per attivare il controllo facoltativo dell export Patrimonio del solo rapporto Credit Lombard, importa una volta `sql/migration_2_7_0_credit_lombard_checks.sql`. Il controllo si trova in **Gestione fidi**, verifica il dossier configurato e confronta il totale del file con il portafoglio Lombard della stessa data. Il file non viene conservato e il controllo non modifica alcuno snapshot.

Per aggiornare il confronto al rateo Fineco, dopo il backup importa una volta `sql/migration_2_7_1_lombard_accrued_interest.sql`, quindi reimporta l export del Dossier Credit Lombard per la data da verificare. Il controllo confronta il totale Fineco con `valore di mercato + rateo`, conservando le due componenti separate e senza modificare P/L o valori storici gia presenti.

## Deploy (FTP)

Carica tutto in:

`www.raffimac.it/apps/portafoglio`

## Setup rapido

1. Esegui `composer install --no-dev --optimize-autoloader` in locale e carica anche `vendor/`.
2. Importa `sql/schema.sql` nel tuo DB Aruba.
3. Copia `config/config.local.sample.php` in `config/config.local.php` e inserisci le credenziali DB reali.
4. Configura in `config/config.local.php` anche l'hash del PIN privacy di 4 cifre.
5. Verifica i permessi di scrittura delle cartelle `storage`, `storage/uploads` e `storage/ai_reports`.
6. Apri `/apps/portafoglio/`, sblocca il portafoglio e carica il primo file Fineco.

Per creare una cartella FTP completa e pulita puoi eseguire:

`.\build_deploy.ps1`

Lo script crea una cartella `portafoglio_ftp_YYYYMMDD_HHMM` accanto al progetto, includendo `vendor/` ma lasciando fuori `.git`, file di sviluppo, cache, credenziali locali, upload storici e archivio dei report AI. Le cartelle `storage/uploads` e `storage/ai_reports` non sono nemmeno ricreate vuote nel pacchetto. Usa `./build_deploy.ps1 -IncludeLocalConfig` soltanto per una prima installazione controllata.

Durante l'upload con FileZilla, trasferisci i file del pacchetto nella cartella dell'app senza eliminare o sincronizzare in modo distruttivo `storage/` sul server. Le cartelle di storage vengono create automaticamente dall'app alla prima necessita.

`config/config.php` non contiene segreti: legge variabili ambiente `PORTFOLIO_DB_*` oppure il file locale `config/config.local.php`, escluso da Git.

## Hardening leggero incluso

- protezione CSRF su upload e cancellazione snapshot
- validazione upload Excel piu rigorosa
- parser Fineco con detection dinamica intestazioni e supporto diretto `.xls`/`.xlsx` tramite PhpSpreadsheet
- cartella `storage/` non esposta pubblicamente
- footer runtime pubblico ridotto al minimo per default
- versione app e changelog visibili dal footer
- configurazione applicativa esplicita per target PHP 8.4
- libreria Chart.js servita localmente da `assets/vendor/` senza dipendenza CDN runtime
- home pubblica priva di importi e pagine operative protette da PIN privacy
- blocco di 15 minuti dopo 10 tentativi errati e scadenza dello sblocco dopo 30 minuti di inattivita
- report delle trimestrali vincolati a una fonte HTTPS ufficiale recuperata con Gemini URL Context

## Opzioni esterne

In `config/config.local.php` puoi regolare:

- `timezone`: fuso orario usato per data, ora e nomi automatici degli export.
- `enable_fx`: abilita/disabilita EUR/USD da ECB.
- `enable_news`: abilita/disabilita le news RSS in dashboard.
- `external_fetch_timeout_seconds`: timeout delle chiamate esterne.

## Pagine

- `index.php`: home privacy senza dati economici e sblocco con PIN
- `dashboard.php`: dashboard completa del portafoglio
- `upload.php`: import separato del portafoglio e, facoltativamente, dell'export Patrimonio Fineco
- `account.php`: patrimonio, saldi, liquidita, riconciliazione e snapshot manuali opzionali
- `account_history.php`: archivio patrimoniale paginato, estremi storici e grafici separati di patrimonio e saldi
- `credit_lines.php`: gestione separata di fidi e linee di credito
- `dossiers.php`: integrazione manuale di posizioni in dossier non esportati
- `snapshots.php`: storico snapshot paginato, con massimo/minimo P/L evidenziati sull'intero archivio
- `snapshot.php?id=...`: dettaglio snapshot
- `assets.php`: composizione, diversificazione, fonti e anagrafica strumenti
- `asset.php?id=...`: dettaglio storico di un singolo titolo
- `ai_report.php`: Centro AI, report e confronti distinti per portafoglio e patrimonio, generazione esplicita e archivio report
- `ai_report_view.php`: dettaglio e stampa di un report AI archiviato
- `changelog.php`: storico versioni applicative

## PIN privacy

Il PIN e una protezione semplice pensata per evitare che i dati del portafoglio siano visibili quando l'app viene aperta in presenza di altre persone. Non sostituisce un sistema di autenticazione multiutente.

Salva soltanto l'hash del PIN in `config/config.local.php` oppure nella variabile ambiente `PORTFOLIO_PRIVACY_PIN_HASH`. Puoi generarlo con `password_hash()` come mostrato in `config/config.local.sample.php`. Il PIN non viene mai salvato in chiaro.

Lo sblocco scade dopo 30 minuti di inattivita. Dopo 10 tentativi errati dallo stesso indirizzo IP, l'accesso resta bloccato per 15 minuti. Il registro tecnico e salvato in `storage/privacy_attempts.json`, escluso da Git e dai pacchetti FTP; la cartella `storage` deve essere scrivibile da PHP.

## Conto, patrimonio e dossier

Il portafoglio resta la base obbligatoria dell'app. Tutte le altre funzioni sono opzionali: se carichi soltanto l'export Portfolio Fineco l'app continua a funzionare come prima.

Per una prima installazione del modulo Conto importa prima `sql/migration_2_2_0_account_tracker.sql`. Per aggiornare un modulo Conto gia presente, prima esporta il database completo da phpMyAdmin, poi importa **una sola volta** `sql/migration_2_4_0_credit_lombard_semantics.sql`. Aruba non offre un backup automatico affidabile da PHP senza shell: l'export phpMyAdmin e il backup completo da conservare prima della migrazione. La migrazione aggiunge soltanto colonne nullable e non modifica gli snapshot esistenti. Ogni modifica manuale a uno snapshot gia esistente crea inoltre un backup logico recuperabile in `pf_snapshot_revisions`.

In `Upload` sono disponibili tre flussi distinti:

1. **Portafoglio principale**: obbligatorio. Ogni file e importato nel dossier ordinario indipendentemente dal filename. Gli snapshot storici precedenti alla migrazione sono trattati automaticamente come dossier ordinario.
2. **Dossier Credit Lombard**: facoltativo. Usa lo stesso parser del portafoglio principale, ma importa nel dossier Credit Lombard anche quando Fineco chiama entrambi i file `portafoglio-export.xls`.
3. **Import patrimonio Fineco**: facoltativo. Il parser riconosce le etichette Fineco, non righe fisse, e importa solo voci presenti: patrimonio totale, portafoglio totale, saldo contabile, saldo disponibile, totale liquidita, liquidita prenotata, multicurrency, fido e valori dossier. Nel file campione Fineco il dossier a pegno viene associato al dossier Credit Lombard; i suoi valori servono alla riconciliazione e non vengono sommati una seconda volta al patrimonio totale.

Le priorita sono: import Patrimonio Fineco, override manuale per la stessa data, valore calcolato se applicabile. Il payload importato e conservato insieme agli override manuali. Il credito disponibile non e mai patrimonio. Quando Fineco lo include in liquidita e patrimonio totale, l'app mostra separatamente Patrimonio Fineco e Patrimonio netto rettificato (`Patrimonio Fineco - fido disponibile`), oltre a Liquidita Fineco e Liquidita propria rettificata (`Liquidita Fineco - fido disponibile`). Il fido utilizzato usa il campo Fineco diretto quando presente, altrimenti solo il controllo `accordato - disponibile`.

La pagina `Conto -> Andamento patrimonio` conserva gli snapshot patrimoniali giornalieri in un archivio separato da quello del portafoglio. Mostra gli estremi sull'intera cronologia e grafici distinti per patrimonio e saldi; valori assenti restano semplicemente non disponibili e non generano grafici vuoti.

Per il trasferimento BTP del `20/08/2026`, non modificare lo storico automaticamente. Dopo avere un export del dossier Credit Lombard, importalo selezionando il dossier corretto oppure usa `Dossier e posizioni manuali`: verifica data, quantita, valore di carico e mercato, spunta la conferma. L'app conserva il prima/dopo nel backup logico, ricalcola il consolidato e, se il matching e netto, propone il trasferimento interno da confermare. Nessuna proposta modifica P/L o patrimonio da sola.

## Report AI

La pagina `Report AI` e un centro operativo che usa Gemini lato server per generare report sullo storico degli snapshot. Per il portafoglio sono disponibili report complessivo, confronto quotidiano e confronto settimanale del sabato. La sezione Patrimonio e opzionale e offre le stesse tre modalita usando soltanto gli snapshot patrimoniali disponibili: mantiene separati patrimonio Fineco, portafoglio consolidato, liquidita, saldi e credito. Il confronto settimanale e disponibile esclusivamente il sabato e usa le rilevazioni con data esatta del sabato corrente e del sabato precedente, distanti sette giorni. Le anteprime sono calcolate gratuitamente da PHP, mentre la spiegazione AI parte solo dopo conferma. La semplice apertura della pagina non esegue chiamate API: ogni generazione richiede un'azione e una conferma esplicite. Durante l'elaborazione viene mostrato un overlay con avanzamento stimato, stato finale ed eventuali errori.
Configura la chiave solo in `config/config.local.php` o tramite `PORTFOLIO_GEMINI_API_KEY`.
Il modello predefinito e' `gemini-3.8-flash`, configurabile con `PORTFOLIO_GEMINI_MODEL`. Il listino introduttivo usato per la stima e' $0,75 per milione di token input e $3,75 per milione di token output o ragionamento fino al 31 dicembre 2026 (https://ai.google.dev/gemini-api/docs/pricing). La stima andra' aggiornata alla scadenza della promozione. Primo prompt, massimo 20000 token e timeout di 90 secondi restano invariati rispetto al 3.7. Il ragionamento predefinito e' `medium`; dalla v2.7.4 il solo complessivo del portafoglio con il 3.8 usa `low`.

Per aggiornare un'installazione esistente, completare prima i report di confronto con il 3.7 e preservare `storage/`, database e configurazione privata. Se `config/config.local.php` contiene `gemini.model`, cambiare solo quel valore in `gemini-3.8-flash`: la configurazione locale ha precedenza sul valore predefinito e sulle variabili d'ambiente. Non sostituire API key, PIN o credenziali del database con i file sample. Nessuna migrazione SQL richiesta. Verificare il modello nella pagina Report AI prima di generare nuovi report. Per tornare al 3.7 basta ripristinare il valore del modello; i report archiviati mantengono modello e consumi originali.

Test locali senza chiamate API: `php tests/ai_report_model_test.php` e `php tests/account_tracker_logic_test.php`.

Correzione v2.7.3: le stime mancanti nei report precedenti vengono ricostruite soltanto in lettura, se modello e consumi input/risposta/ragionamento sono noti. Valori archiviati validi (anche zero) non vengono sostituiti; se non si puo stimare il costo, si mostra `Non disponibile`. Nessuna riscrittura dell'archivio e nessuna nuova chiamata API per aggiornare la visualizzazione. I totali con stime mancanti sono parziali.

Il primo prompt del confronto 3.7/3.8 resta invariato. Solo il tentativo correttivo di un report complessivo oltre 1400 parole usa un prompt di sintesi mirato con conteggio PHP e budget per sezione. Non si tronca il testo, non si allenta la validazione e non si aggiungono tentativi. I report registrano la nuova versione della pipeline `2026-09-02-general-length-repair-v13`. I consumi dei tentativi falliti senza report archiviato non sono compresi nei totali dell'archivio e vanno verificati nella console del provider.

Dalla v2.7.4, come concordato dopo gli errori `MAX_TOKENS`, solo il complessivo del portafoglio con `gemini-3.8-flash` usa `general_thinking_level = low`, configurabile anche tramite `PORTFOLIO_GEMINI_GENERAL_THINKING_LEVEL`. Il valore locale, se presente, ha precedenza. Giornalieri, settimanali, patrimonio, personalizzati e altri modelli continuano a usare `thinking_level` (Medium nella configurazione corrente). Nella sezione del complessivo e nel report archiviato compare il livello effettivo. Il confronto con il 3.7 Medium non e piu a parita di ragionamento. Non e stato aumentato il budget di 20000 token e non sono state aggiunte chiamate automatiche.

Un errore di limite token include, quando il provider li comunica, input/risposta/ragionamento e costo stimato dei tentativi della richiesta corrente. Non viene creato un report incompleto e questi consumi non entrano nell'archivio. I blocchi con `thought: true` sono esclusi dal testo narrativo, non dalla contabilizzazione del ragionamento.
Il livello di ragionamento predefinito e' `medium`, configurabile con `PORTFOLIO_GEMINI_THINKING_LEVEL`.
Il budget massimo predefinito e' di 20000 token, configurabile con `PORTFOLIO_GEMINI_MAX_OUTPUT_TOKENS`.
La system instruction impone la semantica dei dati Fineco, il divieto di introdurre cause esterne e l'uso esclusivo di un blocco di fatti numerici verificati e formattati da PHP.
I report troncati, fuori dai limiti di lunghezza, privi delle sezioni richieste o incoerenti con i principali valori verificati non vengono archiviati. Se la prima bozza non supera i controlli, l'app effettua al massimo un secondo tentativo correttivo e conteggia i token di entrambe le chiamate.
Sono disponibili un report complessivo guidato dal prompt madre e report personalizzati basati su una richiesta libera. Ogni risultato mostra token utilizzati e costo API stimato secondo le tariffe configurate nel codice.
I report vengono salvati come JSON protetti in `storage/ai_reports`, esclusi da Git e dai pacchetti FTP. Non esiste una scadenza o pulizia automatica: restano archiviati finche la relativa cartella sul server non viene eliminata. Durante il deploy non eliminare o sincronizzare in modo distruttivo questa cartella. La vecchia cache `storage/ai_report_cache.json`, se presente, viene importata una sola volta nel nuovo archivio.

## Trimestrali

Il modulo Trimestrali e temporaneamente ritirato dalla navigazione in attesa di una riprogettazione. Le tabelle, la migrazione SQL e gli eventi gia archiviati non vengono eliminati; le vecchie URL reindirizzano al Centro AI.

## Strumenti e diversificazione

La pagina `Strumenti` parte sempre dall’ultimo snapshot Fineco disponibile: valori, pesi, concentrazione e valute di negoziazione sono calcolati direttamente dalle posizioni importate.

Le classificazioni territoriali e settoriali sono nel file versionato `config/asset_classifications.php`, associato agli ISIN e corredato da fonte, data di riferimento e metodo. Per i fondi con factsheet analizzabile sono usati i pesi look-through disponibili; dove la fonte non espone una ripartizione completa, l’app mostra una classificazione dello strumento o del suo mandato e lo dichiara nella tabella Fonti e metodo. La pagina non usa soglie, giudizi o raccomandazioni automatiche.

Quando cambia in modo significativo la composizione di un fondo, aggiorna il relativo profilo nel catalogo e la data della fonte: gli importi visualizzati continueranno a essere ricalcolati dallo snapshot piu recente senza modifiche al database.

## Versioning

La versione corrente e' definita in `config/config.php` tramite `app_version` ed e' riportata anche nel file `VERSION`.
Il changelog applicativo e' in `config/changelog.php`.
Ogni modifica significativa deve aggiornare sia il numero di versione sia il changelog.
