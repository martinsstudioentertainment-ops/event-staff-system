$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$client = New-Object System.Net.WebClient
$client.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)

$paths = @(
    'admin/backup-download.php',
    'admin/backup-center.php',
    'admin/event-form.php',
    'includes/staff-app-v3-data.php',
    'includes/staff-app-v3-pages.php',
    'includes/staff-app-v3-shell.php',
    'includes/event-checkin-window-schema.php',
    'includes/weekly-backup.php',
    'includes/site-files-backup.php'
)

$ProjectRoot = Split-Path -Parent $PSScriptRoot
foreach ($rel in $paths) {
    $localPath = Join-Path $ProjectRoot ($rel -replace '/', '\')
    $localSize = if (Test-Path $localPath) { (Get-Item $localPath).Length } else { -1 }
    try {
        $uri = Get-FtpUri -Server $cfg.FtpServer -RemoteDir $cfg.FtpRemoteDir -RelativePath $rel
        $remoteSize = $client.DownloadData($uri).Length
        $status = if ($localSize -eq $remoteSize) { 'SYNCED' } else { 'DIFF' }
        Write-Output ('{0,-45} local={1,7} remote={2,7} {3}' -f $rel, $localSize, $remoteSize, $status)
    } catch {
        Write-Output ('{0,-45} local={1,7} remote=MISSING' -f $rel, $localSize)
    }
}

$uri = Get-FtpUri -Server $cfg.FtpServer -RemoteDir $cfg.FtpRemoteDir -RelativePath 'admin/event-form.php'
$ef = $client.DownloadString($uri)
@('checkin_open_time', 'checkin_close_time', 'Sign-in opens', 'Sign-in closes') | ForEach-Object {
    if ($ef -match [regex]::Escape($_)) { "PROD event-form FOUND: $_" } else { "PROD event-form MISSING: $_" }
}

$uri2 = Get-FtpUri -Server $cfg.FtpServer -RemoteDir $cfg.FtpRemoteDir -RelativePath 'includes/staff-app-v3-data.php'
$sd = $client.DownloadString($uri2)
@('getStaffV3ShiftStatusMeta', 'resolveStaffShiftOutcomeMeta', 'Completed', 'Upcoming') | ForEach-Object {
    if ($sd -match [regex]::Escape($_)) { "PROD staff-v3-data FOUND: $_" } else { "PROD staff-v3-data MISSING: $_" }
}
