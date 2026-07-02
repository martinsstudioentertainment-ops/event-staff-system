# One-off: deploy BIB capture on web check-in.
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    @{ Local = 'includes\attendance-bib-schema.php'; Remote = 'includes/attendance-bib-schema.php' },
    @{ Local = 'includes\checkin-bib.php'; Remote = 'includes/checkin-bib.php' },
    @{ Local = 'includes\attendance-repository.php'; Remote = 'includes/attendance-repository.php' },
    @{ Local = 'includes\event-sign-flow.php'; Remote = 'includes/event-sign-flow.php' },
    @{ Local = 'includes\event-signin-export.php'; Remote = 'includes/event-signin-export.php' },
    @{ Local = 'includes\admin\attendance-roster-row.php'; Remote = 'includes/admin/attendance-roster-row.php' },
    @{ Local = 'admin\scan-checkin-action.php'; Remote = 'admin/scan-checkin-action.php' },
    @{ Local = 'admin\scan-checkin.php'; Remote = 'admin/scan-checkin.php' },
    @{ Local = 'admin\attendance.php'; Remote = 'admin/attendance.php' },
    @{ Local = 'admin\export-attendance.php'; Remote = 'admin/export-attendance.php' },
    @{ Local = 'admin\print-roster.php'; Remote = 'admin/print-roster.php' },
    @{ Local = 'assets\js\admin-scan-checkin.js'; Remote = 'assets/js/admin-scan-checkin.js' }
)

foreach ($f in $files) {
    $local = Join-Path $ProjectRoot $f.Local
    if (-not (Test-Path $local)) {
        throw "Missing local file: $local"
    }
    Send-FtpFile -LocalPath $local -RemoteRelativePath $f.Remote -Deploy $cfg -RemoteBase $cfg.FtpRemoteDir
}

Write-Host 'BIB web deploy complete.' -ForegroundColor Green
