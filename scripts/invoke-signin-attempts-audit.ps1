param([string]$Date = '')
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

Send-FtpFile -LocalPath (Join-Path $Root 'cron\signin-attempts-audit.php') -RemoteRelativePath 'cron/signin-attempts-audit.php' -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg

$key = & (Join-Path $Root 'scripts\fetch-cron-key.ps1')
$url = 'https://admin.olasentra.com/cron/signin-attempts-audit.php?key=' + [uri]::EscapeDataString($key)
if ($Date -ne '') {
    $url += '&date=' + [uri]::EscapeDataString($Date)
}

Write-Host "Fetching: $url" -ForegroundColor Cyan
$json = curl.exe -s $url
$outDir = Join-Path $Root 'storage\reports'
if (-not (Test-Path $outDir)) { New-Item -ItemType Directory -Path $outDir -Force | Out-Null }
$outFile = Join-Path $outDir 'signin-attempts-audit-latest.json'
$json | Out-File $outFile -Encoding utf8
Write-Host $json
