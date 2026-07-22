param(
    [switch] $SkipLocalConfig
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
    (Join-Path $projectRoot 'storage\uploads')
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
    'privacy_attempts.json'
)

if ($SkipLocalConfig) {
    $excludeFiles += 'config.local.php'
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

$uploadsDir = Join-Path $destination 'storage\uploads'
New-Item -ItemType Directory -Force -Path $uploadsDir | Out-Null
New-Item -ItemType File -Force -Path (Join-Path $uploadsDir '.gitkeep') | Out-Null

$aiReportsDir = Join-Path $destination 'storage\ai_reports'
New-Item -ItemType Directory -Force -Path $aiReportsDir | Out-Null
New-Item -ItemType File -Force -Path (Join-Path $aiReportsDir '.gitkeep') | Out-Null

Write-Host "Cartella FTP pronta: $destination"
