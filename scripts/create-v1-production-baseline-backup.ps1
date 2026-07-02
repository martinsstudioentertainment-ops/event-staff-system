# Olasentra ERP v1.0 — local production baseline codebase backup.
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\create-v1-production-baseline-backup.ps1
#
# Creates:
#   storage/backups/OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE-YYYYMMDD-HHMMSS.zip
# Copies manifest:
#   storage/baseline/OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE.json (unchanged)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$stamp   = Get-Date -Format 'yyyyMMdd-HHmmss'
$outDir  = Join-Path $ProjectRoot 'storage\backups'
$zipName = "OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE-$stamp.zip"
$zipPath = Join-Path $outDir $zipName

if (-not (Test-Path $outDir)) {
    New-Item -ItemType Directory -Path $outDir -Force | Out-Null
}

$excludeDirs = @(
    '.git', 'vendor', 'node_modules', '.cursor', '.idea', '.vscode',
    'storage\backups', 'storage\logs', 'storage\tmp-staff-template-content.xml',
    '_recovery-staging', '_tmp-restore', 'android'
)
$excludeFiles = @(
    'config.php', 'deploy.local.ps1',
    'apply\admin.zip', 'apply\olastofx_apply.sql'
)

Write-Host "OLASENTRA ERP v1.0 baseline backup" -ForegroundColor Cyan
Write-Host "  Label: OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE" -ForegroundColor Gray
Write-Host "  Output: $zipPath" -ForegroundColor Gray

$tempRoot = Join-Path $env:TEMP "olasentra-v1-baseline-$stamp"
$stage    = Join-Path $tempRoot 'event-staff-system'
New-Item -ItemType Directory -Path $stage -Force | Out-Null

function Should-SkipPath([string]$rel) {
    $norm = $rel -replace '/', '\'
    foreach ($d in $excludeDirs) {
        if ($norm -eq $d -or $norm.StartsWith("$d\")) { return $true }
    }
    foreach ($f in $excludeFiles) {
        if ($norm -ieq $f) { return $true }
    }
    if ($norm -match '(?i)\\config\\(database|eventstaff-database|sso\.local)\.php$') { return $true }
    if ($norm -match '(?i)service-account\.json$') { return $true }
    return $false
}

Get-ChildItem -Path $ProjectRoot -Recurse -File -ErrorAction SilentlyContinue | ForEach-Object {
    $rel = $_.FullName.Substring($ProjectRoot.Length).TrimStart('\')
    if (Should-SkipPath $rel) { return }
    $dest = Join-Path $stage $rel
    $parent = Split-Path $dest -Parent
    if (-not (Test-Path $parent)) {
        New-Item -ItemType Directory -Path $parent -Force | Out-Null
    }
    Copy-Item $_.FullName $dest -Force
}

# Include version manifest inside archive root
$manifestSrc = Join-Path $ProjectRoot 'storage\baseline\OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE.json'
if (Test-Path $manifestSrc) {
    Copy-Item $manifestSrc (Join-Path $stage 'OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE.json') -Force
}

if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
Compress-Archive -Path $stage -DestinationPath $zipPath -CompressionLevel Optimal
Remove-Item $tempRoot -Recurse -Force

$sizeMb = [math]::Round((Get-Item $zipPath).Length / 1MB, 2)
Write-Host "Done. Baseline size: ${sizeMb} MB" -ForegroundColor Green
Write-Host ""
Write-Host "Next: run production server baseline via cron/record-production-v1-baseline.php" -ForegroundColor Yellow
