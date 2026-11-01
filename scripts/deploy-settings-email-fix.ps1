$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    'includes/admin/settings-handler.php',
    'includes/admin/layout-top.php',
    'includes/auth.php',
    'includes/app-environment.php',
    'includes/email-layout.php',
    'includes/email-branding.php'
)

foreach ($remote in $files) {
    $local = $remote -replace '/', '\'
    Write-Host "-> $remote" -ForegroundColor Gray
    Send-FtpFile -LocalPath (Join-Path $ProjectRoot $local) -RemoteRelativePath $remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

Write-Host 'Settings email fix deployed.' -ForegroundColor Green
