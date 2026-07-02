. "$PSScriptRoot\ftp-common.ps1"
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$cfg = Get-DeployConfig
Send-FtpFile `
    -LocalPath (Join-Path $ProjectRoot 'cron\michael-buble-roster-assign.php') `
    -RemoteRelativePath 'cron/michael-buble-roster-assign.php' `
    -RemoteBase $cfg.FtpRemoteDir `
    -Deploy $cfg
Write-Host 'Uploaded cron/michael-buble-roster-assign.php'
