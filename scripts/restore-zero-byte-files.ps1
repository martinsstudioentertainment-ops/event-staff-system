# Restore zero-byte / corrupt local files from _tmp-restore backup.
# Never touches config.php, deploy.local.ps1, or credentials.

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$BackupRoot  = Join-Path $ProjectRoot '_tmp-restore\event-staff-system'
$SkipFiles   = @('config.php', 'deploy.local.ps1', '.env', 'deploy.local.ps1.example')

function Get-CleanUtf8Content {
    param([string]$Path)
    $bytes = [System.IO.File]::ReadAllBytes($Path)
    $start = 0
    while ($start -lt $bytes.Length -and $bytes[$start] -eq 0) { $start++ }
    if ($start -ge $bytes.Length) { return $null }
    $trimmed = $bytes[$start..($bytes.Length - 1)]
    return [System.Text.Encoding]::UTF8.GetString($trimmed)
}

$listPath = Join-Path $ProjectRoot 'docs\audit-empty-files.txt'
if (-not (Test-Path $listPath)) { throw "Missing $listPath" }

$restored = 0
$skipped  = 0
$failed   = 0
$log      = @()

Get-Content $listPath | ForEach-Object {
    $rel = $_.Trim()
    if ($rel -eq '' -or $SkipFiles -contains (Split-Path $rel -Leaf)) {
        $skipped++
        return
    }

    $local  = Join-Path $ProjectRoot $rel
    $backup = Join-Path $BackupRoot $rel
    if (-not (Test-Path $backup)) {
        $failed++
        $log += "NO_BACKUP|$rel"
        return
    }

    $backupLen = (Get-Item $backup).Length
    if ($backupLen -lt 10) {
        $failed++
        $log += "BACKUP_TOO_SMALL|$rel|$backupLen"
        return
    }

    $localLen = if (Test-Path $local) { (Get-Item $local).Length } else { 0 }
    if ($localLen -gt 500 -and $localLen -gt $backupLen) {
        $skipped++
        $log += "KEEP_LOCAL|$rel|local=$localLen"
        return
    }

    $dir = Split-Path $local -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }

    $content = Get-CleanUtf8Content -Path $backup
    if ($null -eq $content -or $content.Trim().Length -lt 5) {
        $failed++
        $log += "BACKUP_NULL_ONLY|$rel"
        return
    }

    [System.IO.File]::WriteAllText($local, $content, [System.Text.UTF8Encoding]::new($false))
    $restored++
    $log += "RESTORED|$rel|$backupLen"
}

$outDir = Join-Path $ProjectRoot 'docs\stabilization-2026-06-18'
New-Item -ItemType Directory -Force -Path $outDir | Out-Null
$log | Set-Content (Join-Path $outDir 'restore-log.txt') -Encoding UTF8

Write-Host "Restored: $restored | Skipped: $skipped | Failed: $failed"
Write-Host "Log: docs\stabilization-2026-06-18\restore-log.txt"
