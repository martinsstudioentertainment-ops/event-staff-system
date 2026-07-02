# Hotfix: admin events blank page + WhatsApp share preview image.
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir

$files = @(
    @{ Local = 'admin\events.php'; Remote = 'admin/events.php' },
    @{ Local = 'admin\event-hub.php'; Remote = 'admin/event-hub.php' },
    @{ Local = 'admin\event-form.php'; Remote = 'admin/event-form.php' },
    @{ Local = 'admin\save-event.php'; Remote = 'admin/save-event.php' },
    @{ Local = 'admin\events-probe.php'; Remote = 'admin/events-probe.php' },
    @{ Local = 'includes\go-live-schema.php'; Remote = 'includes/go-live-schema.php' },
    @{ Local = 'includes\live-events-sync.php'; Remote = 'includes/live-events-sync.php' },
    @{ Local = 'includes\events-repository.php'; Remote = 'includes/events-repository.php' },
    @{ Local = 'includes\venues-repository.php'; Remote = 'includes/venues-repository.php' },
    @{ Local = 'includes\venues-schema.php'; Remote = 'includes/venues-schema.php' },
    @{ Local = 'includes\event-capacity.php'; Remote = 'includes/event-capacity.php' },
    @{ Local = 'includes\event-main-security-schema.php'; Remote = 'includes/event-main-security-schema.php' },
    @{ Local = 'includes\event-times-schema.php'; Remote = 'includes/event-times-schema.php' },
    @{ Local = 'includes\event-checkin-window-schema.php'; Remote = 'includes/event-checkin-window-schema.php' },
    @{ Local = 'includes\google-sheets-schema.php'; Remote = 'includes/google-sheets-schema.php' },
    @{ Local = 'includes\google-sheets-sync.php'; Remote = 'includes/google-sheets-sync.php' },
    @{ Local = 'includes\pwa-head.php'; Remote = 'includes/pwa-head.php' },
    @{ Local = 'includes\share-meta.php'; Remote = 'includes/share-meta.php' },
    @{ Local = 'og-image.php'; Remote = 'og-image.php' },
    @{ Local = 'storage\branding\olasentra-whatsapp-share.png'; Remote = 'storage/branding/olasentra-whatsapp-share.png' }
)

Write-Host 'Uploading admin events + WhatsApp preview fix...' -ForegroundColor Green
foreach ($entry in $files) {
    Send-FtpFile -LocalPath (Join-Path $ProjectRoot $entry.Local) -RemoteRelativePath $entry.Remote -RemoteBase $base -Deploy $cfg
}
Write-Host 'Done.' -ForegroundColor Green
