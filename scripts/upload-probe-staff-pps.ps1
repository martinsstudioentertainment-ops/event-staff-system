. "$PSScriptRoot\ftp-common.ps1"
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$cfg = Get-DeployConfig
Send-FtpFile `
    -LocalPath (Join-Path $ProjectRoot 'cron\probe-staff-pps.php') `
    -RemoteRelativePath 'cron/probe-staff-pps.php' `
    -RemoteBase $cfg.FtpRemoteDir `
    -Deploy $cfg
Write-Host 'Uploaded probe-staff-pps.php'
