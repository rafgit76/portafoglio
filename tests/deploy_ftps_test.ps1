param([string] $Fixture, [string] $ToolsPath)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path (Split-Path -Parent $PSScriptRoot) 'deploy\Deploy.Common.ps1')
. (Join-Path (Split-Path -Parent $PSScriptRoot) 'deploy\WinSCP.Adapter.ps1')
$data = Get-Content -LiteralPath $Fixture -Raw | ConvertFrom-Json
Add-Type -Path (Join-Path $ToolsPath 'WinSCPnet.dll')
$options = New-Object WinSCP.SessionOptions
$options.Protocol = [WinSCP.Protocol]::Ftp
$options.FtpSecure = [WinSCP.FtpSecure]::Explicit
$options.HostName = '127.0.0.1'
$options.PortNumber = [int] $data.Port
$options.UserName = 'fixture'
$options.SecurePassword = ConvertTo-SecureString $data.Password -AsPlainText -Force
$options.TlsHostCertificateFingerprint = $data.Fingerprint
$session = New-Object WinSCP.Session
$session.ExecutablePath = Join-Path $ToolsPath 'WinSCP.exe'
$session.IniFilePath = 'nul'
$remote = $null
try {
    $session.Open($options)
    $remote = New-WinSCPAdapter $session '/site/apps/portafoglio'
    $run = Join-Path $data.Root 'run'
    $files = @()
    foreach ($pair in @{'index.php' = '<?php echo "NEW";'; 'VERSION' = '2.7.5'; 'assets/style.css' = 'body{}'}.GetEnumerator()) {
        $path = Get-ContainedPath (Join-Path $run 'package') $pair.Key
        [IO.Directory]::CreateDirectory((Split-Path -Parent $path)) | Out-Null
        [IO.File]::WriteAllText($path, $pair.Value)
        $files += [pscustomobject]@{ Path = $pair.Key; Hash = (Get-FileHash -LiteralPath $path).Hash }
    }
    $plan = @(Get-PublishPlan $remote $remote.Root $run $files)
    if ($plan.Count -ne 3 -or @($plan | Where-Object Action -eq 'Nuovo').Count -ne 1) { throw 'Piano FTPS errato.' }
    Invoke-PublishPlan $remote $remote.Root $run $plan -Confirmed
    if (@(Get-PublishPlan $remote $remote.Root ($run + '-second') $files).Count) { throw 'Verifica FTPS finale errata.' }
    $server = Join-Path $data.Root 'server\site\apps\portafoglio'
    if ((Get-Content -LiteralPath (Join-Path $server 'storage\report.json') -Raw) -ne 'KEEP REPORT') { throw 'Storage modificato.' }
    if ((Get-Content -LiteralPath (Join-Path $server 'config\config.local.php') -Raw) -ne 'KEEP PRIVATE CONFIG') { throw 'Config privata modificata.' }
    if ((Get-Content -LiteralPath (Join-Path $run 'before\index.php') -Raw) -ne 'OLD INDEX') { throw 'Backup errato.' }
    $blocked = $false
    try { $remote.Exists('/site/apps/another/index.php') | Out-Null } catch { $blocked = $true }
    if (-not $blocked) { throw 'Confine app non applicato.' }
    Write-Host 'OK: FTPS reale locale, preview, backup, file nuovi, overwrite via rename, hash, preservazione config/storage e confine app.'
} finally {
    $session.Dispose()
}
