$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
Send-FtpFile -LocalPath (Join-Path $ProjectRoot 'cron\bib-web-verify.php') -RemoteRelativePath 'cron/bib-web-verify.php' -Deploy $cfg -RemoteBase $cfg.FtpRemoteDir
Write-Host 'Uploaded cron/bib-web-verify.php' -ForegroundColor Green
