$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

$main = @(
    'includes/app-environment.php',
    'includes/auth.php',
    'includes/admin/layout-top.php'
)

foreach ($remote in $main) {
    $local = $remote -replace '/', '\'
    Write-Host "Main -> $remote" -ForegroundColor Gray
    Send-FtpFile -LocalPath (Join-Path $ProjectRoot $local) -RemoteRelativePath $remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

$apply = @(
    'includes/app-environment-shim.php',
    'includes/secure-layout.php',
    'includes/auth.php'
)

foreach ($remote in $apply) {
    $local = Join-Path $ProjectRoot ('apply\admin\' + ($remote -replace '/', '\'))
    Write-Host "Apply -> apply/admin/$remote" -ForegroundColor Gray
    Send-FtpFile -LocalPath $local -RemoteRelativePath $remote -RemoteBase $cfg.FtpApplyRemoteDir -Deploy $cfg
}

Write-Host 'Admin idle timeout (10 min) deployed.' -ForegroundColor Green
