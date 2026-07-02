# Deploy staff app BIB display + assigned bib schema.
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir

$files = @(
    @{ Local = 'includes\attendance-bib-schema.php'; Remote = 'includes/attendance-bib-schema.php' },
    @{ Local = 'includes\checkin-bib.php'; Remote = 'includes/checkin-bib.php' },
    @{ Local = 'includes\staff-app-v3-data.php'; Remote = 'includes/staff-app-v3-data.php' },
    @{ Local = 'includes\staff-app-v3-shell.php'; Remote = 'includes/staff-app-v3-shell.php' },
    @{ Local = 'includes\staff-app-v3-pages.php'; Remote = 'includes/staff-app-v3-pages.php' },
    @{ Local = 'includes\staff-app-easy.php'; Remote = 'includes/staff-app-easy.php' },
    @{ Local = 'includes\staff-venue-checkin.php'; Remote = 'includes/staff-venue-checkin.php' },
    @{ Local = 'staff-checkin.php'; Remote = 'staff-checkin.php' },
    @{ Local = 'staff-shifts.php'; Remote = 'staff-shifts.php' },
    @{ Local = 'staff-app.php'; Remote = 'staff-app.php' },
    @{ Local = 'staff-profile-hub.php'; Remote = 'staff-profile-hub.php' },
    @{ Local = 'assets\css\staff-app-v3.css'; Remote = 'assets/css/staff-app-v3.css' },
    @{ Local = 'assets\js\staff-app-v3.js'; Remote = 'assets/js/staff-app-v3.js' },
    @{ Local = 'cron\assign-staff-bibs.php'; Remote = 'cron/assign-staff-bibs.php' },
    @{ Local = 'cron\staff-bib-app-verify.php'; Remote = 'cron/staff-bib-app-verify.php' }
)

Write-Host 'Uploading staff app BIB...' -ForegroundColor Green
foreach ($entry in $files) {
    $local = Join-Path $ProjectRoot $entry.Local
    if (-not (Test-Path $local)) {
        throw "Missing local file: $local"
    }
    Send-FtpFile -LocalPath $local -RemoteRelativePath $entry.Remote -RemoteBase $base -Deploy $cfg
}
Write-Host 'Staff app BIB deploy complete.' -ForegroundColor Green
