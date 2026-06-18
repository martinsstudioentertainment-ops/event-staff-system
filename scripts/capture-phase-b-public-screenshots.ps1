# Phase B public platform audit — capture production page screenshots (desktop + mobile).
$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
$OutDir = Join-Path $Root 'docs\screenshots\phase-b'
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

$edge = @(
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles}\Microsoft\Edge\Application\msedge.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $edge) { throw 'Microsoft Edge required for screenshots' }

function Capture-Url([string]$Url, [string]$Png, [int]$W, [int]$H, [int]$DelayMs = 2500) {
    $args = @(
        '--headless=new', '--disable-gpu', '--hide-scrollbars',
        "--window-size=$W,$H", "--screenshot=$Png",
        "--virtual-time-budget=$DelayMs", $Url
    )
    $p = Start-Process -FilePath $edge -ArgumentList $args -Wait -PassThru -WindowStyle Hidden
    if ($p.ExitCode -ne 0) { Write-Warning "Exit $($p.ExitCode): $Png" }
    else { Write-Host "  $(Split-Path $Png -Leaf)" -ForegroundColor Green }
}

$pages = @(
    @{ Id = 'home';     Url = 'https://olasentra.com/home.php' },
    @{ Id = 'roles';    Url = 'https://olasentra.com/roles.php' },
    @{ Id = 'events';   Url = 'https://olasentra.com/events-page.php' },
    @{ Id = 'how';      Url = 'https://olasentra.com/how-it-works.php' },
    @{ Id = 'faq';      Url = 'https://olasentra.com/faq.php' },
    @{ Id = 'contact';  Url = 'https://olasentra.com/contact.php' },
    @{ Id = 'privacy';  Url = 'https://olasentra.com/privacy.php' },
    @{ Id = 'register'; Url = 'https://register.olasentra.com/' },
    @{ Id = 'staff-app';Url = 'https://register.olasentra.com/staff-app.php' }
)

$viewports = @(
    @{ Suffix = 'desktop'; W = 1280; H = 900 },
    @{ Suffix = 'mobile';  W = 390;  H = 844 }
)

foreach ($page in $pages) {
    foreach ($vp in $viewports) {
        $png = Join-Path $OutDir "$($page.Id)-$($vp.Suffix).png"
        Capture-Url $page.Url $png $vp.W $vp.H 3000
    }
}
