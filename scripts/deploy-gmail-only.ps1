$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig
$files = @(
    @{ Local = 'index.php'; Remote = 'index.php' },
    @{ Local = 'submit.php'; Remote = 'submit.php' },
    @{ Local = 'includes\staff-google-oauth.php'; Remote = 'includes/staff-google-oauth.php' },
    @{ Local = 'includes\registration-google-gate.php'; Remote = 'includes/registration-google-gate.php' },
    @{ Local = 'includes\staff-profile-gate.php'; Remote = 'includes/staff-profile-gate.php' },
    @{ Local = 'includes\staff-app-easy.php'; Remote = 'includes/staff-app-easy.php' },
    @{ Local = 'includes\settings-repository.php'; Remote = 'includes/settings-repository.php' },
    @{ Local = 'includes\admin\settings-handler.php'; Remote = 'includes/admin/settings-handler.php' }
)
foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
    Write-Host "Uploaded $($f.Remote)" -ForegroundColor Green
}
Write-Host 'Gmail-only registration + sign-in deployed.' -ForegroundColor Cyan
