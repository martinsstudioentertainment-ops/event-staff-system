# Deploy test-account purge cron + registrant purge helper.
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir

$files = @(
    @{ Local = 'includes\registrant-complete-purge.php'; Remote = 'includes/registrant-complete-purge.php' },
    @{ Local = 'cron\purge-named-test-accounts.php'; Remote = 'cron/purge-named-test-accounts.php' }
)

Write-Host 'Uploading test-account purge scripts...' -ForegroundColor Green
foreach ($f in $files) {
    $localPath = Join-Path $ProjectRoot $f.Local
    if (-not (Test-Path $localPath)) {
        throw "Missing file: $($f.Local)"
    }
    Send-FtpFile -LocalPath $localPath -RemoteRelativePath $f.Remote -RemoteBase $base -Deploy $cfg
}
Write-Host 'Done.' -ForegroundColor Green
