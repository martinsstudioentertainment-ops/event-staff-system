$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
@(
    @{ Local = 'admin\events.php'; Remote = 'admin/events.php' },
    @{ Local = 'admin\event-form.php'; Remote = 'admin/event-form.php' },
    @{ Local = 'admin\delete-event.php'; Remote = 'admin/delete-event.php' },
    @{ Local = 'includes\event-complete-purge.php'; Remote = 'includes/event-complete-purge.php' },
    @{ Local = 'assets\css\admin-v3.css'; Remote = 'assets/css/admin-v3.css' },
    @{ Local = 'assets\js\admin.js'; Remote = 'assets/js/admin.js' }
) | ForEach-Object {
    Send-FtpFile -LocalPath (Join-Path $Root $_.Local) -RemoteRelativePath $_.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}
Write-Host 'Done.' -ForegroundColor Green
