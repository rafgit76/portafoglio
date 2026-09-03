param([string] $Php = 'C:\php84\php.exe', [switch] $LiveWebsiteCheck)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
. (Join-Path $project 'deploy\Deploy.Common.ps1')
# Load only these pure/helper definitions, without running the interactive publisher.
$tokens = $null; $errors = $null
$ast = [Management.Automation.Language.Parser]::ParseFile((Join-Path $project 'publish.ps1'), [ref] $tokens, [ref] $errors)
if ($errors.Count) { throw 'Sintassi publisher non valida.' }
foreach ($name in @('Initialize-PrivateDirectory', 'Read-DeployCredential', 'Test-PublishPackage', 'Test-PublishedWebsite')) {
    $node = $ast.Find({ param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq $name }, $false)
    . ([scriptblock]::Create($node.Extent.Text))
}
$script:checks = 0
function Assert-Entry { param([bool] $Condition) if (-not $Condition) { throw 'Test entry point fallito.' }; $script:checks++ }
function Assert-Blocked { param([scriptblock] $Action) $blocked = $false; try { & $Action | Out-Null } catch { $blocked = $true }; Assert-Entry $blocked }
function Read-Host {
    param([string] $Prompt, [switch] $AsSecureString)
    $script:credentialReads++
    if ($Prompt -like 'Password*') {
        Assert-Entry $AsSecureString.IsPresent
        if ($script:cancelPassword) { throw 'Annullamento simulato.' }
        return $script:mockPassword
    }
    Assert-Entry (-not $AsSecureString.IsPresent)
    return $script:mockUser
}
$script:credentialReads = 0; $script:cancelPassword = $false
$script:mockUser = ' fixture '
$script:mockPassword = ConvertTo-SecureString ' ONLY-SYNTHETIC-TEST ' -AsPlainText -Force
$consoleCredential = Read-DeployCredential
Assert-Entry ($consoleCredential -is [Management.Automation.PSCredential])
Assert-Entry ($consoleCredential.UserName -ceq 'fixture')
Assert-Entry ($consoleCredential.GetNetworkCredential().Password -ceq ' ONLY-SYNTHETIC-TEST ')
Assert-Entry ($script:credentialReads -eq 2)
$script:mockUser = ' '; $script:credentialReads = 0
Assert-Blocked { Read-DeployCredential }
Assert-Entry ($script:credentialReads -eq 1)
$script:mockUser = 'fixture'; $script:mockPassword = New-Object Security.SecureString
Assert-Blocked { Read-DeployCredential }
$script:cancelPassword = $true
Assert-Blocked { Read-DeployCredential }
Assert-Entry ($null -eq $ast.Find({ param($node) $node -is [Management.Automation.Language.CommandAst] -and $node.GetCommandName() -eq 'Get-Credential' }, $true))
$temp = Join-Path ([IO.Path]::GetTempPath()) ('portfolio-publisher-entry-' + [Guid]::NewGuid().ToString('N'))
Initialize-PrivateDirectory $temp
Initialize-PrivateDirectory $temp
$acl = (New-Object IO.DirectoryInfo($temp)).GetAccessControl()
Assert-Entry $acl.AreAccessRulesProtected
Assert-Entry ($acl.Access.Count -eq 3)
$fake = New-Object Management.Automation.PSCredential('fixture', (ConvertTo-SecureString 'ONLY-SYNTHETIC-TEST' -AsPlainText -Force))
$fake | Export-Clixml -LiteralPath (Join-Path $temp 'fixture.clixml')
Assert-Entry ((Get-Content -LiteralPath (Join-Path $temp 'fixture.clixml') -Raw) -notmatch 'ONLY-SYNTHETIC-TEST')
$roundTrip = Import-Clixml -LiteralPath (Join-Path $temp 'fixture.clixml')
Assert-Entry ($roundTrip.GetNetworkCredential().Password -ceq 'ONLY-SYNTHETIC-TEST')
$run = Join-Path $temp 'run'
$files = @(New-PublishPackage $project $run)
$version = Test-PublishPackage $Php (Join-Path $run 'package') $files
Assert-Entry ($version -match '^\d+\.\d+\.\d+$')
[IO.File]::WriteAllText((Join-Path $run 'package\VERSION'), '0.0.0')
Assert-Blocked { Test-PublishPackage $Php (Join-Path $run 'package') $files }
[IO.File]::WriteAllText((Join-Path $run 'package\VERSION'), $version)
[IO.File]::WriteAllText((Join-Path $run 'package\index.php'), '<?php syntax error')
Assert-Blocked { Test-PublishPackage $Php (Join-Path $run 'package') $files }
if ($LiveWebsiteCheck) {
    Test-PublishedWebsite 'https://www.raffimac.it/apps/portafoglio' $version
    Assert-Entry $true
    Write-Host 'Controllo HTTPS home/VERSION OK. Nessun PIN, login FTP o upload.'
}
function Invoke-WebRequest {
    param($Uri, [switch] $UseBasicParsing, $TimeoutSec, $MaximumRedirection)
    if ($Uri -match '/VERSION\?') { return [pscustomobject]@{ StatusCode = 200; Content = [Text.Encoding]::UTF8.GetBytes($script:mockVersion) } }
    return [pscustomobject]@{ StatusCode = 200; Content = $script:mockHome }
}
$script:mockVersion = $version
$script:mockHome = "Portfolio Investimenti v$version"
Test-PublishedWebsite 'https://example.test/portafoglio' $version
Assert-Entry $true
$script:mockVersion = '0.0.0'
Assert-Blocked { Test-PublishedWebsite 'https://example.test/portafoglio' $version }
$script:mockVersion = $version; $script:mockHome = 'Wrong website'
Assert-Blocked { Test-PublishedWebsite 'https://example.test/portafoglio' $version }
Write-Host "OK: $script:checks controlli credenziali terminale, ACL, DPAPI, PHP, versione e verifica web. Nessuna credenziale reale."
