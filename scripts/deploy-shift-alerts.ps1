$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    'includes/event-staff-alerts.php',
    'includes/settings-repository.php',
    'includes/admin/settings-handler.php',
    'admin/save-event.php',
    'admin/toggle-event.php',
    'admin/settings-email.php'
)

foreach ($remote in $files) {
    $local = $remote -replace '/', '\'
    Write-Host "-> $remote" -ForegroundColor Gray
    Send-FtpFile -LocalPath (Join-Path $ProjectRoot $local) -RemoteRelativePath $remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

Write-Host 'Shift alert notifications deployed.' -ForegroundColor Green
