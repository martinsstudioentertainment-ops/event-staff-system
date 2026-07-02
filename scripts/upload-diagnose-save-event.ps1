$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
Send-FtpFile -LocalPath (Join-Path $ProjectRoot 'cron\diagnose-save-event.php') -RemoteRelativePath 'cron/diagnose-save-event.php' -Deploy $cfg -RemoteBase $cfg.FtpRemoteDir
