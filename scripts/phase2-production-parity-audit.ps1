# Phase 2 — compare Production snapshot vs Local vs _tmp-restore reference.
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\phase2-production-parity-audit.ps1

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$refRoot = Join-Path $ProjectRoot '_tmp-restore\event-staff-system'
$snapRoot = Get-ChildItem (Join-Path $ProjectRoot '_recovery-staging') -Directory -Filter 'production-snapshot-*' -ErrorAction SilentlyContinue |
    Sort-Object Name -Descending | Select-Object -First 1

if (-not $snapRoot) {
    throw 'No production snapshot found under _recovery-staging/production-snapshot-*'
}

$snapshotRoot = $snapRoot.FullName
Write-Host "Production snapshot: $($snapRoot.Name)" -ForegroundColor Gray

function Test-ExcludedPath {
    param([string]$RelativePath, [string[]]$ExcludePrefixes)
    foreach ($prefix in $ExcludePrefixes) {
        $p = ($prefix -replace '\\', '/').Trim('/')
        if ($RelativePath -eq $p -or $RelativePath.StartsWith("$p/")) {
            return $true
        }
    }
    return $false
}

function Build-Map {
    param([string]$Root, [string[]]$ExcludePrefixes = @())
    $map = @{}
    if (-not (Test-Path $Root)) { return $map }
    Get-ChildItem -Path $Root -Recurse -File -ErrorAction SilentlyContinue |
        Where-Object { $_.Extension -in @('.php', '.js', '.css') } |
        ForEach-Object {
            $rel = $_.FullName.Substring($Root.Length).TrimStart('\', '/').Replace('\', '/')
            if (Test-ExcludedPath $rel $ExcludePrefixes) { return }
            $map[$rel] = @{
                size = $_.Length
                hash = if ($_.Length -gt 0) { (Get-FileHash $_.FullName -Algorithm SHA256).Hash } else { $null }
            }
        }
    return $map
}

$localExclude = @('_tmp-restore', '_recovery-staging', 'node_modules', 'vendor', '.git', 'storage/backups', 'android')
$localMap = Build-Map $ProjectRoot $localExclude
$prodMap  = Build-Map $snapshotRoot
$refMap   = Build-Map $refRoot

$allPaths = New-Object System.Collections.Generic.HashSet[string]
foreach ($m in @($localMap, $prodMap, $refMap)) {
    foreach ($k in $m.Keys) { [void]$allPaths.Add($k) }
}

$rows = New-Object System.Collections.Generic.List[object]
foreach ($rel in ($allPaths | Sort-Object)) {
    $loc = $localMap[$rel]
    $prod = $prodMap[$rel]
    $ref = $refMap[$rel]
    $rows.Add([pscustomobject]@{
        path            = $rel
        local_bytes     = if ($loc) { $loc.size } else { $null }
        production_bytes = if ($prod) { $prod.size } else { $null }
        reference_bytes = if ($ref) { $ref.size } else { $null }
        local_matches_production = ($loc -and $prod -and $loc.size -eq $prod.size -and $loc.hash -eq $prod.hash)
        local_matches_reference  = ($loc -and $ref -and $loc.size -eq $ref.size -and $loc.hash -eq $ref.hash)
        in_local        = [bool]$loc
        in_production   = [bool]$prod
        in_reference    = [bool]$ref
    })
}

$summary = [ordered]@{
    compared_at              = (Get-Date).ToString('o')
    production_snapshot      = $snapRoot.Name
    total_paths              = $allPaths.Count
    in_local_only            = @($rows | Where-Object { $_.in_local -and -not $_.in_production -and -not $_.in_reference } | Select-Object -ExpandProperty path)
    in_production_only       = @($rows | Where-Object { $_.in_production -and -not $_.in_local } | Select-Object -ExpandProperty path)
    in_reference_only        = @($rows | Where-Object { $_.in_reference -and -not $_.in_local -and -not $_.in_production } | Select-Object -ExpandProperty path)
    local_zero_in_overlap    = @($rows | Where-Object { $_.in_local -and $_.local_bytes -eq 0 } | Select-Object -ExpandProperty path)
    local_ne_prod_count      = @($rows | Where-Object { $_.in_local -and $_.in_production -and -not $_.local_matches_production }).Count
    local_ne_ref_count       = @($rows | Where-Object { $_.in_local -and $_.in_reference -and -not $_.local_matches_reference }).Count
    local_matches_production_count = @($rows | Where-Object { $_.local_matches_production }).Count
}

$out = Join-Path $ProjectRoot 'docs\phase2-production-parity-report.json'
@{
    summary = $summary
    rows    = @($rows | Where-Object { $_.in_local -or $_.in_production })
} | ConvertTo-Json -Depth 6 | Set-Content $out -Encoding UTF8

Write-Host "Paths compared     : $($allPaths.Count)"
Write-Host "Local matches prod : $($summary.local_matches_production_count)"
Write-Host "Local != production: $($summary.local_ne_prod_count)"
Write-Host "Local != reference : $($summary.local_ne_ref_count)"
Write-Host "Report             : docs/phase2-production-parity-report.json"
