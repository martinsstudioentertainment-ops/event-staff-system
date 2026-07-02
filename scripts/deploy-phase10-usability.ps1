# Phase 10 — High impact usability deploy
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\deploy-phase10-usability.ps1
#
# Run ONLY after review/approval of docs/PHASE10-USABILITY-IMPLEMENTATION-REPORT.md

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $ProjectRoot "storage\backups\phase10-pre-deploy-$stamp"

$approvedFiles = @(
    @{ Local = 'manifest.php'; Remote = 'manifest.php' },
    @{ Local = 'assets\js\pwa-install.js'; Remote = 'assets/js/pwa-install.js' },
    @{ Local = 'assets\js\staff-app-v3.js'; Remote = 'assets/js/staff-app-v3.js' },
    @{ Local = 'assets\css\staff-app-v3.css'; Remote = 'assets/css/staff-app-v3.css' },
    @{ Local = 'includes\staff-app-v3-shell.php'; Remote = 'includes/staff-app-v3-shell.php' },
    @{ Local = 'includes\staff-app-v3-pages.php'; Remote = 'includes/staff-app-v3-pages.php' },
    @{ Local = 'status.php'; Remote = 'status.php' },
    @{ Local = 'staff-profile.php'; Remote = 'staff-profile.php' }
)

function Get-FileSha256 {
    param([string]$Path)
    return (Get-FileHash -Path $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

Write-Host ''
Write-Host '========================================' -ForegroundColor Green
Write-Host '  Phase 10 — Usability Deploy' -ForegroundColor Green
Write-Host '========================================' -ForegroundColor Green
Write-Host ''

Write-Host '[1/5] Deploy safety gate ...' -ForegroundColor Green
& (Join-Path $ProjectRoot 'scripts\deploy-safety-gate.ps1')
if ($LASTEXITCODE -ne 0) {
    throw 'Deploy blocked by safety gate.'
}

Write-Host ''
Write-Host '[2/5] Phase 10 static regression tests ...' -ForegroundColor Green
php (Join-Path $ProjectRoot 'scripts\phase10-usability-test.php')
if ($LASTEXITCODE -ne 0) {
    throw 'Phase 10 usability tests failed.'
}

php (Join-Path $ProjectRoot 'scripts\phase5c-login-parity-test.php')
if ($LASTEXITCODE -ne 0) {
    throw 'Phase 5C login parity regression failed.'
}

Write-Host ''
Write-Host '[3/5] Pre-deploy backup ...' -ForegroundColor Green
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
    phase           = 'PHASE10-USABILITY'
    timestamp       = (Get-Date).ToString('o')
    backup_dir      = $backupRoot
    backup_status   = $backupStatus
    production_hash = $hashBefore
    local_hash      = $hashLocal
}
$manifest | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $backupRoot 'manifest.json') -Encoding UTF8

Write-Host ''
Write-Host '[4/5] Upload approved files ...' -ForegroundColor Green
foreach ($entry in $approvedFiles) {
    $local = Join-Path $ProjectRoot $entry.Local
    Send-FtpFile -LocalPath $local -RemoteRelativePath $entry.Remote -RemoteBase $base -Deploy $cfg
    Write-Host "  uploaded: $($entry.Remote)" -ForegroundColor Green
}

Write-Host ''
Write-Host '[5/5] Verify uploaded files ...' -ForegroundColor Green
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
    phase           = 'PHASE10-USABILITY'
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
Write-Host 'Phase 10 deploy complete.' -ForegroundColor Green
Write-Host "Backup: $backupRoot" -ForegroundColor Gray
