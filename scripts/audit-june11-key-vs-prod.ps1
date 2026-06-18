$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$client = New-Object System.Net.WebClient
$client.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)

# Known work items 11 Jun 2026 (1am-9am session from transcript + file timestamps)
$keyFiles = @(
    @{ Area = 'Sign-in: email/PPS after GPS'; Path = 'includes/event-sign-flow.php' },
    @{ Area = 'Sign-in: email/PPS after GPS'; Path = 'assets/js/event-sign-location.js' },
    @{ Area = 'Sign-in: venue GPS audit'; Path = 'cron/venue-signin-gps-audit.php' },
    @{ Area = 'Sign-in: attempts audit'; Path = 'cron/signin-attempts-audit.php' },
    @{ Area = 'Sign-in: location log'; Path = 'includes/signin-location-log.php' },
    @{ Area = 'Sign-in: location API'; Path = 'api/signin-location-verify.php' },
    @{ Area = 'Sign-in: geo audits page'; Path = 'admin/geo-audits.php' },
    @{ Area = 'Sign-in: attendance merge'; Path = 'includes/attendance-repository.php' },
    @{ Area = 'Check-in window UI'; Path = 'admin/event-form.php' },
    @{ Area = 'Check-in window save'; Path = 'includes/events-repository.php' },
    @{ Area = 'Check-in window schema'; Path = 'includes/event-checkin-window-schema.php' },
    @{ Area = 'Check-in window save'; Path = 'admin/save-event.php' },
    @{ Area = 'Ops email alerts'; Path = 'includes/notification-center.php' },
    @{ Area = 'Ops email alerts'; Path = 'includes/notifications.php' },
    @{ Area = 'Ops email settings'; Path = 'admin/settings-email.php' },
    @{ Area = 'Ops email settings'; Path = 'includes/admin/settings-handler.php' },
    @{ Area = 'Manual sign-in'; Path = 'admin/manual-signin.php' },
    @{ Area = 'Manual sign-in'; Path = 'admin/manual-signin-action.php' },
    @{ Area = 'Manual sign-in'; Path = 'includes/admin-manual-signin.php' },
    @{ Area = 'Dashboard'; Path = 'admin/dashboard.php' },
    @{ Area = 'Dashboard CSS'; Path = 'assets/css/dashboard-s7-mockup.css' },
    @{ Area = 'Events page'; Path = 'admin/events.php' },
    @{ Area = 'Events pagination'; Path = 'includes/admin-pagination.php' },
    @{ Area = 'Event Hub'; Path = 'admin/event-hub.php' },
    @{ Area = 'Event Hub'; Path = 'includes/platform/event-hub.php' },
    @{ Area = 'Shift status view-staff'; Path = 'admin/view-staff.php' },
    @{ Area = 'Shift status dashboard'; Path = 'includes/components/staff-status-dashboard.php' },
    @{ Area = 'Staff status page'; Path = 'status.php' },
    @{ Area = 'Email HTML system'; Path = 'includes/mailer.php' },
    @{ Area = 'Email HTML system'; Path = 'includes/email-layout.php' },
    @{ Area = 'Email HTML system'; Path = 'includes/email-branding.php' },
    @{ Area = 'Email job alerts'; Path = 'includes/event-staff-alerts.php' },
    @{ Area = 'Email reminders'; Path = 'includes/reminders.php' },
    @{ Area = 'Email access pass'; Path = 'includes/access-pass-email.php' },
    @{ Area = 'Backup download fix'; Path = 'admin/backup-download.php' },
    @{ Area = 'Staff pool download'; Path = 'admin/staff-download.php' },
    @{ Area = 'Staff pool download'; Path = 'admin/staff-download-export.php' },
    @{ Area = 'Staff app v3'; Path = 'staff-app.php' },
    @{ Area = 'Staff app v3'; Path = 'includes/staff-app-v3-data.php' },
    @{ Area = 'Communication hub'; Path = 'admin/communication-hub.php' }
)

$start = Get-Date '2026-06-11 01:00:00'
$end   = Get-Date '2026-06-11 09:00:00'

Write-Output 'JUNE 11 2026 (1am-9am) KEY UPDATES vs PRODUCTION'
Write-Output '=================================================='
Write-Output ''

$synced = 0
$missing = 0
$diff = 0
$notInWindow = 0

foreach ($item in $keyFiles) {
    $rel = $item.Path
    $localPath = Join-Path $ProjectRoot ($rel -replace '/', '\')
    if (-not (Test-Path $localPath)) {
        Write-Output "[NO LOCAL] $($item.Area) -> $rel"
        $missing++
        continue
    }
    $localSize = (Get-Item $localPath).Length
    $mtime = (Get-Item $localPath).LastWriteTime
    $inWindow = ($mtime -ge $start -and $mtime -lt $end)
    try {
        $uri = Get-FtpUri -Server $cfg.FtpServer -RemoteDir $cfg.FtpRemoteDir -RelativePath $rel
        $remoteSize = $client.DownloadData($uri).Length
        if ($localSize -eq $remoteSize) {
            $status = 'LIVE'
            $synced++
        } else {
            $status = "NOT SYNCED (local=$localSize prod=$remoteSize)"
            $diff++
        }
    } catch {
        $status = 'NOT ON SERVER'
        $missing++
    }
    $win = if ($inWindow) { 'edited 1-9am' } else { 'edited outside window' }
    if (-not $inWindow) { $notInWindow++ }
    Write-Output "[$status] $($item.Area)"
    Write-Output "  $rel ($win, modified $($mtime.ToString('HH:mm')))"
}

Write-Output ''
Write-Output "SUMMARY: LIVE=$synced  NOT_SYNCED=$diff  MISSING=$missing  (edited outside 1-9am window: $notInWindow files)"
