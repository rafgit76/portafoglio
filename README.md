# Portfolio App (Aruba PHP/MySQL, PHP 8.4 ready)

App PHP 8.4 ready per Aruba Linux, pensata per importare direttamente gli export Fineco (`.xls` e `.xlsx`) e visualizzare lo storico del portafoglio.

Versione corrente: `1.9.0`

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

Lo script crea una cartella `portafoglio_ftp_YYYYMMDD_HHMM` accanto al progetto, includendo `vendor/` e la configurazione locale se presente, ma lasciando fuori `.git`, file di sviluppo, cache e upload storici.

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
- `upload.php`: import diretto file Fineco `.xls`/`.xlsx` e conversione opzionale in `.xlsx`
- `snapshots.php`: storico snapshot
- `snapshot.php?id=...`: dettaglio snapshot
- `assets.php`: anagrafica strumenti
- `asset.php?id=...`: dettaglio storico di un singolo titolo
- `earnings.php`: calendario, titoli significativi e archivio delle trimestrali
- `earnings_event.php?id=...`: dettaglio evento, impatto sugli snapshot e report Gemini
- `ai_report.php`: Centro AI, confronti giornaliero e settimanale, generazione esplicita e archivio report
- `ai_report_view.php`: dettaglio e stampa di un report AI archiviato
- `changelog.php`: storico versioni applicative

## PIN privacy

Il PIN e una protezione semplice pensata per evitare che i dati del portafoglio siano visibili quando l'app viene aperta in presenza di altre persone. Non sostituisce un sistema di autenticazione multiutente.

Salva soltanto l'hash del PIN in `config/config.local.php` oppure nella variabile ambiente `PORTFOLIO_PRIVACY_PIN_HASH`. Puoi generarlo con `password_hash()` come mostrato in `config/config.local.sample.php`. Il PIN non viene mai salvato in chiaro.

Lo sblocco scade dopo 30 minuti di inattivita. Dopo 10 tentativi errati dallo stesso indirizzo IP, l'accesso resta bloccato per 15 minuti. Il registro tecnico e salvato in `storage/privacy_attempts.json`, escluso da Git e dai pacchetti FTP; la cartella `storage` deve essere scrivibile da PHP.

## Report AI

La pagina `Report AI` e un centro operativo che usa Gemini lato server per generare report sullo storico degli snapshot. La funzione `Cosa e cambiato?` confronta soltanto gli ultimi due snapshot. Il confronto settimanale e disponibile esclusivamente il sabato e usa le rilevazioni con data esatta del sabato corrente e del sabato precedente, distanti sette giorni. Le anteprime sono calcolate gratuitamente da PHP, mentre la spiegazione AI parte solo dopo conferma. La semplice apertura della pagina non esegue chiamate API: ogni generazione richiede un'azione e una conferma esplicite. Durante l'elaborazione viene mostrato un overlay con avanzamento stimato, stato finale ed eventuali errori.
Configura la chiave solo in `config/config.local.php` o tramite `PORTFOLIO_GEMINI_API_KEY`.
Il modello predefinito e' `gemini-3.5-flash-lite`, configurabile con `PORTFOLIO_GEMINI_MODEL`.
Il livello di ragionamento predefinito e' `medium`, configurabile con `PORTFOLIO_GEMINI_THINKING_LEVEL`.
Il budget massimo predefinito e' di 20000 token, configurabile con `PORTFOLIO_GEMINI_MAX_OUTPUT_TOKENS`.
La system instruction impone la semantica dei dati Fineco, il divieto di introdurre cause esterne e l'uso esclusivo di un blocco di fatti numerici verificati e formattati da PHP.
I report troncati, fuori dai limiti di lunghezza, privi delle sezioni richieste o incoerenti con i principali valori verificati non vengono archiviati. Se la prima bozza non supera i controlli, l'app effettua al massimo un secondo tentativo correttivo e conteggia i token di entrambe le chiamate.
Sono disponibili un report complessivo guidato dal prompt madre e report personalizzati basati su una richiesta libera. Ogni risultato mostra token utilizzati e costo API stimato secondo le tariffe configurate nel codice.
I report vengono salvati come JSON protetti in `storage/ai_reports`, esclusi da Git e dai pacchetti FTP. Durante il deploy non eliminare questa cartella sul server se vuoi conservare lo storico. La vecchia cache `storage/ai_report_cache.json`, se presente, viene importata una sola volta nel nuovo archivio.

## Trimestrali

Il modulo `Trimestrali` collega gli eventi societari ai titoli di tipo `AZIONE`. Le date vengono inserite come confermate o stimate e la fonte ufficiale puo essere aggiunta quando il comunicato e disponibile. Il report Gemini parte solo dopo conferma, usa URL Context e viene archiviato soltanto se la fonte risulta recuperata.

L'impatto e calcolato da PHP confrontando gli snapshot utili prima e dopo l'annuncio. Poiche gli snapshot Fineco importati al mattino riportano la chiusura della sera precedente, per una pubblicazione dopo la chiusura il primo snapshot successivo resta il riferimento pre-evento e quello seguente misura la prima seduta completa di reazione. Per limitare l'effetto di acquisti o vendite nella finestra, la variazione del valore unitario in EUR viene applicata alla quantita precedente. Il risultato comprende comunque l'effetto del cambio incorporato nei controvalori Fineco e non costituisce una misura causale perfetta.

Su un database gia esistente importa una sola volta `sql/migration_1_9_0_earnings.sql`. Le nuove installazioni possono continuare a usare `sql/schema.sql`.

## Versioning

La versione corrente e' definita in `config/config.php` tramite `app_version` ed e' riportata anche nel file `VERSION`.
Il changelog applicativo e' in `config/changelog.php`.
Ogni modifica significativa deve aggiornare sia il numero di versione sia il changelog.
