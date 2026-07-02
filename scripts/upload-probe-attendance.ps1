. "$PSScriptRoot\ftp-common.ps1"
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$cfg = Get-DeployConfig
Send-FtpFile -LocalPath (Join-Path $ProjectRoot 'cron\probe-attendance-list.php') -RemoteRelativePath 'cron/probe-attendance-list.php' -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
