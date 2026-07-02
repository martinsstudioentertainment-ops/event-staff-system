# Phase 2 — restore local zero-byte / missing files from production snapshot or live FTP.
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\phase2-restore-from-production.ps1
#   powershell -ExecutionPolicy Bypass -File .\scripts\phase2-restore-from-production.ps1 -SnapshotLabel 20260621-120000

param(
    [string]$SnapshotLabel = ''
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

$snapshotRoot = $null
if ($SnapshotLabel -ne '') {
    $snapshotRoot = Join-Path $ProjectRoot "_recovery-staging\production-snapshot-$SnapshotLabel"
    if (-not (Test-Path $snapshotRoot)) {
        throw "Snapshot not found: $snapshotRoot"
    }
} else {
    $latest = Get-ChildItem (Join-Path $ProjectRoot '_recovery-staging') -Directory -Filter 'production-snapshot-*' -ErrorAction SilentlyContinue |
        Sort-Object Name -Descending | Select-Object -First 1
    if ($latest) {
        $snapshotRoot = $latest.FullName
        Write-Host "Using snapshot: $($latest.Name)" -ForegroundColor Gray
    }
}

$gate = Get-Content (Join-Path $ProjectRoot 'docs\phase1-deploy-safety-gate.json') -Raw | ConvertFrom-Json
$targets = @($gate.zero_byte_files)

$restored = New-Object System.Collections.Generic.List[object]
$stillZero = New-Object System.Collections.Generic.List[object]
$missingOnProd = New-Object System.Collections.Generic.List[object]

foreach ($rel in $targets) {
    $localPath = Join-Path $ProjectRoot ($rel -replace '/', '\')
    $needsRestore = -not (Test-Path $localPath) -or ((Get-Item $localPath).Length -eq 0)
    if (-not $needsRestore) {
        continue
    }

    $dir = Split-Path $localPath -Parent
    if ($dir -and -not (Test-Path $dir)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
    }

    $source = 'none'
    $bytes = 0

    if ($snapshotRoot) {
        $snapPath = Join-Path $snapshotRoot ($rel -replace '/', '\')
        if ((Test-Path $snapPath) -and (Get-Item $snapPath).Length -gt 0) {
            Copy-Item $snapPath $localPath -Force
            $bytes = (Get-Item $localPath).Length
            $source = 'snapshot'
        }
    }

    if ($bytes -eq 0) {
        try {
            $bytes = Download-FtpFile -LocalPath $localPath -RemoteRelativePath $rel -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
            if ($bytes -gt 0) {
                $source = 'live_ftp'
            }
        } catch {
            $missingOnProd.Add([pscustomobject]@{ file = $rel; detail = $_.Exception.Message })
        }
    }

    if ($bytes -gt 0) {
        $restored.Add([pscustomobject]@{ file = $rel; source = $source; bytes = $bytes })
    } elseif ((Test-Path $localPath) -and (Get-Item $localPath).Length -eq 0) {
        $stillZero.Add($rel)
    } elseif (-not (Test-Path $localPath)) {
        $stillZero.Add($rel)
    }
}

$report = [ordered]@{
    restored_at        = (Get-Date).ToString('o')
    snapshot_used      = if ($snapshotRoot) { Split-Path $snapshotRoot -Leaf } else { $null }
    restored_count     = $restored.Count
    restored           = @($restored | Sort-Object file | ForEach-Object { [ordered]@{ file = $_.file; source = $_.source; bytes = $_.bytes } })
    still_zero_count   = $stillZero.Count
    still_zero         = @($stillZero | Sort-Object | ForEach-Object { $_ })
    missing_on_prod    = @($missingOnProd | ForEach-Object { [ordered]@{ file = $_.file; detail = $_.detail } })
}

$out = Join-Path $ProjectRoot 'docs\phase2-restoration-report.json'
$report | ConvertTo-Json -Depth 5 | Set-Content $out -Encoding UTF8

Write-Host "Restored from production: $($restored.Count)" -ForegroundColor Green
Write-Host "Still zero-byte       : $($stillZero.Count)" -ForegroundColor $(if ($stillZero.Count -gt 0) { 'Yellow' } else { 'Green' })
Write-Host "Report                : docs/phase2-restoration-report.json" -ForegroundColor Gray
