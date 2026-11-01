$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $PSScriptRoot 'ftp-common.ps1')

$cfg = Get-DeployConfig
$files = @(
    @{ Local = 'includes\admin-manual-signin.php'; Remote = 'includes/admin-manual-signin.php' },
    @{ Local = 'cron\restore-shift-attendance.php'; Remote = 'cron/restore-shift-attendance.php' }
)

Write-Host 'Uploading shift restore...' -ForegroundColor Green
foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

$key = & (Join-Path $PSScriptRoot 'fetch-cron-key.ps1')
$base = 'https://admin.olasentra.com/cron/restore-shift-attendance.php'

$params = @{
    key               = $key
    registration_id   = '231'
    hours             = '8.5'
    note              = 'Restored 8.5h after dashboard check-in reset — worked Nick Cave shift'
}

Write-Host 'Preview restore...' -ForegroundColor Green
$preview = Invoke-RestMethod -Uri ($base + '?' + (($params.GetEnumerator() | ForEach-Object { "$($_.Key)=$([uri]::EscapeDataString([string]$_.Value))" }) -join '&'))
$preview | ConvertTo-Json -Depth 6

if ($preview.already_checked_in) {
    Write-Host 'Already has check-in — no restore needed.' -ForegroundColor Yellow
    exit 0
}

Write-Host 'Restoring 8.5 hours...' -ForegroundColor Yellow
$params['confirm'] = '1'
$result = Invoke-RestMethod -Uri ($base + '?' + (($params.GetEnumerator() | ForEach-Object { "$($_.Key)=$([uri]::EscapeDataString([string]$_.Value))" }) -join '&'))
$result | ConvertTo-Json -Depth 6
