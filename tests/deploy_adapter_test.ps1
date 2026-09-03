param([Parameter(Mandatory = $true)][string] $ToolsPath)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path (Split-Path -Parent $PSScriptRoot) 'deploy\Deploy.Common.ps1')
. (Join-Path (Split-Path -Parent $PSScriptRoot) 'deploy\WinSCP.Adapter.ps1')
Add-Type -Path (Join-Path $ToolsPath 'WinSCPnet.dll')
$script:checks = 0
function Assert-Adapter { param([bool] $Condition, [string] $Label) if (-not $Condition) { throw "TEST FALLITO: $Label" }; $script:checks++ }
function Assert-Blocked { param([scriptblock] $Action) $blocked = $false; try { & $Action | Out-Null } catch { $blocked = $true }; Assert-Adapter $blocked 'operazione bloccata' }
$temp = Join-Path ([IO.Path]::GetTempPath()) ('portfolio-adapter-test-' + [Guid]::NewGuid().ToString('N'))

function New-AdapterCase {
    $session = [pscustomobject]@{
        Infos = 0; Listings = 0; Downloads = 0; Opens = 0; Uploads = 0; Moves = 0
        InfoFailures = 0; DownloadFailures = 0; ListingFailures = 0; Denied = $false; DirectoryDenied = $false
        RootLink = $false; LinkAfterReconnect = $false
        Entries = @{
            '/app/portafoglio/index.php' = [pscustomobject]@{ Name = 'index.php'; FileType = '-'; IsDirectory = $false }
            '/app/portafoglio/assets' = [pscustomobject]@{ Name = 'assets'; FileType = 'D'; IsDirectory = $true }
            '/app/portafoglio/assets/style.css' = [pscustomobject]@{ Name = 'style.css'; FileType = '-'; IsDirectory = $false }
        }
    }
    $session | Add-Member ScriptMethod GetFileInfo {
        param($Path)
        $this.Infos++
        if ($Path -cne '/app/portafoglio') { throw 'Stat duplicato su un file anziche root.' }
        if ($this.Denied) { throw 'Permission denied.' }
        if ($this.InfoFailures -gt 0) { $this.InfoFailures--; throw 'Lost connection. Disconnected from server.' }
        return [pscustomobject]@{ Name = 'portafoglio'; FileType = $(if ($this.RootLink) { 'L' } else { 'D' }); IsDirectory = $true }
    }
    $session | Add-Member ScriptMethod ListDirectory {
        param($Path)
        $this.Listings++
        if ($this.DirectoryDenied) { throw 'Permission denied.' }
        if ($this.ListingFailures -gt 0) { $this.ListingFailures--; throw 'Lost connection.' }
        $children = @($this.Entries.Keys | Where-Object { $_.Substring(0, $_.LastIndexOf('/')) -ceq $Path } | ForEach-Object { $this.Entries[$_] })
        return [pscustomobject]@{ Files = $children }
    }
    $session | Add-Member ScriptMethod GetFiles {
        param($Path, $Local, $Remove, $Options)
        $this.Downloads++
        if ($Remove) { throw 'Rimozione non ammessa.' }
        if ($this.DownloadFailures -gt 0) { $this.DownloadFailures--; [IO.File]::WriteAllText($Local, 'PARTIAL'); throw 'Lost connection.' }
        [IO.File]::WriteAllText($Local, 'COMPLETE FILE')
        $result = [pscustomobject]@{ Transfers = @('one') }
        $result | Add-Member ScriptMethod Check { }
        return $result
    }
    $session | Add-Member ScriptMethod Close { }
    $session | Add-Member ScriptMethod Open {
        param($Options)
        if ($Options.Marker -cne 'original-options') { throw 'Opzioni connessione alterate.' }
        $this.Opens++
        if ($this.LinkAfterReconnect) { $this.Entries['/app/portafoglio/index.php'].FileType = 'L' }
    }
    $session | Add-Member ScriptMethod PutFiles { param($Local, $Path, $Remove, $Options) $this.Uploads++; throw 'Lost connection.' }
    $session | Add-Member ScriptMethod MoveFile { param($Source, $Target) $this.Moves++; throw 'Lost connection.' }
    $adapter = New-WinSCPAdapter $session '/app/portafoglio' ([pscustomobject]@{ Marker = 'original-options' })
    $adapter.ReadRetrySeconds = 0
    return $adapter
}

$remote = New-AdapterCase
$local = Join-Path $temp 'read.txt'
$hash = Get-RemoteFileHash $remote '/app/portafoglio/index.php' $local
Assert-Adapter ($hash -eq (Get-FileHash -LiteralPath $local).Hash) 'hash completo'
Assert-Adapter ($remote.Session.Infos -eq 1 -and $remote.Session.Listings -eq 1 -and $remote.Session.Downloads -eq 1) 'un solo controllo per download, senza stat duplicati'
$remote = New-AdapterCase
Assert-Adapter ($null -eq (Get-RemoteFileHash $remote '/app/portafoglio/missing.php' $local)) 'assenza dopo listing riuscito'
Assert-Adapter ($remote.Session.Downloads -eq 0) 'file assente non scaricato'
$remote.Session.Entries['/app/portafoglio/missing.php'] = [pscustomobject]@{ Name = 'missing.php'; FileType = '-'; IsDirectory = $false }
Assert-Adapter ($null -ne (Get-RemoteFileHash $remote '/app/portafoglio/missing.php' $local)) 'aggiunta concorrente rilevata senza cache'
Assert-Adapter (-not $remote.Exists('/app/portafoglio/INDEX.php')) 'nomi case sensitive'
foreach ($kind in @('InfoFailures', 'ListingFailures', 'DownloadFailures')) {
    $remote = New-AdapterCase
    $remote.Session.$kind = 1
    Get-RemoteFileHash $remote '/app/portafoglio/index.php' $local | Out-Null
    Assert-Adapter ($remote.Session.Opens -eq 1) "una riconnessione per $kind"
    Assert-Adapter ((Get-Content -LiteralPath $local -Raw) -ceq 'COMPLETE FILE') 'nessun download parziale usato come backup valido'
}
$remote = New-AdapterCase; $remote.Session.InfoFailures = 99
Assert-Blocked { Get-RemoteFileHash $remote '/app/portafoglio/index.php' $local }
Assert-Adapter ($remote.Session.Infos -eq 3 -and $remote.Session.Opens -eq 2) 'riconnessioni limitate a due'
foreach ($kind in @('Denied', 'DirectoryDenied')) {
    $remote = New-AdapterCase; $remote.Session.$kind = $true
    Assert-Blocked { Get-RemoteFileHash $remote '/app/portafoglio/index.php' $local }
    Assert-Adapter ($remote.Session.Opens -eq 0 -and $remote.Session.Downloads -eq 0) 'permesso negato non interpretato come file assente o disconnessione'
}
$remote = New-AdapterCase; $remote.Session.RootLink = $true
Assert-Blocked { Get-RemoteFileHash $remote '/app/portafoglio/index.php' $local }
$remote = New-AdapterCase; $remote.Session.Entries['/app/portafoglio/assets'].FileType = 'L'
Assert-Blocked { Get-RemoteFileHash $remote '/app/portafoglio/assets/style.css' $local }
Assert-Adapter ($remote.Session.Downloads -eq 0) 'nessun attraversamento di link'
$remote = New-AdapterCase; $remote.Session.InfoFailures = 1; $remote.Session.LinkAfterReconnect = $true
Assert-Blocked { Get-RemoteFileHash $remote '/app/portafoglio/index.php' $local }
Assert-Adapter ($remote.Session.Opens -eq 1 -and $remote.Session.Downloads -eq 0) 'sicurezza rivalutata dopo riconnessione'
$remote = New-AdapterCase
Assert-Blocked { $remote.Upload($local, '/app/portafoglio/index.php') }
Assert-Adapter ($remote.Session.Uploads -eq 1 -and $remote.Session.Opens -eq 0) 'upload non ripetuto dal wrapper'
$remote = New-AdapterCase
Assert-Blocked { $remote.Move('/app/portafoglio/index.php', '/app/portafoglio/new.php') }
Assert-Adapter ($remote.Session.Moves -eq 1 -and $remote.Session.Opens -eq 0) 'rinomina non ripetuta dal wrapper'
Assert-Blocked { $remote.Exists('/app/other/index.php') }
Write-Host "OK: $script:checks controlli adapter, metadati, disconnessioni e protezioni. Nessuna connessione reale."
