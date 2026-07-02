$ErrorActionPreference = 'Continue'
$base = 'https://register.olasentra.com'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$outJson = Join-Path $PSScriptRoot "..\docs\post-go-live-monitoring-$stamp.json"
$failures = @()
$checks = @()

function Add-Check {
    param([string]$Area, [string]$Name, [bool]$Ok, [string]$Detail)
    $script:checks += [ordered]@{
        area   = $Area
        name   = $Name
        result = $(if ($Ok) { 'PASS' } else { 'FAIL' })
        detail = $Detail
    }
    if (-not $Ok) {
        $script:failures += [ordered]@{
            area   = $Area
            name   = $Name
            detail = $Detail
        }
    }
}

function Get-Body {
    param([string]$Url)
    $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 30 -MaximumRedirection 5
    return @{ Status = $r.StatusCode; Body = [string]$r.Content; Len = $r.Content.Length }
}

Write-Host "Post-go-live monitoring - $base" -ForegroundColor Cyan

# Login
try {
    $r = Get-Body "$base/staff-app.php"
    Add-Check 'Login' 'Guest login page' ($r.Status -eq 200 -and $r.Body -like '*Sign in with Google*' -and $r.Body -like '*staff-portal-email-otp*') "HTTP $($r.Status), $($r.Len) bytes"
} catch { Add-Check 'Login' 'Guest login page' $false $_.Exception.Message }

# Google OAuth redirect
try {
    $r = Invoke-WebRequest -Uri "$base/staff-google-signin.php?return=staff-app.php" -UseBasicParsing -TimeoutSec 30 -MaximumRedirection 0 -ErrorAction Stop
    Add-Check 'Google Login' 'OAuth redirect' $false "Unexpected HTTP $($r.StatusCode)"
} catch {
    $code = 0
    $loc = ''
    if ($_.Exception.Response) {
        $code = [int]$_.Exception.Response.StatusCode
        $loc = $_.Exception.Response.Headers['Location']
    }
    $ok = ($code -eq 302 -and $loc -like '*accounts.google.com*')
    Add-Check 'Google Login' 'OAuth redirect' $ok "HTTP $code -> $loc"
}

# OTP endpoints
foreach ($ep in @('api/staff-portal-otp-send.php', 'api/staff-portal-otp-verify.php')) {
    try {
        $r = Invoke-WebRequest -Uri "$base/$ep" -Method GET -UseBasicParsing -TimeoutSec 20 -ErrorAction Stop
        Add-Check 'Email OTP' $ep ($r.StatusCode -lt 500) "HTTP $($r.StatusCode)"
    } catch {
        $code = if ($_.Exception.Response) { [int]$_.Exception.Response.StatusCode } else { 0 }
        $ok = ($code -ge 400 -and $code -lt 500)
        Add-Check 'Email OTP' $ep $ok "HTTP $code (client error expected on GET)"
    }
}

# Check-in auth gate
try {
    $r = Invoke-WebRequest -Uri "$base/staff-checkin.php" -UseBasicParsing -TimeoutSec 30 -MaximumRedirection 0 -ErrorAction Stop
    Add-Check 'Check-in' 'Guest auth gate' ($r.StatusCode -eq 302) "HTTP $($r.StatusCode)"
} catch {
    $code = if ($_.Exception.Response) { [int]$_.Exception.Response.StatusCode } else { 0 }
    Add-Check 'Check-in' 'Guest auth gate' ($code -eq 302) "HTTP $code"
}

# Application Status
try {
    $r = Get-Body "$base/status.php"
    Add-Check 'Application Status' 'Status page reachable' ($r.Status -eq 200 -and $r.Body -like '*Application status*' -and $r.Body -like '*staff-app-v3.css*') "HTTP $($r.Status), v3 markers present"
} catch { Add-Check 'Application Status' 'Status page reachable' $false $_.Exception.Message }

# Profile edit
try {
    $r = Get-Body "$base/staff-profile.php"
    $ok = ($r.Status -eq 200 -and $r.Body -like '*staff-app-v3.css*' -and $r.Body -notmatch 'Fatal error|Parse error')
    Add-Check 'Profile Edit' 'Profile page reachable' $ok "HTTP $($r.Status), guest may see login gate"
} catch { Add-Check 'Profile Edit' 'Profile page reachable' $false $_.Exception.Message }

# PWA install
try {
    $r = Get-Body "$base/staff-app.php"
    Add-Check 'PWA Install' 'Single install banner' ($r.Body -like '*es-v3-pwa-banner*' -and $r.Body -notlike '*pwa-install.css*') 'es-v3-pwa-banner in shell'
} catch { Add-Check 'PWA Install' 'Single install banner' $false $_.Exception.Message }

try {
    $r = Get-Body "$base/assets/js/pwa-install.js"
    Add-Check 'PWA Install' 'Legacy skip on v3' ($r.Body -like "*data-staff-app-v3') === '1'*") 'Early return present'
} catch { Add-Check 'PWA Install' 'Legacy skip on v3' $false $_.Exception.Message }

try {
    $r = Get-Body "$base/manifest.php"
    $json = $r.Body | ConvertFrom-Json
    $ok = ($json.theme_color -eq '#F58220' -and $json.background_color -eq '#0B1020')
    Add-Check 'PWA Install' 'Manifest brand colours' $ok "theme=$($json.theme_color) bg=$($json.background_color)"
} catch { Add-Check 'PWA Install' 'Manifest brand colours' $false $_.Exception.Message }

# Offline / SW
try {
    $r = Get-Body "$base/offline.php"
    Add-Check 'PWA Install' 'Offline page' ($r.Body -like '*staff-app-v3.css*' -and $r.Body -like '*es-ds__empty*') "HTTP $($r.Status)"
} catch { Add-Check 'PWA Install' 'Offline page' $false $_.Exception.Message }

try {
    $r = Get-Body "$base/sw.js"
    Add-Check 'PWA Install' 'Service worker' ($r.Body -like '*event-staff-v10*' -or $r.Body -like '*offline*') "HTTP $($r.Status)"
} catch { Add-Check 'PWA Install' 'Service worker' $false $_.Exception.Message }

# Mobile API config
try {
    $r = Get-Body "$base/api/mobile/v1/config"
    $cfg = $r.Body | ConvertFrom-Json
    $ok = ($r.Status -eq 200 -and $cfg.google_signin_enabled -eq $true)
    Add-Check 'Login' 'Mobile API config' $ok "google_signin=$($cfg.google_signin_enabled) email_otp=$($cfg.email_otp_enabled)"
} catch { Add-Check 'Login' 'Mobile API config' $false $_.Exception.Message }

$staffFeedback = $false
$feedbackNote = 'No completed TESTER-FEEDBACK-FORM or admin inbox exports found in repository for this monitoring window.'

$verdict = if ($failures.Count -eq 0) { 'NO ISSUES REPORTED' } else { 'FIXES RECOMMENDED' }

$report = [ordered]@{
    phase                    = 'POST-GO-LIVE-MONITORING'
    probed_at                = (Get-Date).ToString('o')
    target                   = $base
    last_deploy              = 'Phase 10 usability (2026-06-21)'
    staff_feedback_received  = $staffFeedback
    staff_feedback_note      = $feedbackNote
    operational_failures     = $failures
    checks                   = $checks
    verdict                  = $verdict
    new_development_phase    = $(if ($failures.Count -eq 0) { 'None required' } else { 'Only if operational failures confirmed on device' })
}

$report | ConvertTo-Json -Depth 6 | Set-Content $outJson -Encoding UTF8

Write-Host ''
foreach ($c in $checks) {
    $col = if ($c.result -eq 'PASS') { 'Green' } else { 'Red' }
    Write-Host "$($c.result)  [$($c.area)] $($c.name) - $($c.detail)" -ForegroundColor $col
}
Write-Host ''
$verdictColor = if ($verdict -eq 'NO ISSUES REPORTED') { 'Green' } else { 'Yellow' }
Write-Host "Verdict: $verdict" -ForegroundColor $verdictColor
Write-Host "Artifact: $outJson" -ForegroundColor Gray
exit $(if ($failures.Count -eq 0) { 0 } else { 1 })
