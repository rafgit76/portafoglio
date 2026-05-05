# Portfolio App (Aruba PHP/MySQL, PHP 8.4 ready)

App PHP 8.4 ready per Aruba Linux, pensata per importare l'export Fineco (`.xlsx`) e visualizzare lo storico del portafoglio.

## Deploy (FTP)

Carica tutto in:

`www.raffimac.it/apps/portafoglio`

## Setup rapido

1. Esegui `composer install --no-dev --optimize-autoloader` in locale e carica anche `vendor/`.
2. Importa `sql/schema.sql` nel tuo DB Aruba.
3. Copia `config/config.local.sample.php` in `config/config.local.php` e inserisci le credenziali DB reali.
4. Verifica permessi scrittura cartella `storage/uploads`.
5. Vai su `/apps/portafoglio/upload.php` e carica il primo file Fineco.

`config/config.php` non contiene segreti: legge variabili ambiente `PORTFOLIO_DB_*` oppure il file locale `config/config.local.php`, escluso da Git.

## Hardening leggero incluso

- protezione CSRF su upload e cancellazione snapshot
- validazione upload XLSX piu rigorosa
- parser Fineco con detection dinamica intestazioni e supporto PhpSpreadsheet
- cartella `storage/` non esposta pubblicamente
- footer runtime pubblico ridotto al minimo per default
- configurazione applicativa esplicita per target PHP 8.4

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
