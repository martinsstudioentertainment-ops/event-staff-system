$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    @{ Local = 'storage\branding\olasentra-logo-master.png'; Remote = 'storage/branding/olasentra-logo-master.png' },
    @{ Local = 'storage\branding\olasentra-whatsapp-share.png'; Remote = 'storage/branding/olasentra-whatsapp-share.png' },
    @{ Local = 'storage\branding\mobile\app-logo.png'; Remote = 'storage/branding/mobile/app-logo.png' },
    @{ Local = 'storage\branding\mobile\splash-logo.png'; Remote = 'storage/branding/mobile/splash-logo.png' },
    @{ Local = 'storage\branding\mobile\login-logo.png'; Remote = 'storage/branding/mobile/login-logo.png' },
    @{ Local = 'storage\branding\mobile\dashboard-logo.png'; Remote = 'storage/branding/mobile/dashboard-logo.png' },
    @{ Local = 'assets\icons\pwa\icon-48.png'; Remote = 'assets/icons/pwa/icon-48.png' },
    @{ Local = 'assets\icons\pwa\icon-72.png'; Remote = 'assets/icons/pwa/icon-72.png' },
    @{ Local = 'assets\icons\pwa\icon-96.png'; Remote = 'assets/icons/pwa/icon-96.png' },
    @{ Local = 'assets\icons\pwa\icon-144.png'; Remote = 'assets/icons/pwa/icon-144.png' },
    @{ Local = 'assets\icons\pwa\icon-180.png'; Remote = 'assets/icons/pwa/icon-180.png' },
    @{ Local = 'assets\icons\pwa\icon-192.png'; Remote = 'assets/icons/pwa/icon-192.png' },
    @{ Local = 'assets\icons\pwa\icon-512.png'; Remote = 'assets/icons/pwa/icon-512.png' },
    @{ Local = 'assets\icons\pwa\icon-maskable-512.png'; Remote = 'assets/icons/pwa/icon-maskable-512.png' },
    @{ Local = 'cron\apply-olasentra-branding.php'; Remote = 'cron/apply-olasentra-branding.php' },
    @{ Local = 'includes\brand-logo.php'; Remote = 'includes/brand-logo.php' },
    @{ Local = 'includes\share-meta.php'; Remote = 'includes/share-meta.php' },
    @{ Local = 'includes\admin\website-handler.php'; Remote = 'includes/admin/website-handler.php' },
    @{ Local = 'og-image.php'; Remote = 'og-image.php' }
)

foreach ($f in $files) {
    $localPath = Join-Path $Root $f.Local
    if (-not (Test-Path $localPath)) {
        Write-Host "Skip missing: $($f.Local)" -ForegroundColor Yellow
        continue
    }
    Send-FtpFile -LocalPath $localPath -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
    Write-Host "Uploaded $($f.Remote)" -ForegroundColor Green
}

Write-Host 'Files uploaded. Applying settings on server...' -ForegroundColor Cyan
$applyUrl = 'https://register.olasentra.com/cron/apply-olasentra-branding.php?key=email-encoding-verify-20260606'
try {
    $resp = Invoke-RestMethod -Uri $applyUrl -Method Get -TimeoutSec 120
    $resp | ConvertTo-Json -Depth 5
} catch {
    Write-Host "Apply request failed: $_" -ForegroundColor Red
    throw
}
