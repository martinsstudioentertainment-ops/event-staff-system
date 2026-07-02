$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
Send-FtpFile -LocalPath (Join-Path $ProjectRoot 'cron\kodaleone-fix-three-staff.php') -RemoteRelativePath 'cron/kodaleone-fix-three-staff.php' -Deploy $cfg -RemoteBase $cfg.FtpRemoteDir
