# Phase 5 — targeted FTP deploy (Clock In UI + GTBank orange branding).
# Deploys approved files ONLY:
#   assets/css/staff-app-v3.css
#   includes/staff-app-v3-pages.php
#   includes/staff-app-v3-shell.php
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\deploy-phase5-clockin-ui.ps1

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $ProjectRoot "storage\backups\phase5-pre-deploy-$stamp"

$approvedFiles = @(
    @{ Local = 'assets\css\staff-app-v3.css'; Remote = 'assets/css/staff-app-v3.css' },
    @{ Local = 'includes\staff-app-v3-pages.php'; Remote = 'includes/staff-app-v3-pages.php' },
    @{ Local = 'includes\staff-app-v3-shell.php'; Remote = 'includes/staff-app-v3-shell.php' }
)

function Get-FileSha256 {
    param([string]$Path)
    return (Get-FileHash -Path $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

Write-Host ''
Write-Host '========================================' -ForegroundColor Green
Write-Host '  Phase 5 — Clock In UI Deploy' -ForegroundColor Green
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

Write-Host ''
Write-Host '[2/5] Pre-deploy backup ...' -ForegroundColor Green
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null

. (Join-Path $ProjectRoot 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir

$hashBefore = @{}
$hashLocal  = @{}

foreach ($entry in $approvedFiles) {
    $local = Join-Path $ProjectRoot $entry.Local
    if (-not (Test-Path $local)) { throw "Missing local file: $local" }
    if ((Get-Item $local).Length -eq 0) { throw "Local file is 0 bytes: $local" }
    $hashLocal[$entry.Remote] = Get-FileSha256 $local

    $prodBackup = Join-Path $backupRoot ($entry.Remote -replace '/', '\')
    $prodDir = Split-Path $prodBackup -Parent
    if (-not (Test-Path $prodDir)) { New-Item -ItemType Directory -Path $prodDir -Force | Out-Null }
    try {
        $bytes = Download-FtpFile -LocalPath $prodBackup -RemoteRelativePath $entry.Remote -RemoteBase $base -Deploy $cfg
        if ($bytes -gt 0) {
            $hashBefore[$entry.Remote] = Get-FileSha256 $prodBackup
            Write-Host "  backed up: $($entry.Remote)" -ForegroundColor Gray
        }
    } catch {
        Write-Host "  !! backup skip $($entry.Remote): $($_.Exception.Message)" -ForegroundColor Yellow
    }
}

Write-Host ''
Write-Host '[3/5] Upload approved files ...' -ForegroundColor Green
foreach ($entry in $approvedFiles) {
    $local = Join-Path $ProjectRoot $entry.Local
    Send-FtpFile -LocalPath $local -RemoteRelativePath $entry.Remote -RemoteBase $base -Deploy $cfg
}

Write-Host ''
Write-Host '[4/5] Verify uploads ...' -ForegroundColor Green
$verifyDir = Join-Path $backupRoot 'post-deploy-verify'
New-Item -ItemType Directory -Path $verifyDir -Force | Out-Null

foreach ($entry in $approvedFiles) {
    $local = Join-Path $ProjectRoot $entry.Local
    $expectedSize = (Get-Item $local).Length
    $verifyPath = Join-Path $verifyDir ($entry.Remote -replace '/', '\')
    $downloaded = Download-FtpFile -LocalPath $verifyPath -RemoteRelativePath $entry.Remote -RemoteBase $base -Deploy $cfg
    $hash = Get-FileSha256 $verifyPath
    if ($downloaded -ne $expectedSize -or $hash -ne $hashLocal[$entry.Remote]) {
        throw "Verify failed: $($entry.Remote)"
    }
    Write-Host "  verified: $($entry.Remote)" -ForegroundColor Green
}

Write-Host ''
Write-Host 'Phase 5 deploy complete.' -ForegroundColor Green
Write-Host "Backup: $backupRoot" -ForegroundColor Gray

@{
    phase          = 'PHASE5-CLOCKIN-UI'
    backup_dir     = $backupRoot
    deployed_at    = (Get-Date).ToString('o')
    production_hash = $hashBefore
    deployed_hash  = $hashLocal
    files          = @($approvedFiles | ForEach-Object { $_.Remote })
} | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $backupRoot 'deploy-result.json') -Encoding UTF8
