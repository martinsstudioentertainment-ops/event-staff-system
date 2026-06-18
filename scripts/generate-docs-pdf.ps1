# Regenerate all platform documentation PDFs from HTML sources.
# Usage: powershell -ExecutionPolicy Bypass -File .\scripts\generate-docs-pdf.ps1

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$docs = Join-Path $root 'docs'
$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'

if (-not (Test-Path $chrome)) {
    $chrome = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
}
if (-not (Test-Path $chrome)) {
    throw 'Chrome or Edge required for PDF generation.'
}

function Get-HtmlPart([string]$path) {
    $raw = Get-Content $path -Raw -Encoding UTF8
    $styles = if ($raw -match '(?s)<style>(.*?)</style>') { $Matches[1] } else { '' }
    $body   = if ($raw -match '(?s)<body>(.*?)</body>') { $Matches[1] } else { '' }
    return @{ Styles = $styles; Body = $body }
}

function Convert-HtmlToPdf([string]$htmlPath, [string]$pdfPath) {
    $uri = [System.Uri]::new($htmlPath).AbsoluteUri
    if (Test-Path $pdfPath) { Remove-Item $pdfPath -Force }
    & $chrome --headless=new --disable-gpu --no-pdf-header-footer --print-to-pdf="$pdfPath" "$uri" 2>$null
    $waitMs = if ($pdfPath -match 'EVERYTHING') { 10000 } else { 2500 }
    Start-Sleep -Milliseconds $waitMs
    if (-not (Test-Path $pdfPath)) {
        throw "Failed: $pdfPath"
    }
    $kb = [math]::Round((Get-Item $pdfPath).Length / 1KB, 1)
    Write-Host "OK  $([System.IO.Path]::GetFileName($pdfPath)) ($kb KB)"
}

$sources = @(
    @{ File = 'PLATFORM-FOCUS.html'; Pdf = 'PLATFORM-FOCUS.pdf'; Part = 'Platform Focus & Product Priorities' },
    @{ File = 'DATABASE-HEALTH-REPORT.html'; Pdf = 'DATABASE-HEALTH-REPORT.pdf'; Part = 'Database Health Report — Live production audit' },
    @{ File = 'PHASE-16-DEPLOYMENT-RESULTS.html'; Pdf = 'PHASE-16-DEPLOYMENT-RESULTS.pdf'; Part = 'Phase 16 — Production Deployment Results' },
    @{ File = 'PHASE-16-IMPLEMENTATION-PACKAGE.html'; Pdf = 'PHASE-16-IMPLEMENTATION-PACKAGE.pdf'; Part = 'Phase 16 — Database Optimization Package' },
    @{ File = 'IMPLEMENTATION-MASTER-PLAN.html'; Pdf = 'IMPLEMENTATION-MASTER-PLAN.pdf'; Part = 'Implementation Master Plan v1.2 — Phases 0–22' },
    @{ File = 'UX-MASTER-PLAN.html';       Pdf = 'UX-MASTER-PLAN.pdf';       Part = 'UX Master Plan v2.0 — Final architecture for approval' },
    @{ File = 'UX-BLUEPRINT.html';         Pdf = 'UX-BLUEPRINT.pdf';         Part = 'UX Blueprint v1 — Information architecture' },
    @{ File = 'ADMIN-PAGE-INVENTORY.html'; Pdf = 'ADMIN-PAGE-INVENTORY.pdf'; Part = 'Admin Page Inventory — 109 PHP pages' },
    @{ File = 'AUDIT-REPORT.html';         Pdf = 'AUDIT-REPORT.pdf';         Part = 'Production Audit Report — 2026-06-06' }
)

# Individual PDFs
foreach ($src in $sources) {
    $html = Join-Path $docs $src.File
    if (-not (Test-Path $html)) {
        Write-Warning "Skip missing: $($src.File)"
        continue
    }
    Convert-HtmlToPdf $html (Join-Path $docs $src.Pdf)
}

# Combined EVERYTHING.pdf (all parts)
$allStyles = New-Object System.Collections.Generic.List[string]
$partsHtml = New-Object System.Collections.Generic.List[string]
$partLetters = 'A','B','C','D','E','F','G','H','I','J'

$idx = 0
foreach ($src in $sources) {
    $html = Join-Path $docs $src.File
    if (-not (Test-Path $html)) { continue }
    $part = Get-HtmlPart $html
    if ($part.Styles) { $allStyles.Add($part.Styles) }
    $letter = $partLetters[$idx]
    $partsHtml.Add(@"
<div class="part-divider">Part $letter — $($src.Part)</div>
<div class="content-wrap">$($part.Body)</div>
"@)
    $idx++
}

$combinedHtml = Join-Path $docs 'EVERYTHING.html'
$combinedStyles = ($allStyles | Select-Object -Unique) -join "`n"
$combinedBody = $partsHtml -join "`n"
$dateLabel = Get-Date -Format 'd MMMM yyyy'

@"

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Olasentra — Complete Platform Documentation</title>
<style>
@page { size: A4; margin: 12mm 11mm; }
* { box-sizing: border-box; }
body { font-family: "Segoe UI", system-ui, sans-serif; margin: 0; padding: 0; color: #0f172a; }
.cover {
    min-height: 95vh; display: flex; flex-direction: column; justify-content: center;
    align-items: center; text-align: center; padding: 50px;
    background: linear-gradient(135deg, #030712, #1e1b4b 45%, #312e81); color: #f8fafc;
    page-break-after: always;
}
.cover h1 { font-size: 24pt; margin: 0 0 10px; }
.cover .sub { font-size: 12pt; color: #c7d2fe; margin-bottom: 8px; }
.cover .meta { font-size: 9pt; color: #64748b; }
.cover .toc-cover { margin-top: 32px; text-align: left; font-size: 9pt; line-height: 1.85; max-width: 420px; }
.part-divider {
    page-break-before: always; padding: 22px 18px 8px;
    background: linear-gradient(90deg, #4f46e5, #7c3aed); color: #fff;
    font-size: 13pt; font-weight: 700;
}
.content-wrap { padding: 0 16px 16px; }
.page-break { page-break-before: always; }
$combinedStyles
</style>
</head>
<body>
<div class="cover">
    <p class="sub">Event Operations Command Platform</p>
    <h1>Olasentra — Everything</h1>
    <p class="sub">Complete internal documentation</p>
    <p class="meta">Generated $dateLabel · Confidential</p>
    <div class="toc-cover">
        <strong>Part A</strong> — Platform Focus &amp; Priorities<br>
        <strong>Part B</strong> — Database Health Report<br>
        <strong>Part C</strong> — Phase 16 Deployment Results<br>
        <strong>Part D</strong> — Phase 16 Implementation Package<br>
        <strong>Part E</strong> — Implementation Master Plan v1.2<br>
        <strong>Part F</strong> — UX Master Plan v2.0<br>
        <strong>Part G</strong> — UX Blueprint v1<br>
        <strong>Part H</strong> — Admin Page Inventory<br>
        <strong>Part I</strong> — Production Audit Report
    </div>
</div>
$combinedBody
</body>
</html>
"@ | Set-Content -Path $combinedHtml -Encoding UTF8

Convert-HtmlToPdf $combinedHtml (Join-Path $docs 'EVERYTHING.pdf')

# Legacy alias (blueprint + inventory only)
$ux = Get-HtmlPart (Join-Path $docs 'UX-BLUEPRINT.html')
$inv = Get-HtmlPart (Join-Path $docs 'ADMIN-PAGE-INVENTORY.html')
$legacyHtml = Join-Path $docs 'PLATFORM-DOCUMENTATION.html'
@"

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Event Staff Platform — Documentation (legacy bundle)</title>
<style>
@page { size: A4; margin: 14mm 12mm; }
body { font-family: "Segoe UI", system-ui, sans-serif; margin: 0; padding: 0; color: #0f172a; }
.part-divider { page-break-before: always; padding: 24px 18px 8px; background: linear-gradient(90deg, #4f46e5, #7c3aed); color: #fff; font-size: 14pt; font-weight: 700; }
.content-wrap { padding: 0 18px 18px; }
$($ux.Styles)
$($inv.Styles)
</style>
</head>
<body>
<div class="part-divider">Part A — UX Blueprint</div>
<div class="content-wrap">$($ux.Body)</div>
<div class="part-divider">Part B — Admin Page Inventory</div>
<div class="content-wrap">$($inv.Body)</div>
</body>
</html>
"@ | Set-Content -Path $legacyHtml -Encoding UTF8
Convert-HtmlToPdf $legacyHtml (Join-Path $docs 'PLATFORM-DOCUMENTATION.pdf')

Write-Host ""
Write-Host "Done. Primary file: docs\EVERYTHING.pdf"
