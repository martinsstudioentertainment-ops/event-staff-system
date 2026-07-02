# Pre-deploy safety gate — blocks deploy when repository integrity checks fail.
# Read-only against production; validates local tree only.
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\deploy-safety-gate.ps1
#   powershell -ExecutionPolicy Bypass -File .\scripts\deploy-safety-gate.ps1 -ReportOnly

param(
    [switch]$ReportOnly
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$configPath = Join-Path $PSScriptRoot 'deploy-critical-files.json'
$classPath  = Join-Path $PSScriptRoot 'phase2-file-classification.json'
if (-not (Test-Path $configPath)) {
    throw 'Missing scripts/deploy-critical-files.json'
}

$gateConfig = Get-Content $configPath -Raw | ConvertFrom-Json
$criticalFiles = @($gateConfig.critical_files)
$extensions = @($gateConfig.zero_byte_scan_extensions)
$excludeDirs = @($gateConfig.zero_byte_scan_exclude_dirs | ForEach-Object { ($_ -replace '\\', '/').Trim('/') })
$safeExcludePaths = New-Object System.Collections.Generic.HashSet[string]
if (Test-Path $classPath) {
    $classConfig = Get-Content $classPath -Raw | ConvertFrom-Json
    foreach ($p in @($classConfig.zero_byte_safe_exclude_paths)) {
        [void]$safeExcludePaths.Add(($p -replace '\\', '/').Trim('/'))
    }
}

function Test-SafeExcludedPath {
    param([string]$RelativePath)
    return $script:safeExcludePaths.Contains($RelativePath)
}

function Get-RelativePath {
    param([string]$FullPath, [string]$Root)
    return $FullPath.Substring($Root.Length).TrimStart('\', '/').Replace('\', '/')
}

function Test-PathExcluded {
    param([string]$RelativePath, [string[]]$Excludes)
    foreach ($dir in $Excludes) {
        if ($RelativePath -eq $dir -or $RelativePath.StartsWith("$dir/")) {
            return $true
        }
    }
    return $false
}

$missingCritical = New-Object System.Collections.Generic.List[string]
$zeroCritical = New-Object System.Collections.Generic.List[string]

foreach ($rel in $criticalFiles) {
    $local = Join-Path $ProjectRoot ($rel -replace '/', '\')
    if (-not (Test-Path $local)) {
        $missingCritical.Add($rel)
        continue
    }
    if ((Get-Item $local).Length -eq 0) {
        $zeroCritical.Add($rel)
    }
}

$zeroByteFiles = New-Object System.Collections.Generic.List[string]
foreach ($pattern in $extensions) {
    Get-ChildItem -Path $ProjectRoot -Filter $pattern -Recurse -File -ErrorAction SilentlyContinue |
        Where-Object { -not (Test-PathExcluded (Get-RelativePath $_.FullName $ProjectRoot) $excludeDirs) } |
        ForEach-Object {
            $rel = Get-RelativePath $_.FullName $ProjectRoot
            if ($_.Length -eq 0 -and -not (Test-SafeExcludedPath $rel)) {
                $zeroByteFiles.Add($rel)
            }
        }
}

$report = [ordered]@{
    checked_at           = (Get-Date).ToString('o')
    phase                = 'PHASE2-20260621-OLASENTRA'
    report_only          = [bool]$ReportOnly
    critical_missing     = @($missingCritical | Sort-Object)
    critical_zero_byte   = @($zeroCritical | Sort-Object)
    zero_byte_count      = $zeroByteFiles.Count
    zero_byte_files      = @($zeroByteFiles | Sort-Object)
    safe_excluded_count  = $safeExcludePaths.Count
    deploy_allowed       = $false
    block_reasons        = @()
}

if ($missingCritical.Count -gt 0) {
    $report.block_reasons += "Missing $($missingCritical.Count) critical file(s)."
}
if ($zeroCritical.Count -gt 0) {
    $report.block_reasons += "Critical file(s) are 0 bytes: $($zeroCritical -join ', ')."
}
if ($zeroByteFiles.Count -gt 0) {
    $report.block_reasons += "Found $($zeroByteFiles.Count) zero-byte PHP/JS/CSS file(s) in deploy tree."
}

$report.deploy_allowed = ($report.block_reasons.Count -eq 0)

$reportDir = Join-Path $ProjectRoot 'docs'
if (-not (Test-Path $reportDir)) {
    New-Item -ItemType Directory -Force -Path $reportDir | Out-Null
}
$reportPath = Join-Path $reportDir 'phase2-deploy-safety-gate.json'
$report | ConvertTo-Json -Depth 6 | Set-Content $reportPath -Encoding UTF8

Write-Host ''
Write-Host '========================================' -ForegroundColor Cyan
Write-Host '  Deploy Safety Gate (Phase 2)' -ForegroundColor Cyan
Write-Host '========================================' -ForegroundColor Cyan
Write-Host "  Critical missing : $($missingCritical.Count)" -ForegroundColor $(if ($missingCritical.Count -gt 0) { 'Red' } else { 'Green' })
Write-Host "  Critical 0-byte  : $($zeroCritical.Count)" -ForegroundColor $(if ($zeroCritical.Count -gt 0) { 'Red' } else { 'Green' })
Write-Host "  All 0-byte scan  : $($zeroByteFiles.Count)" -ForegroundColor $(if ($zeroByteFiles.Count -gt 0) { 'Red' } else { 'Green' })
Write-Host "  Safe excluded    : $($safeExcludePaths.Count)" -ForegroundColor Gray
Write-Host "  Report           : docs/phase2-deploy-safety-gate.json" -ForegroundColor Gray
Write-Host ''

if ($report.deploy_allowed) {
    Write-Host ''
    Write-Host '[Gate] Master Staff Identity regression test ...' -ForegroundColor Cyan
    $phpExe = Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $phpExe) {
        $report.deploy_allowed = $false
        $report.block_reasons += 'PHP CLI not found — cannot run canonical-identity-regression-test.php'
    } else {
        & php (Join-Path $ProjectRoot 'scripts\canonical-identity-regression-test.php')
        if ($LASTEXITCODE -ne 0) {
            $report.deploy_allowed = $false
            $report.block_reasons += 'Master Staff Identity regression test failed.'
        }
    }
}

if ($report.deploy_allowed) {
    Write-Host '  PASS — deploy may proceed.' -ForegroundColor Green
    exit 0
}

Write-Host '  BLOCKED — deploy is not safe.' -ForegroundColor Red
foreach ($reason in $report.block_reasons) {
    Write-Host "    - $reason" -ForegroundColor Yellow
}
if ($zeroByteFiles.Count -gt 0 -and $zeroByteFiles.Count -le 30) {
    Write-Host ''
    Write-Host '  Zero-byte files:' -ForegroundColor Yellow
    $zeroByteFiles | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkYellow }
}

if ($ReportOnly) {
    exit 2
}

throw 'Deploy safety gate failed. Fix repository integrity before deploying.'
