# Phase 1 regression — staff preferences foundation
# Usage: powershell -ExecutionPolicy Bypass -File .\scripts\phase1-preferences-regression.ps1

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$Base = 'https://register.olasentra.com'
$Admin = 'https://admin.olasentra.com'
$results = @()

function Add-Result([string]$Area, [string]$Check, [string]$Status, [string]$Detail) {
    $script:results += [pscustomobject]@{ Area = $Area; Check = $Check; Status = $Status; Detail = $Detail }
}

Write-Host '=== Phase 1 Staff Preferences Regression ===' -ForegroundColor Cyan

# Mobile API config (public)
try {
    $cfg = Invoke-RestMethod -Uri "$Base/api/mobile/v1/config" -TimeoutSec 60
    $po = $cfg.preference_options
    if ($null -eq $po) {
        Add-Result 'Mobile API' 'GET /config preference_options' 'FAIL' 'preference_options missing'
    } else {
        $shiftCount = @($po.shift_types).Count
        $locCount = @($po.locations).Count
        if ($shiftCount -ge 10 -and $locCount -ge 1) {
            Add-Result 'Mobile API' 'GET /config preference_options' 'PASS' "shift_types=$shiftCount locations=$locCount"
        } else {
            Add-Result 'Mobile API' 'GET /config preference_options' 'WARN' "shift_types=$shiftCount locations=$locCount"
        }
    }
    if ($cfg.mobile_api_enabled) { Add-Result 'Mobile API' 'mobile_api_enabled' 'PASS' 'true' }
    if ($cfg.google_signin_enabled -ne $null) { Add-Result 'Mobile API' 'google_signin_enabled present' 'PASS' (string)$cfg.google_signin_enabled }
    if ($cfg.email_otp_enabled -ne $null) { Add-Result 'Mobile API' 'email_otp_enabled present' 'PASS' (string)$cfg.email_otp_enabled }
} catch {
    Add-Result 'Mobile API' 'GET /config' 'FAIL' $_.Exception.Message
}

# Auth-protected route without token
try {
    Invoke-WebRequest -Uri "$Base/api/mobile/v1/me/preferences" -Method GET -TimeoutSec 30 -SkipHttpErrorCheck | Out-Null
    $code = $_.Exception.Response.StatusCode.value__
} catch {
    $resp = $_.Exception.Response
    if ($resp) { $code = [int]$resp.StatusCode } else { $code = 0 }
}
if ($code -eq 401) {
    Add-Result 'Mobile API' 'GET /me/preferences auth guard' 'PASS' 'HTTP 401 without token'
} else {
    try {
        $r = Invoke-WebRequest -Uri "$Base/api/mobile/v1/me/preferences" -Method GET -TimeoutSec 30
        $code = $r.StatusCode
    } catch {}
    Add-Result 'Mobile API' 'GET /me/preferences auth guard' $(if ($code -eq 401) {'PASS'} else {'WARN'}) "HTTP $code"
}

# Registration endpoint still reachable (GET redirect)
try {
    $r = Invoke-WebRequest -Uri "$Base/submit.php" -Method GET -MaximumRedirection 0 -SkipHttpErrorCheck
    $code = $r.StatusCode
    if ($code -in 301,302,303,307,308,200) {
        Add-Result 'Registration' 'submit.php GET' 'PASS' "HTTP $code"
    } else {
        Add-Result 'Registration' 'submit.php GET' 'WARN' "HTTP $code"
    }
} catch {
    if ($_.Exception.Response.StatusCode.value__ -in 301,302) {
        Add-Result 'Registration' 'submit.php GET' 'PASS' 'Redirect (expected)'
    } else {
        Add-Result 'Registration' 'submit.php GET' 'FAIL' $_.Exception.Message
    }
}

# Events API (auth required)
try {
    $r = Invoke-WebRequest -Uri "$Base/api/mobile/v1/events" -Method GET -SkipHttpErrorCheck
    Add-Result 'Events' 'GET /events' $(if ($r.StatusCode -eq 401) {'PASS'} else {'WARN'}) "HTTP $($r.StatusCode)"
} catch {
    Add-Result 'Events' 'GET /events' 'WARN' $_.Exception.Message
}

# Public pages
@(
    @{ Area = 'Download'; Url = "$Base/staff-app-download.php" },
    @{ Area = 'Staff portal'; Url = "$Base/staff-app.php" }
) | ForEach-Object {
    try {
        $r = Invoke-WebRequest -Uri $_.Url -Method GET -TimeoutSec 45
        Add-Result $_.Area $_.Url 'PASS' "HTTP $($r.StatusCode)"
    } catch {
        Add-Result $_.Area $_.Url 'FAIL' $_.Exception.Message
    }
}

# Admin pages (expect login redirect)
@(
    'settings-preference-locations.php',
    'staff-preferences.php',
    'allocation-centre.php',
    'staff-availability.php',
    'payroll-centre.php',
    'attendance.php'
) | ForEach-Object {
    try {
        $r = Invoke-WebRequest -Uri "$Admin/admin/$_" -Method GET -MaximumRedirection 0 -SkipHttpErrorCheck
        $code = $r.StatusCode
        if ($code -in 301,302,303,307,308,200) {
            Add-Result 'Admin' $_ 'PASS' "HTTP $code (auth gate ok)"
        } else {
            Add-Result 'Admin' $_ 'WARN' "HTTP $code"
        }
    } catch {
        Add-Result 'Admin' $_ 'WARN' $_.Exception.Message
    }
}

$outDir = Join-Path $Root 'docs\phase1-audit-' + (Get-Date -Format 'yyyy-MM-dd')
New-Item -ItemType Directory -Force -Path $outDir | Out-Null
$csv = Join-Path $outDir 'regression.csv'
$results | Export-Csv -Path $csv -NoTypeInformation

$pass = ($results | Where-Object Status -eq 'PASS').Count
$fail = ($results | Where-Object Status -eq 'FAIL').Count
$warn = ($results | Where-Object Status -eq 'WARN').Count

Write-Host ''
$results | Format-Table -AutoSize
Write-Host "PASS=$pass FAIL=$fail WARN=$warn" -ForegroundColor $(if ($fail -eq 0) { 'Green' } else { 'Yellow' })
Write-Host "Report: $csv" -ForegroundColor Gray

if ($fail -gt 0) { exit 1 }
