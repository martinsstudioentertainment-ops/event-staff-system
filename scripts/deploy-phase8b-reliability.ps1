# Phase 8B — Reliability deploy (P8-01, P8-04, P8-05)
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\deploy-phase8b-reliability.ps1
#
# Run ONLY after review/approval of docs/PHASE8B-RELIABILITY-IMPLEMENTATION-REPORT.md

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $ProjectRoot "storage\backups\phase8b-pre-deploy-$stamp"

$approvedFiles = @(
    @{ Local = 'sw.js'; Remote = 'sw.js' },
    @{ Local = 'includes\staff-app-v3-pages.php'; Remote = 'includes/staff-app-v3-pages.php' },
    @{ Local = 'staff-messages.php'; Remote = 'staff-messages.php' }
)

function Get-FileSha256 {
    param([string]$Path)
    return (Get-FileHash -Path $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

Write-Host ''
Write-Host '========================================' -ForegroundColor Green
Write-Host '  Phase 8B — Reliability Deploy' -ForegroundColor Green
Write-Host '========================================' -ForegroundColor Green
Write-Host ''

Write-Host '[1/5] Deploy safety gate ...' -ForegroundColor Green
& (Join-Path $ProjectRoot 'scripts\deploy-safety-gate.ps1')
if ($LASTEXITCODE -ne 0) {
    throw 'Deploy blocked by safety gate.'
}

Write-Host ''
Write-Host '[2/5] Phase 8B static regression tests ...' -ForegroundColor Green
php (Join-Path $ProjectRoot 'scripts\phase8b-reliability-test.php')
if ($LASTEXITCODE -ne 0) {
    throw 'Phase 8B reliability tests failed.'
}

Write-Host ''
Write-Host '[3/5] Pre-deploy backup (production FTP snapshot) ...' -ForegroundColor Green
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null

. (Join-Path $ProjectRoot 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir

$hashBefore = @{}
$hashLocal  = @{}
$backupStatus = @{}

foreach ($entry in $approvedFiles) {
    $local = Join-Path $ProjectRoot $entry.Local
    if (-not (Test-Path $local)) {
        throw "Missing local file: $local"
    }
    if ((Get-Item $local).Length -eq 0) {
        throw "Local file is 0 bytes: $local"
    }
    $hashLocal[$entry.Remote] = Get-FileSha256 $local

    $prodBackup = Join-Path $backupRoot ($entry.Remote -replace '/', '\')
    $prodDir = Split-Path $prodBackup -Parent
    if (-not (Test-Path $prodDir)) {
        New-Item -ItemType Directory -Path $prodDir -Force | Out-Null
    }
    try {
        $bytes = Download-FtpFile -LocalPath $prodBackup -RemoteRelativePath $entry.Remote -RemoteBase $base -Deploy $cfg
        if ($bytes -gt 0) {
            $hashBefore[$entry.Remote] = Get-FileSha256 $prodBackup
            $backupStatus[$entry.Remote] = 'backed_up'
            Write-Host "  backed up production: $($entry.Remote) ($bytes bytes)" -ForegroundColor Gray
        } else {
            $backupStatus[$entry.Remote] = 'empty_or_missing'
            Write-Host "  !! production backup empty: $($entry.Remote)" -ForegroundColor Yellow
        }
    } catch {
        $backupStatus[$entry.Remote] = 'download_failed'
        Write-Host "  !! could not download production $($entry.Remote): $($_.Exception.Message)" -ForegroundColor Yellow
    }
}

$manifest = [ordered]@{
    phase           = 'PHASE8B-RELIABILITY'
    timestamp       = (Get-Date).ToString('o')
    backup_dir      = $backupRoot
    backup_status   = $backupStatus
    production_hash = $hashBefore
    local_hash      = $hashLocal
}
$manifest | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $backupRoot 'manifest.json') -Encoding UTF8

Write-Host ''
Write-Host '[4/5] Upload approved files to production ...' -ForegroundColor Green
foreach ($entry in $approvedFiles) {
    $local = Join-Path $ProjectRoot $entry.Local
    Send-FtpFile -LocalPath $local -RemoteRelativePath $entry.Remote -RemoteBase $base -Deploy $cfg
    Write-Host "  uploaded: $($entry.Remote)" -ForegroundColor Green
}

Write-Host ''
Write-Host '[5/5] Verify uploaded files on production ...' -ForegroundColor Green
$verifyDir = Join-Path $backupRoot 'post-deploy-verify'
New-Item -ItemType Directory -Path $verifyDir -Force | Out-Null
$hashAfter = @{}

foreach ($entry in $approvedFiles) {
    $local = Join-Path $ProjectRoot $entry.Local
    $expectedSize = (Get-Item $local).Length
    $verifyPath = Join-Path $verifyDir ($entry.Remote -replace '/', '\')
    $downloaded = Download-FtpFile -LocalPath $verifyPath -RemoteRelativePath $entry.Remote -RemoteBase $base -Deploy $cfg
    $hashAfter[$entry.Remote] = Get-FileSha256 $verifyPath
    if ($downloaded -ne $expectedSize) {
        throw "Size mismatch after upload for $($entry.Remote): expected $expectedSize got $downloaded"
    }
    if ($hashAfter[$entry.Remote] -ne $hashLocal[$entry.Remote]) {
        throw "Hash mismatch after upload for $($entry.Remote)"
    }
    Write-Host "  verified: $($entry.Remote) ($downloaded bytes, hash OK)" -ForegroundColor Green
}

$deployResult = [ordered]@{
    phase           = 'PHASE8B-RELIABILITY'
    backup_dir      = $backupRoot
    production_hash = $hashBefore
    deployed_hash   = $hashLocal
    verified_hash   = $hashAfter
    backup_status   = $backupStatus
    deployed_at     = (Get-Date).ToString('o')
    files           = @($approvedFiles | ForEach-Object { $_.Remote })
    verdict         = 'DEPLOYMENT SUCCESSFUL'
}
$deployResult | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $backupRoot 'deploy-result.json') -Encoding UTF8

Write-Host ''
Write-Host 'Phase 8B deploy complete.' -ForegroundColor Green
Write-Host "Backup: $backupRoot" -ForegroundColor Gray
Write-Host 'Post-deploy: verify offline PWA styling + guest messages redirect (see PHASE8B report).' -ForegroundColor Gray
