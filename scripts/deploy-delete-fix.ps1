$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
@(
    @{ Local = 'admin\delete-event.php'; Remote = 'admin/delete-event.php' },
    @{ Local = 'admin\events.php'; Remote = 'admin/events.php' },
    @{ Local = 'admin\event-form.php'; Remote = 'admin/event-form.php' },
    @{ Local = 'includes\event-complete-purge.php'; Remote = 'includes/event-complete-purge.php' },
    @{ Local = 'includes\admin\layout-bottom.php'; Remote = 'includes/admin/layout-bottom.php' },
    @{ Local = 'cron\purge-test-events.php'; Remote = 'cron/purge-test-events.php' }
) | ForEach-Object {
    Send-FtpFile -LocalPath (Join-Path $Root $_.Local) -RemoteRelativePath $_.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

$key = & (Join-Path $PSScriptRoot 'fetch-cron-key.ps1')
Write-Host 'Scan test events...' -ForegroundColor Green
$scan = Invoke-RestMethod -Uri "https://admin.olasentra.com/cron/purge-test-events.php?key=$key"
$scan | ConvertTo-Json -Depth 6
if ([int]($scan.count) -gt 0) {
    Write-Host 'Purging test events...' -ForegroundColor Yellow
    $result = Invoke-RestMethod -Uri "https://admin.olasentra.com/cron/purge-test-events.php?key=$key&confirm=1"
    $result | ConvertTo-Json -Depth 6
}
Write-Host 'Done.' -ForegroundColor Green
