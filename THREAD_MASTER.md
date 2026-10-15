# [PROGETTO RIUSCITTO] Portafoglio Snapshot WEB APP

## Stato del thread

Questo file e il documento master ufficiale del thread dedicato alla web app `Portafoglio Snapshot WEB APP`.

Versione corrente:
- `2.8.1`

Linee registrate v2.8.1 (15/10/2026):
- utilizzato e disponibile sono letti dall ultimo snapshot patrimonio tramite account_tracker_registered_credit_lines; campi mancanti in fallback ai valori registrati;
- conservati dati contrattuali, accordato e form manuale; la lettura corrente non scrive su DB o storico e non usa i saldi per dedurre il credito;
- utilizzato esplicito prioritario, altrimenti accordato meno disponibile dello snapshot se entrambi presenti; fonte/data mostrate nella tabella;
- fido aggregato attribuito solo a una linea Fineco attiva univoca, compatibile con l accordato e con la data di attivazione; piu linee o accordati incompatibili mantengono dati registrati;
- test sintetico dedicato al caso accordato 12000, utilizzato 3500, disponibile 8500 e alle condizioni di fallback: 31 controlli superati, piu 109 controlli conto, 44 export patrimonio e verifica tabella desktop/mobile; nessuna migrazione;
- pubblicata su autorizzazione dell utente il 15/10/2026: sei file aggiornati e verificati byte per byte, VERSION 2.8.1 e home raggiungibile; esito verified nel run locale 20261015-082306-f2726715;
- l utente ha verificato la sezione protetta da PIN: utilizzato e disponibile correnti, fonte e data dello snapshot visibili, accordato contrattuale e stato operativo conservati. Confermata anche la quadratura accordato = utilizzato + disponibile.

Export patrimonio v2.8.0:
- account_history.php aggiunge Excel e vista Stampa PDF sullo storico completo, senza modificare query, snapshot o migrazioni;
- lib/account_history_export.php riutilizza le rettifiche gia presenti, fornisce riepiloghi con pari merito e workbook numerici su tre fogli;
- partials/account_history_print.php e assets/account-history-export.css/js: PDF via stampa browser, A4 blu/giallo, netto prioritario, saldi/credito separati, grafici locali e tabelle paginate;
- export dietro il bootstrap/PIN esistente; nessuna chiamata AI, nessuna scrittura sul DB, nessuna nuova dipendenza;
- test sintetici di formule, null, vecchi payload, pari merito, XLSX riletto, PDF multipagina e viewport desktop/mobile superati;
- pubblicata il 03/09/2026 tramite Pubblica Portafoglio.cmd: nove file verificati byte per byte e pagina iniziale raggiungibile, come da esito condiviso dall utente;
- verificati gli export reali: PDF A4 di quattro pagine e XLSX di tre fogli, 13 snapshot dal 20/08/2026 al 03/09/2026; valori coincidenti al centesimo, rettifiche, estremi e impaginazione coerenti. Nessuna modifica ai file esportati o al database.

Archivio AI v2.7.5:
- conferma eliminazione in un unico dialog nativo fuori dalla tabella, con report e data identificabili, Annulla ed Esc;
- fallback senza JavaScript nella riga, senza riquadri assoluti sovrapposti;
- POST, CSRF, controllo PIN, archivio e generazione invariati; nessuna migrazione.

Complessivo portafoglio v2.7.4:
- autorizzato dall utente Low solo per `general` con Gemini 3.8 Flash; gli altri report restano Medium, limite per tentativo 20000 invariato;
- `general_thinking_level` configurabile, livello effettivo visibile e archiviato; confronto 3.7 Medium / 3.8 Low da etichettare come configurazioni diverse;
- errori MAX_TOKENS espongono i consumi disponibili anche se il testo e vuoto, senza nuovi tentativi automatici e senza archiviare report incompleti;
- nessuna generazione a pagamento eseguita dai test locali; l utente ha verificato sul server il complessivo 3.8 Low del 02/09/2026: report completo, due tentativi e costo stimato USD 0,05412. Il confronto con il 3.7 Medium (un tentativo, USD 0,07240) riguarda questa singola prova e non include le precedenti generazioni fallite.

Correzione report v2.7.3:
- costo null non piu convertito in zero; ricostruzione in lettura dai token se il modello e noto, nessuna riscrittura dell archivio;
- report complessivo troppo lungo: solo il prompt correttivo cambia, con conteggio e budget per sezione; primo prompt, medium, validazione e massimo due tentativi invariati;
- casi reali 02/09/2026, Gemini 3.8: giornaliero 7500 input / 2203 risposta / 3347 ragionamento = USD 0,0264375; patrimonio 5621 / 1321 / 4845 = USD 0,02733825;
- nessuna migrazione; aggiornare insieme libreria AI, pagina archivio e vista report. Dopo FTP l utente ha verificato la generazione di giornaliero e complessivo patrimonio con costo non nullo.

Upgrade Gemini v2.7.2:
- modello predefinito `gemini-3.8-flash`; prompt, versione dei prompt, thinking `medium` e limiti invariati per il confronto con il 3.7;
- stima input USD 0,75/M e output piu ragionamento USD 3,75/M fino al 31/12/2026; aggiornare il listino alla scadenza;
- configurazioni private con override `gemini.model` richiedono l aggiornamento esplicito di quel valore;
- nessuna migrazione, nessuna modifica a dati finanziari o report esistenti; generazione reale e confronto qualitativo a cura dell utente dopo il caricamento FTP.

Controllo rapporto Credit Lombard v2.7.1:
- il valore patrimoniale del dossier viene confrontato con valore di mercato piu rateo dello snapshot Lombard della stessa data;
- mercato, rateo e totale confrontato restano distinti nell archivio dei controlli;
- caso reale 22/08/2026: EUR 19.644,00 + EUR 95,20 = EUR 19.739,20;
- migrazione incrementale richiesta: `sql/migration_2_7_1_lombard_accrued_interest.sql`, poi reimport del dossier Lombard per valorizzare il rateo.

Controllo rapporto Credit Lombard v2.7.0:
- nella Gestione fidi e disponibile un upload separato dell export Patrimonio del solo rapporto Lombard;
- il parser rileva data e identificativo del dossier a pegno, rifiutando patrimoni complessivi o rapporti diversi;
- il valore del dossier viene confrontato al centesimo con il portafoglio Credit Lombard della stessa data;
- l archivio `pf_credit_lombard_checks` conserva valori ed esito, non il file, e non modifica snapshot, posizioni, P/L, patrimonio o credito;
- migrazione incrementale richiesta: `sql/migration_2_7_0_credit_lombard_checks.sql` dopo backup database.

Profilo ufficiale Credit Lombard v2.6.0, correzione anteprima import v2.6.1 e deduplicazione trasferimenti v2.6.2:
- `pf_credit_lines.credit_type` resta il tipo canonico della linea e `agreed_amount` resta l importo accordato, evitando colonne duplicate;
- conto ordinario collegato, conto a pegno e dossier a garanzia hanno identificativi separati; conto e dossier possono condividere il numero Fineco ma restano entita logiche differenti;
- validita, attivazione, provenienza `fineco_acceptance_letter` e documento sorgente sono salvati nell anagrafica della linea;
- la migrazione `sql/migration_2_6_0_credit_lombard_profile.sql` non modifica snapshot, posizioni, P/L o consolidamento.

Correzione compatibilita Aruba v2.5.3:
- la conferma o il rifiuto di un trasferimento interno usa placeholder SQL distinti, evitando l errore PDO `HY093` con prepared statement nativi;
- l operazione cambia soltanto lo stato della proposta e non altera posizioni o snapshot.

Decisione sicurezza import multi-dossier v2.5.2:
- ogni import di portafoglio passa prima da un anteprima server-side con numero posizioni, carico e mercato;
- il parser cerca metadati di dossier nelle proprieta del file e nelle prime righe del foglio;
- metadati affidabili incompatibili bloccano l import; assenza di metadati richiede una conferma manuale e non viene mai risolta dal filename.

Decisione import multi-dossier v2.5.1:
- Fineco puo denominare sia l export ordinario sia quello a garanzia `portafoglio-export.xls`: l app non usa il filename per decidere il dossier.
- In Upload esistono gli slot distinti Portafoglio principale e Dossier Credit Lombard; entrambi usano il medesimo parser e alimentano lo stesso snapshot in dossier separati.
- Il consolidamento continua a essere la somma delle posizioni dei dossier. Per il BTP trasferito il caso di riferimento e `118.291,56 + 20.000,00 = 138.291,56` di carico consolidato, senza vendita o P/L realizzato.
- Nessuna migrazione aggiuntiva: schema, backup logico e gestione del giro interno documentato v2.5.0 restano invariati.

Decisione Credit Lombard v2.5.0:
- Il file Patrimonio Fineco post-attivazione Credit Lombard e opzionale e puo riportare un solo fido aggregato: l'import legge il valore effettivamente presente, senza hardcodare Minifido o Lombard.
- `GIRO INT PAR INT` e un trasferimento interno documentato, mai vendita o P/L realizzato. La correzione viene proposta in Dossier, richiede conferma e genera un backup logico per ogni snapshot modificato.
- Il costo e il PMC provengono dall'ultima posizione del dossier origine; il valore mercato viene applicato solo se l'export Patrimonio dello stesso giorno ha un controvalore del dossier a pegno verificabile.
- Migrazione incrementale richiesta: `sql/migration_2_5_0_documented_internal_transfers.sql` dopo backup database. Nessun OCR PDF runtime su Aruba.

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

## Semantica Credit Lombard 2.4.0

- Il file Patrimonio Fineco del 21/08/2026 conferma che Fineco include il credito disponibile sia in Totale liquidita sia in Patrimonio totale.
- Il dato riportato da Fineco resta conservato come `Patrimonio Fineco`; la metrica primaria di ricchezza propria e `Patrimonio netto rettificato = Patrimonio Fineco - fido disponibile` quando entrambi i dati esistono.
- La liquidita viene esposta sia come valore Fineco sia come `Liquidita propria rettificata = Totale liquidita Fineco - fido disponibile`.
- Portafoglio totale Fineco, liquidita prenotata e fidi vengono memorizzati separatamente; i dossier, incluso quello a pegno, restano componenti del portafoglio e non credito.
- Migrazione richiesta: dopo un export completo del DB, importare una sola volta `sql/migration_2_4_0_credit_lombard_semantics.sql`. Nessuno snapshot storico viene aggiornato automaticamente.

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
- aggiornare la dashboard iniziale con un riepilogo progressivo del patrimonio: Patrimonio Fineco, patrimonio netto rettificato, liquidita propria, saldi e fidi, sempre separati dai dati del portafoglio e mostrati soltanto quando disponibili
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

Pubblicazione locale assistita (03/09/2026):
- `Configura Pubblicazione.cmd`, `Anteprima Pubblicazione.cmd`, `Pubblica Portafoglio.cmd`; guida in `deploy/README.md`.
- WinSCP FTPS esplicito, certificato verificato, credenziali DPAPI in LocalAppData fuori dal repository; nessun segreto nei comandi o nel pacchetto.
- host TLS `ftplnx02.aruba.it`: verificato il 03/09/2026 sugli stessi IP di `ftp.raffimac.it`; percorso `/www.raffimac.it/apps/portafoglio`. Ricontrollare in caso di cambio infrastruttura Aruba.
- upload solo dopo anteprima e conferma `PUBBLICA`, allowlist codice, backup reale prima della sostituzione, staging e rilettura SHA-256, VERSION ultima, controllo HTTPS della home.
- storage/config privato/SQL esclusi; niente cancellazioni o rollback automatici. Migrazioni manuali con verifica esplicita, mai eseguite dal publisher.
- strumenti locali soltanto: nessuna migrazione o incremento della versione web per questa funzione.
- verificati test unitari e FTPS loopback con dati fittizi; configurazione utente completata e accesso Aruba autenticato verificato in sola lettura il 03/09/2026. L utente ha poi confermato e completato la prima pubblicazione della 2.7.5 e quella della 2.8.0.
- corretta la disconnessione in anteprima: eliminate richieste metadati duplicate, aggiunti tentativi limitati alle letture e avanzamento per file. Anteprima iniziale su 42 file; le 30 differenze osservate erano esclusivamente CRLF/LF. Entrambe le pubblicazioni sono terminate con verifica dei file e raggiungibilita della home; controlli protetti dal PIN eseguiti separatamente dall utente.

Questo documento va aggiornato quando cambiano:
- struttura progetto
- modalita di deploy
- decisioni architetturali
- priorita del backlog
- vincoli operativi del thread
