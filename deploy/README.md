# Pubblicazione assistita Portafoglio

Strumento locale Windows per aggiornare un'installazione esistente. Non va caricato sul sito. La versione web rimane 2.7.5; nessuna nuova migrazione.

## Prima volta

1. Apri `Configura Pubblicazione.cmd` nella cartella del progetto.
2. Controlla i valori proposti e premi Invio per mantenerli. Scrivi `CONFIGURA` per bloccare la destinazione.
3. Inserisci utente e password Aruba direttamente nello stesso terminale. La password viene letta come SecureString con caratteri nascosti: non si apre una finestra separata. Non inviarli in chat.
4. Se hai gia verificato backup e migrazioni dell'attuale installazione, scrivi `SQL VERIFICATO`. Altrimenti lascia vuoto: potrai usare `Verifica Migrazioni.cmd` dopo la verifica manuale. Non reimportare schema/seed o migrazioni gia eseguite.
5. Apri `Anteprima Pubblicazione.cmd`: controlla accesso e differenze scaricando i file del server, senza caricare o cancellare nulla.

Destinazione predefinita: FTPS esplicito, porta 21, host `ftplnx02.aruba.it`, cartella `/www.raffimac.it/apps/portafoglio`, sito `https://www.raffimac.it/apps/portafoglio`.

Il 03/09/2026 `ftp.raffimac.it` e `ftplnx02.aruba.it` risolvevano agli stessi indirizzi, ma il certificato Aruba copriva soltanto il secondo nome. La connessione a quest'ultimo ha superato la verifica TLS senza autenticazione. Non viene accettato qualsiasi certificato e non e previsto fallback su FTP in chiaro. Se Aruba cambia infrastruttura, verificare il nuovo host prima di riconfigurare.

## Aggiornamenti successivi

Apri **Pubblica Portafoglio.cmd**. Il programma:

1. Prepara una copia locale immutabile del codice e verifica sintassi PHP e coerenza della versione.
2. Verifica host/cartella e la presenza di un'installazione esistente.
3. Confronta SHA-256 con i file effettivi del server, anche se erano stati caricati con FileZilla.
4. Mostra l'elenco dei soli file nuovi o diversi, la destinazione e la cartella dei backup.
5. Attende `PUBBLICA`. Invio o qualunque altro testo annullano senza upload.
6. Ricontrolla che pacchetto, backup e file remoti non siano cambiati. Carica file temporanei e li riscarica per verificarli.
7. Attiva i file tramite rinomina, con `VERSION` per ultima, e verifica di nuovo il contenuto trasferito.
8. Controlla via HTTPS versione e pagina iniziale. Le funzioni protette dal PIN restano da verificare nell'app.

Durante il confronto compare il numero del file in corso. I metadati vengono controllati senza ripetere lo stesso stat per esistenza, tipo e download. Un file viene considerato assente solo dopo un elenco di cartella riuscito: errori di accesso o rete non diventano falsi file nuovi. Ogni operazione rilegge i metadati, senza cache tra confronti, per rilevare aggiunte e cambi di link concorrenti.

Le sole letture possono essere ritentate fino a tre volte in caso di disconnessione, con attese di 2 e 4 secondi e la stessa configurazione TLS. Il wrapper non ripete automaticamente upload, creazione cartelle o rinomine. Un errore prima degli upload indica esplicitamente che il server non e stato modificato.

Nessuna pubblicazione parte automaticamente quando si modifica il codice. Non vengono generati report AI o effettuate chiamate Gemini.

## Esclusioni e database

- `storage/`, report, upload, `config/config.local*`, `.env*`, SQL, test, Git, script e profili locali sono esclusi dal publisher.
- Solo i file runtime ammessi da `Test-PublishPath` vengono trasferiti. Aggiungendo una nuova pagina PHP di primo livello bisogna aggiornare anche questa allowlist; non si pubblicano PHP arbitrari.
- `vendor/` non viene trasferita normalmente. I metadati Composer devono coincidere; se cambiano, l'operazione si blocca. Dopo verifica tecnica usare `publish.ps1 -IncludeDependencies` per includere esplicitamente le dipendenze.
- Nessun file remoto viene cancellato, neppure se assente localmente. I file obsoleti richiedono una rimozione separata, valutata esplicitamente.
- Non si modifica il database e non si crea un backup SQL. Se cambiano i file SQL, la pubblicazione viene bloccata fino alla verifica manuale con `Verifica Migrazioni.cmd`.
- `SQL VERIFICATO` registra soltanto una dichiarazione locale dell'utente; non certifica automaticamente lo schema Aruba.

## Credenziali e backup

Profilo, password cifrata da Windows DPAPI, WinSCP e registro sono in `%LOCALAPPDATA%\PortafoglioDeploy\<identificativo-progetto>`, fuori da Git e dai pacchetti FTP. La cartella e limitata all'utente corrente, amministratori e sistema. La password DPAPI e legata a questo utente Windows e a questo PC: su un altro PC riconfigurare.

Ogni esecuzione crea `runs\<data-id>`:

| Percorso | Contenuto |
| --- | --- |
| `package/` | Codice locale congelato prima dell'upload |
| `before/` | Copia effettiva dei file remoti letti, inclusi quelli da sostituire |
| `plan.json` | Destinazione, hash, file nuovi e aggiornati |
| `journal.json` | Stato di ogni file durante staging e attivazione |
| `result.json` | Esito finale quando si pubblica o si verifica un errore |

Questi backup non comprendono database, storage o configurazione privata e non sostituiscono un backup completo. Non vengono eliminati automaticamente: occupano spazio locale. Non pubblicarli sul server.

## Interruzioni e recupero

FTPS non rende atomico l'aggiornamento di tutta l'app: evita di usarla durante la breve fase di pubblicazione. Se fallisce lo staging, nessun file finale viene attivato. Se fallisce una rinomina, il sito puo essere parzialmente aggiornato: il programma segnala errore e conserva backup/registro; non fa un rollback automatico e non dichiara successo. Il server deve supportare la rinomina con sostituzione del file esistente, come i filesystem POSIX; in caso contrario si ferma senza ripiegare su cancellazioni.

Per recuperare, individua l'esecuzione indicata nel messaggio, esamina `journal.json` e confronta i file correnti. Ripristina soltanto i file interessati da `before/`, oppure completa una nuova pubblicazione verificata. I file nuovi non hanno una versione precedente. Una rimozione o un ripristino vanno decisi esplicitamente, soprattutto in presenza di modifiche concorrenti. Non trascinare l'intera cartella dei backup sul sito.

Possono restare file temporanei `.deploy-*` dopo un errore; non vengono cancellati automaticamente. L'attuale `.htaccess` ne impedisce l'accesso web. Un errore del controllo HTTPS non significa necessariamente che l'upload sia fallito: consultare il registro prima di ripetere.

## Requisiti e test

I launcher usano Windows PowerShell 5.1, senza privilegi amministrativi, con `RemoteSigned` limitato al processo. Non modificano permanentemente le policy Windows. PHP e configurabile; su questo PC si trova in `C:\php84\php.exe`.

WinSCP Automation 6.5.6 viene installato fuori dal repository dal sito ufficiale, verificando lo SHA-256 della distribuzione. Non vengono salvati log di sessione contenenti credenziali. La configurazione SFTP alternativa richiede una fingerprint SSH verificata.

Test senza credenziali reali, da Windows PowerShell:

```powershell
.\tests\deploy_test.ps1
.\tests\publish_entry_test.ps1
.\tests\deploy_adapter_test.ps1 -ToolsPath <cartella-WinSCP>
.\publish.ps1 -Mode LocalCheck
php tests/ai_report_model_test.php
php tests/account_tracker_logic_test.php
```

`publish_entry_test.ps1 -LiveWebsiteCheck` aggiunge esclusivamente GET HTTPS pubbliche a home e VERSION, senza login FTP o PIN.

Il test dell'adapter simula disconnessioni, download parziali, permessi negati, file assenti, modifiche concorrenti e link; verifica anche il limite dei tentativi e che upload/rinomine non vengano ripetuti dal wrapper.

`tests/deploy_ftps_test.py --winscp <cartella-WinSCP>` avvia un server TLS esclusivamente su `127.0.0.1` e usa un certificato temporaneo con fingerprint esatta, senza aggiungerlo ai certificati Windows. Richiede Python, `pyftpdlib==2.0.1` e `pyOpenSSL==25.1.0`; dipendenze di test esterne all'app. Simula la rinomina POSIX su Windows. Verifica il vero adapter WinSCP per file nuovi/esistenti, backup, hash e preservazione di storage/config privato. Non autentica o modifica Aruba.

Riferimenti: [WinSCP PowerShell](https://winscp.net/eng/docs/library_powershell), [credenziali DPAPI](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.utility/export-clixml), [configurazione FTP/FTPS Aruba](https://guide.aruba.it/hosting-e-domini/hosting/gestione-strumenti-hosting/pubblicazione-gestione-sito/configurazione-client-ftp-pubblicazione-ftp-ftps).
