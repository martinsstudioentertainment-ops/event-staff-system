$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$client = New-Object System.Net.WebClient
$client.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)

$paths = @(
    'admin/settings-email.php',
    'includes/mailer.php',
    'includes/email-copy.php',
    'includes/settings-repository.php',
    'includes/admin/settings-handler.php',
    'includes/access-pass-email.php',
    'includes/staff-profile-email.php',
    'includes/staff-messages.php',
    'includes/event-staff-alerts.php',
    'includes/reminders.php',
    'includes/automation/comms-hub.php',
    'admin/communication-hub.php',
    'includes/notifications.php',
    'includes/notification-center.php'
)

Write-Output 'EMAIL FILES — local vs production (admin.olasentra.com)'
Write-Output 'local NEWER = your PC has more code | remote NEWER = server had more (may have been overwritten)'
Write-Output ''

foreach ($rel in $paths) {
    $localPath = Join-Path $ProjectRoot ($rel -replace '/', '\')
    $localSize = if (Test-Path $localPath) { (Get-Item $localPath).Length } else { -1 }
    try {
        $uri = Get-FtpUri -Server $cfg.FtpServer -RemoteDir $cfg.FtpRemoteDir -RelativePath $rel
        $remoteSize = $client.DownloadData($uri).Length
        if ($localSize -lt 0) {
            $status = 'NO_LOCAL'
        } elseif ($localSize -eq $remoteSize) {
            $status = 'SAME_SIZE'
        } elseif ($localSize -gt $remoteSize) {
            $status = 'LOCAL_NEWER (not on server?)'
        } else {
            $status = 'REMOTE_NEWER (server had more — check overwrite)'
        }
        Write-Output ('{0,-45} local={1,7} remote={2,7}  {3}' -f $rel, $localSize, $remoteSize, $status)
    } catch {
        Write-Output ('{0,-45} local={1,7} remote=MISSING' -f $rel, $localSize)
    }
}
