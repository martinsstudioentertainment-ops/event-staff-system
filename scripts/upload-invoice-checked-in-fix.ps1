# Invoice: only bill staff who actually checked in at the venue.
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir

$files = @(
    @{ Local = 'includes\commission-invoice-repository.php'; Remote = 'includes/commission-invoice-repository.php' },
    @{ Local = 'includes\work-hours-repository.php'; Remote = 'includes/work-hours-repository.php' },
    @{ Local = 'admin\invoice-form.php'; Remote = 'admin/invoice-form.php' },
    @{ Local = 'admin\invoice-action.php'; Remote = 'admin/invoice-action.php' },
    @{ Local = 'cron\rebuild-commission-invoice.php'; Remote = 'cron/rebuild-commission-invoice.php' }
)

Write-Host 'Uploading invoice checked-in staff fix...' -ForegroundColor Green
foreach ($f in $files) {
    $localPath = Join-Path $ProjectRoot $f.Local
    if (-not (Test-Path $localPath)) {
        throw "Missing file: $($f.Local)"
    }
    if ((Get-Item $localPath).Length -lt 1) {
        throw "Refusing 0-byte upload: $($f.Local)"
    }
    Send-FtpFile -LocalPath $localPath -RemoteRelativePath $f.Remote -RemoteBase $base -Deploy $cfg
}
Write-Host 'Done.' -ForegroundColor Green
