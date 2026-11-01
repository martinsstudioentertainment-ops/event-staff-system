$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    'includes/staff-app-v3-shell.php',
    'includes/share-meta.php',
    'includes/brand-logo.php',
    'og-image.php',
    'storage/branding/olasentra-whatsapp-share.png'
)

foreach ($remote in $files) {
    $local = $remote -replace '/', '\'
    Write-Host "-> $remote" -ForegroundColor Gray
    Send-FtpFile -LocalPath (Join-Path $ProjectRoot $local) -RemoteRelativePath $remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

Write-Host 'WhatsApp preview fix deployed.' -ForegroundColor Green
