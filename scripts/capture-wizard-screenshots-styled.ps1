# Capture wizard UX screenshots via local HTTP server (full production CSS).
param(
    [int[]] $Steps = @(5, 6, 7, 8),
    [string] $OutSubDir = 'styled',
    [string] $BaseOut = '',
    [string] $PreviewMode = ''
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
if ($BaseOut -eq '') {
    $BaseOut = Join-Path $Root 'docs\screenshots\a4-verification'
}
$OutDir = Join-Path $BaseOut $OutSubDir

$Edge = @(
    "${env:ProgramFiles}\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $Edge) { throw 'Microsoft Edge required.' }

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) { throw 'PHP CLI required (Laragon).' }

$port = 8765
$baseUrl = "http://127.0.0.1:$port/wizard-screenshot-preview.php"

$viewports = @(
    @{ Key = 'mobile';  Width = 390;  Height = @{ 1 = 1100; 5 = 900; 6 = 1000; 7 = 1280; 8 = 1450 } }
    @{ Key = 'tablet';  Width = 768;  Height = @{ 1 = 1000; 5 = 820; 6 = 920; 7 = 1200; 8 = 1350 } }
    @{ Key = 'desktop'; Width = 1200; Height = @{ 1 = 950; 5 = 800; 6 = 880; 7 = 1100; 8 = 1250 } }
)

$stepNames = @{ 1 = 'welcome-resume'; 5 = 'contact'; 6 = 'payroll'; 7 = 'psa'; 8 = 'review-saved' }

foreach ($vp in @('mobile', 'tablet', 'desktop')) {
    New-Item -ItemType Directory -Force -Path (Join-Path $OutDir $vp) | Out-Null
}

Write-Host "Starting PHP server on port $port ..." -ForegroundColor Cyan
$serverJob = Start-Job -ScriptBlock {
    param($Root, $Port)
    Set-Location $Root
    php -S "127.0.0.1:$Port" -t $Root 2>&1
} -ArgumentList $Root, $port

Start-Sleep -Seconds 2

try {
    # Verify CSS loads
    $cssProbe = Invoke-WebRequest -Uri "http://127.0.0.1:$port/assets/css/registration-wizard.css" -UseBasicParsing -TimeoutSec 10
    if ($cssProbe.StatusCode -ne 200) {
        throw "registration-wizard.css returned $($cssProbe.StatusCode)"
    }
    Write-Host "CSS probe OK: registration-wizard.css ($($cssProbe.RawContentLength) bytes)" -ForegroundColor Green

    $pageProbe = Invoke-WebRequest -Uri "$baseUrl`?step=5&vp=mobile" -UseBasicParsing -TimeoutSec 30
    if ($pageProbe.Content -notmatch 'registration-wizard\.css') {
        throw 'Preview page missing registration-wizard.css link'
    }
    if ($pageProbe.Content -notmatch 'public-front\.css') {
        throw 'Preview page missing public-front.css link'
    }
    Write-Host "Page probe OK: full CSS stack linked" -ForegroundColor Green

    foreach ($stepNum in $Steps) {
        $slug = $stepNames[$stepNum]
        if (-not $slug) { $slug = "step-$stepNum" }

        foreach ($vp in $viewports) {
            $h = $vp.Height[$stepNum]
            if (-not $h) { $h = 900 }
            $modeQ = ''
            if ($PreviewMode -ne '') {
                $modeQ = '&mode=' + [uri]::EscapeDataString($PreviewMode)
            } elseif ($stepNum -eq 1) {
                $modeQ = '&mode=resume'
            }
            $url = "$baseUrl`?step=$stepNum&vp=$($vp.Key)$modeQ"
            $pngName = "step-{0:D2}-{1}.png" -f $stepNum, $slug
            $pngFile = Join-Path (Join-Path $OutDir $vp.Key) $pngName

            $args = @(
                '--headless=new',
                '--disable-gpu',
                '--hide-scrollbars',
                "--window-size=$($vp.Width),$h",
                "--screenshot=$pngFile",
                $url
            )
            Write-Host "  $($vp.Key)/step-$('{0:D2}' -f $stepNum)-$slug.png" -ForegroundColor Green
            Start-Process -FilePath $Edge -ArgumentList $args -Wait -WindowStyle Hidden | Out-Null
        }
    }
}
finally {
    Stop-Job $serverJob -ErrorAction SilentlyContinue
    Remove-Job $serverJob -Force -ErrorAction SilentlyContinue
}

Write-Host "Styled screenshots: $OutDir" -ForegroundColor Cyan
