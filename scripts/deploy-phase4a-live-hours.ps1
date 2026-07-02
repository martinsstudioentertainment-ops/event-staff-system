# Phase 4A — Live Hours Counter (display layer only).
# Deploys approved files ONLY:
#   includes/staff-app-v3-data.php
#   includes/staff-app-v3-pages.php
#   includes/staff-app-v3-shell.php
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\deploy-phase4a-live-hours.ps1

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $ProjectRoot "storage\backups\phase4a-pre-deploy-$stamp"
$reportPath = Join-Path $ProjectRoot "docs\PHASE4A-DEPLOYMENT-VALIDATION-REPORT.md"

$approvedFiles = @(
    @{ Local = 'includes\staff-app-v3-data.php'; Remote = 'includes/staff-app-v3-data.php' },
    @{ Local = 'includes\staff-app-v3-pages.php'; Remote = 'includes/staff-app-v3-pages.php' },
    @{ Local = 'includes\staff-app-v3-shell.php'; Remote = 'includes/staff-app-v3-shell.php' }
)

function Get-FileSha256 {
    param([string]$Path)
    return (Get-FileHash -Path $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

Write-Host ''
Write-Host '========================================' -ForegroundColor Green
Write-Host '  Phase 4A — Live Hours Deploy' -ForegroundColor Green
Write-Host '========================================' -ForegroundColor Green
Write-Host ''

Write-Host '[1/5] Deploy safety gate ...' -ForegroundColor Green
& (Join-Path $ProjectRoot 'scripts\deploy-safety-gate.ps1')
if ($LASTEXITCODE -ne 0) {
    throw 'Deploy blocked by safety gate.'
}

$gateReport = Get-Content (Join-Path $ProjectRoot 'docs\phase2-deploy-safety-gate.json') -Raw | ConvertFrom-Json
if (-not $gateReport.deploy_allowed) {
    throw "deploy_allowed is false: $($gateReport.block_reasons -join '; ')"
}
Write-Host "  deploy_allowed: true" -ForegroundColor Green

Write-Host ''
Write-Host '[2/5] Pre-deploy backup (production FTP snapshot of approved files) ...' -ForegroundColor Green
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null

. (Join-Path $ProjectRoot 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir

$hashBefore = @{}
$hashLocal  = @{}

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
            Write-Host "  backed up production: $($entry.Remote) ($bytes bytes)" -ForegroundColor Gray
        } else {
            Write-Host "  !! production backup empty: $($entry.Remote)" -ForegroundColor Yellow
        }
    } catch {
        Write-Host "  !! could not download production $($entry.Remote): $($_.Exception.Message)" -ForegroundColor Yellow
    }
}

$manifest = [ordered]@{
    phase           = 'PHASE4A-LIVE-HOURS'
    timestamp       = (Get-Date).ToString('o')
    backup_dir      = $backupRoot
    production_hash = $hashBefore
    local_hash      = $hashLocal
}
$manifest | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $backupRoot 'manifest.json') -Encoding UTF8

Write-Host ''
Write-Host '[3/5] Local file hashes (to deploy) ...' -ForegroundColor Green
foreach ($rel in $hashLocal.Keys | Sort-Object) {
    Write-Host "  $rel`n    SHA256: $($hashLocal[$rel])" -ForegroundColor Gray
}

Write-Host ''
Write-Host '[4/5] Upload approved files to production ...' -ForegroundColor Green
foreach ($entry in $approvedFiles) {
    $local = Join-Path $ProjectRoot $entry.Local
    Send-FtpFile -LocalPath $local -RemoteRelativePath $entry.Remote -RemoteBase $base -Deploy $cfg
}

Write-Host ''
Write-Host '[5/5] Verify uploaded files on production (size check) ...' -ForegroundColor Green
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
    Write-Host "  verified: $($entry.Remote) ($downloaded bytes)" -ForegroundColor Green
}

Write-Host ''
Write-Host 'Phase 4A deploy complete.' -ForegroundColor Green
Write-Host "Backup: $backupRoot" -ForegroundColor Gray
Write-Host "Report: $reportPath" -ForegroundColor Gray

# Emit machine-readable summary for report generation
@{
    backup_dir       = $backupRoot
    deploy_allowed   = $true
    production_hash  = $hashBefore
    deployed_hash    = $hashLocal
    verified_hash    = $hashAfter
    deployed_at      = (Get-Date).ToString('o')
    files            = @($approvedFiles | ForEach-Object { $_.Remote })
} | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $backupRoot 'deploy-result.json') -Encoding UTF8
