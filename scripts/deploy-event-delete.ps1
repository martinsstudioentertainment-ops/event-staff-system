$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $PSScriptRoot 'ftp-common.ps1')

$cfg = Get-DeployConfig
$files = @(
    @{ Local = 'includes\event-complete-purge.php'; Remote = 'includes/event-complete-purge.php' },
    @{ Local = 'admin\delete-event.php'; Remote = 'admin/delete-event.php' },
    @{ Local = 'admin\event-form.php'; Remote = 'admin/event-form.php' },
    @{ Local = 'admin\events.php'; Remote = 'admin/events.php' },
    @{ Local = 'assets\css\admin-v3.css'; Remote = 'assets/css/admin-v3.css' }
)

Write-Host 'Uploading event delete feature...' -ForegroundColor Green
foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}
Write-Host 'Done.' -ForegroundColor Green
