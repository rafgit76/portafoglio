Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Test-PublishPath {
    param([string] $Path, [switch] $IncludeDependencies)
    if ($Path -match '(^/|\\|:|[\x00-\x20%*?\[\]]|(^|/)\.{1,2}(/|$))') { return $false }
    if ($Path -match '(?i)(^|/)(storage|sql|tests|tmp|deploy|\.git)(/|$)|(^|/)(config\.local[^/]*|\.env[^/]*)(/|$)') { return $false }
    $rootFiles = @(
        '.htaccess', 'VERSION', 'account_history.php', 'account.php', 'ai_report_view.php', 'ai_report.php',
        'andamento.php', 'asset.php', 'assets.php', 'bootstrap.php', 'changelog.php', 'compare.php',
        'credit_lines.php', 'dashboard.php', 'dossiers.php', 'earnings_event.php', 'earnings.php',
        'index.php', 'snapshot.php', 'snapshots.php', 'upload.php',
        'config/config.php', 'config/changelog.php', 'config/asset_classifications.php'
    )
    if ($rootFiles -ccontains $Path) { return $true }
    if ($Path -cmatch '^(lib|partials)/[a-z0-9_-]+\.php$') { return $true }
    if ($Path -cmatch '^assets/[a-zA-Z0-9_./-]+\.(css|js|png|jpg|jpeg|webp|svg|ico|woff2?)$') { return $true }
    return $IncludeDependencies -and $Path.StartsWith('vendor/', [StringComparison]::Ordinal)
}

function Get-ContainedPath {
    param([string] $Root, [string] $Relative)
    if ($Relative -match '(^/|\\|:|(^|/)\.{1,2}(/|$))') { throw 'Percorso relativo non sicuro.' }
    $base = [IO.Path]::GetFullPath($Root).TrimEnd('\', '/')
    $full = [IO.Path]::GetFullPath((Join-Path $base $Relative))
    if (-not $full.StartsWith($base + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Percorso fuori dalla cartella prevista.'
    }
    $cursor = $full
    while ($cursor.Length -ge $base.Length) {
        if (Test-Path -LiteralPath $cursor) {
            if ((Get-Item -LiteralPath $cursor -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) {
                throw "Link o junction non ammessi: $cursor"
            }
        }
        $cursor = Split-Path -Parent $cursor
        if (-not $cursor) { break }
    }
    return $full
}

function Get-PublishFiles {
    param([string] $ProjectRoot, [switch] $IncludeDependencies)
    $root = [IO.Path]::GetFullPath($ProjectRoot).TrimEnd('\', '/')
    $candidates = @(Get-ChildItem -LiteralPath $root -File -Force)
    foreach ($folder in @('assets', 'lib', 'partials', 'config') + $(if ($IncludeDependencies) { @('vendor') } else { @() })) {
        $path = Get-ContainedPath $root $folder
        if (Test-Path -LiteralPath $path) {
            # Inspect each directory before descending, including on Windows PowerShell 5.1.
            $pending = New-Object 'Collections.Generic.Stack[string]'
            $pending.Push($path)
            while ($pending.Count) {
                foreach ($entry in Get-ChildItem -LiteralPath $pending.Pop() -Force) {
                    if ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw "Link nella cartella $folder" }
                    if ($entry.PSIsContainer) { $pending.Push($entry.FullName) } else { $candidates += $entry }
                }
            }
        }
    }
    foreach ($file in $candidates) {
        $relative = $file.FullName.Substring($root.Length + 1).Replace('\', '/')
        if (Test-PublishPath $relative -IncludeDependencies:$IncludeDependencies) {
            $safePath = Get-ContainedPath $root $relative
            [pscustomobject]@{ Path = $relative; FullName = $safePath; Hash = (Get-FileHash -LiteralPath $safePath -Algorithm SHA256).Hash }
        }
    }
}

function Assert-DeployTarget {
    param($Config, [string] $ProjectRoot)
    if ($Config.Protocol -notin @('Ftps', 'Sftp')) { throw 'Sono ammessi solo FTPS esplicito o SFTP.' }
    if ($Config.HostName -notmatch '^[a-zA-Z0-9.-]+$' -or [int] $Config.Port -lt 1 -or [int] $Config.Port -gt 65535) { throw 'Host o porta non validi.' }
    if ($Config.RemotePath -cnotmatch '^/(?:[a-zA-Z0-9_.-]+/)*portafoglio$' -or $Config.RemotePath -match '(^|/)\.{1,2}(/|$)') {
        throw 'Destinazione non sicura: serve la cartella assoluta dell app, terminante in /portafoglio.'
    }
    $url = $null
    if (-not [Uri]::TryCreate($Config.PublicUrl, [UriKind]::Absolute, [ref] $url) -or $url.Scheme -ne 'https' -or $url.UserInfo -or $url.Query -or $url.Fragment -or $url.AbsolutePath.TrimEnd('/') -notmatch '/portafoglio$') {
        throw 'URL pubblico HTTPS dell app non valido.'
    }
    if ([IO.Path]::GetFullPath($Config.ProjectRoot).TrimEnd('\') -ne [IO.Path]::GetFullPath($ProjectRoot).TrimEnd('\')) { throw 'Profilo associato a un altro progetto.' }
    if ($Config.Protocol -eq 'Sftp' -and ($Config.SshHostKey -notmatch '^ssh-' -or $Config.SshHostKey -match '[*;\r\n]')) { throw 'SFTP richiede la fingerprint verificata della chiave host.' }
}

function Get-RemotePath {
    param([string] $Root, [string] $Relative)
    if ($Relative -match '(^/|\\|:|[\x00-\x20%*?\[\]]|(^|/)\.{1,2}(/|$))') { throw 'Percorso remoto non sicuro.' }
    return $Root.TrimEnd('/') + '/' + $Relative
}

function Write-DeployJson {
    param([string] $Path, $Value)
    $json = ConvertTo-Json -InputObject $Value -Depth 12
    [IO.File]::WriteAllText($Path, $json, (New-Object Text.UTF8Encoding($false)))
}

function Get-SqlState {
    param([string] $ProjectRoot)
    $rows = @(Get-ChildItem -LiteralPath (Join-Path $ProjectRoot 'sql') -File -Filter '*.sql' | Sort-Object Name | ForEach-Object {
        $_.Name + ':' + (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash
    })
    return $rows -join "`n"
}

function New-PublishPackage {
    param([string] $ProjectRoot, [string] $RunPath, [switch] $IncludeDependencies)
    $files = @(Get-PublishFiles $ProjectRoot -IncludeDependencies:$IncludeDependencies)
    if ($files.Count -eq 0) { throw 'Nessun file pubblicabile.' }
    $package = Join-Path $RunPath 'package'
    foreach ($file in $files) {
        $target = Get-ContainedPath $package $file.Path
        [IO.Directory]::CreateDirectory((Split-Path -Parent $target)) | Out-Null
        Copy-Item -LiteralPath $file.FullName -Destination $target
        if ((Get-FileHash -LiteralPath $target).Hash -ne $file.Hash) { throw 'File locale cambiato durante la preparazione.' }
    }
    return $files
}

function Get-RemoteFileHash {
    param($Remote, [string] $RemotePath, [string] $LocalPath)
    if ($Remote.PSObject.Methods['DownloadIfExists']) {
        [IO.Directory]::CreateDirectory((Split-Path -Parent $LocalPath)) | Out-Null
        if (-not $Remote.DownloadIfExists($RemotePath, $LocalPath)) { return $null }
        return (Get-FileHash -LiteralPath $LocalPath -Algorithm SHA256).Hash
    }
    if (-not $Remote.Exists($RemotePath)) { return $null }
    if ($Remote.IsUnsafe($RemotePath)) { throw "File remoto non regolare: $RemotePath" }
    [IO.Directory]::CreateDirectory((Split-Path -Parent $LocalPath)) | Out-Null
    $Remote.Download($RemotePath, $LocalPath)
    return (Get-FileHash -LiteralPath $LocalPath -Algorithm SHA256).Hash
}

function Get-PublishPlan {
    param($Remote, [string] $RemoteRoot, [string] $RunPath, [array] $Files)
    if (Test-Path -LiteralPath (Join-Path $RunPath 'before')) { throw 'Esecuzione gia usata: il backup originale non puo essere sovrascritto.' }
    $index = 0
    foreach ($file in $Files) {
        $index++
        Write-Host "Confronto [$index/$($Files.Count)]: $($file.Path)"
        $before = Get-ContainedPath (Join-Path $RunPath 'before') $file.Path
        $oldHash = Get-RemoteFileHash $Remote (Get-RemotePath $RemoteRoot $file.Path) $before
        if ($oldHash -ne $file.Hash) {
            [pscustomobject]@{ Path = $file.Path; Hash = $file.Hash; BeforeHash = $oldHash; Action = $(if ($null -eq $oldHash) { 'Nuovo' } else { 'Aggiorna' }); Status = 'planned' }
        }
    }
}

function Invoke-PublishPlan {
    param($Remote, [string] $RemoteRoot, [string] $RunPath, [array] $Plan, [switch] $Confirmed)
    if (-not $Confirmed) { throw 'Conferma esplicita mancante: nessun upload.' }
    $journal = Join-Path $RunPath 'journal.json'
    $runId = [Guid]::NewGuid().ToString('N')
    # Freeze and validate the entire plan before any remote write. VERSION is activated last.
    $ordered = @($Plan | Sort-Object @{ Expression = { if ($_.Path -eq 'VERSION') { 1 } else { 0 } } }, Path)
    foreach ($entry in $ordered) {
        if (-not (Test-PublishPath $entry.Path -IncludeDependencies)) { throw 'File escluso nel piano di pubblicazione.' }
        $source = Get-ContainedPath (Join-Path $RunPath 'package') $entry.Path
        if ((Get-FileHash -LiteralPath $source).Hash -ne $entry.Hash) { throw 'Pacchetto cambiato dopo l anteprima.' }
        if ($null -ne $entry.BeforeHash) {
            $backup = Get-ContainedPath (Join-Path $RunPath 'before') $entry.Path
            if ((Get-FileHash -LiteralPath $backup).Hash -ne $entry.BeforeHash) { throw 'Backup mancante o alterato.' }
        }
        $recheck = Get-ContainedPath (Join-Path $RunPath 'recheck') $entry.Path
        $currentHash = Get-RemoteFileHash $Remote (Get-RemotePath $RemoteRoot $entry.Path) $recheck
        if ($currentHash -ne $entry.BeforeHash) { throw "File remoto cambiato dopo l anteprima: $($entry.Path)" }
    }
    Write-DeployJson $journal $ordered
    foreach ($entry in $ordered) {
        $source = Get-ContainedPath (Join-Path $RunPath 'package') $entry.Path
        $relativeParent = ($entry.Path -replace '[^/]+$', '').TrimEnd('/')
        $parent = if ($relativeParent) { Get-RemotePath $RemoteRoot $relativeParent } else { $RemoteRoot }
        $Remote.EnsureDirectory($parent)
        $staged = $parent + '/.deploy-' + $runId + '-' + [IO.Path]::GetFileName($entry.Path)
        $entry | Add-Member -NotePropertyName StagedPath -NotePropertyValue $staged
        $Remote.Upload($source, $staged)
        $checkPath = Get-ContainedPath (Join-Path $RunPath 'verify-stage') $entry.Path
        if ((Get-RemoteFileHash $Remote $staged $checkPath) -ne $entry.Hash) { throw 'Verifica del file temporaneo fallita. Nessuna attivazione.' }
        $entry.Status = 'staged'
        Write-DeployJson $journal $ordered
    }
    foreach ($entry in $ordered) {
        $destination = Get-RemotePath $RemoteRoot $entry.Path
        $checkPath = Get-ContainedPath (Join-Path $RunPath 'recheck') $entry.Path
        if ((Get-RemoteFileHash $Remote $destination $checkPath) -ne $entry.BeforeHash) { throw "Modifica concorrente sul server: $($entry.Path)" }
        $entry.Status = 'activating'
        Write-DeployJson $journal $ordered
        $Remote.Move($entry.StagedPath, $destination)
        $checkPath = Get-ContainedPath (Join-Path $RunPath 'verify-final') $entry.Path
        if ((Get-RemoteFileHash $Remote $destination $checkPath) -ne $entry.Hash) { throw "Verifica finale fallita: $($entry.Path)" }
        $entry.Status = 'verified'
        Write-DeployJson $journal $ordered
        Write-Host "Verificato: $($entry.Path)"
    }
}
