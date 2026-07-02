. "$PSScriptRoot\ftp-common.ps1"
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$cfg = Get-DeployConfig
Send-FtpFile `
    -LocalPath (Join-Path $ProjectRoot 'cron\canonical-email-normalize.php') `
    -RemoteRelativePath 'cron/canonical-email-normalize.php' `
    -RemoteBase $cfg.FtpRemoteDir `
    -Deploy $cfg
Write-Host 'Uploaded canonical-email-normalize.php'
