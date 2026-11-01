$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    @{ Local = 'includes\pwa-install-analytics.php'; Remote = 'includes/pwa-install-analytics.php' },
    @{ Local = 'includes\pwa-scripts.php'; Remote = 'includes/pwa-scripts.php' },
    @{ Local = 'api\pwa-install-event.php'; Remote = 'api/pwa-install-event.php' },
    @{ Local = 'assets\js\pwa-install-analytics.js'; Remote = 'assets/js/pwa-install-analytics.js' },
    @{ Local = 'assets\js\pwa-install.js'; Remote = 'assets/js/pwa-install.js' },
    @{ Local = 'staff-app.php'; Remote = 'staff-app.php' },
    @{ Local = 'admin\dashboard.php'; Remote = 'admin/dashboard.php' }
)

foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

Write-Host 'Deployed PWA install analytics (dashboard + staff app tracking).' -ForegroundColor Green
