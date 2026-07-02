# Phase A.6 screenshot capture (styled, localhost HTTP).
$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
$OutDir = Join-Path $Root 'docs\screenshots\a6-verification\mobile'
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

$Edge = @(
    "${env:ProgramFiles}\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $Edge) { throw 'Microsoft Edge required for screenshots.' }

$port = 8767
$serverJob = Start-Job -ScriptBlock {
    param($Root, $Port)
    Set-Location $Root
    php -S "127.0.0.1:$Port" -t $Root 2>&1
} -ArgumentList $Root, $port
Start-Sleep -Seconds 2

try {
    $shots = @(
        @{ Name = '01-resume-prompt.png'; Url = "http://127.0.0.1:$port/wizard-screenshot-preview.php?step=1&vp=mobile&mode=resume"; H = 1100 }
        @{ Name = '02-save-status-review.png'; Url = "http://127.0.0.1:$port/wizard-screenshot-preview.php?step=8&vp=mobile&mode=normal"; H = 1400 }
    )
    foreach ($s in $shots) {
        $png = Join-Path $OutDir $s.Name
        $args = @('--headless=new', '--disable-gpu', "--window-size=390,$($s.H)", "--screenshot=$png", $s.Url)
        & $Edge @args 2>$null
        if (Test-Path $png) {
            Write-Host "Captured $($s.Name)" -ForegroundColor Green
        } else {
            Write-Warning "Failed: $($s.Name)"
        }
    }
} finally {
    Stop-Job $serverJob -ErrorAction SilentlyContinue
    Remove-Job $serverJob -Force -ErrorAction SilentlyContinue
}

Write-Host "Screenshots: $OutDir"
