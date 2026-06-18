$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

Send-FtpFile -LocalPath (Join-Path $Root 'includes\event-sign-flow.php') -RemoteRelativePath 'includes/event-sign-flow.php' -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
Send-FtpFile -LocalPath (Join-Path $Root 'cron\venue-signin-gps-audit.php') -RemoteRelativePath 'cron/venue-signin-gps-audit.php' -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg

$key = & (Join-Path $Root 'scripts\fetch-cron-key.ps1')
$url = 'https://admin.olasentra.com/cron/venue-signin-gps-audit.php?key=' + [uri]::EscapeDataString($key)
Write-Host "Fetching: $url" -ForegroundColor Cyan
$json = curl.exe -s $url
$outDir = Join-Path $Root 'storage\reports'
if (-not (Test-Path $outDir)) { New-Item -ItemType Directory -Path $outDir -Force | Out-Null }
$outFile = Join-Path $outDir 'venue-signin-gps-audit-latest.json'
$json | Out-File $outFile -Encoding utf8
Write-Host $json
