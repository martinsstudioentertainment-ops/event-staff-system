$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
Send-FtpFile -LocalPath (Join-Path $ProjectRoot 'cron\kodaleone-85-report.php') -RemoteRelativePath 'cron/kodaleone-85-report.php' -Deploy $cfg -RemoteBase $cfg.FtpRemoteDir
