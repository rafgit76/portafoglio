function New-DeployRemote {
    param($Config, [Management.Automation.PSCredential] $Credential, [string] $ToolsPath)
    Add-Type -Path (Join-Path $ToolsPath 'WinSCPnet.dll')
    $options = New-Object WinSCP.SessionOptions
    $options.HostName = $Config.HostName
    $options.PortNumber = [int] $Config.Port
    $options.UserName = $Credential.UserName
    $options.SecurePassword = $Credential.Password
    $options.Timeout = [TimeSpan]::FromSeconds(45)
    if ($Config.Protocol -eq 'Ftps') {
        $options.Protocol = [WinSCP.Protocol]::Ftp
        $options.FtpSecure = [WinSCP.FtpSecure]::Explicit
        $options.FtpMode = [WinSCP.FtpMode]::Passive
    } else {
        $options.Protocol = [WinSCP.Protocol]::Sftp
        $options.SshHostKeyFingerprint = $Config.SshHostKey
    }
    $session = New-Object WinSCP.Session
    $session.ExecutablePath = Join-Path $ToolsPath 'WinSCP.exe'
    $session.IniFilePath = 'nul'
    # No session/debug log: credentials are neither printed nor passed on the command line.
    try { $session.Open($options) } catch { $session.Dispose(); throw 'Connessione sicura non riuscita. Verifica host, credenziali e certificato; nessun upload.' }
    return New-WinSCPAdapter $session $Config.RemotePath $options
}

function Test-DeployConnectionFailure {
    param([Exception] $Exception)
    for ($current = $Exception; $null -ne $current; $current = $current.InnerException) {
        if ($current.Message -match '(?i)lost connection|disconnected from server|connection (?:was |has been )?(?:unexpectedly )?(?:closed|lost)|network error|connection timed out|timeout waiting for WinSCP|session is not opened') { return $true }
    }
    return $false
}

function New-WinSCPAdapter {
    param($Session, [string] $Root, $ConnectionOptions = $null)
    $transfer = New-Object WinSCP.TransferOptions
    $transfer.TransferMode = [WinSCP.TransferMode]::Binary
    $transfer.PreserveTimestamp = $false
    $transfer.ResumeSupport.State = [WinSCP.TransferResumeSupportState]::Off
    $remote = [pscustomobject]@{ Session = $Session; Options = $transfer; Root = $Root; ConnectionOptions = $ConnectionOptions; ReadRetrySeconds = 2 }
    $remote | Add-Member ScriptMethod Guard {
        param($Path)
        if ($Path -ne $this.Root -and -not $Path.StartsWith($this.Root + '/', [StringComparison]::Ordinal)) { throw 'Percorso remoto fuori dal progetto.' }
        if ($Path -match '(^|/)\.{1,2}(/|$)|[\\\x00-\x20%*?\[\]]') { throw 'Percorso remoto non sicuro.' }
    }
    $remote | Add-Member ScriptMethod InvokeRead {
        param([scriptblock] $Action)
        for ($attempt = 1; $attempt -le 3; $attempt++) {
            try {
                if ($attempt -gt 1) {
                    try { $this.Session.Close() } catch { }
                    $this.Session.Open($this.ConnectionOptions)
                }
                return & $Action $this
            } catch {
                if ($attempt -eq 3 -or $null -eq $this.ConnectionOptions -or -not (Test-DeployConnectionFailure $_.Exception)) { throw }
                Write-Host "Connessione interrotta: nuovo tentativo di sola lettura ($($attempt + 1)/3)..."
                Start-Sleep -Seconds ($this.ReadRetrySeconds * $attempt)
            }
        }
    }
    $remote | Add-Member ScriptMethod GetInfo {
        param($Path)
        $this.Guard($Path)
        $item = $this.Session.GetFileInfo($this.Root)
        if ($item.FileType -eq 'L' -or -not $item.IsDirectory) { throw 'Cartella app remota non regolare.' }
        if ($Path -eq $this.Root) { return $item }
        $parent = $this.Root
        # A successful listing distinguishes a missing file from a failed metadata request.
        # No cross-operation cache: concurrent additions and symlink changes remain visible.
        foreach ($name in $Path.Substring($this.Root.Length + 1).Split('/')) {
            if (-not $item.IsDirectory) { throw 'Un componente del percorso remoto non e una cartella.' }
            $matches = @($this.Session.ListDirectory($parent).Files | Where-Object { $_.Name -ceq $name })
            if ($matches.Count -eq 0) { return $null }
            if ($matches.Count -ne 1) { throw 'Informazioni file remoto ambigue.' }
            $item = $matches[0]
            if ($item.FileType -eq 'L') { throw 'Link remoto non ammesso.' }
            $parent += '/' + $name
        }
        return $item
    }
    $remote | Add-Member ScriptMethod Exists {
        param($Path)
        return $this.InvokeRead({ param($remote) return $null -ne $remote.GetInfo($Path) })
    }
    $remote | Add-Member ScriptMethod IsUnsafe {
        param($Path)
        return $this.InvokeRead({ param($remote) $item = $remote.GetInfo($Path); return $null -eq $item -or $item.FileType -ne '-' })
    }
    $remote | Add-Member ScriptMethod DownloadIfExists {
        param($Path, $Local)
        return $this.InvokeRead({
            param($remote)
            $item = $remote.GetInfo($Path)
            if ($null -eq $item) { return $false }
            if ($item.FileType -ne '-') { throw 'File remoto non regolare.' }
            $result = $remote.Session.GetFiles([WinSCP.RemotePath]::EscapeFileMask($Path), $Local, $false, $remote.Options)
            $result.Check()
            if ($result.Transfers.Count -ne 1) { throw 'Download non univoco.' }
            return $true
        })
    }
    $remote | Add-Member ScriptMethod Download {
        param($Path, $Local)
        if (-not $this.DownloadIfExists($Path, $Local)) { throw 'File remoto non trovato.' }
    }
    $remote | Add-Member ScriptMethod Upload {
        param($Local, $Path)
        $item = $this.GetInfo($Path)
        if ($null -ne $item -and $item.FileType -ne '-') { throw 'Destinazione upload non regolare.' }
        $result = $this.Session.PutFiles([WinSCP.RemotePath]::EscapeFileMask($Local), $Path, $false, $this.Options)
        $result.Check()
        if ($result.Transfers.Count -ne 1) { throw 'Upload non univoco.' }
    }
    $remote | Add-Member ScriptMethod Move {
        param($Source, $Target)
        $sourceInfo = $this.GetInfo($Source); $targetInfo = $this.GetInfo($Target)
        if ($null -eq $sourceInfo -or $sourceInfo.FileType -ne '-' -or ($null -ne $targetInfo -and $targetInfo.FileType -ne '-')) { throw 'File non regolare nella rinomina.' }
        $this.Session.MoveFile($Source, $Target)
    }
    $remote | Add-Member ScriptMethod EnsureDirectory {
        param($Path)
        $this.Guard($Path)
        $missing = @()
        while (-not $this.Exists($Path)) {
            $missing = @($Path) + $missing
            $Path = $Path.Substring(0, $Path.LastIndexOf('/'))
            if ($Path.Length -lt $this.Root.Length) { throw 'Cartella app non trovata.' }
        }
        if (-not $this.GetInfo($Path).IsDirectory) { throw 'Destinazione non e una cartella.' }
        foreach ($directory in $missing) { $this.Session.CreateDirectory($directory) }
    }
    $remote | Add-Member ScriptMethod Close { $this.Session.Dispose() }
    return $remote
}
