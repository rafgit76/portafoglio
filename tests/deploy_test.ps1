Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path (Split-Path -Parent $PSScriptRoot) 'deploy\Deploy.Common.ps1')
$script:checks = 0
function Assert-Test { param([bool] $Condition, [string] $Label) if (-not $Condition) { throw "TEST FALLITO: $Label" }; $script:checks++ }
function Assert-Rejected { param([scriptblock] $Action, [string] $Label) $failed = $false; try { & $Action | Out-Null } catch { $failed = $true }; Assert-Test $failed $Label }
function Write-TestFile { param([string] $Root, [string] $Relative, [string] $Text) $path = Get-ContainedPath $Root $Relative; [IO.Directory]::CreateDirectory((Split-Path -Parent $path)) | Out-Null; [IO.File]::WriteAllText($path, $Text) }

$temp = Join-Path ([IO.Path]::GetTempPath()) ('portfolio-deploy-test-' + [Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($temp) | Out-Null
$project = Join-Path $temp 'project'
foreach ($pair in @{
    'index.php' = '<?php echo "OK";'; 'VERSION' = '2.7.5'; 'config/config.php' = '<?php return [];';
    'assets/style.css' = 'body{}'; 'assets/ai-report.js' = 'void 0;'; 'lib/helpers.php' = '<?php';
    'config/config.local.php' = 'PRIVATE_CONFIG'; 'config/config.local.sample.php' = 'SAMPLE';
    'storage/ai_reports/test.json' = 'PRIVATE_REPORT'; 'storage/uploads/test.xls' = 'PRIVATE_UPLOAD';
    'sql/migration.sql' = 'DO_NOT_RUN_SQL'; 'tests/fixture.php' = 'DO_NOT_DEPLOY';
    '.env' = 'PRIVATE_ENV'; 'publish.ps1' = 'DO_NOT_DEPLOY'; 'vendor/autoload.php' = '<?php';
    'deploy/profile.local.json' = 'PRIVATE_PROFILE'
}.GetEnumerator()) { Write-TestFile $project $pair.Key $pair.Value }
$files = @(Get-PublishFiles $project)
Assert-Test ($files.Count -eq 6) 'allowlist pubblica soltanto sei file di codice'
Assert-Test (@($files | Where-Object { $_.Path -match 'storage|local|sql|vendor|deploy' }).Count -eq 0) 'dati, segreti e dipendenze esclusi'
Assert-Test (@(Get-PublishFiles $project -IncludeDependencies).Count -eq 7) 'vendor richiede opt-in'
foreach ($path in @('../index.php', '/index.php', 'assets/../../storage/a.json', 'assets/test.js:stream', 'assets\test.js', 'assets/test%2f.js', 'config/config.local.php', 'storage/ai_reports/a.json', 'sql/schema.sql', 'unknown.php', 'assets/x.js/../test.js')) {
    Assert-Test (-not (Test-PublishPath $path -IncludeDependencies)) "esclusione $path"
}
Assert-Rejected { Get-ContainedPath $project '../elsewhere' } 'traversal locale bloccato'
Assert-Rejected { Get-RemotePath '/app/portafoglio' '../other' } 'traversal remoto bloccato'
$config = [pscustomobject]@{ Protocol = 'Ftps'; HostName = 'ftp.example.test'; Port = 21; RemotePath = '/site/apps/portafoglio'; PublicUrl = 'https://example.test/apps/portafoglio'; ProjectRoot = $project; SshHostKey = '' }
Assert-DeployTarget $config $project
Assert-Test $true 'destinazione FTPS valida'
foreach ($path in @('/', '/site/apps', '/site/apps/triagecsm', '/site/../portafoglio', '/site/apps/portafoglio/../portafoglio')) {
    $bad = $config.PSObject.Copy(); $bad.RemotePath = $path
    Assert-Rejected { Assert-DeployTarget $bad $project } "cartella sbagliata: $path"
}
$bad = $config.PSObject.Copy(); $bad.Protocol = 'Ftp'
Assert-Rejected { Assert-DeployTarget $bad $project } 'FTP non cifrato rifiutato'
$bad = $config.PSObject.Copy(); $bad.PublicUrl = 'http://example.test/portafoglio'
Assert-Rejected { Assert-DeployTarget $bad $project } 'URL HTTP rifiutato'
$bad = $config.PSObject.Copy(); $bad.Protocol = 'Sftp'; $bad.SshHostKey = '*'
Assert-Rejected { Assert-DeployTarget $bad $project } 'chiave SFTP wildcard rifiutata'

function New-TestCase {
    $case = Join-Path $temp ([Guid]::NewGuid().ToString('N'))
    $server = Join-Path $case 'server'
    $run = Join-Path $case 'run'
    $packageFiles = @(New-PublishPackage $project $run)
    foreach ($file in $packageFiles) { Write-TestFile $server $file.Path ([IO.File]::ReadAllText($file.FullName)) }
    Write-TestFile $server 'index.php' 'PREVIOUS INDEX'
    Write-TestFile $server 'VERSION' '2.7.4'
    Write-TestFile $server 'storage/ai_reports/report.json' 'KEEP_REPORT'
    Write-TestFile $server 'config/config.local.php' 'KEEP_CREDENTIALS'
    Write-TestFile $server 'unknown.txt' 'KEEP_UNMANAGED'
    $remote = [pscustomobject]@{ Folder = $server; Root = '/site/apps/portafoglio'; Uploads = 0; Moves = 0; FailUpload = 0; FailMove = 0; Corrupt = $false; Activated = @() }
    $remote | Add-Member ScriptMethod Local {
        param($Path)
        if (-not $Path.StartsWith($this.Root + '/', [StringComparison]::Ordinal)) { throw 'Fuori root di test.' }
        return Get-ContainedPath $this.Folder $Path.Substring($this.Root.Length + 1)
    }
    $remote | Add-Member ScriptMethod Exists { param($Path) return Test-Path -LiteralPath ($this.Local($Path)) }
    $remote | Add-Member ScriptMethod IsUnsafe { param($Path) return (Get-Item -LiteralPath ($this.Local($Path))).PSIsContainer }
    $remote | Add-Member ScriptMethod Download { param($Path, $Local) Copy-Item -LiteralPath ($this.Local($Path)) -Destination $Local }
    $remote | Add-Member ScriptMethod Upload {
        param($Local, $Path)
        $this.Uploads++
        if ($this.FailUpload -eq $this.Uploads) { throw 'Upload simulato fallito.' }
        $target = $this.Local($Path)
        Copy-Item -LiteralPath $Local -Destination $target
        if ($this.Corrupt) { [IO.File]::WriteAllText($target, 'CORRUPT') }
    }
    $remote | Add-Member ScriptMethod EnsureDirectory { param($Path) if ($Path -ne $this.Root) { [IO.Directory]::CreateDirectory($this.Local($Path)) | Out-Null } }
    $remote | Add-Member ScriptMethod Move {
        param($Source, $Target)
        $this.Moves++
        if ($this.FailMove -eq $this.Moves) { throw 'Rinomina simulata fallita.' }
        $from = $this.Local($Source); $to = $this.Local($Target)
        Move-Item -LiteralPath $from -Destination $to -Force
        $this.Activated += $Target
    }
    $plan = @(Get-PublishPlan $remote $remote.Root $run $packageFiles)
    return [pscustomobject]@{ Remote = $remote; Run = $run; Plan = $plan; Files = $packageFiles }
}

$case = New-TestCase
Assert-Test ($case.Plan.Count -eq 2) 'differenze rilevate anche dopo upload manuali'
Assert-Test ($case.Remote.Uploads -eq 0 -and $case.Remote.Moves -eq 0) 'anteprima non scrive sul server'
Assert-Test ((Get-Content -LiteralPath (Join-Path $case.Run 'before\index.php') -Raw) -eq 'PREVIOUS INDEX') 'backup conserva contenuto precedente'
Assert-Rejected { Invoke-PublishPlan $case.Remote $case.Remote.Root $case.Run $case.Plan } 'nessuna pubblicazione senza conferma'
Assert-Test ($case.Remote.Uploads -eq 0) 'annullamento senza upload'
Invoke-PublishPlan $case.Remote $case.Remote.Root $case.Run $case.Plan -Confirmed
Assert-Test ($case.Remote.Uploads -eq 2 -and $case.Remote.Moves -eq 2) 'solo file cambiati caricati e attivati'
Assert-Test ($case.Remote.Activated[-1].EndsWith('/VERSION')) 'VERSION attivata per ultima'
Assert-Rejected { Get-PublishPlan $case.Remote $case.Remote.Root $case.Run $case.Files } 'backup di una esecuzione non sovrascrivibile'
Assert-Test (@(Get-PublishPlan $case.Remote $case.Remote.Root ($case.Run + '-new') $case.Files).Count -eq 0) 'seconda pubblicazione senza differenze'
Assert-Test ((Get-Content -LiteralPath (Join-Path $case.Run 'before\index.php') -Raw) -eq 'PREVIOUS INDEX') 'seconda anteprima preserva il primo backup'
Assert-Test ((Get-Content -LiteralPath (Join-Path $case.Remote.Folder 'storage\ai_reports\report.json') -Raw) -eq 'KEEP_REPORT') 'report remoto preservato'
Assert-Test ((Get-Content -LiteralPath (Join-Path $case.Remote.Folder 'config\config.local.php') -Raw) -eq 'KEEP_CREDENTIALS') 'credenziali remote preservate'
Assert-Test (Test-Path -LiteralPath (Join-Path $case.Remote.Folder 'unknown.txt')) 'nessuna cancellazione di file estranei'

$case = New-TestCase
Write-TestFile $case.Remote.Folder 'index.php' 'CHANGED AFTER PREVIEW'
Assert-Rejected { Invoke-PublishPlan $case.Remote $case.Remote.Root $case.Run $case.Plan -Confirmed } 'modifica remota concorrente bloccata'
Assert-Test ($case.Remote.Uploads -eq 0) 'concorrenza bloccata prima di scrivere'
$case = New-TestCase
Write-TestFile (Join-Path $case.Run 'package') 'index.php' 'PACKAGE TAMPERED'
Assert-Rejected { Invoke-PublishPlan $case.Remote $case.Remote.Root $case.Run $case.Plan -Confirmed } 'pacchetto alterato bloccato'
Assert-Test ($case.Remote.Uploads -eq 0) 'hash locale ricontrollato prima dell upload'
$case = New-TestCase
Write-TestFile (Join-Path $case.Run 'before') 'index.php' 'BACKUP TAMPERED'
Assert-Rejected { Invoke-PublishPlan $case.Remote $case.Remote.Root $case.Run $case.Plan -Confirmed } 'backup alterato bloccato'
$case = New-TestCase
$case.Remote.FailUpload = 2
Assert-Rejected { Invoke-PublishPlan $case.Remote $case.Remote.Root $case.Run $case.Plan -Confirmed } 'errore di trasferimento propagato'
Assert-Test ($case.Remote.Moves -eq 0) 'staging incompleto non attiva alcun file'
Assert-Test ((Get-Content -LiteralPath (Join-Path $case.Remote.Folder 'index.php') -Raw) -eq 'PREVIOUS INDEX') 'vecchia versione preservata se lo staging fallisce'
$case = New-TestCase
$case.Remote.Corrupt = $true
Assert-Rejected { Invoke-PublishPlan $case.Remote $case.Remote.Root $case.Run $case.Plan -Confirmed } 'file trasferito corrotto rifiutato'
Assert-Test ($case.Remote.Moves -eq 0) 'hash errato non attivato'
$case = New-TestCase
$case.Remote.FailMove = 2
Assert-Rejected { Invoke-PublishPlan $case.Remote $case.Remote.Root $case.Run $case.Plan -Confirmed } 'errore attivazione non mascherato'
$journal = Get-Content -LiteralPath (Join-Path $case.Run 'journal.json') -Raw | ConvertFrom-Json
Assert-Test ($journal[0].Status -eq 'verified' -and $journal[1].Status -eq 'activating') 'registro segnala attivazione parziale'
Assert-Test ((Get-Content -LiteralPath (Join-Path $case.Run 'before\index.php') -Raw) -eq 'PREVIOUS INDEX') 'backup disponibile dopo errore parziale'
$case = New-TestCase
$malicious = [pscustomobject]@{ Path = 'storage/ai_reports/report.json'; Hash = 'fake'; BeforeHash = $null; Action = 'Nuovo'; Status = 'planned' }
Assert-Rejected { Invoke-PublishPlan $case.Remote $case.Remote.Root $case.Run @($malicious) -Confirmed } 'piano manipolato non aggira esclusioni'
Assert-Test ($case.Remote.Uploads -eq 0) 'piano vietato non produce upload'
Write-Host "OK: $script:checks controlli pubblicazione. Nessuna connessione esterna o credenziale reale."
