# Screenshot production email client previews (Gmail / Outlook / Apple Mail chrome).
$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
$HtmlDir = Join-Path $Root 'docs\screenshots\email-production\html'
$OutDir = Join-Path $Root 'docs\screenshots\email-production'
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

$edge = @(
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles}\Microsoft\Edge\Application\msedge.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $edge) { throw 'Microsoft Edge required for screenshots' }

function Capture-Preview([string]$HtmlFile, [string]$Png, [int]$W, [int]$H) {
    $uri = 'file:///' + $HtmlFile.Replace('\', '/')
    $args = @('--headless=new', '--disable-gpu', '--hide-scrollbars', "--window-size=$W,$H", "--screenshot=$Png", $uri)
    $p = Start-Process -FilePath $edge -ArgumentList $args -Wait -PassThru -WindowStyle Hidden
    if ($p.ExitCode -ne 0) { Write-Warning "Exit $($p.ExitCode): $Png" }
    else { Write-Host "  $(Split-Path $Png -Leaf)" -ForegroundColor Green }
}

$templates = @('registration_confirmation', 'registration_approved', 'access_pass', 'event_reminder', 'admin_alert')
$clients = @(
    @{ Id = 'gmail'; W = 620; H = 900 },
    @{ Id = 'outlook'; W = 720; H = 900 },
    @{ Id = 'apple'; W = 390; H = 820 }
)

foreach ($tpl in $templates) {
    foreach ($c in $clients) {
        $html = Join-Path $HtmlDir "$tpl-$($c.Id).html"
        if (-not (Test-Path $html)) { continue }
        $png = Join-Path $OutDir "$tpl-$($c.Id).png"
        Capture-Preview $html $png $c.W $c.H
    }
}
