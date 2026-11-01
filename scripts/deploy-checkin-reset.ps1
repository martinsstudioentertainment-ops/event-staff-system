$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $PSScriptRoot 'ftp-common.ps1')

$cfg = Get-DeployConfig
$files = @(
    @{ Local = 'includes\attendance-repository.php'; Remote = 'includes/attendance-repository.php' },
    @{ Local = 'admin\attendance.php'; Remote = 'admin/attendance.php' },
    @{ Local = 'admin\checkin-reset-action.php'; Remote = 'admin/checkin-reset-action.php' },
    @{ Local = 'cron\reset-today-checkins.php'; Remote = 'cron/reset-today-checkins.php' }
)

Write-Host 'Uploading check-in reset files...' -ForegroundColor Green
foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

$key = & (Join-Path $PSScriptRoot 'fetch-cron-key.ps1')
$scanUrl = "https://admin.olasentra.com/cron/reset-today-checkins.php?key=$key&scan=1"
$confirmUrl = "https://admin.olasentra.com/cron/reset-today-checkins.php?key=$key&confirm=1"

Write-Host ''
Write-Host 'Scanning production today check-ins...' -ForegroundColor Green
$scan = Invoke-RestMethod -Uri $scanUrl -Method Get
$scan | ConvertTo-Json -Depth 6

if ([int]$scan.today_checkins -gt 0) {
    Write-Host ''
    Write-Host 'Clearing today check-ins on production...' -ForegroundColor Yellow
    $result = Invoke-RestMethod -Uri $confirmUrl -Method Get
    $result | ConvertTo-Json -Depth 6
} else {
    Write-Host 'No today check-ins to clear.' -ForegroundColor Gray
}
