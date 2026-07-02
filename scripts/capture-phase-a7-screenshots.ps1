# Phase A.7 — multi-device viewport screenshots from production (flag ON).
param(
    [string] $BaseUrl = 'https://register.olasentra.com',
    [string] $OutRoot = ''
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
if ($OutRoot -eq '') {
    $OutRoot = Join-Path $Root 'docs\screenshots\a7-mobile-qa'
}

$browser = @(
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles}\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles}\Google\Chrome\Application\chrome.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $browser) { throw 'Chrome or Edge required.' }

$devices = @(
    @{ Key = 'iphone-safari';     Width = 390;  Label = 'iPhone Safari' }
    @{ Key = 'android-chrome';    Width = 412;  Label = 'Android Chrome' }
    @{ Key = 'tablet-portrait';   Width = 768;  Label = 'Tablet Portrait' }
    @{ Key = 'tablet-landscape';  Width = 1024; Label = 'Tablet Landscape' }
)

$heights = @{ 1 = 1100; 2 = 1200; 3 = 1050; 4 = 1150; 5 = 900; 6 = 1000; 7 = 1280; 8 = 1450; 9 = 1200 }
$stepModes = @{ 1 = 'resume'; 3 = 'returning' }
$stepSlugs = @{ 1='welcome'; 2='gigs'; 3='email'; 4='about'; 5='contact'; 6='payroll'; 7='psa'; 8='review'; 9='success' }

function Get-ShotUrl([int]$Step) {
    if ($Step -eq 9) {
        return ('{0}/status-screenshot-preview.php' -f $BaseUrl)
    }
    if ($Step -eq 1) {
        return ('{0}/index.php' -f $BaseUrl)
    }
    $mode = $stepModes[$Step]
    if ($mode) {
        return ('{0}/wizard-screenshot-preview.php?step={1}&vp=mobile&mode={2}' -f $BaseUrl, $Step, $mode)
    }
    return ('{0}/wizard-screenshot-preview.php?step={1}&vp=mobile' -f $BaseUrl, $Step)
}

foreach ($dev in $devices) {
    $dir = Join-Path $OutRoot $dev.Key
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
}

Write-Host "A.7 screenshots -> $OutRoot" -ForegroundColor Cyan

foreach ($dev in $devices) {
    Write-Host "Device: $($dev.Label) ($($dev.Width)px)" -ForegroundColor Yellow
    foreach ($step in 1..9) {
        $slug = $stepSlugs[$step]
        $h = $heights[$step]
        $url = Get-ShotUrl $step
        $png = Join-Path (Join-Path $OutRoot $dev.Key) ("step-{0:D2}-{1}.png" -f $step, $slug)
        $browserArgs = @(
            '--headless=new', '--disable-gpu', '--hide-scrollbars',
            ('--window-size={0},{1}' -f $dev.Width, $h),
            ('--screenshot={0}' -f $png),
            $url
        )
        Write-Host "  $($dev.Key)/step-$('{0:D2}' -f $step)-$slug.png" -ForegroundColor Green
        Start-Process -FilePath $browser -ArgumentList $browserArgs -Wait -NoNewWindow | Out-Null
        Start-Sleep -Milliseconds 400
        if (-not (Test-Path $png)) { throw "Missing screenshot: $png" }
    }
}

Write-Host "Done: $((Get-ChildItem $OutRoot -Recurse -Filter '*.png').Count) PNG files" -ForegroundColor Cyan
