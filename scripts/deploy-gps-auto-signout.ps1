$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    @{ Local = 'includes\attendance-gps-signout-schema.php'; Remote = 'includes/attendance-gps-signout-schema.php' },
    @{ Local = 'includes\attendance-gps-signout.php'; Remote = 'includes/attendance-gps-signout.php' },
    @{ Local = 'api\attendance-gps-ping.php'; Remote = 'api/attendance-gps-ping.php' },
    @{ Local = 'includes\event-sign-flow.php'; Remote = 'includes/event-sign-flow.php' },
    @{ Local = 'assets\js\event-sign-location.js'; Remote = 'assets/js/event-sign-location.js' },
    @{ Local = 'admin\event-form.php'; Remote = 'admin/event-form.php' }
)

foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

Write-Host 'Deployed GPS auto sign-out when leaving venue radius.' -ForegroundColor Green
