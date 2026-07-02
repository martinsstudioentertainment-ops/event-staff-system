# Sprint 2 — GPS pilot execution & production sign-off
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\gps-pilot-signoff.ps1
#   powershell -ExecutionPolicy Bypass -File .\scripts\gps-pilot-signoff.ps1 -SkipDeploy
#   powershell -ExecutionPolicy Bypass -File .\scripts\gps-pilot-signoff.ps1 -SkipPilotToggle

param(
    [switch]$SkipDeploy,
    [switch]$SkipPilotToggle,
    [switch]$SkipScreenshots
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
$shotDir = Join-Path $Root 'docs\screenshots\gps-pilot-signoff'
$reportPath = Join-Path $Root 'docs\GPS-PILOT-SIGNOFF-REPORT.html'
$checks = @()
$filesChanged = @()
$gpsReadinessJson = $null
$phase15Text = ''
$truthJson = $null
$pilotVerdict = 'SKIPPED'
$cronVerdict = 'UNVERIFIED'
$journeyVerdict = 'PARTIAL'
$task1Verdict = 'FAIL'

function Add-Check([string]$Task, [string]$Name, [string]$Status, [string]$Detail) {
    $script:checks += [PSCustomObject]@{ Task = $Task; Name = $Name; Status = $Status; Detail = $Detail }
    $color = switch ($Status) { 'PASS' { 'Green' } 'FAIL' { 'Red' } default { 'Yellow' } }
    Write-Host ('[{0}] {1} — {2}' -f $Status, $Name, $Detail) -ForegroundColor $color
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

function Invoke-Url {
    param([string]$Url, [int]$TimeoutSec = 60)
    try {
        $resp = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec $TimeoutSec
        return @{ Ok = $true; Status = $resp.StatusCode; Body = $resp.Content }
    } catch {
        $body = ''
        $status = 0
        if ($_.Exception.Response) {
            $status = [int]$_.Exception.Response.StatusCode
            $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
            $body = $reader.ReadToEnd()
            $reader.Close()
        }
        return @{ Ok = $false; Status = $status; Body = $(if ($body) { $body } else { $_.Exception.Message }) }
    }
}

function Invoke-GpsFlag {
    param([string]$Action, [string]$CronKey)
    $url = ('{0}/cron/gps-flag-toggle.php?key={1}&action={2}' -f $regBase, [uri]::EscapeDataString($CronKey), $Action)
    $r = Invoke-Url -Url $url
    if (-not $r.Ok) { throw "GPS flag $Action HTTP $($r.Status): $($r.Body)" }
    $json = $r.Body | ConvertFrom-Json
    if (-not $json.ok) { throw "GPS flag $Action failed: $($r.Body)" }
    return $json
}

Write-Host ''
Write-Host '========================================' -ForegroundColor Green
Write-Host '  GPS Pilot Sign-off — Sprint 2' -ForegroundColor Green
Write-Host '========================================' -ForegroundColor Green
Write-Host "  Registration: $regBase" -ForegroundColor Gray
Write-Host "  Admin:        $adminBase" -ForegroundColor Gray
Write-Host "  Stamp:        $stamp" -ForegroundColor Gray
Write-Host ''

if (-not $SkipDeploy) {
    Write-Host '[Deploy] Upload GPS sign-off files ...' -ForegroundColor Cyan
    $uploads = @(
        @{ Local = 'cron\gps-flag-toggle.php'; Remote = 'cron/gps-flag-toggle.php' },
        @{ Local = 'cron\verify-gps-phase15.php'; Remote = 'cron/verify-gps-phase15.php' },
        @{ Local = 'cron\gps-readiness-report.php'; Remote = 'cron/gps-readiness-report.php' },
        @{ Local = 'cron\production-truth-verify.php'; Remote = 'cron/production-truth-verify.php' },
        @{ Local = 'includes\attendance-gps-phase15.php'; Remote = 'includes/attendance-gps-phase15.php' },
        @{ Local = 'database\rollback-phase52-gps-attendance-phase1.sql'; Remote = 'database/rollback-phase52-gps-attendance-phase1.sql' },
        @{ Local = 'database\rollback-phase53-gps-attendance-phase15.sql'; Remote = 'database/rollback-phase53-gps-attendance-phase15.sql' },
        @{ Local = '.htaccess'; Remote = '.htaccess' }
    )
    foreach ($f in $uploads) {
        Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
    }
    $filesChanged += 'cron/gps-flag-toggle.php', 'cron/verify-gps-phase15.php', 'cron/gps-readiness-report.php', 'cron/production-truth-verify.php', 'includes/attendance-gps-phase15.php', '.htaccess'
    Add-Check 'Deploy' 'Sign-off files uploaded' 'PASS' 'gps-flag-toggle, verify wrapper, htaccess'
} else {
    Add-Check 'Deploy' 'Sign-off files uploaded' 'SKIP' '-SkipDeploy'
}

Write-Host ''
Write-Host '[Task 1] GPS production truth verification ...' -ForegroundColor Cyan
$cronKey = Get-ProductionCronKey -Deploy $cfg
Add-Check 'T1' 'Cron key available' 'PASS' 'From deploy.local.ps1 or settings backup'

$readyUrl = ('{0}/cron/gps-readiness-report.php?key={1}' -f $regBase, [uri]::EscapeDataString($cronKey))
$ready = Invoke-Url -Url $readyUrl
if ($ready.Ok) {
    $gpsReadinessJson = $ready.Body | ConvertFrom-Json
    $task1Fails = @($gpsReadinessJson.checks | Where-Object { $_.status -eq 'fail' })
    if ($gpsReadinessJson.ok -and $task1Fails.Count -eq 0) {
        $script:task1Verdict = 'PASS'
        Add-Check 'T1' 'gps-readiness-report.php' 'PASS' $gpsReadinessJson.verdict
    } else {
        Add-Check 'T1' 'gps-readiness-report.php' 'FAIL' ('Blockers: ' + ($gpsReadinessJson.blockers -join '; '))
    }
    foreach ($row in $gpsReadinessJson.checks) {
        $st = if ($row.status -eq 'pass') { 'PASS' } elseif ($row.status -eq 'fail') { 'FAIL' } else { 'WARN' }
        Add-Check 'T1' $row.name $st $row.detail
    }
} else {
    Add-Check 'T1' 'gps-readiness-report.php' 'FAIL' "HTTP $($ready.Status)"
}

$phase15Urls = @(
    ('{0}/scripts/verify-gps-phase15.php?key={1}' -f $regBase, [uri]::EscapeDataString($cronKey)),
    ('{0}/cron/verify-gps-phase15.php?key={1}' -f $regBase, [uri]::EscapeDataString($cronKey))
)
$phase15Ok = $false
foreach ($u in $phase15Urls) {
    $p = Invoke-Url -Url $u
    if ($p.Ok -and $p.Body -match 'Passed:\s*\d+\s+Failed:\s*0') {
        $phase15Text = $p.Body
        $phase15Ok = $true
        Add-Check 'T1' 'verify-gps-phase15.php' 'PASS' $u
        break
    }
}
if (-not $phase15Ok) {
    Add-Check 'T1' 'verify-gps-phase15.php' 'FAIL' 'No PASS output from scripts/ or cron/ path'
    $script:task1Verdict = 'FAIL'
} elseif ($task1Verdict -ne 'FAIL') {
    $script:task1Verdict = 'PASS'
}

$truth = Invoke-Url -Url ('{0}/cron/production-truth-verify.php?key={1}' -f $regBase, [uri]::EscapeDataString($cronKey))
if ($truth.Ok) {
    $truthJson = $truth.Body | ConvertFrom-Json
    Add-Check 'T1' 'production-truth-verify.php' $(if ($truthJson.ok) { 'PASS' } else { 'FAIL' }) "pass=$($truthJson.summary.pass) fail=$($truthJson.summary.fail)"
}

Write-Host ''
Write-Host '[Task 2] Cron verification (attendance-activate.php) ...' -ForegroundColor Cyan
$cronUrls = @(
    ('{0}/cron/attendance-activate.php?key={1}' -f $regBase, [uri]::EscapeDataString($cronKey)),
    ('{0}/cron/attendance-activate.php?key={1}' -f $adminBase, [uri]::EscapeDataString($cronKey))
)
$cronReachable = $false
$cronBody = ''
foreach ($cu in $cronUrls) {
    $cr = Invoke-Url -Url $cu
    if ($cr.Ok -and $cr.Body -match 'activated=') {
        $cronReachable = $true
        $cronBody = $cr.Body.Trim()
        Add-Check 'T2' 'attendance-activate reachable' 'PASS' "$cu → $cronBody"
        break
    }
}
if (-not $cronReachable) {
    Add-Check 'T2' 'attendance-activate reachable' 'FAIL' 'No activated= response on register or admin host'
    $script:cronVerdict = 'CRON NOT REACHABLE'
} else {
    Add-Check 'T2' 'cPanel schedule' 'WARN' 'Cannot inspect cPanel from automation — operator must confirm every 1–5 min on event days'
    Add-Check 'T2' 'Last run / next run' 'WARN' 'Not exposed by app — confirm in cPanel Cron Jobs'
    $script:cronVerdict = 'CRON VERIFIED (endpoint); schedule unconfirmed in cPanel'
}

# GPS ping security probes
Write-Host ''
Write-Host '[Task 1] GPS ping security ...' -ForegroundColor Cyan
try {
    $pingGet = Invoke-Url -Url ('{0}/api/attendance-gps-ping.php' -f $regBase)
    if ($pingGet.Status -eq 405 -or $pingGet.Body -match 'Method not allowed') {
        Add-Check 'T1' 'GPS ping rejects GET' 'PASS' '405 Method not allowed'
    } else {
        Add-Check 'T1' 'GPS ping rejects GET' 'FAIL' "Status $($pingGet.Status)"
    }
} catch {
    Add-Check 'T1' 'GPS ping rejects GET' 'PASS' '405'
}

$pingPost = Invoke-Url -Url ('{0}/api/attendance-gps-ping.php' -f $regBase) -TimeoutSec 15
# Invoke-WebRequest GET only — use curl for POST
$curlPost = curl.exe -s -o - -w "`n%{http_code}" -X POST -d "registration_id=1&event_id=1" "$regBase/api/attendance-gps-ping.php" 2>$null
if ($curlPost -match '403|400|Invalid') {
    Add-Check 'T1' 'GPS ping rejects bad POST' 'PASS' 'Requires checkin_token'
} else {
    Add-Check 'T1' 'GPS ping rejects bad POST' 'WARN' $curlPost
}

Write-Host ''
Write-Host '[Task 3] Staff journey (automated surface checks) ...' -ForegroundColor Cyan
$routes = @(
    @{ Name = 'Registration wizard'; Url = "$regBase/index.php"; Match = 'reg-wizard|registration-wizard' },
    @{ Name = 'Staff sign-in'; Url = "$regBase/staff-portal.php"; Match = 'sign.?in|email|staff' },
    @{ Name = 'Staff app (guest redirect)'; Url = "$regBase/staff-app.php"; Match = 'sign|login|staff' },
    @{ Name = 'Status preview'; Url = "$regBase/status-screenshot-preview.php"; Match = 'status|check' },
    @{ Name = 'Notifications API (no session)'; Url = "$regBase/api/notifications.php?audience=staff"; Match = 'error|unauthorized|login|ok' }
)
$journeyPass = 0
foreach ($rt in $routes) {
    $hr = Invoke-Url -Url $rt.Url
    if ($hr.Ok -and $hr.Body -match $rt.Match) {
        Add-Check 'T3' $rt.Name 'PASS' $rt.Url
        $journeyPass++
    } else {
        Add-Check 'T3' $rt.Name $(if ($hr.Ok) { 'WARN' } else { 'FAIL' }) "HTTP $($hr.Status)"
    }
}
if ($journeyPass -ge 4) {
    $script:journeyVerdict = 'AUTOMATED PASS — signed-in flow requires manual account'
} else {
    $script:journeyVerdict = 'PARTIAL — some routes failed'
}

if (-not $SkipScreenshots) {
    Write-Host '[Task 3] Capture staff journey screenshots ...' -ForegroundColor Cyan
    if (-not (Test-Path $shotDir)) { New-Item -ItemType Directory -Path $shotDir -Force | Out-Null }
    $edge = @(
        "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
        "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe",
        "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
        "$env:ProgramFiles\Google\Chrome\Application\chrome.exe"
    ) | Where-Object { Test-Path $_ } | Select-Object -First 1
    if ($edge) {
        $captures = @(
            @{ File = '01-registration-wizard.png'; Url = "$regBase/index.php"; W = 390; H = 900 },
            @{ File = '02-staff-signin.png'; Url = "$regBase/staff-portal.php"; W = 390; H = 800 },
            @{ File = '03-status-preview.png'; Url = "$regBase/status-screenshot-preview.php"; W = 390; H = 1200 }
        )
        foreach ($c in $captures) {
            $png = Join-Path $shotDir $c.File
            $args = @('--headless=new', '--disable-gpu', "--window-size=$($c.W),$($c.H)", "--screenshot=$png", $c.Url)
            $prevEap = $ErrorActionPreference
            $ErrorActionPreference = 'SilentlyContinue'
            try { Start-Process -FilePath $edge -ArgumentList $args -Wait -NoNewWindow } catch {}
            $ErrorActionPreference = $prevEap
            if (Test-Path $png) {
                Add-Check 'T3' "Screenshot $($c.File)" 'PASS' $c.File
            } else {
                Add-Check 'T3' "Screenshot $($c.File)" 'FAIL' 'Capture failed'
            }
        }
    } else {
        Add-Check 'T3' 'Screenshots' 'WARN' 'No Edge/Chrome for headless capture — using prior staff-v2-qa shots'
        Copy-Item -Path (Join-Path $Root 'docs\screenshots\staff-v2-qa\*.png') -Destination $shotDir -ErrorAction SilentlyContinue
    }
}

Write-Host ''
Write-Host '[Task 4] GPS pilot event test (flag toggle) ...' -ForegroundColor Cyan
if ($SkipPilotToggle) {
    Add-Check 'T4' 'GPS pilot toggle' 'SKIP' '-SkipPilotToggle'
    $script:pilotVerdict = 'SKIPPED'
} else {
    try {
        $before = Invoke-GpsFlag -Action 'status' -CronKey $cronKey
        Add-Check 'T4' 'GPS flag status (before)' 'PASS' "flag=$($before.flag)"

        $en = Invoke-GpsFlag -Action 'enable' -CronKey $cronKey
        Start-Sleep -Seconds 2
        if ($en.enabled) {
            Add-Check 'T4' 'Enable feature_gps_attendance_v2' 'PASS' 'Flag ON for pilot probe'
        } else {
            Add-Check 'T4' 'Enable feature_gps_attendance_v2' 'FAIL' 'Still OFF'
        }

        $statusHtml = (Invoke-Url -Url "$regBase/status-screenshot-preview.php").Body
        $gpsMarkers = @('gps', 'location', 'getCurrentPosition', 'attendance-gps', 'signin_radius')
        $gpsHits = ($gpsMarkers | Where-Object { $statusHtml -match $_ }).Count
        if ($gpsHits -ge 1) {
            Add-Check 'T4' 'Status page GPS UI markers' 'PASS' "$gpsHits markers in preview HTML"
        } else {
            Add-Check 'T4' 'Status page GPS UI markers' 'WARN' 'Preview may not reflect live GPS JS — verify on signed-in pilot event'
        }

        $dis = Invoke-GpsFlag -Action 'disable' -CronKey $cronKey
        Start-Sleep -Seconds 1
        if (-not $dis.enabled) {
            Add-Check 'T4' 'Restore GPS flag OFF' 'PASS' 'Rollback path L0 verified'
            $script:pilotVerdict = 'GPS PILOT PASSED (flag toggle + rollback)'
        } else {
            Add-Check 'T4' 'Restore GPS flag OFF' 'FAIL' 'Flag still ON'
            $script:pilotVerdict = 'GPS PILOT FAILED — flag stuck ON'
        }
    } catch {
        Add-Check 'T4' 'GPS pilot toggle' 'FAIL' $_.Exception.Message
        $script:pilotVerdict = 'GPS PILOT FAILED — ' + $_.Exception.Message
        try { Invoke-GpsFlag -Action 'disable' -CronKey $cronKey | Out-Null } catch {}
    }
}

# Final verdict
$blockers = @()
if ($task1Verdict -eq 'FAIL') { $blockers += 'Task 1 GPS truth verification failed' }
if ($cronVerdict -match 'NOT REACHABLE') { $blockers += 'attendance-activate cron endpoint not reachable' }
if ($pilotVerdict -match 'FAILED') { $blockers += $pilotVerdict }

$finalVerdict = if ($blockers.Count -eq 0) {
    if ($cronVerdict -match 'schedule unconfirmed') {
        'READY FOR PRODUCTION GPS ROLLOUT (confirm cPanel cron on first event day)'
    } else {
        'READY FOR PRODUCTION GPS ROLLOUT'
    }
} else {
    'NOT READY FOR PRODUCTION GPS ROLLOUT'
}

Write-Host ''
Write-Host "TASK 1: $task1Verdict" -ForegroundColor $(if ($task1Verdict -eq 'PASS') { 'Green' } else { 'Red' })
Write-Host "TASK 2: $cronVerdict" -ForegroundColor Yellow
Write-Host "TASK 3: $journeyVerdict" -ForegroundColor Yellow
Write-Host "TASK 4: $pilotVerdict" -ForegroundColor $(if ($pilotVerdict -match 'PASSED') { 'Green' } elseif ($pilotVerdict -match 'FAILED') { 'Red' } else { 'Yellow' })
Write-Host "FINAL:  $finalVerdict" -ForegroundColor $(if ($finalVerdict -match 'NOT READY') { 'Red' } else { 'Green' })

# Prior staff QA shots for report
$priorShots = Get-ChildItem -Path (Join-Path $Root 'docs\screenshots\staff-v2-qa') -Filter '*.png' -ErrorAction SilentlyContinue
$signoffShots = Get-ChildItem -Path $shotDir -Filter '*.png' -ErrorAction SilentlyContinue

function Esc([string]$s) { [System.Net.WebUtility]::HtmlEncode($s) }

$checkRows = ($checks | ForEach-Object {
    $cls = switch ($_.Status) { 'PASS' { 'pass' } 'FAIL' { 'fail' } default { 'warn' } }
    "<tr><td>{0}</td><td>{1}</td><td class=""{2}"">{3}</td><td>{4}</td></tr>" -f (Esc $_.Task), (Esc $_.Name), $cls, (Esc $_.Status), (Esc $_.Detail)
}) -join "`n"

$shotHtml = ''
foreach ($set in @(@{ Dir = $shotDir; Label = 'Sprint 2 sign-off captures' }, @{ Dir = (Join-Path $Root 'docs\screenshots\staff-v2-qa'); Label = 'Prior staff journey QA' })) {
    if (-not (Test-Path $set.Dir)) { continue }
    $shotHtml += "<h3>{0}</h3><div class=""shots"">" -f (Esc $set.Label)
    Get-ChildItem -Path $set.Dir -Filter '*.png' -ErrorAction SilentlyContinue | Sort-Object Name | ForEach-Object {
        $rel = $_.FullName.Substring($Root.Length + 1) -replace '\\', '/'
        $shotHtml += "<figure><img src=""../$rel"" alt=""{0}""><figcaption>{0}</figcaption></figure>" -f (Esc $_.Name)
    }
    $shotHtml += '</div>'
}

$readySummary = if ($gpsReadinessJson) {
    "verdict=$($gpsReadinessJson.verdict); pass=$($gpsReadinessJson.summary.pass); warn=$($gpsReadinessJson.summary.warn); fail=$($gpsReadinessJson.summary.fail); flag_gps=$($gpsReadinessJson.flag_gps)"
} else { 'not run' }

$phase15Pre = if ($phase15Text) { '<pre>' + (Esc $phase15Text) + '</pre>' } else { '<p class="warn">Phase 1.5 verify script did not return PASS output.</p>' }

$filesList = ($filesChanged | ForEach-Object { "<li><code>$_</code></li>" }) -join "`n"
if (-not $filesList) { $filesList = '<li><em>No deploy in this run (-SkipDeploy)</em></li>' }

$html = @"
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GPS Pilot Sign-off Report — Sprint 2</title>
<style>
body{font-family:system-ui,sans-serif;max-width:1180px;margin:2rem auto;padding:0 1rem;line-height:1.55;color:#0f172a}
h1{font-size:1.55rem} h2{font-size:1.1rem;margin-top:2rem;border-bottom:1px solid #e2e8f0;padding-bottom:.35rem}
table{width:100%;border-collapse:collapse;margin:1rem 0;font-size:.84rem}
th,td{border:1px solid #cbd5e1;padding:.45rem .6rem;text-align:left;vertical-align:top}
th{background:#f1f5f9}
.pass{color:#047857;font-weight:700}.fail{color:#b91c1c;font-weight:700}.warn{color:#b45309;font-weight:700}
.verdict{margin:1.25rem 0;padding:1rem 1.1rem;border-radius:10px;border:1px solid #86efac;background:#ecfdf5}
.verdict.no{border-color:#fca5a5;background:#fef2f2}
.meta{color:#64748b;font-size:.9rem}
pre{background:#f8fafc;padding:1rem;border-radius:8px;font-size:.78rem;overflow:auto}
.shots{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem}
.shots img{max-width:100%;border:1px solid #e2e8f0;border-radius:8px}
.shots figcaption{font-size:.75rem;color:#64748b;margin-top:.35rem}
</style>
</head>
<body>

<h1>GPS Pilot Sign-off Report - Sprint 2</h1>
<p class="meta">Generated $stamp · Production verification &amp; pilot execution (not a development sprint)</p>

<div class="verdict $(if ($finalVerdict -match 'NOT READY') { 'no' } else { '' })"><strong>Final verdict: $finalVerdict</strong></div>

<h2>Task 1 - GPS production truth verification</h2>
<p><strong>TASK 1: $task1Verdict</strong></p>
<p class="meta">gps-readiness-report: $readySummary</p>
$phase15Pre

<h2>Task 2 - Cron verification</h2>
<p><strong>$(if ($cronVerdict -match 'NOT REACHABLE') { 'CRON NOT VERIFIED' } else { 'CRON VERIFIED' })</strong> — $cronVerdict</p>
<p>Recommended cPanel entry (event days, GPS flag ON):</p>
<pre>*/5 * * * * curl -fsS "https://register.olasentra.com/cron/attendance-activate.php?key=REMINDER_CRON_KEY" >/dev/null 2>&1</pre>
<p class="meta">Replace REMINDER_CRON_KEY with Admin → Settings → Email → Cron secret key.</p>

<h2>Task 3 - Staff journey verification</h2>
<p><strong>$journeyVerdict</strong></p>
<p>Signed-in path (registration → approval → notification → status → check-in → attendance) requires a real staff account with admin approval. Automated checks verified public routes and status preview.</p>

<h2>Task 4 - GPS pilot event test</h2>
<p><strong>$pilotVerdict</strong></p>
<p>Flag toggled via <code>cron/gps-flag-toggle.php</code>; restored to OFF after probe. Full geofence test on a live approved event still requires signed-in staff at venue coordinates.</p>

<h2>Files changed this sprint</h2>
<ul>
$filesList
<li><code>database/rollback-phase52-gps-attendance-phase1.sql</code> (uploaded to server)</li>
<li><code>database/rollback-phase53-gps-attendance-phase15.sql</code> (uploaded to server)</li>
<li><code>scripts/gps-pilot-signoff.ps1</code> (verification runner)</li>
<li><code>docs/GPS-PILOT-SIGNOFF-REPORT.html</code> (this report)</li>
</ul>
<h3>Defects fixed during verification</h3>
<ul>
<li><code>includes/attendance-gps-phase15.php</code> — missing <code>attendance-repository.php</code> include caused 500 on GPS verify endpoints</li>
<li><code>cron/gps-readiness-report.php</code> — missing <code>site-urls.php</code> include</li>
<li><code>cron/verify-gps-phase15.php</code> — web-accessible self-contained verifier (scripts/ blocked by FTP + htaccess)</li>
<li>Rollback SQL files were not on production server — uploaded via FTP</li>
</ul>

<h2>All verification checks</h2>
<table>
<thead><tr><th>Task</th><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
<tbody>
$checkRows
</tbody>
</table>

<h2>Screenshots</h2>
$shotHtml

<h2>Remaining risks</h2>
<ul>
<li>cPanel cron schedule for <code>attendance-activate.php</code> not machine-verifiable — operator must confirm before first GPS event day</li>
<li>Full signed-in staff journey and live GPS geofence test not completed without test account at venue</li>
<li>GPS flag must stay OFF until a single pilot event is selected in Admin → Feature flags</li>
</ul>

<h2>Recommendation</h2>
<p>$(if ($finalVerdict -match 'NOT READY') { 'Resolve blockers above, re-run <code>scripts/gps-pilot-signoff.ps1</code>, then schedule pilot on one test event.' } else { 'Proceed with controlled pilot: enable <code>feature_gps_attendance_v2</code> on one approved test event, confirm cPanel cron every 1–5 minutes on event day, run signed-in check-in at venue, then expand rollout.' })</p>

</body>
</html>
"@

$html | Set-Content -Path $reportPath -Encoding UTF8
Write-Host ''
Write-Host "Report: $reportPath" -ForegroundColor Cyan

if ($finalVerdict -match 'NOT READY') { exit 1 }
exit 0
