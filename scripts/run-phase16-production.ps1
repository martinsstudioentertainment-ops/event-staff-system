# Phase 16 production deployment — Gate F execution
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\run-phase16-production.ps1
#   powershell -ExecutionPolicy Bypass -File .\scripts\run-phase16-production.ps1 -SkipBackup
#   powershell -ExecutionPolicy Bypass -File .\scripts\run-phase16-production.ps1 -DryRun
#
# Requires deploy.local.ps1 with FTP credentials.
# Optional: ReminderCronKey in deploy.local.ps1 (Admin Settings Email cron key).
# If omitted, attempts to read reminder_cron_key from production settings backup via FTP.

param(
    [switch]$SkipBackup,
    [switch]$DryRun,
    [switch]$SkipUpload
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

$stamp     = Get-Date -Format 'yyyyMMdd-HHmmss'
$dateStamp = Get-Date -Format 'yyyyMMdd'
$logDir    = Join-Path $ProjectRoot "storage\backups\phase16"
$localOut  = Join-Path $logDir "phase16-deploy-$stamp.txt"

if (-not (Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}

function Get-ProductionCronKey {
    param([hashtable]$Deploy)

    if ($Deploy.ReminderCronKey -and $Deploy.ReminderCronKey.Trim() -ne '') {
        return $Deploy.ReminderCronKey.Trim()
    }

    $uri = Get-FtpUri -Server $Deploy.FtpServer -RemoteDir $Deploy.FtpRemoteDir -RelativePath 'storage/backups/weekly/settings-and-cms.json'
    $client = New-Object System.Net.WebClient
    $client.Credentials = New-Object System.Net.NetworkCredential($Deploy.FtpUser, $Deploy.FtpPassword)
    try {
        $bytes = $client.DownloadData($uri)
        $json  = [System.Text.Encoding]::UTF8.GetString($bytes) | ConvertFrom-Json
        $key   = [string]$json.settings.reminder_cron_key
        if ($key.Trim() -ne '') { return $key.Trim() }
    } catch {
        Write-Host "Could not read cron key from FTP settings backup: $($_.Exception.Message)" -ForegroundColor Yellow
    } finally {
        $client.Dispose()
    }

    throw 'Set ReminderCronKey in deploy.local.ps1 (from Admin Settings Email) or ensure weekly settings backup exists on server.'
}

function Invoke-Phase16Url {
    param([string]$Url, [int]$TimeoutSec = 300)
    try {
        $resp = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec $TimeoutSec
        return @{ Ok = $true; Status = $resp.StatusCode; Body = $resp.Content }
    } catch {
        $body = ''
        if ($_.Exception.Response) {
            $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
            $body = $reader.ReadToEnd()
            $reader.Close()
        }
        return @{ Ok = $false; Status = 0; Body = $(if ($body) { $body } else { $_.Exception.Message }) }
    }
}

$uploadFiles = @(
    @{ Local = 'cron\run-phase16-deploy.php'; Remote = 'cron/run-phase16-deploy.php' },
    @{ Local = 'database\phase16-precheck.sql'; Remote = 'database/phase16-precheck.sql' },
    @{ Local = 'database\migrate-phase48-composite-indexes.sql'; Remote = 'database/migrate-phase48-composite-indexes.sql' },
    @{ Local = 'database\migrate-phase49-backfill-staff-id.sql'; Remote = 'database/migrate-phase49-backfill-staff-id.sql' },
    @{ Local = 'database\migrate-phase50-foreign-keys.sql'; Remote = 'database/migrate-phase50-foreign-keys.sql' },
    @{ Local = 'database\migrate-phase51-platform-ops-tables.sql'; Remote = 'database/migrate-phase51-platform-ops-tables.sql' },
    @{ Local = 'database\phase16-postcheck.sql'; Remote = 'database/phase16-postcheck.sql' },
    @{ Local = 'database\rollback-phase48-51.sql'; Remote = 'database/rollback-phase48-51.sql' }
)

Write-Host ''
Write-Host '========================================' -ForegroundColor Green
Write-Host '  Phase 16 — Production Deployment' -ForegroundColor Green
Write-Host '========================================' -ForegroundColor Green
Write-Host "  Stamp: $stamp" -ForegroundColor Gray
Write-Host ''

Write-Host '[1/6] Local pre-deploy backup ...' -ForegroundColor Cyan
& (Join-Path $ProjectRoot 'scripts\pre-deploy-backup.ps1')

if (-not $SkipUpload) {
    Write-Host ''
    Write-Host '[2/6] Upload Phase 16 files via FTP ...' -ForegroundColor Cyan
    foreach ($f in $uploadFiles) {
        $local = Join-Path $ProjectRoot $f.Local
        if (-not (Test-Path $local)) { throw "Missing file: $($f.Local)" }
        Send-FtpFile -LocalPath $local -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
    }
} else {
    Write-Host '[2/6] Skipped upload (-SkipUpload)' -ForegroundColor Yellow
}

$cronKey = Get-ProductionCronKey -Deploy $cfg
$baseUrl = if ($cfg.AdminUrl) { $cfg.AdminUrl.TrimEnd('/') } else { 'https://admin.olasentra.com' }
$baseUrl = $baseUrl -replace '/admin$', ''

if ($DryRun) {
    Write-Host ''
    Write-Host '[DRY RUN] Would invoke:' -ForegroundColor Yellow
    Write-Host "  $baseUrl/cron/run-phase16-deploy.php?key=***"
    exit 0
}

if (-not $SkipBackup) {
    Write-Host ''
    Write-Host '[3/6] Production full backup (weekly) ...' -ForegroundColor Cyan
    $backupUrl = "$baseUrl/cron/weekly-backup.php?key=$([uri]::EscapeDataString($cronKey))"
    $backupResp = Invoke-Phase16Url -Url $backupUrl -TimeoutSec 600
    Write-Host $backupResp.Body
    if (-not $backupResp.Ok -or $backupResp.Body -notmatch 'success|complete') {
        throw 'Production backup failed or returned unexpected output. Aborting migration.'
    }

    Write-Host ''
    Write-Host '  Downloading production DB backup for local archive ...' -ForegroundColor Gray
    $archiveDir = Join-Path $logDir "production-backup-$dateStamp"
    if (-not (Test-Path $archiveDir)) { New-Item -ItemType Directory -Path $archiveDir -Force | Out-Null }
    $dbUri = Get-FtpUri -Server $cfg.FtpServer -RemoteDir $cfg.FtpRemoteDir -RelativePath 'storage/backups/weekly/database.sql'
    $client = New-Object System.Net.WebClient
    $client.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)
    try {
        $client.DownloadFile($dbUri, (Join-Path $archiveDir 'database.sql'))
        $size = (Get-Item (Join-Path $archiveDir 'database.sql')).Length
        Write-Host "  Saved database.sql ($size bytes)" -ForegroundColor Green
        if ($size -lt 102400) {
            throw 'Production database backup smaller than 100 KB — verify before continuing.'
        }
    } finally {
        $client.Dispose()
    }
} else {
    Write-Host '[3/6] Skipped production backup (-SkipBackup)' -ForegroundColor Yellow
}

Write-Host ''
Write-Host '[4/6] Run Phase 16 migration on production ...' -ForegroundColor Cyan
$deployUrl = "$baseUrl/cron/run-phase16-deploy.php?key=$([uri]::EscapeDataString($cronKey))&skip_backup=1"
$deployResp = Invoke-Phase16Url -Url $deployUrl -TimeoutSec 900
$deployResp.Body | Out-File -FilePath $localOut -Encoding UTF8
Write-Host $deployResp.Body

if (-not $deployResp.Ok) {
    throw "Phase 16 deploy HTTP error. Log: $localOut"
}
if ($deployResp.Body -match 'FAILED|ABORT|ROLLBACK REQUIRED') {
    throw "Phase 16 deploy reported failure. Log: $localOut"
}
if ($deployResp.Body -notmatch 'PHASE16_COMPLETE') {
    throw "Phase 16 deploy did not reach PHASE16_COMPLETE. Log: $localOut"
}

Write-Host ''
Write-Host '[5/6] Smoke tests ...' -ForegroundColor Cyan
$health = Invoke-Phase16Url -Url "$baseUrl/api/health.php" -TimeoutSec 60
Write-Host "  health.php: $($health.Body)"
if ($health.Body -notmatch '"database"\s*:\s*"ok"') {
    throw 'Health check failed after migration.'
}

Write-Host ''
Write-Host '[6/6] Download server deploy log ...' -ForegroundColor Cyan
$logPattern = Get-FtpUri -Server $cfg.FtpServer -RemoteDir $cfg.FtpRemoteDir -RelativePath 'storage/backups/phase16/'
# Best-effort: server log path echoed in deploy output
$serverLogName = ($deployResp.Body | Select-String -Pattern 'phase16-deploy-\d{8}-\d{6}\.log' -AllMatches).Matches | Select-Object -Last 1
if ($serverLogName) {
    $logRemote = 'storage/backups/phase16/' + $serverLogName.Value
    $logLocal  = Join-Path $logDir $serverLogName.Value
    $client = New-Object System.Net.WebClient
    $client.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)
    try {
        $client.DownloadFile((Get-FtpUri -Server $cfg.FtpServer -RemoteDir $cfg.FtpRemoteDir -RelativePath $logRemote), $logLocal)
        Write-Host "  Server log: $logLocal" -ForegroundColor Green
    } catch {
        Write-Host "  Could not download server log: $($_.Exception.Message)" -ForegroundColor Yellow
    } finally {
        $client.Dispose()
    }
}

Write-Host ''
Write-Host '========================================' -ForegroundColor Green
Write-Host '  Phase 16 deployment COMPLETE' -ForegroundColor Green
Write-Host '========================================' -ForegroundColor Green
Write-Host "  Local log: $localOut" -ForegroundColor Gray
Write-Host ''
Write-Host 'Next steps:' -ForegroundColor Yellow
Write-Host '  1. Manually verify admin/staff.php?event_id=1 shows 36 rows'
Write-Host '  2. Monitor production for 24 hours'
Write-Host '  3. Approve Gate G before Phase 15'
Write-Host ''
