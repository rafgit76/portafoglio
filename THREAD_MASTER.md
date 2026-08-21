# [PROGETTO RIUSCITTO] Portafoglio Snapshot WEB APP

## Stato del thread

Questo file e il documento master ufficiale del thread dedicato alla web app `Portafoglio Snapshot WEB APP`.

Versione corrente:
- `2.3.1`

Obiettivo del thread:
- mantenere memoria tecnica e operativa del progetto
- centralizzare decisioni, vincoli e prossimi interventi
- usare questo thread come riferimento stabile per evoluzioni future

Decisione corrente:
- questo thread e il thread ufficiale del progetto
- nome di riferimento del thread: `[PROGETTO RIUSCITTO] Portafoglio Snapshot WEB APP`

## Identita del progetto

Nome progetto:
- Portafoglio Snapshot WEB APP

Percorso locale:
- `C:\PROGETTI\portafoglio`

Dominio / URL pubblico:
- `https://www.raffimac.it/apps/portafoglio/index.php`

Hosting target:
- Aruba Linux

Modalita di rilascio:
- allineamento via FTP

Stack:
- PHP 8.2 target originario
- PHP 8.4 target attuale di compatibilita
- MySQL
- HTML/CSS/JS vanilla
- Chart.js servito localmente

## Scopo applicativo

La web app serve a importare direttamente gli export Fineco in formato `.xls` o `.xlsx`, salvare snapshot storici del portafoglio e visualizzare:
- dashboard riepilogativa
- storico snapshot
- dettaglio snapshot
- confronto tra snapshot
- composizione, diversificazione e anagrafica strumenti
- report AI separati per portafoglio e patrimonio opzionale
- dati accessori live come EUR/USD e news

## Architettura applicativa

### Entrypoint principali

- `index.php`: home privacy senza dati economici e sblocco con PIN
- `dashboard.php`: dashboard principale
- `upload.php`: upload file Fineco e creazione snapshot
- `account.php`: modulo opzionale per patrimonio, saldi, liquidita e riconciliazione
- `account_history.php`: archivio patrimoniale con estremi e grafici di patrimonio e saldi
- `credit_lines.php`: fidi e linee di credito separati dal patrimonio
- `dossiers.php`: integrazione manuale di posizioni per dossier non esportati
- `snapshots.php`: storico snapshot con eliminazione
- `snapshot.php`: dettaglio di un singolo snapshot
- `compare.php`: confronto snapshot e export CSV
- `assets.php`: composizione per categoria, diversificazione territoriale e settoriale, concentrazione, fonti e anagrafica strumenti
- `asset.php`: dettaglio storico del singolo titolo
- `ai_report.php`: Centro AI con report complessivi, confronti quotidiani e settimanali per portafoglio e patrimonio, generazione esplicita, report personalizzati e archivio
- `ai_report_view.php`: dettaglio e stampa dei report AI archiviati
- `changelog.php`: storico versioni applicative

### Bootstrap e librerie

- `bootstrap.php`: carica helper, DB e sessione e protegge centralmente le pagine operative
- `lib/helpers.php`: config, escaping, redirect, flash, parsing numerico
- `lib/db.php`: connessione PDO MySQL
- `lib/fineco_importer.php`: import principale Fineco
- `lib/fineco_patrimony_importer.php`: parser opzionale dell'export Patrimonio Fineco con detection per etichette
- `lib/account_tracker.php`: dossier, consolidamento, revisioni, patrimonio e trasferimenti interni
- `lib/fineco_xlsx_parser.php`: parser Fineco `.xls`/`.xlsx`, conversione e detection dinamica intestazioni
- `lib/xlsx_reader.php`: fallback XLSX custom senza Composer
- `lib/fx.php`: recupero e cache EUR/USD da ECB
- `lib/news.php`: recupero e ranking news con cache locale
- `lib/earnings.php`: modulo Trimestrali conservato ma temporaneamente ritirato dalla UI in attesa di riprogettazione
- `lib/diversification.php`: aggregazione dell’ultimo snapshot con classificazioni territoriali e settoriali

### View/layout

- `partials/header.php`
- `partials/footer.php`
- `assets/style.css`

## Database

Schema SQL:
- `sql/schema.sql`

Tabelle realmente usate dall'app:
- `pf_snapshots`
- `pf_assets`
- `pf_snapshot_positions`
- `pf_import_jobs`
- `pf_earnings_events`
- `pf_dossiers`
- `pf_patrimonial_snapshots`
- `pf_patrimonial_dossier_values`
- `pf_patrimonial_import_jobs`
- `pf_credit_lines`
- `pf_credit_line_snapshots`
- `pf_internal_transfers`
- `pf_snapshot_revisions`

Classificazioni versionate fuori dal database:
- `config/asset_classifications.php`: profili per ISIN, fonti, date e pesi look-through o classificazioni dichiarate

Tabelle predisposte ma non ancora esposte nella UI:
- `pf_expected_cashflows`
- `pf_actual_cashflows`

Relazione funzionale principale:
- uno snapshot contiene molte posizioni
- ogni posizione punta a un asset anagrafico
- ogni import genera un job di tracciamento

## Flusso operativo principale

1. L'utente carica direttamente un file Fineco `.xls` o `.xlsx` da `upload.php`.
2. Il parser legge il primo foglio Excel.
3. Le intestazioni vengono normalizzate.
4. Le righe vengono aggregate per ISIN o chiave derivata.
5. L'app crea o aggiorna lo snapshot del giorno scelto.
6. L'app crea o aggiorna gli asset.
7. L'app salva le posizioni dello snapshot.
8. L'app aggiorna i totali di snapshot.
9. L'app registra il job di import in `pf_import_jobs`.

Nota importante:
- in caso di reimport sulla stessa data, lo snapshot viene riusato; dopo la migrazione 2.2.0 viene aggiornato solo il dossier di provenienza dell'import, mentre gli altri dossier restano intatti e il totale viene consolidato

## Modulo conto 2.2.0

Regola fondamentale:
- il portafoglio Fineco e obbligatorio
- patrimonio, liquidita, saldi, fidi e dossier aggiuntivi sono opzionali
- il credito disponibile non e mai patrimonio

Migrazione richiesta su database esistente:
- prima esportare il DB completo da phpMyAdmin
- poi importare una sola volta `sql/migration_2_2_0_account_tracker.sql`
- non esiste un dump automatico affidabile via PHP su Aruba condiviso; le revisioni logiche di singoli snapshot sono un ulteriore livello di recupero, non sostituiscono il backup del DB

Flussi supportati:
- solo export portafoglio
- portafoglio + snapshot manuali del conto
- portafoglio + export Patrimonio Fineco
- portafoglio + export Patrimonio + override manuali
- dossier ordinario e Credit Lombard nello stesso snapshot

Il parser Patrimonio si basa sulle etichette reali dell'export Fineco: Patrimonio totale, Saldo contabile, Saldo disponibile, Totale liquidita, Totale multicurrency, Fido accordato/utilizzato/disponibile, Dossier e Totale portafoglio. I valori dei dossier sono usati per riconciliare il portafoglio consolidato e non vengono aggiunti una seconda volta al patrimonio Fineco.

## Storico patrimonio 2.2.1

- `account_history.php` legge soltanto `pf_patrimonial_snapshots` e gli snapshot portafoglio della stessa data per il valore consolidato.
- mostra massimi/minimi senza modificare alcun dato e separa i grafici di patrimonio da quelli dei saldi.
- i campi null restano non disponibili: nessun requisito aggiuntivo viene imposto al flusso solo portafoglio.

## Correzione import multi-dossier 2.2.2

- Aruba PDO non accetta il riuso dello stesso placeholder nominato nella query di ricalcolo dei pesi.
- i parametri sono ora distinti, cosi l'import del portafoglio ricalcola i pesi senza errore `HY093` e resta integralmente transazionale.

## Archivio patrimonio 2.2.3

- la tabella dello storico patrimoniale e ordinata dallo snapshot piu recente al piu vecchio, mentre i grafici restano in ordine cronologico.

## Report AI patrimonio 2.3.0

- il Centro AI offre report complessivo, confronto quotidiano e confronto settimanale del sabato anche per il patrimonio opzionale.
- i payload usano esclusivamente gli snapshot patrimoniali e non sostituiscono valori mancanti con zero.
- patrimonio Fineco, portafoglio consolidato, liquidita, saldi, fido utilizzato e credito disponibile restano semanticamente separati.
- il modulo Trimestrali e ritirato dalla navigazione e le URL storiche reindirizzano al Centro AI; dati e migrazioni restano intatti.

## Correzione report patrimonio 2.3.1

- anche i form Patrimonio sono collegati alla finestra di avanzamento AI e ignorano invii ripetuti mentre una richiesta e in corso.
- dall'archivio AI ogni singolo report puo essere eliminato dopo una conferma esplicita protetta da CSRF; snapshot e dati conto non vengono coinvolti.
- i delta usati nei report sono calcolati sulle stesse cifre arrotondate mostrate al lettore, cosi ogni sottrazione coincide con la tabella.
- prompt e validatori impediscono nessi causali e descrizioni temporali non supportate da due soli snapshot.

## Regola operativa ufficiale snapshot

Regola corrente approvata:
- 1 snapshot al giorno
- caricamento normalmente effettuato dopo le 07:30 del mattino

Motivazione operativa:
- su Fineco dopo le 07:30 risultano aggiornati anche i NAV dei fondi PAC

Implicazione pratica:
- se viene ricaricato un file con la stessa data, lo snapshot del giorno viene aggiornato/sovrascritto logicamente invece di crearne uno nuovo

## Configurazione nota

Config locale presente in:
- `config/config.php`
- `config/config.local.php` opzionale, ignorato da Git

Impostazioni applicative confermate:
- `base_path = /apps/portafoglio`
- `default_currency = EUR`

Nota operativa:
- le credenziali DB reali non devono stare in `config/config.php`

## Dipendenze runtime e requisiti

Requisiti PHP rilevanti:
- PDO MySQL
- mbstring
- SimpleXML
- ZipArchive
- sessioni PHP
- Composer vendor con PhpSpreadsheet richiesto per import `.xls` e conversione `.xlsx`

Dipendenze esterne runtime:
- Chart.js servito da `assets/vendor/`
- Google Fonts
- feed ECB per EUR/USD
- feed Google News RSS, disattivabile da config

Cartelle con scrittura richiesta:
- `storage/uploads`
- `storage/ai_reports` per l'archivio privato dei report AI
- `storage/` per il registro dei tentativi PIN
- `storage/` per cache news e FX

Regola di deploy:
- i pacchetti FTP non devono contenere o ricreare `storage/uploads` e `storage/ai_reports`; sul server queste cartelle non vanno mai eliminate o sincronizzate in modo distruttivo, per conservare gli upload e l'archivio report.

## Stato tecnico rilevato

Valutazione sintetica:
- progetto piccolo ma solido
- struttura chiara
- codice sintatticamente valido
- modello dati coerente con il dominio
- adatto a hosting condiviso Aruba

Verifiche gia eseguite in questo thread:
- lettura completa della struttura progetto
- lettura di pagine, librerie e schema SQL
- verifica config presente
- lint PHP su tutti i file PHP: esito pulito

Hardening leggero applicato nel thread:
- CSRF su upload e cancellazione snapshot
- validazione upload Excel `.xls`/`.xlsx` rafforzata
- header HTTP di hardening lato PHP
- cookie di sessione impostato con `HttpOnly` e `SameSite=Lax`
- footer runtime pubblico minimizzato per default
- protezione accesso web a `storage/` e directory interne sensibili
- configurazione app aggiornata con target PHP 8.4

## Vincoli e decisioni del thread

Decisioni esplicite gia fissate:
- non serve introdurre autenticazione utente
- il progetto e considerato attivo e funzionante
- il deploy resta orientato ad Aruba + FTP

Implicazione pratica:
- eventuali attivita di "messa in sicurezza" non devono assumere login, utenti, ruoli o area privata, salvo richiesta futura esplicita

## Messa in sicurezza: cosa significa qui

In questo progetto "messa in sicurezza" non significa necessariamente autenticazione.

Nel contesto attuale significa soprattutto hardening non invasivo, ad esempio:
- ridurre esposizione di dettagli ambiente nel footer
- proteggere meglio file caricati e cartelle sensibili
- evitare esposizione inutile di informazioni tecniche o credenziali
- migliorare alcuni controlli sugli upload
- pulire piccoli problemi di encoding o robustezza

Autenticazione:
- esclusa dalle priorita correnti
- da aggiungere solo se richiesta in futuro

## Rischi e attenzioni attuali

Rischi principali rilevati:
- le credenziali reali vanno tenute fuori da Git e da `config/config.php`
- il footer puo mostrare dettagli runtime se riattivato da config
- l'area upload salva file sotto web root
- alcune stringhe mostrano segni di encoding non perfetto
- la web app dipende in parte da servizi esterni live

Nota funzionale:
- la cancellazione snapshot ha protezione CSRF
- l'upload non usa un token CSRF dedicato

## Punti forti del progetto

- nessuna dipendenza Composer necessaria
- adatto a hosting condiviso
- import Fineco specializzato e concreto
- storico e confronto snapshot ben pensati
- grafici e UI gia maturi per uso reale
- separazione accettabile tra logica, layout e accesso dati
- classificazioni di diversificazione tracciabili a fonti e date, senza dipendenza da feed finanziari runtime

## Backlog consigliato

Priorita alta:
- mantenere le credenziali reali fuori da Git e dal file `config/config.php`

Priorita media:
- rifinire validazioni upload
- correggere stringhe con encoding imperfetto

Priorita bassa:
- valutare uso futuro di cashflow attesi/reali
- eventuale fallback locale per asset esterni CDN

## Convenzioni per questo thread

Quando si lavora in questo thread:
- considerare questo file come fonte master di contesto
- preservare compatibilita con Aruba Linux
- evitare interventi pesanti non richiesti
- privilegiare modifiche incrementali e verificabili
- non proporre autenticazione come default

## Prossimo uso consigliato

Questo documento va aggiornato quando cambiano:
- struttura progetto
- modalita di deploy
- decisioni architetturali
- priorita del backlog
- vincoli operativi del thread
