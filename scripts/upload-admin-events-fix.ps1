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
