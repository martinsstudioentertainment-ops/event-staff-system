# Restore local 0-byte PHP/JS/CSS files from reference trees (read-only sources).
#
# Priority: _tmp-restore/event-staff-system → forensic snapshot → ftp-download
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\restore-zero-byte-from-reference.ps1

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$sources = @(
    @{ name = '_tmp-restore'; base = Join-Path $ProjectRoot '_tmp-restore\event-staff-system' },
    @{ name = 'forensic-snapshot'; base = Join-Path $ProjectRoot '_recovery-staging\forensic-snapshot\20260612-182432' },
    @{ name = 'ftp-download'; base = Join-Path $ProjectRoot '_recovery-staging\ftp-download' }
)

$excludeDirs = @('_tmp-restore', '_recovery-staging', 'node_modules', 'vendor', '.git', 'storage\backups', 'android')

function Test-Excluded {
    param([string]$FullPath)
    $rel = $FullPath.Substring($ProjectRoot.Length + 1).Replace('\', '/')
    foreach ($dir in $excludeDirs) {
        $norm = ($dir -replace '\\', '/').Trim('/')
        if ($rel -eq $norm -or $rel.StartsWith("$norm/")) { return $true }
    }
    return $false
}

$restored = New-Object System.Collections.Generic.List[object]
$stillZero = New-Object System.Collections.Generic.List[string]

Get-ChildItem -Path $ProjectRoot -Recurse -File -ErrorAction SilentlyContinue | Where-Object {
    ($_.Extension -in @('.php', '.js', '.css')) -and ($_.Length -eq 0) -and -not (Test-Excluded $_.FullName)
} | ForEach-Object {
    $rel = $_.FullName.Substring($ProjectRoot.Length + 1).Replace('\', '/')
    $copied = $false
    foreach ($src in $sources) {
        if (-not (Test-Path $src.base)) { continue }
        $srcPath = Join-Path $src.base ($rel -replace '/', '\')
        if ((Test-Path $srcPath) -and (Get-Item $srcPath).Length -gt 0) {
            Copy-Item -Path $srcPath -Destination $_.FullName -Force
            $restored.Add([pscustomobject]@{
                file   = $rel
                source = $src.name
                bytes  = (Get-Item $_.FullName).Length
            })
            $copied = $true
            break
        }
    }
    if (-not $copied) {
        $stillZero.Add($rel)
    }
}

$report = [ordered]@{
    restored_at      = (Get-Date).ToString('o')
    restored_count   = $restored.Count
    restored         = @($restored | Sort-Object file)
    still_zero_count = $stillZero.Count
    still_zero       = @($stillZero | Sort-Object)
}

$out = Join-Path $ProjectRoot 'docs\phase1-restoration-report.json'
$report | ConvertTo-Json -Depth 5 | Set-Content $out -Encoding UTF8

Write-Host "Restored : $($restored.Count)"
Write-Host "Still 0B : $($stillZero.Count)"
Write-Host "Report   : docs/phase1-restoration-report.json"
