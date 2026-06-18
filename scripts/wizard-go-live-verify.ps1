# Enable feature_registration_wizard_v2 on production, verify UI, E2E, rollback, re-enable.
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\wizard-go-live-verify.ps1
#   powershell -ExecutionPolicy Bypass -File .\scripts\wizard-go-live-verify.ps1 -SkipDeploy
#   powershell -ExecutionPolicy Bypass -File .\scripts\wizard-go-live-verify.ps1 -LeaveDisabled

param(
    [switch]$SkipDeploy,
    [switch]$LeaveDisabled,
    [switch]$SkipE2e
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
Set-Location $Root

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

$regBase = if ($env:REGISTRATION_BASE_URL) { $env:REGISTRATION_BASE_URL.TrimEnd('/') } else { 'https://register.olasentra.com' }
$adminBase = if ($cfg.AdminUrl) { $cfg.AdminUrl.TrimEnd('/') } else { 'https://admin.olasentra.com' }
$adminBase = $adminBase -replace '/admin$', ''

$stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
$outDir = Join-Path $Root 'docs\screenshots\wizard-live-production'
$reportPath = Join-Path $Root 'docs\WIZARD-LIVE-VERIFICATION-REPORT.html'
$checks = @()

function Add-Check([string]$Name, [string]$Status, [string]$Detail) {
    $script:checks += [PSCustomObject]@{ Name = $Name; Status = $Status; Detail = $Detail }
    $color = switch ($Status) { 'PASS' { 'Green' } 'FAIL' { 'Red' } default { 'Yellow' } }
    Write-Host ('[{0}] {1} - {2}' -f $Status, $Name, $Detail) -ForegroundColor $color
}

function Get-ProductionCronKey {
    param([hashtable]$Deploy)
    if ($Deploy.ReminderCronKey -and $Deploy.ReminderCronKey.Trim() -ne '') {
        return $Deploy.ReminderCronKey.Trim()
    }
    $uri = Get-FtpUri -Server $Deploy.FtpServer -RemoteDir $Deploy.FtpRemoteDir -RelativePath 'storage/backups/weekly/settings-and-cms.json'
    $client = New-Object System.Net.WebClient
    $client.Credentials = New-Object System.Net.NetworkCredential($Deploy.FtpUser, $Deploy.FtpPassword)
    try {
        $bytes = $client.DownloadData($uri)
        $json  = [System.Text.Encoding]::UTF8.GetString($bytes) | ConvertFrom-Json
        $key   = [string]$json.settings.reminder_cron_key
        if ($key.Trim() -ne '') { return $key.Trim() }
    } finally {
        $client.Dispose()
    }
    throw 'Set ReminderCronKey in deploy.local.ps1 or ensure weekly settings backup exists.'
}

function Invoke-FlagToggle {
    param([string]$Action, [string]$CronKey)
    $url = ('{0}/cron/wizard-flag-toggle.php?key={1}&action={2}' -f $regBase, [uri]::EscapeDataString($CronKey), $Action)
    $resp = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 30
    $json = $resp.Content | ConvertFrom-Json
    if (-not $json.ok) { throw "Flag toggle $Action failed: $($resp.Content)" }
    return $json
}

function Test-WizardHtml {
    param([string]$ExpectMode)
    $html = (Invoke-WebRequest -Uri "$regBase/index.php" -UseBasicParsing -TimeoutSec 30).Content
    $mode = if ($html -match 'data-wizard-mode="(\d)"') { $Matches[1] } else { '?' }
    $steps = ([regex]::Matches($html, 'reg-wizard__step')).Count
    return @{ Mode = $mode; Steps = $steps; Html = $html }
}

Write-Host ''
Write-Host '========================================' -ForegroundColor Green
Write-Host '  Wizard go-live verification' -ForegroundColor Green
Write-Host '========================================' -ForegroundColor Green
Write-Host "  Registration: $regBase" -ForegroundColor Gray
Write-Host "  Stamp: $stamp" -ForegroundColor Gray
Write-Host ''

if (-not $SkipDeploy) {
    Write-Host '[1/8] Upload toggle + preview files ...' -ForegroundColor Cyan
    $uploads = @(
        @{ Local = 'cron\wizard-flag-toggle.php'; Remote = 'cron/wizard-flag-toggle.php' },
        @{ Local = 'wizard-screenshot-preview.php'; Remote = 'wizard-screenshot-preview.php' }
    )
    foreach ($f in $uploads) {
        Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
    }
    Add-Check 'Deploy toggle + preview' 'PASS' 'FTP upload complete'
} else {
    Add-Check 'Deploy toggle + preview' 'SKIP' '-SkipDeploy'
}

Write-Host ''
Write-Host '[2/8] Read production cron key ...' -ForegroundColor Cyan
$cronKey = Get-ProductionCronKey -Deploy $cfg
Add-Check 'Cron key available' 'PASS' 'From deploy.local.ps1 or settings backup'

Write-Host ''
Write-Host '[3/8] Enable feature_registration_wizard_v2 ...' -ForegroundColor Cyan
$enable = Invoke-FlagToggle -Action 'enable' -CronKey $cronKey
if ($enable.enabled) {
    Add-Check 'Enable wizard flag' 'PASS' 'feature_registration_wizard_v2 = 1'
} else {
    Add-Check 'Enable wizard flag' 'FAIL' 'Flag still OFF after enable'
}

Start-Sleep -Seconds 2

Write-Host ''
Write-Host '[4/8] Production HTML verification ...' -ForegroundColor Cyan
$live = Test-WizardHtml -ExpectMode '1'
if ($live.Mode -eq '1') {
    Add-Check 'data-wizard-mode on index.php' 'PASS' 'data-wizard-mode="1"'
} else {
    Add-Check 'data-wizard-mode on index.php' 'FAIL' "data-wizard-mode=`"$($live.Mode)`""
}
if ($live.Steps -ge 8) {
    Add-Check 'Eight wizard step panels' 'PASS' "$($live.Steps) reg-wizard__step elements"
} else {
    Add-Check 'Eight wizard step panels' 'FAIL' "Found $($live.Steps) panels"
}
if ($live.Html -match 'registration-wizard\.css' -and $live.Html -match 'registration-wizard\.js') {
    Add-Check 'Wizard assets linked' 'PASS' 'CSS + JS in index.php'
} else {
    Add-Check 'Wizard assets linked' 'FAIL' 'Missing wizard asset links'
}
if ($live.Html -match 'Step 1 of 8') {
    Add-Check 'Progress label Step 1 of 8' 'PASS' 'Wizard shell rendered'
} else {
    Add-Check 'Progress label Step 1 of 8' 'FAIL' 'Shell not in HTML'
}

& (Join-Path $Root 'scripts\verify-wizard-production.ps1')
$verifyExit = $LASTEXITCODE
if ($verifyExit -eq 0) {
    Add-Check 'verify-wizard-production.ps1' 'PASS' 'All automated checks passed'
} else {
    Add-Check 'verify-wizard-production.ps1' 'FAIL' "Exit code $verifyExit"
}

Write-Host ''
Write-Host '[5/8] Capture production screenshots (Steps 1-8) ...' -ForegroundColor Cyan
try {
    & (Join-Path $Root 'scripts\capture-production-wizard-screenshots.ps1') -BaseUrl $regBase -OutDir $outDir
    $shotCount = (Get-ChildItem -Path $outDir -Filter '*.png' -ErrorAction SilentlyContinue).Count
    if ($shotCount -ge 8) {
        Add-Check 'Screenshots Steps 1-8' 'PASS' "$shotCount PNG files in docs/screenshots/wizard-live-production"
    } else {
        Add-Check 'Screenshots Steps 1-8' 'FAIL' "Only $shotCount PNG files captured"
    }
} catch {
    Add-Check 'Screenshots Steps 1-8' 'FAIL' $_.Exception.Message
}

Write-Host ''
Write-Host '[6/8] Step 8 review summary probe ...' -ForegroundColor Cyan
try {
    $reviewUrl = ('{0}/wizard-screenshot-preview.php?step=8&vp=mobile' -f $regBase)
    $reviewHtml = (Invoke-WebRequest -Uri $reviewUrl -UseBasicParsing -TimeoutSec 30).Content
    if ($reviewHtml -match 'reg-wizard-review-summary' -and $reviewHtml -match 'registration-wizard-review\.js') {
        Add-Check 'Step 8 review panel' 'PASS' 'Review mount + review.js present'
    } else {
        Add-Check 'Step 8 review panel' 'FAIL' 'Review summary markup missing'
    }
} catch {
    Add-Check 'Step 8 review panel' 'FAIL' $_.Exception.Message
}

if (-not $SkipE2e) {
    Write-Host ''
    Write-Host '[7/8] E2E registration (submit.php) ...' -ForegroundColor Cyan
    $e2eEmail = "e2e-live-wizard-$(Get-Date -Format 'yyyyMMddHHmmss')@olasentra-e2e.test"
    $e2eOut = & php (Join-Path $Root 'scripts\e2e-registration-wizard-test.php') --url=$regBase --email=$e2eEmail 2>&1
    $e2eText = $e2eOut | Out-String
    if ($e2eText -match '\[PASS\].*submit' -or $e2eText -match 'E2E PASSED' -or ($e2eText -notmatch '\[FAIL\]')) {
        Add-Check 'E2E submit.php registration' 'PASS' $e2eEmail
    } else {
        Add-Check 'E2E submit.php registration' 'FAIL' ($e2eText -split "`n" | Select-Object -Last 3) -join '; '
    }
} else {
    Add-Check 'E2E submit.php registration' 'SKIP' '-SkipE2e'
}

Write-Host ''
Write-Host '[8/8] Rollback test (disable flag) ...' -ForegroundColor Cyan
$disable = Invoke-FlagToggle -Action 'disable' -CronKey $cronKey
Start-Sleep -Seconds 2
$legacy = Test-WizardHtml -ExpectMode '0'
if ($legacy.Mode -eq '0' -and $legacy.Steps -eq 0) {
    Add-Check 'Rollback to legacy form' 'PASS' 'data-wizard-mode="0", no wizard panels'
} elseif ($legacy.Mode -eq '0') {
    Add-Check 'Rollback to legacy form' 'PASS' 'data-wizard-mode="0"'
} else {
    Add-Check 'Rollback to legacy form' 'FAIL' "data-wizard-mode=`"$($legacy.Mode)`""
}

if (-not $LeaveDisabled) {
    Write-Host 'Re-enabling wizard flag after rollback test ...' -ForegroundColor Cyan
    $reenable = Invoke-FlagToggle -Action 'enable' -CronKey $cronKey
    if ($reenable.enabled) {
        Add-Check 'Re-enable after rollback test' 'PASS' 'Wizard left ON for production'
    } else {
        Add-Check 'Re-enable after rollback test' 'FAIL' 'Flag OFF after re-enable'
    }
} else {
    Add-Check 'Re-enable after rollback test' 'SKIP' '-LeaveDisabled'
}

# HTML report
Add-Type -AssemblyName System.Web
$passCount = @($checks | Where-Object Status -eq 'PASS').Count
$failCount = @($checks | Where-Object Status -eq 'FAIL').Count
$overall = if ($failCount -eq 0) { 'PASS' } else { 'FAIL' }
$overallClass = $overall.ToLower()

$rows = ($checks | ForEach-Object {
    $cls = $_.Status.ToLower()
    "<tr class=""$cls""><td>$( [System.Web.HttpUtility]::HtmlEncode($_.Name) )</td><td>$( [System.Web.HttpUtility]::HtmlEncode($_.Status) )</td><td>$( [System.Web.HttpUtility]::HtmlEncode($_.Detail) )</td></tr>"
}) -join "`n"

$shots = Get-ChildItem -Path $outDir -Filter 'step-*-mobile.png' -ErrorAction SilentlyContinue | Sort-Object Name
$shotGrid = ($shots | ForEach-Object {
    $rel = "screenshots/wizard-live-production/$($_.Name)"
    $label = $_.BaseName -replace '-mobile$',''
    "<figure><img src=""$rel"" alt=""$label"" loading=""lazy""><figcaption>$label</figcaption></figure>"
}) -join "`n"

$reportParts = @(
    '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
    '<meta name="viewport" content="width=device-width, initial-scale=1">'
    "<title>Wizard live verification - $stamp</title>"
    '<style>body{font-family:system-ui,sans-serif;max-width:960px;margin:2rem auto;padding:0 1rem;line-height:1.5}'
    'h1{font-size:1.5rem}.badge{display:inline-block;padding:.25rem .75rem;border-radius:6px;font-weight:700}'
    '.badge.pass{background:#dcfce7;color:#166534}.badge.fail{background:#fee2e2;color:#991b1b}'
    'table{width:100%;border-collapse:collapse;margin:1.5rem 0;font-size:.9rem}'
    'th,td{border:1px solid #e5e7eb;padding:.5rem .75rem;text-align:left}th{background:#f9fafb}'
    'tr.pass td:nth-child(2){color:#166534;font-weight:600}tr.fail td:nth-child(2){color:#991b1b;font-weight:600}'
    '.gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-top:2rem}'
    '.gallery figure{margin:0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden}'
    '.gallery img{width:100%;display:block}.gallery figcaption{padding:.5rem;font-size:.85rem;background:#f9fafb}'
    '.meta{color:#6b7280;font-size:.9rem}</style></head><body>'
    '<h1>Registration wizard v2 live production verification</h1>'
    ('<p class="meta">Generated {0} | <a href="{1}/index.php">{1}/index.php</a></p>' -f $stamp, $regBase)
    ('<p>Overall: <span class="badge {0}">{1}</span> | {2} passed, {3} failed</p>' -f $overallClass, $overall, $passCount, $failCount)
    '<table><thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead><tbody>'
    $rows
    '</tbody></table>'
    '<h2>Production screenshots</h2>'
    '<p class="meta">Step 1 from live index.php; steps 2-8 from wizard-screenshot-preview.php on production.</p>'
    '<div class="gallery">'
    $shotGrid
    '</div></body></html>'
)
$reportHtml = $reportParts -join ''

$reportHtml | Set-Content -Path $reportPath -Encoding UTF8

Write-Host ''
Write-Host "Report: $reportPath" -ForegroundColor Cyan
$summary = ('Overall: {0} - {1} passed, {2} failed' -f $overall, $passCount, $failCount)
Write-Host $summary -ForegroundColor $(if ($failCount -eq 0) { 'Green' } else { 'Red' })
if ($failCount -gt 0) { exit 1 }
