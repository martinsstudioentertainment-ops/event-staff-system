$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    'admin/view-staff.php',
    'includes/staff-repository.php',
    'includes/staff-app-v3-data.php',
    'includes/status-repository.php',
    'includes/components/staff-status-dashboard.php',
    'assets/css/variables.css',
    'assets/css/style.css'
)

foreach ($remote in $files) {
    $local = Join-Path $Root ($remote -replace '/', '\')
    Write-Host "  -> $remote"
    Send-FtpFile -LocalPath $local -RemoteRelativePath $remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

Write-Host 'Shift outcome files uploaded.'
