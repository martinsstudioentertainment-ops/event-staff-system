# Compare local repository against _tmp-restore reference (read-only).
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\repository-parity-audit.ps1

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$ReferenceRoot = Join-Path $ProjectRoot '_tmp-restore\event-staff-system'

if (-not (Test-Path $ReferenceRoot)) {
    throw "Reference tree missing: $ReferenceRoot"
}

$excludeDirs = @('_tmp-restore', '_recovery-staging', 'node_modules', 'vendor', '.git', 'storage\backups', 'storage\temp', 'android', 'docs')

function Test-Excluded {
    param([string]$FullPath, [string]$Root)
    $rel = $FullPath.Substring($Root.Length + 1).Replace('\', '/')
    foreach ($dir in $excludeDirs) {
        $norm = ($dir -replace '\\', '/').Trim('/')
        if ($rel -eq $norm -or $rel.StartsWith("$norm/")) { return $true }
    }
    return $false
}

$localMap = @{}
Get-ChildItem -Path $ProjectRoot -Recurse -File -ErrorAction SilentlyContinue | Where-Object {
    ($_.Extension -in @('.php', '.js', '.css')) -and -not (Test-Excluded $_.FullName $ProjectRoot)
} | ForEach-Object {
    $rel = $_.FullName.Substring($ProjectRoot.Length + 1).Replace('\', '/')
    $localMap[$rel] = @{
        size = $_.Length
        hash = if ($_.Length -gt 0) { (Get-FileHash $_.FullName -Algorithm SHA256).Hash } else { $null }
    }
}

$missingInLocal = New-Object System.Collections.Generic.List[string]
$missingInRef = New-Object System.Collections.Generic.List[string]
$localZero = New-Object System.Collections.Generic.List[string]
$refZero = New-Object System.Collections.Generic.List[string]
$sizeDiff = New-Object System.Collections.Generic.List[object]
$hashDiff = New-Object System.Collections.Generic.List[string]

Get-ChildItem -Path $ReferenceRoot -Recurse -File -ErrorAction SilentlyContinue | Where-Object {
    $_.Extension -in @('.php', '.js', '.css')
} | ForEach-Object {
    $rel = $_.FullName.Substring($ReferenceRoot.Length + 1).Replace('\', '/')
    if (-not $localMap.ContainsKey($rel)) {
        $missingInLocal.Add($rel)
        return
    }
    $loc = $localMap[$rel]
    $refSize = $_.Length
    if ($refSize -eq 0) { $refZero.Add($rel) }
    if ($loc.size -eq 0) { $localZero.Add($rel) }
    if ($loc.size -ne $refSize) {
        $sizeDiff.Add([pscustomobject]@{ file = $rel; local_bytes = $loc.size; reference_bytes = $refSize })
        return
    }
    if ($refSize -gt 0) {
        $refHash = (Get-FileHash $_.FullName -Algorithm SHA256).Hash
        if ($loc.hash -ne $refHash) { $hashDiff.Add($rel) }
    }
}

foreach ($rel in $localMap.Keys) {
    $refPath = Join-Path $ReferenceRoot ($rel -replace '/', '\')
    if (-not (Test-Path $refPath)) {
        $missingInRef.Add($rel)
    }
}

$report = [ordered]@{
    compared_at        = (Get-Date).ToString('o')
    reference_root     = '_tmp-restore/event-staff-system'
    local_file_count   = $localMap.Count
    missing_in_local   = @($missingInLocal | Sort-Object)
    missing_in_ref     = @($missingInRef | Sort-Object)
    local_zero_byte    = @($localZero | Sort-Object)
    reference_zero_byte = @($refZero | Sort-Object)
    size_differences   = @($sizeDiff | Sort-Object file)
    hash_differences   = @($hashDiff | Sort-Object)
}

$out = Join-Path $ProjectRoot 'docs\phase1-parity-report.json'
$report | ConvertTo-Json -Depth 6 | Set-Content $out -Encoding UTF8

Write-Host "Local files scanned : $($localMap.Count)"
Write-Host "Missing in local    : $($missingInLocal.Count) (exist in reference only)"
Write-Host "Missing in reference: $($missingInRef.Count) (local-only additions)"
Write-Host "Local 0-byte        : $($localZero.Count)"
Write-Host "Size differences    : $($sizeDiff.Count)"
Write-Host "Hash differences    : $($hashDiff.Count)"
Write-Host "Report              : docs/phase1-parity-report.json"
