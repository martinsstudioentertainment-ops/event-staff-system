# Restore sign-in window, shift status, backup download — does not touch dashboard/events fixes.
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir

$files = @(
    @{ Local = 'admin\backup-download.php'; Remote = 'admin/backup-download.php' },
    @{ Local = 'admin\event-form.php'; Remote = 'admin/event-form.php' },
    @{ Local = 'admin\save-event.php'; Remote = 'admin/save-event.php' },
    @{ Local = 'admin\view-staff.php'; Remote = 'admin/view-staff.php' },
    @{ Local = 'includes\events-repository.php'; Remote = 'includes/events-repository.php' },
    @{ Local = 'includes\attendance-repository.php'; Remote = 'includes/attendance-repository.php' },
    @{ Local = 'includes\event-checkin-window-schema.php'; Remote = 'includes/event-checkin-window-schema.php' },
    @{ Local = 'includes\components\staff-status-dashboard.php'; Remote = 'includes/components/staff-status-dashboard.php' },
    @{ Local = 'status.php'; Remote = 'status.php' },
    @{ Local = 'includes\staff-repository.php'; Remote = 'includes/staff-repository.php' }
)

Write-Host 'Restoring missing features to production...' -ForegroundColor Green
foreach ($entry in $files) {
    Send-FtpFile -LocalPath (Join-Path $ProjectRoot $entry.Local) -RemoteRelativePath $entry.Remote -RemoteBase $base -Deploy $cfg
}
Write-Host 'Done.' -ForegroundColor Green
