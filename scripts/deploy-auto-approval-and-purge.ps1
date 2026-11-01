$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    'includes/platform/auto-approval-engine.php',
    'admin/auto-approval.php',
    'cron/apply-auto-approval-low-threshold.php',
    'cron/purge-test-registrant.php',
    'includes/registrant-complete-purge.php'
)

foreach ($remote in $files) {
    $local = $remote -replace '/', '\'
    Write-Host "-> $remote" -ForegroundColor Gray
    Send-FtpFile -LocalPath (Join-Path $ProjectRoot $local) -RemoteRelativePath $remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

Write-Host 'Deployed auto-approval + purge scripts.' -ForegroundColor Green
