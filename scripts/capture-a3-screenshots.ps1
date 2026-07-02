# Capture Phase A.3 review screenshots from local HTML previews (Edge headless).
$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
$PreviewDir = Join-Path $Root 'docs\screenshots\a3'
$Edge = @(
    "${env:ProgramFiles}\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $Edge) {
    throw 'Microsoft Edge not found — open docs/screenshots/a3/*.html manually and screenshot.'
}

$captures = @(
    @{ Html = '01-step1-welcome.html';       Png = '01-step1-welcome.png';       Width = 390;  Height = 844 }
    @{ Html = '02-step2-events.html';        Png = '02-step2-events.png';        Width = 390;  Height = 900 }
    @{ Html = '03-step3-returning.html';     Png = '03-step3-returning.png';     Width = 390;  Height = 920 }
    @{ Html = '04-profile-status-card.html'; Png = '04-profile-status-card.png'; Width = 390;  Height = 1100 }
    @{ Html = '05-mobile-returning-flow.html'; Png = '05-mobile-iphone.png';     Width = 390;  Height = 780 }
    @{ Html = '06-tablet-step2.html';        Png = '06-tablet.png';              Width = 768;  Height = 900 }
)

Write-Host "Capturing Phase A.3 screenshots to $PreviewDir" -ForegroundColor Cyan

foreach ($cap in $captures) {
    $htmlPath = Join-Path $PreviewDir $cap.Html
    $pngPath  = Join-Path $PreviewDir $cap.Png
    if (-not (Test-Path $htmlPath)) {
        Write-Warning "Skip missing $htmlPath"
        continue
    }
    $uri = [Uri]::new((Resolve-Path $htmlPath)).AbsoluteUri
    $args = @(
        '--headless=new',
        '--disable-gpu',
        '--hide-scrollbars',
        "--window-size=$($cap.Width),$($cap.Height)",
        "--screenshot=$pngPath",
        $uri
    )
    Write-Host "  -> $($cap.Png)" -ForegroundColor Green
    $proc = Start-Process -FilePath $Edge -ArgumentList $args -Wait -PassThru -WindowStyle Hidden
    if ($proc.ExitCode -ne 0 -and -not (Test-Path $pngPath)) {
        Write-Warning "Edge exit $($proc.ExitCode) for $($cap.Png)"
    }
}

Write-Host 'Done.' -ForegroundColor Cyan
