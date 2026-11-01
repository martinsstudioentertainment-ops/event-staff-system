$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    @{ Local = 'includes\event-checkin-window-schema.php'; Remote = 'includes/event-checkin-window-schema.php' },
    @{ Local = 'includes\attendance-repository.php'; Remote = 'includes/attendance-repository.php' },
    @{ Local = 'includes\events-repository.php'; Remote = 'includes/events-repository.php' },
    @{ Local = 'admin\event-form.php'; Remote = 'admin/event-form.php' },
    @{ Local = 'includes\notification-center.php'; Remote = 'includes/notification-center.php' },
    @{ Local = 'includes\notifications.php'; Remote = 'includes/notifications.php' },
    @{ Local = 'includes\settings-repository.php'; Remote = 'includes/settings-repository.php' },
    @{ Local = 'includes\admin\settings-handler.php'; Remote = 'includes/admin/settings-handler.php' },
    @{ Local = 'admin\settings-email.php'; Remote = 'admin/settings-email.php' },
    @{ Local = 'admin\settings-site.php'; Remote = 'admin/settings-site.php' }
)

foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

Write-Host 'Deployed sign-in window settings + multi ops email alerts.' -ForegroundColor Green
