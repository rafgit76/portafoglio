param([Parameter(Mandatory = $true)][string] $Destination)
$ErrorActionPreference = 'Stop'
# Pinned official automation package, not a system-wide installation.
$version = '6.5.6'
$expectedHash = '49d1f193a52e7cb65fe503a30819956b88ee7d870f47e1026833fda91cc26486'
if (Test-Path -LiteralPath $Destination) { throw 'Destinazione WinSCP gia presente; non sovrascritta.' }
$download = 'https://winscp.net/download/WinSCP-' + $version + '-Automation.zip/download'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$page = Invoke-WebRequest -Uri $download -UseBasicParsing -TimeoutSec 45
$match = [regex]::Match($page.Content, 'href="(https://cdn\.winscp\.net/files/WinSCP-6\.5\.6-Automation\.zip[^"<>]*)"')
if (-not $match.Success) { throw 'Link ufficiale WinSCP non trovato. Nessun download eseguito.' }
$zip = Join-Path ([IO.Path]::GetTempPath()) ('winscp-' + [Guid]::NewGuid().ToString('N') + '.zip')
Invoke-WebRequest -Uri ([Net.WebUtility]::HtmlDecode($match.Groups[1].Value)) -OutFile $zip -UseBasicParsing -TimeoutSec 120
if ((Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash -ne $expectedHash) { throw "Checksum WinSCP non corrispondente: non eseguito. Archivio: $zip" }
Expand-Archive -LiteralPath $zip -DestinationPath $Destination
foreach ($name in @('WinSCP.exe', 'WinSCPnet.dll')) {
    if (-not (Test-Path -LiteralPath (Join-Path $Destination $name))) { throw 'Pacchetto WinSCP incompleto.' }
}
Write-Host "WinSCP verificato e pronto: $Destination"
