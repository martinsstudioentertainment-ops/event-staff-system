# Capture registration wizard screenshots from production (flag must be ON).
param(
    [string] $BaseUrl = 'https://register.olasentra.com',
    [string] $OutDir = '',
    [int[]] $Steps = @(1, 2, 3, 4, 5, 6, 7, 8)
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
if ($OutDir -eq '') {
    $OutDir = Join-Path $Root 'docs\screenshots\wizard-live-production'
}
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

$browser = @(
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles}\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles}\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
    "${env:LocalAppData}\Google\Chrome\Application\chrome.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $browser) { throw 'Chrome or Edge required for headless screenshots.' }

$stepSlugs = @{
    1 = 'welcome'
    2 = 'your-gigs'
    3 = 'email'
    4 = 'about-you'
    5 = 'contact'
    6 = 'payroll'
    7 = 'psa'
    8 = 'review'
}
$heights = @{
    1 = 1100; 2 = 1200; 3 = 1050; 4 = 1150
    5 = 900;  6 = 1000; 7 = 1280; 8 = 1450
}
$modeQ = @{
    1 = 'resume'
    3 = 'returning'
}

Write-Host "Production wizard screenshots -> $OutDir" -ForegroundColor Cyan

foreach ($stepNum in $Steps) {
    $slug = $stepSlugs[$stepNum]
    if (-not $slug) { $slug = "step-$stepNum" }
    $h = $heights[$stepNum]
    if (-not $h) { $h = 1000 }

    if ($stepNum -eq 1) {
        $url = "$BaseUrl/index.php"
    } else {
        $mode = $modeQ[$stepNum]
        $url = if ($mode) {
            ('{0}/wizard-screenshot-preview.php?step={1}&vp=mobile&mode={2}' -f $BaseUrl, $stepNum, $mode)
        } else {
            ('{0}/wizard-screenshot-preview.php?step={1}&vp=mobile' -f $BaseUrl, $stepNum)
        }
    }

    $pngFile = Join-Path $OutDir ("step-{0:D2}-{1}-mobile.png" -f $stepNum, $slug)
    $browserArgs = @(
        '--headless=new',
        '--disable-gpu',
        '--hide-scrollbars',
        ('--window-size=390,{0}' -f $h),
        ('--screenshot={0}' -f $pngFile),
        $url
    )
    Write-Host "  step-$('{0:D2}' -f $stepNum)-$slug-mobile.png" -ForegroundColor Green
    Start-Process -FilePath $browser -ArgumentList $browserArgs -Wait -NoNewWindow | Out-Null
    Start-Sleep -Milliseconds 500
    if (-not (Test-Path $pngFile)) {
        throw "Screenshot missing: $pngFile"
    }
}

Write-Host "Done: $OutDir" -ForegroundColor Cyan
