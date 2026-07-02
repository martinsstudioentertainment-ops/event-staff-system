$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
foreach ($f in @(
    @{ Local = 'admin\save-event.php'; Remote = 'admin/save-event.php' },
    @{ Local = 'includes\smtp-mailer.php'; Remote = 'includes/smtp-mailer.php' }
)) {
    Send-FtpFile -LocalPath (Join-Path $ProjectRoot $f.Local) -RemoteRelativePath $f.Remote -Deploy $cfg -RemoteBase $cfg.FtpRemoteDir
}
Write-Host 'Deployed save-event + smtp-mailer fix' -ForegroundColor Green
