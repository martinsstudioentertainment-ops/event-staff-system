# Generate SESSION-REPORT PDF from HTML source.
# Usage: powershell -ExecutionPolicy Bypass -File .\scripts\generate-session-report-pdf.ps1

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$docs = Join-Path $root 'docs'
$html = Join-Path $docs 'SESSION-REPORT-2026-06-06.html'
$pdf  = Join-Path $docs 'SESSION-REPORT-2026-06-06.pdf'

if (-not (Test-Path $html)) {
    throw "Missing source: $html"
}

$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
if (-not (Test-Path $chrome)) {
    $chrome = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
}
if (-not (Test-Path $chrome)) {
    throw 'Chrome or Edge required for PDF generation.'
}

if (Test-Path $pdf) { Remove-Item $pdf -Force }

$uri = [System.Uri]::new($html).AbsoluteUri
& $chrome --headless=new --disable-gpu --no-pdf-header-footer --print-to-pdf="$pdf" "$uri" 2>$null
Start-Sleep -Milliseconds 3000

if (-not (Test-Path $pdf)) {
    throw "Failed to create: $pdf"
}

$kb = [math]::Round((Get-Item $pdf).Length / 1KB, 1)
Write-Host "OK  SESSION-REPORT-2026-06-06.pdf ($kb KB)"
Write-Host "    $pdf"
