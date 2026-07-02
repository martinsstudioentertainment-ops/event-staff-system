$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $ProjectRoot 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir

$files = @(
    @{ Local = 'includes\email-branding.php'; Remote = 'includes/email-branding.php' },
    @{ Local = 'includes\email-layout.php'; Remote = 'includes/email-layout.php' },
    @{ Local = 'storage\branding\olasentra-email-banner.png'; Remote = 'storage/branding/olasentra-email-banner.png' }
)

foreach ($entry in $files) {
    $local = Join-Path $ProjectRoot $entry.Local
    if (-not (Test-Path $local)) {
        throw "Missing: $local"
    }
    Send-FtpFile -LocalPath $local -RemoteRelativePath $entry.Remote -RemoteBase $base -Deploy $cfg
    Write-Host "uploaded: $($entry.Remote)" -ForegroundColor Green
}
