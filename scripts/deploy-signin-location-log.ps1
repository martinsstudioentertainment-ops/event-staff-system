$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    @{ Local = 'includes\signin-location-log.php'; Remote = 'includes/signin-location-log.php' },
    @{ Local = 'includes\event-sign-flow.php'; Remote = 'includes/event-sign-flow.php' },
    @{ Local = 'includes\attendance-repository.php'; Remote = 'includes/attendance-repository.php' },
    @{ Local = 'api\signin-location-verify.php'; Remote = 'api/signin-location-verify.php' },
    @{ Local = 'assets\js\event-sign-location.js'; Remote = 'assets/js/event-sign-location.js' },
    @{ Local = 'admin\geo-audits.php'; Remote = 'admin/geo-audits.php' },
    @{ Local = 'admin\dashboard.php'; Remote = 'admin/dashboard.php' },
    @{ Local = 'assets\css\dashboard-s7-mockup.css'; Remote = 'assets/css/dashboard-s7-mockup.css' }
)

foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

Write-Host 'Deployed sign-in location log + dashboard restore.' -ForegroundColor Green
