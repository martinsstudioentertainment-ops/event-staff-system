$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
Send-FtpFile -LocalPath (Join-Path $ProjectRoot 'cron\lookup-staff-by-phone.php') -RemoteRelativePath 'cron/lookup-staff-by-phone.php' -Deploy $cfg -RemoteBase $cfg.FtpRemoteDir
Write-Host 'Uploaded cron/lookup-staff-by-phone.php' -ForegroundColor Green
