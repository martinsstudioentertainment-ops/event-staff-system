$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    @{ Local = 'storage\branding\olasentra-logo-master.png'; Remote = 'storage/branding/olasentra-logo-master.png' },
    @{ Local = 'storage\branding\olasentra-whatsapp-share.png'; Remote = 'storage/branding/olasentra-whatsapp-share.png' },
    @{ Local = 'cron\apply-olasentra-branding.php'; Remote = 'cron/apply-olasentra-branding.php' },
    @{ Local = 'includes\brand-logo.php'; Remote = 'includes/brand-logo.php' },
    @{ Local = 'og-image.php'; Remote = 'og-image.php' },
    @{ Local = 'includes\share-meta.php'; Remote = 'includes/share-meta.php' }
)

foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
    Write-Host "Uploaded $($f.Remote)" -ForegroundColor Green
}

Write-Host 'Files uploaded. Applying settings on server...' -ForegroundColor Cyan
$applyUrl = 'https://register.olasentra.com/cron/apply-olasentra-branding.php?key=$env:OLASENTRA_CRON_KEY'
try {
    $resp = Invoke-RestMethod -Uri $applyUrl -Method Get -TimeoutSec 120
    $resp | ConvertTo-Json -Depth 5
} catch {
    Write-Host "Apply request failed: $_" -ForegroundColor Red
    throw
}
