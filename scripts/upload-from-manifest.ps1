# Upload main admin files from docs/V1.0-DEPLOY-MANIFEST.json
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\upload-from-manifest.ps1

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

$manifestPath = Join-Path $ProjectRoot 'docs\V1.0-DEPLOY-MANIFEST.json'
if (-not (Test-Path $manifestPath)) {
    throw 'Missing docs/V1.0-DEPLOY-MANIFEST.json — run scripts/generate-v10-deploy-manifest.ps1 first'
}

$manifest = Get-Content $manifestPath -Raw | ConvertFrom-Json
$files = @($manifest.main_admin)
if ($files.Count -lt 1) {
    throw 'Manifest contains no main_admin files'
}

Write-Host "Main admin manifest upload -> $($cfg.FtpServer)$($cfg.FtpRemoteDir) ($($files.Count) files)" -ForegroundColor Green

$uploaded = 0
$skipped  = 0
foreach ($entry in $files) {
    $localRel = [string]$entry.local
    $remoteRel = [string]$entry.remote
    $localPath = Join-Path $ProjectRoot ($localRel -replace '/', '\')
    if (-not (Test-Path $localPath)) {
        Write-Host "  skip missing: $localRel" -ForegroundColor Yellow
        $skipped++
        continue
    }
    if ((Get-Item $localPath).Length -lt 1) {
        throw "Refusing 0-byte upload: $localRel"
    }
    Send-FtpFile -LocalPath $localPath -RemoteRelativePath $remoteRel -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
    $uploaded++
}

Write-Host ''
Write-Host "Manifest upload complete: $uploaded uploaded, $skipped skipped." -ForegroundColor Green
