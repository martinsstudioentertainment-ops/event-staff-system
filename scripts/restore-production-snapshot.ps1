param(
    [string]$Label = '2026-06-11-2355'
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$snapRoot = Join-Path $ProjectRoot "storage\backups\restore-points\$Label-production"
if (-not (Test-Path $snapRoot)) {
    throw "Snapshot not found: $snapRoot"
}

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir

Get-ChildItem -Path $snapRoot -Recurse -File | Where-Object { $_.Name -notin @('manifest.csv', 'snapshot.json') } | ForEach-Object {
    $rel = $_.FullName.Substring($snapRoot.Length + 1) -replace '\\', '/'
    Send-FtpFile -LocalPath $_.FullName -RemoteRelativePath $rel -RemoteBase $base -Deploy $cfg
}
Write-Host "Restored production from snapshot $Label" -ForegroundColor Green
