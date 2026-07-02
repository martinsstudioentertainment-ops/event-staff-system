# Deploy staff app BIB UI to admin public_html AND register.olasentra.com docroot.
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

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
    @{ Local = 'assets\js\staff-app-v3.js'; Remote = 'assets/js/staff-app-v3.js' }
)

$bases = @(
    @{ Label = 'admin'; Base = $cfg.FtpRemoteDir },
    @{ Label = 'register'; Base = '/register.olasentra.com' }
)

foreach ($target in $bases) {
    Write-Host "=== Deploying to $($target.Label) ($($target.Base)) ===" -ForegroundColor Green
    foreach ($entry in $files) {
        $local = Join-Path $ProjectRoot $entry.Local
        if (-not (Test-Path $local)) {
            throw "Missing local file: $local"
        }
        $remoteDir = ($entry.Remote -replace '\\', '/') -replace '/[^/]+$', ''
        if ($remoteDir -ne '') {
            Ensure-FtpDirectoryTree -Server $cfg.FtpServer -RemoteDir "$($target.Base.TrimEnd('/'))/$remoteDir" -Deploy $cfg
        }
        Send-FtpFile -LocalPath $local -RemoteRelativePath $entry.Remote -RemoteBase $target.Base -Deploy $cfg
    }
}

Write-Host 'Staff app BIB deploy complete (admin + register).' -ForegroundColor Green
