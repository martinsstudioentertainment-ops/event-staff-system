$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
Send-FtpFile -LocalPath (Join-Path $ProjectRoot 'cron\bib19-kodaleone-status.php') -RemoteRelativePath 'cron/bib19-kodaleone-status.php' -Deploy $cfg -RemoteBase $cfg.FtpRemoteDir
