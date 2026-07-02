# Phase 11B — OTP click-block fix deploy
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\deploy-phase11b-otp-click-fix.ps1

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $ProjectRoot "storage\backups\phase11b-pre-deploy-$stamp"

$approvedFiles = @(
    @{ Local = 'assets\css\staff-app-v3.css'; Remote = 'assets/css/staff-app-v3.css' },
    @{ Local = 'assets\js\staff-app-v3.js'; Remote = 'assets/js/staff-app-v3.js' },
    @{ Local = 'assets\js\staff-portal-email-otp.js'; Remote = 'assets/js/staff-portal-email-otp.js' }
)

function Get-FileSha256 {
    param([string]$Path)
    return (Get-FileHash -Path $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

Write-Host ''
Write-Host '========================================' -ForegroundColor Green
Write-Host '  Phase 11B — OTP Click-Block Fix Deploy' -ForegroundColor Green
Write-Host '========================================' -ForegroundColor Green
Write-Host ''

Write-Host '[1/6] Deploy safety gate ...' -ForegroundColor Green
& (Join-Path $ProjectRoot 'scripts\deploy-safety-gate.ps1')
if ($LASTEXITCODE -ne 0) {
    throw 'Deploy blocked by safety gate.'
}

Write-Host ''
Write-Host '[2/6] Phase 11B regression tests ...' -ForegroundColor Green
php (Join-Path $ProjectRoot 'scripts\phase11b-otp-click-fix-test.php')
if ($LASTEXITCODE -ne 0) {
    throw 'Phase 11B tests failed.'
}

php (Join-Path $ProjectRoot 'scripts\phase5c-login-parity-test.php')
if ($LASTEXITCODE -ne 0) {
    throw 'Phase 5C login parity regression failed.'
}

php (Join-Path $ProjectRoot 'scripts\phase11-auth-registration-test.php')
if ($LASTEXITCODE -ne 0) {
    throw 'Phase 11 auth regression failed.'
}

Write-Host ''
Write-Host '[3/6] Pre-deploy backup ...' -ForegroundColor Green
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
    phase           = 'PHASE11B-OTP-CLICK-FIX'
    timestamp       = (Get-Date).ToString('o')
    backup_dir      = $backupRoot
    backup_status   = $backupStatus
    production_hash = $hashBefore
    local_hash      = $hashLocal
}
$manifest | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $backupRoot 'manifest.json') -Encoding UTF8

Write-Host ''
Write-Host '[4/6] Upload approved files ...' -ForegroundColor Green
foreach ($entry in $approvedFiles) {
    $local = Join-Path $ProjectRoot $entry.Local
    Send-FtpFile -LocalPath $local -RemoteRelativePath $entry.Remote -RemoteBase $base -Deploy $cfg
    Write-Host "  uploaded: $($entry.Remote)" -ForegroundColor Green
}

Write-Host ''
Write-Host '[5/6] Verify uploaded files ...' -ForegroundColor Green
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

Write-Host ''
Write-Host '[6/6] Production HTTP verification ...' -ForegroundColor Green
powershell -ExecutionPolicy Bypass -File (Join-Path $ProjectRoot 'scripts\phase11b-post-deploy-verify.ps1')
if ($LASTEXITCODE -ne 0) {
    throw 'Phase 11B post-deploy verification failed.'
}

$deployResult = [ordered]@{
    phase           = 'PHASE11B-OTP-CLICK-FIX'
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
Write-Host 'Phase 11B deploy complete.' -ForegroundColor Green
Write-Host "Backup: $backupRoot" -ForegroundColor Gray
