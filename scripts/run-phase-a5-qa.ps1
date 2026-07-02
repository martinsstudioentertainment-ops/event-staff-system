# Phase A.5 full QA: static checks, E2E, screenshots, report seed.
$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
$OutScreens = Join-Path $Root 'docs\screenshots\a5-verification'
$E2eJson = Join-Path $Root 'storage\reports\e2e-a5-latest.json'
$E2eUrl = if ($env:E2E_BASE_URL) { $env:E2E_BASE_URL } else { 'https://register.olasentra.com' }

New-Item -ItemType Directory -Force -Path (Join-Path $OutScreens 'mobile') | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $OutScreens 'styled\mobile') | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $Root 'storage\reports') | Out-Null

Write-Host '=== Phase A.5 static verification ===' -ForegroundColor Cyan
& (Join-Path $Root 'scripts\verify-phase-a5.ps1')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host ''
Write-Host '=== Phase A.5 E2E registration test ===' -ForegroundColor Cyan
php (Join-Path $Root 'scripts\e2e-registration-wizard-test.php') --url=$E2eUrl --json | Tee-Object -FilePath $E2eJson
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host ''
Write-Host '=== Phase A.5 styled screenshots ===' -ForegroundColor Cyan
& (Join-Path $Root 'scripts\capture-wizard-screenshots-styled.ps1') -Steps @(8) -OutSubDir 'styled' -BaseOut $OutScreens

$Edge = @(
    "${env:ProgramFiles}\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if ($Edge) {
    $php = Get-Command php -ErrorAction SilentlyContinue
    if ($php) {
        $port = 8766
        $serverJob = Start-Job -ScriptBlock {
            param($Root, $Port)
            Set-Location $Root
            php -S "127.0.0.1:$Port" -t $Root 2>&1
        } -ArgumentList $Root, $port
        Start-Sleep -Seconds 2
        try {
            $urls = @(
                @{ File = 'step-08-review-errors.png'; Url = "http://127.0.0.1:$port/wizard-screenshot-preview.php?step=8&vp=mobile&mode=error" }
                @{ File = 'status-success-confirmation.png'; Url = "http://127.0.0.1:$port/status-screenshot-preview.php" }
            )
            foreach ($item in $urls) {
                $png = Join-Path (Join-Path $OutScreens 'mobile') $item.File
                $args = @('--headless=new', '--disable-gpu', "--window-size=390,1400", "--screenshot=$png", $item.Url)
                & $Edge @args 2>$null
                if (Test-Path $png) {
                    Write-Host "Captured $($item.File)" -ForegroundColor Green
                }
            }
        } finally {
            Stop-Job $serverJob -ErrorAction SilentlyContinue
            Remove-Job $serverJob -Force -ErrorAction SilentlyContinue
        }
    }
}

Write-Host ''
Write-Host 'Phase A.5 QA pipeline complete.' -ForegroundColor Green
Write-Host "E2E JSON: $E2eJson"
Write-Host "Screenshots: $OutScreens"
