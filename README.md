# Portfolio App (Aruba PHP/MySQL, PHP 8.4 ready)

App PHP 8.4 ready per Aruba Linux, pensata per importare l'export Fineco (`.xlsx`) e visualizzare lo storico del portafoglio.

Versione corrente: `1.3.0`

## Deploy (FTP)

Carica tutto in:

`www.raffimac.it/apps/portafoglio`

## Setup rapido

1. Esegui `composer install --no-dev --optimize-autoloader` in locale e carica anche `vendor/`.
2. Importa `sql/schema.sql` nel tuo DB Aruba.
3. Copia `config/config.local.sample.php` in `config/config.local.php` e inserisci le credenziali DB reali.
4. Verifica permessi scrittura cartella `storage/uploads`.
5. Vai su `/apps/portafoglio/upload.php` e carica il primo file Fineco.

Per creare una cartella FTP completa e pulita puoi eseguire:

`.\build_deploy.ps1`

Lo script crea una cartella `portafoglio_ftp_YYYYMMDD_HHMM` accanto al progetto, includendo `vendor/` e la configurazione locale se presente, ma lasciando fuori `.git`, file di sviluppo, cache e upload storici.

`config/config.php` non contiene segreti: legge variabili ambiente `PORTFOLIO_DB_*` oppure il file locale `config/config.local.php`, escluso da Git.

## Hardening leggero incluso

- protezione CSRF su upload e cancellazione snapshot
- validazione upload XLSX piu rigorosa
- parser Fineco con detection dinamica intestazioni e supporto PhpSpreadsheet
- cartella `storage/` non esposta pubblicamente
- footer runtime pubblico ridotto al minimo per default
- versione app e changelog visibili dal footer
- configurazione applicativa esplicita per target PHP 8.4
- libreria Chart.js servita localmente da `assets/vendor/` senza dipendenza CDN runtime

## Opzioni esterne

In `config/config.local.php` puoi regolare:

- `enable_fx`: abilita/disabilita EUR/USD da ECB.
- `enable_news`: abilita/disabilita le news RSS in dashboard.
- `external_fetch_timeout_seconds`: timeout delle chiamate esterne.

## Pagine

- `index.php`: dashboard
- `upload.php`: upload file Fineco
- `snapshots.php`: storico snapshot
- `snapshot.php?id=...`: dettaglio snapshot
- `assets.php`: anagrafica strumenti
- `asset.php?id=...`: dettaglio storico di un singolo titolo
- `ai_report.php`: report AI sullo storico snapshot
- `changelog.php`: storico versioni applicative

## Report AI

La pagina `Report AI` usa Gemini lato server per generare un report sullo storico degli snapshot.
Configura la chiave solo in `config/config.local.php` o tramite `PORTFOLIO_GEMINI_API_KEY`.
Il modello predefinito e' `gemini-3.1-flash-lite`, configurabile con `PORTFOLIO_GEMINI_MODEL`.
Il report viene salvato in cache in `storage/ai_report_cache.json` e rigenerato quando cambiano gli snapshot o quando premi “Rigenera report”.

## Versioning

La versione corrente e' definita in `config/config.php` tramite `app_version` ed e' riportata anche nel file `VERSION`.
Il changelog applicativo e' in `config/changelog.php`.
Ogni modifica significativa deve aggiornare sia il numero di versione sia il changelog.
