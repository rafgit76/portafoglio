[CmdletBinding()]
param(
    [ValidateSet('Publish', 'Preview', 'Configure', 'AcknowledgeMigrations', 'LocalCheck')][string] $Mode = 'Publish',
    [switch] $IncludeDependencies
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'deploy\Deploy.Common.ps1')
. (Join-Path $PSScriptRoot 'deploy\WinSCP.Adapter.ps1')

function Initialize-PrivateDirectory {
    param([string] $Path)
    [IO.Directory]::CreateDirectory($Path) | Out-Null
    if ((Get-Item -LiteralPath $Path).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Cartella privata non puo essere un link.' }
    $directory = New-Object IO.DirectoryInfo($Path)
    $acl = $directory.GetAccessControl([Security.AccessControl.AccessControlSections]::Access)
    $acl.SetAccessRuleProtection($true, $false)
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    foreach ($existing in @($acl.Access)) { $acl.RemoveAccessRuleSpecific($existing) }
    foreach ($identity in @($sid, (New-Object Security.Principal.SecurityIdentifier('S-1-5-18')), (New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')))) {
        $rule = New-Object Security.AccessControl.FileSystemAccessRule($identity, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
        $acl.AddAccessRule($rule)
    }
    # Change only the DACL, not audit settings that require SeSecurityPrivilege.
    $directory.SetAccessControl($acl)
}

function Read-Setting {
    param([string] $Label, [string] $Default = '')
    $answer = Read-Host "$Label $(if ($Default) { "[$Default]" })"
    if ([string]::IsNullOrWhiteSpace($answer)) { return $Default }
    return $answer.Trim()
}

function Read-DeployCredential {
    $userName = Read-Host 'Utente FTP Aruba (lo stesso di FileZilla)'
    if ([string]::IsNullOrWhiteSpace($userName)) { throw 'Utente FTP mancante. Configurazione non salvata.' }
    Write-Host 'Inserisci la password nel terminale: i caratteri restano nascosti.'
    $password = Read-Host 'Password FTP Aruba' -AsSecureString
    if ($null -eq $password -or $password.Length -eq 0) { throw 'Password FTP mancante. Configurazione non salvata.' }
    return New-Object Management.Automation.PSCredential($userName.Trim(), $password)
}

function Test-PublishPackage {
    param([string] $Php, [string] $PackageRoot, [array] $Files)
    if (-not (Test-Path -LiteralPath $Php -PathType Leaf)) { throw 'PHP non trovato. Aggiorna la configurazione locale.' }
    foreach ($file in $Files | Where-Object { $_.Path.EndsWith('.php') }) {
        $output = & $Php -l (Get-ContainedPath $PackageRoot $file.Path) 2>&1
        if ($LASTEXITCODE -ne 0) { throw "Errore sintattico PHP in $($file.Path). Pubblicazione bloccata." }
    }
    $version = [IO.File]::ReadAllText((Join-Path $PackageRoot 'VERSION')).Trim()
    if ($version -notmatch '^\d+\.\d+\.\d+$') { throw 'VERSION non valida.' }
    $configText = [IO.File]::ReadAllText((Join-Path $PackageRoot 'config\config.php'))
    if ($configText -notmatch ("'app_version'\s*=>\s*'" + [regex]::Escape($version) + "'")) { throw 'Versione config e VERSION non coerenti.' }
    return $version
}

function Test-PublishedWebsite {
    param([string] $Url, [string] $Version)
    $nonce = [Guid]::NewGuid().ToString('N')
    $base = $Url.TrimEnd('/')
    $versionResponse = Invoke-WebRequest -Uri "$base/VERSION?deploy=$nonce" -UseBasicParsing -TimeoutSec 30 -MaximumRedirection 0
    $versionText = if ($versionResponse.Content -is [byte[]]) { [Text.Encoding]::UTF8.GetString($versionResponse.Content) } else { [string] $versionResponse.Content }
    if ($versionResponse.StatusCode -ne 200 -or $versionText.Trim() -ne $Version) { throw 'VERSION pubblica non corrisponde al pacchetto.' }
    $page = Invoke-WebRequest -Uri "$base/index.php?deploy=$nonce" -UseBasicParsing -TimeoutSec 30 -MaximumRedirection 0
    if ($page.StatusCode -ne 200 -or $page.Content -notmatch 'Portfolio Investimenti' -or $page.Content -notmatch [regex]::Escape($Version)) {
        throw 'La pagina iniziale non conferma app e versione attese.'
    }
}

$remote = $null
$lock = $null
$run = $null
$remoteWritesPossible = $false
try {
    if ($env:OS -ne 'Windows_NT' -or $PSVersionTable.PSEdition -ne 'Desktop') { throw 'Avvia con Windows PowerShell 5.1 o con Pubblica Portafoglio.cmd.' }
    $project = [IO.Path]::GetFullPath($PSScriptRoot)
    $sha = [Security.Cryptography.SHA256]::Create()
    try { $projectKey = ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($project.ToLowerInvariant())))).Replace('-', '').Substring(0, 16) } finally { $sha.Dispose() }
    $privateRoot = Get-ContainedPath $env:LOCALAPPDATA ('PortafoglioDeploy/' + $projectKey)
    Initialize-PrivateDirectory $privateRoot
    $profilePath = Join-Path $privateRoot 'profile.json'
    $credentialPath = Join-Path $privateRoot 'credential.clixml'
    $toolsPath = Join-Path $privateRoot 'tools\WinSCP'
    $lock = [IO.File]::Open((Join-Path $privateRoot 'publish.lock'), [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)

    if ($Mode -eq 'Configure' -or (-not (Test-Path -LiteralPath $profilePath) -and $Mode -ne 'LocalCheck')) {
        Write-Host 'Configurazione locale. Password protetta da Windows, non salvata nel progetto.'
        Write-Host 'Host TLS Aruba verificato: ftplnx02.aruba.it (server di ftp.raffimac.it).'
        $config = [pscustomobject]@{
            ProjectRoot = $project
            HostName = Read-Setting 'Host' 'ftplnx02.aruba.it'
            Port = 21
            Protocol = Read-Setting 'Protocollo: Ftps oppure Sftp' 'Ftps'
            RemotePath = Read-Setting 'Percorso remoto assoluto della cartella portafoglio' '/www.raffimac.it/apps/portafoglio'
            PublicUrl = Read-Setting 'URL HTTPS dell app' 'https://www.raffimac.it/apps/portafoglio'
            SshHostKey = ''
            PhpPath = Read-Setting 'Eseguibile PHP locale' 'C:\php84\php.exe'
            SqlState = ''
        }
        $config.Port = [int] (Read-Setting 'Porta' $(if ($config.Protocol -eq 'Sftp') { '22' } else { '21' }))
        if ($config.Protocol -eq 'Sftp') { $config.SshHostKey = Read-Setting 'Fingerprint SSH verificata dal provider' }
        $config.RemotePath = $config.RemotePath.TrimEnd('/')
        $config.PublicUrl = $config.PublicUrl.TrimEnd('/')
        Assert-DeployTarget $config $project
        Write-Host "Destinazione da bloccare: $($config.HostName):$($config.Port)$($config.RemotePath)"
        if ((Read-Host 'Scrivi CONFIGURA per salvare questa destinazione') -cne 'CONFIGURA') { throw 'Configurazione annullata.' }
        $credential = Read-DeployCredential
        $credential | Export-Clixml -LiteralPath $credentialPath
        Write-Host 'Le migrazioni SQL restano manuali. Lo schema attuale deve essere gia aggiornato.'
        if ((Read-Host 'Se hai gia verificato backup e migrazioni correnti, scrivi SQL VERIFICATO; altrimenti premi Invio') -ceq 'SQL VERIFICATO') {
            $config.SqlState = Get-SqlState $project
        }
        Write-DeployJson $profilePath $config
        Write-Host 'Configurazione salvata. Nessun file remoto modificato.'
        if (-not $config.SqlState) { Write-Host 'Prima della pubblicazione apri Verifica Migrazioni.cmd dopo la verifica dello schema.' }
        Write-Host 'Passo successivo: Anteprima Pubblicazione.cmd (solo lettura del server).'
        return
    }

    if ($Mode -eq 'LocalCheck') {
        $run = Join-Path $privateRoot ('runs\local-' + [Guid]::NewGuid().ToString('N'))
        $files = @(New-PublishPackage $project $run -IncludeDependencies:$IncludeDependencies)
        $php = if (Test-Path -LiteralPath $profilePath) { (Get-Content -LiteralPath $profilePath -Raw | ConvertFrom-Json).PhpPath } else { 'C:\php84\php.exe' }
        $version = Test-PublishPackage $php (Join-Path $run 'package') $files
        Write-Host "Controlli locali OK: versione $version, $($files.Count) file. Nessuna connessione remota."
        Write-Host "Pacchetto: $run"
        return
    }
    $config = Get-Content -LiteralPath $profilePath -Raw | ConvertFrom-Json
    Assert-DeployTarget $config $project
    if ($Mode -eq 'AcknowledgeMigrations') {
        Write-Host 'Questo comando NON esegue SQL e NON crea un backup del database.'
        Write-Host 'Conferma solo dopo avere verificato backup e migrazioni richieste per questa versione.'
        Get-ChildItem -LiteralPath (Join-Path $project 'sql') -File -Filter '*.sql' | Select-Object -ExpandProperty Name | Out-Host
        if ((Read-Host 'Scrivi SQL VERIFICATO per registrare la verifica manuale') -cne 'SQL VERIFICATO') { throw 'Verifica SQL non confermata.' }
        $config.SqlState = Get-SqlState $project
        Write-DeployJson $profilePath $config
        Write-Host 'Stato SQL registrato solo sul PC.'
        return
    }
    if ($Mode -eq 'Publish' -and $config.SqlState -ne (Get-SqlState $project)) {
        throw 'Stato SQL non confermato o file SQL cambiati. Usa -Mode AcknowledgeMigrations dopo la verifica manuale. Nessun upload.'
    }
    if (-not (Test-Path -LiteralPath (Join-Path $toolsPath 'WinSCPnet.dll'))) {
        if ((Read-Host 'Scaricare WinSCP Automation dal sito ufficiale? Scrivi SI') -cne 'SI') { throw 'WinSCP non installato.' }
        & (Join-Path $PSScriptRoot 'deploy\Install-WinSCP.ps1') -Destination $toolsPath
    }
    $run = Join-Path $privateRoot ('runs\' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [Guid]::NewGuid().ToString('N').Substring(0, 8))
    $files = @(New-PublishPackage $project $run -IncludeDependencies:$IncludeDependencies)
    $version = Test-PublishPackage $config.PhpPath (Join-Path $run 'package') $files
    Write-Host "Controlli PHP e pacchetto OK. Versione: $version"
    $credential = Import-Clixml -LiteralPath $credentialPath
    $remote = New-DeployRemote $config $credential $toolsPath
    foreach ($marker in @('VERSION', 'bootstrap.php', 'ai_report.php', 'config/config.php')) {
        if (-not $remote.Exists((Get-RemotePath $config.RemotePath $marker))) { throw 'Destinazione non riconosciuta come installazione esistente dell app.' }
    }
    # Existing dependencies stay untouched unless explicitly included; metadata drift blocks publishing.
    if (-not $IncludeDependencies) {
        $installed = Get-ContainedPath $project 'vendor/composer/installed.json'
        $remoteInstalled = Join-Path $run 'vendor-installed.json'
        $installedHash = Get-RemoteFileHash $remote (Get-RemotePath $config.RemotePath 'vendor/composer/installed.json') $remoteInstalled
        if (-not (Test-Path -LiteralPath $installed) -or $installedHash -ne (Get-FileHash -LiteralPath $installed).Hash) {
            throw 'Dipendenze locali/remoto diverse o non verificabili. Verifica vendor e usa -IncludeDependencies se devi pubblicarle.'
        }
    }
    Write-Host 'Confronto SHA-256 con i file effettivi sul server e copia di backup locale...'
    $plan = @(Get-PublishPlan $remote $config.RemotePath $run $files)
    Write-DeployJson (Join-Path $run 'plan.json') ([ordered]@{ Version = $version; HostName = $config.HostName; RemotePath = $config.RemotePath; Files = $plan })
    Write-Host "Destinazione: $($config.HostName)$($config.RemotePath)"
    Write-Host "Backup dei file esistenti: $run\before"
    if ($plan.Count -eq 0) {
        Test-PublishedWebsite $config.PublicUrl $version
        Write-Host 'Nessuna differenza: file e versione pubblica gia aggiornati.'
        return
    }
    $plan | Select-Object Action, Path | Format-Table -AutoSize | Out-Host
    Write-Host 'Storage, credenziali, SQL e file remoti estranei non vengono modificati.'
    if ($Mode -eq 'Preview') { Write-Host 'Anteprima terminata: nessun upload.'; return }
    Write-Host 'La pubblicazione di piu file FTP non e atomica: non usare l app durante l aggiornamento.'
    if ((Read-Host "Pubblicare $($plan.Count) file? Scrivi PUBBLICA") -cne 'PUBBLICA') { Write-Host 'Annullato: nessun upload.'; return }
    $remoteWritesPossible = $true
    Invoke-PublishPlan $remote $config.RemotePath $run $plan -Confirmed
    Test-PublishedWebsite $config.PublicUrl $version
    Write-DeployJson (Join-Path $run 'result.json') ([ordered]@{ Status = 'verified'; Version = $version; FinishedAt = (Get-Date).ToString('o'); Files = $plan.Count })
    Write-Host 'PUBBLICAZIONE COMPLETATA: file verificati byte per byte e pagina iniziale raggiungibile.' -ForegroundColor Green
    Write-Host 'Le funzioni protette dal PIN vanno provate nell app; nessun report AI generato dal controllo.'
} catch {
    Write-Host ('Operazione interrotta: ' + $_.Exception.Message) -ForegroundColor Red
    if ($run) {
        Write-Host "Backup e registro disponibili in: $run"
        if ($remoteWritesPossible) {
            Write-Host 'Gli upload potrebbero essere iniziati: consulta il registro. Nessun ripristino automatico.'
        } else {
            Write-Host 'Nessun upload o rinomina eseguiti: i file sul server non sono stati modificati.'
        }
        if (Test-Path -LiteralPath $run) {
            Write-DeployJson (Join-Path $run 'result.json') ([ordered]@{ Status = 'failed'; FinishedAt = (Get-Date).ToString('o') })
        }
    }
    exit 1
} finally {
    if ($null -ne $remote) { $remote.Close() }
    if ($null -ne $lock) { $lock.Dispose() }
}
