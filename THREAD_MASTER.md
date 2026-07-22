# [PROGETTO RIUSCITTO] Portafoglio Snapshot WEB APP

## Stato del thread

Questo file e il documento master ufficiale del thread dedicato alla web app `Portafoglio Snapshot WEB APP`.

Versione corrente:
- `1.5.4`

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
- anagrafica e composizione strumenti
- dati accessori live come EUR/USD e news

## Architettura applicativa

### Entrypoint principali

- `index.php`: dashboard principale
- `upload.php`: upload file Fineco e creazione snapshot
- `snapshots.php`: storico snapshot con eliminazione
- `snapshot.php`: dettaglio di un singolo snapshot
- `compare.php`: confronto snapshot e export CSV
- `assets.php`: anagrafica strumenti e composizione portafoglio
- `asset.php`: dettaglio storico del singolo titolo
- `ai_report.php`: Centro AI con generazione esplicita, report personalizzati e archivio
- `ai_report_view.php`: dettaglio e stampa dei report AI archiviati
- `changelog.php`: storico versioni applicative

### Bootstrap e librerie

- `bootstrap.php`: carica helper, DB e sessione
- `lib/helpers.php`: config, escaping, redirect, flash, parsing numerico
- `lib/db.php`: connessione PDO MySQL
- `lib/fineco_importer.php`: import principale Fineco
- `lib/fineco_xlsx_parser.php`: parser Fineco `.xls`/`.xlsx`, conversione e detection dinamica intestazioni
- `lib/xlsx_reader.php`: fallback XLSX custom senza Composer
- `lib/fx.php`: recupero e cache EUR/USD da ECB
- `lib/news.php`: recupero e ranking news con cache locale

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
- in caso di reimport sulla stessa data, lo snapshot viene riusato e le posizioni esistenti di quello snapshot vengono rigenerate

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
- `storage/` per cache news e FX

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
