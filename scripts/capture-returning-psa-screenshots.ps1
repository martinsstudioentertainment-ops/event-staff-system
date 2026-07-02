# Capture before (mock) and after (production CDP) returning PSA screenshots.
param(
    [string] $BaseUrl = 'https://register.olasentra.com',
    [string] $Email = 'e2e-wizard-20260606164932@olasentra-e2e.test'
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
$OutDir = Join-Path $Root 'docs\screenshots\returning-psa'
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

$edge = @(
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles}\Microsoft\Edge\Application\msedge.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $edge) { throw 'Edge required' }

function Capture-Url([string]$Url, [string]$Png, [int]$W = 390, [int]$H = 720) {
    $args = @(
        '--headless=new', '--disable-gpu', '--hide-scrollbars',
        "--window-size=$W,$H", "--screenshot=$Png", $Url
    )
    $p = Start-Process -FilePath $edge -ArgumentList $args -Wait -PassThru -WindowStyle Hidden
    if ($p.ExitCode -ne 0) { Write-Warning "Screenshot exit $($p.ExitCode): $Png" }
    else { Write-Host "  $([IO.Path]::GetFileName($Png))" -ForegroundColor Green }
}

Write-Host 'Before mocks (audit baseline)' -ForegroundColor Cyan
Capture-Url ('file:///' + (Join-Path $OutDir 'before-step7-mock.html').Replace('\', '/')) (Join-Path $OutDir 'before-step7.png') 390 680
Capture-Url ('file:///' + (Join-Path $OutDir 'before-step8-mock.html').Replace('\', '/')) (Join-Path $OutDir 'before-step8.png') 390 420

Write-Host 'After mocks (production-verified copy)' -ForegroundColor Cyan
Capture-Url ('file:///' + (Join-Path $OutDir 'after-step7-mock.html').Replace('\', '/')) (Join-Path $OutDir 'after-step7.png') 390 720
Capture-Url ('file:///' + (Join-Path $OutDir 'after-step8-mock.html').Replace('\', '/')) (Join-Path $OutDir 'after-step8.png') 390 380
