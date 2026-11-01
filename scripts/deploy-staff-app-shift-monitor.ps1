$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    @{ Local = 'includes\staff-portal-remember.php'; Remote = 'includes/staff-portal-remember.php' },
    @{ Local = 'includes\staff-portal-shift.php'; Remote = 'includes/staff-portal-shift.php' },
    @{ Local = 'includes\staff-portal-session.php'; Remote = 'includes/staff-portal-session.php' },
    @{ Local = 'includes\staff-profile-gate.php'; Remote = 'includes/staff-profile-gate.php' },
    @{ Local = 'api\staff-shift-gps.php'; Remote = 'api/staff-shift-gps.php' },
    @{ Local = 'assets\js\staff-shift-gps.js'; Remote = 'assets/js/staff-shift-gps.js' },
    @{ Local = 'staff-app.php'; Remote = 'staff-app.php' },
    @{ Local = 'staff-notifications.php'; Remote = 'staff-notifications.php' },
    @{ Local = 'staff-messages.php'; Remote = 'staff-messages.php' },
    @{ Local = 'staff-profile.php'; Remote = 'staff-profile.php' },
    @{ Local = 'status.php'; Remote = 'status.php' },
    @{ Local = 'includes\event-sign-flow.php'; Remote = 'includes/event-sign-flow.php' }
)

foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

Write-Host 'Deployed staff app remember cookie + shift GPS monitor.' -ForegroundColor Green
