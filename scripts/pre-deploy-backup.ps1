# Local snapshot before git push / FTP deploy (does not touch production).
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\pre-deploy-backup.ps1
#
# Creates storage/backups/pre-deploy-YYYYMMDD-HHMMSS.zip of source files
# (excludes vendor, .git, secrets, large dumps).

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$stamp   = Get-Date -Format 'yyyyMMdd-HHmmss'
$outDir  = Join-Path $ProjectRoot 'storage\backups'
$zipPath = Join-Path $outDir "pre-deploy-$stamp.zip"

if (-not (Test-Path $outDir)) {
    New-Item -ItemType Directory -Path $outDir -Force | Out-Null
}

$excludeDirs = @(
    '.git', 'vendor', 'node_modules', '.cursor', '.idea', '.vscode',
    'storage\backups', 'storage\logs', 'storage\tmp-staff-template-content.xml'
)
$excludeFiles = @(
    'config.php', 'deploy.local.ps1',
    'apply\admin.zip', 'apply\olastofx_apply.sql'
)

Write-Host "Creating pre-deploy backup ..." -ForegroundColor Green
Write-Host "  $zipPath" -ForegroundColor Gray

$tempRoot = Join-Path $env:TEMP "event-staff-predeploy-$stamp"
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

Get-ChildItem -Path $ProjectRoot -Recurse -File | ForEach-Object {
    $rel = $_.FullName.Substring($ProjectRoot.Length).TrimStart('\')
    if (Should-SkipPath $rel) { return }
    $dest = Join-Path $stage $rel
    $parent = Split-Path $dest -Parent
    if (-not (Test-Path $parent)) {
        New-Item -ItemType Directory -Path $parent -Force | Out-Null
    }
    Copy-Item $_.FullName $dest -Force
}

if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
Compress-Archive -Path $stage -DestinationPath $zipPath -CompressionLevel Optimal
Remove-Item $tempRoot -Recurse -Force

$sizeMb = [math]::Round((Get-Item $zipPath).Length / 1MB, 2)
Write-Host "Done. Backup size: ${sizeMb} MB" -ForegroundColor Green
Write-Host $zipPath
