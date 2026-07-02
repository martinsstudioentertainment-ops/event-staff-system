$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    @{ Local = 'includes\staff-portal-session.php'; Remote = 'includes/staff-portal-session.php' },
    @{ Local = 'includes\staff-profile-gate.php'; Remote = 'includes/staff-profile-gate.php' },
    @{ Local = 'includes\work-hours-repository.php'; Remote = 'includes/work-hours-repository.php' },
    @{ Local = 'includes\staff-portal-shift.php'; Remote = 'includes/staff-portal-shift.php' },
    @{ Local = 'staff-portal.php'; Remote = 'staff-portal.php' },
    @{ Local = 'staff-app.php'; Remote = 'staff-app.php' },
    @{ Local = 'admin\view-staff.php'; Remote = 'admin/view-staff.php' },
    @{ Local = 'admin\work-hours-action.php'; Remote = 'admin/work-hours-action.php' },
    @{ Local = 'assets\js\staff-shift-gps.js'; Remote = 'assets/js/staff-shift-gps.js' }
)

foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

Write-Host 'Deployed PPS staff sign-in + sent home on staff profile.' -ForegroundColor Green
