$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig
$files = @(
    @{ Local = 'includes\brand-logo.php'; Remote = 'includes/brand-logo.php' },
    @{ Local = 'includes\share-meta.php'; Remote = 'includes/share-meta.php' },
    @{ Local = 'includes\admin\website-handler.php'; Remote = 'includes/admin/website-handler.php' },
    @{ Local = 'og-image.php'; Remote = 'og-image.php' },
    @{ Local = 'admin\website-global.php'; Remote = 'admin/website-global.php' }
)
foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
    Write-Host "Uploaded $($f.Remote)" -ForegroundColor Green
}
