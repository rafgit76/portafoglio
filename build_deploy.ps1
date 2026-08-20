param(
    [switch] $IncludeLocalConfig
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectParent = Split-Path -Parent $projectRoot
$timestamp = Get-Date -Format 'yyyyMMdd_HHmm'
$destination = Join-Path $projectParent "portafoglio_ftp_$timestamp"

if (Test-Path -LiteralPath $destination) {
    throw "La cartella di destinazione esiste gia: $destination"
}

New-Item -ItemType Directory -Path $destination | Out-Null

$excludeDirs = @(
    (Join-Path $projectRoot '.git'),
    (Join-Path $projectRoot 'storage\ai_reports'),
    (Join-Path $projectRoot 'storage\uploads'),
    (Join-Path $projectRoot 'tests'),
    (Join-Path $projectRoot 'tmp')
)

$excludeFiles = @(
    '.gitignore',
    '.phpunit.result.cache',
    'README.md',
    'THREAD_MASTER.md',
    'build_deploy.ps1',
    'composer.json',
    'composer.lock',
    'ai_report_cache.json',
    'privacy_attempts.json',
    'config.local.php'
)

if ($IncludeLocalConfig) {
    $excludeFiles = $excludeFiles | Where-Object { $_ -ne 'config.local.php' }
}

$robocopyArgs = @(
    $projectRoot,
    $destination,
    '/E',
    '/XD'
) + $excludeDirs + @('/XF') + $excludeFiles

robocopy @robocopyArgs | Out-Host
$exitCode = $LASTEXITCODE
if ($exitCode -ge 8) {
    throw "Robocopy ha restituito un errore: $exitCode"
}

Write-Host "Cartella FTP pronta: $destination"
