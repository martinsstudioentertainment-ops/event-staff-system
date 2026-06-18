# Phase 6 — Full system verification harness (HTTP/API/file probes)
# Screenshots and authenticated UI require manual device/browser sessions.

param(
    [string]$OutDir = 'docs/phase6-audit-2026-06-18'
)

$ErrorActionPreference = 'Continue'
$root = Split-Path $PSScriptRoot -Parent
New-Item -ItemType Directory -Force -Path (Join-Path $root $OutDir) | Out-Null
$outRoot = Join-Path $root $OutDir

$AdminBase = 'https://admin.olasentra.com/admin'
$StaffBase = 'https://register.olasentra.com'
$ApplyBase = 'https://apply.olasentra.com'
$PublicBase = 'https://olasentra.com'
$ApiBase = 'https://register.olasentra.com/api/mobile/v1'

function Test-Http {
    param([string]$Name, [string]$Url, [string]$Portal = 'public')
    $r = [ordered]@{ name=$Name; url=$Url; portal=$Portal; status='FAIL'; http=0; len=0; notes=''; screenshot='BLOCKED' }
    try {
        $resp = Invoke-WebRequest -Uri $Url -UseBasicParsing -MaximumRedirection 5 -TimeoutSec 25
        $body = [string]$resp.Content
        $r.http = [int]$resp.StatusCode
        $r.len = $body.Length
        $phpErr = $body -match 'Fatal error|Parse error|Uncaught Error'
        $blank = $body.Trim().Length -lt 80
        if ($phpErr) { $r.status='FAIL'; $r.notes='PHP error in body' }
        elseif ($blank) { $r.status='FAIL'; $r.notes='Blank response' }
        elseif ($r.http -ge 200 -and $r.http -lt 400) { $r.status='PASS'; $r.notes='Reachable' }
        else { $r.status='WARNING'; $r.notes="HTTP $($r.http)" }
    } catch {
        $r.notes = $_.Exception.Message
        if ($_.Exception.Response) { $r.http = [int]$_.Exception.Response.StatusCode; if ($r.http -eq 302) { $r.status='WARNING'; $r.notes='Redirect (auth gate likely)' } }
    }
    [pscustomobject]$r
}

function Test-Api {
    param([string]$Method, [string]$Path, [string]$Body = '{}', [int[]]$PassCodes = @(401,403,422))
    $url = "$ApiBase/$Path"
    $r = [ordered]@{ method=$Method; path=$Path; code=0; status='FAIL'; body=''; notes='' }
    try {
        if ($Method -eq 'GET') {
            $resp = Invoke-WebRequest -Uri $url -Method GET -UseBasicParsing -TimeoutSec 25
        } else {
            $resp = Invoke-WebRequest -Uri $url -Method POST -UseBasicParsing -Body $Body -ContentType 'application/json' -TimeoutSec 25
        }
        $r.code = [int]$resp.StatusCode
        $r.body = $resp.Content.Substring(0, [Math]::Min(200, $resp.Content.Length))
    } catch {
        if ($_.Exception.Response) {
            $r.code = [int]$_.Exception.Response.StatusCode
            $sr = New-Object IO.StreamReader($_.Exception.Response.GetResponseStream())
            $r.body = $sr.ReadToEnd(); $sr.Close()
            $r.body = $r.body.Substring(0, [Math]::Min(200, $r.body.Length))
        } else { $r.notes = $_.Exception.Message }
    }
    if ($r.code -eq 404) { $r.status='FAIL'; $r.notes='Route missing' }
    elseif ($PassCodes -contains $r.code) { $r.status='PASS'; $r.notes='Route exists' }
    elseif ($r.code -eq 200) { $r.status='PASS'; $r.notes='OK' }
    else { $r.status='WARNING'; $r.notes="HTTP $($r.code)" }
    [pscustomobject]$r
}

# Run phase2 for admin/staff/apply/api baseline
& (Join-Path $root 'scripts\phase2-authenticated-audit.ps1') -OutDir $OutDir | Out-Null

# Public site pages
$publicPages = @(
    @{ n='Home'; u="$PublicBase/" },
    @{ n='About'; u="$PublicBase/about.php" },
    @{ n='Contact'; u="$PublicBase/contact.php" },
    @{ n='Services'; u="$PublicBase/services.php" },
    @{ n='Register home'; u="$StaffBase/" },
    @{ n='Staff app'; u="$StaffBase/staff-app.php" },
    @{ n='Privacy'; u="$StaffBase/privacy.php" },
    @{ n='Terms'; u="$StaffBase/terms.php" },
    @{ n='Apply home'; u="$ApplyBase/" },
    @{ n='Apply admin login'; u="$ApplyBase/admin/login.php" }
)
$publicResults = foreach ($p in $publicPages) { Test-Http $p.n $p.u 'public' }
$publicResults | Export-Csv (Join-Path $outRoot 'public-pages.csv') -NoTypeInformation

# Auth API deep probes
$device = 'phase6test0001dev'
$authResults = @(
    Test-Api GET 'config' '{}' @(200)
    Test-Api POST 'auth/otp/send' (@{email='unknown@olasentra.com';device_id=$device;purpose='login'}|ConvertTo-Json) @(404)
    Test-Api POST 'auth/otp/verify' (@{email='test@example.com';code='000000';device_id=$device}|ConvertTo-Json) @(401)
    Test-Api POST 'auth/google' (@{id_token='bad';device_id=$device}|ConvertTo-Json) @(401)
    Test-Api POST 'auth/refresh' '{}' @(401,422)
    Test-Api POST 'auth/logout' '{}' @(401,422)
    Test-Api GET 'me' '{}' @(401)
    Test-Api GET 'dashboard' '{}' @(401)
    Test-Api POST 'me/password' '{}' @(401)
)
$authResults | Export-Csv (Join-Path $outRoot 'auth-api.csv') -NoTypeInformation

# google-services oauth check
$gsPath = Join-Path $root 'android\olasentra-staff\app\google-services.json'
$gs = @{ oauth_app=0; oauth_staff=0; project='' }
if (Test-Path $gsPath) {
    $j = Get-Content $gsPath -Raw | ConvertFrom-Json
    $gs.project = $j.project_info.project_id
    foreach ($c in $j.client) {
        $pkg = $c.client_info.android_client_info.package_name
        $cnt = @($c.oauth_client | Where-Object { $_ }).Count
        if ($pkg -eq 'com.olasentra.app') { $gs.oauth_app = $cnt }
        if ($pkg -eq 'com.olasentra.staff') { $gs.oauth_staff = $cnt }
    }
}

# Android screens (code inventory)
$androidScreens = @(
    'Splash','Welcome','Login','EmailSignIn','OtpVerification','RegistrationEmail',
    'ApplyRegistration','NativeRegistration','Main','Dashboard','Shifts','ShiftDetail',
    'CheckIn','Messages','Profile','Settings','EditProfile','ChangePassword',
    'Notifications','Documents','Availability','AvailableEvents'
)

# Zero-byte PHP in critical paths
$critical = @('admin','includes','api','staff-*.php')
$zeroBytes = Get-ChildItem (Join-Path $root 'admin') -Filter '*.php' -File -ErrorAction SilentlyContinue | Where-Object { $_.Length -eq 0 }
$zeroBytes += Get-ChildItem (Join-Path $root 'includes') -Recurse -Filter '*.php' -File -ErrorAction SilentlyContinue | Where-Object { $_.Length -eq 0 }

$summary = [ordered]@{
    generated = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
    phase = 'Phase 6 Full Verification'
    public_pages_tested = $publicResults.Count
    public_pass = @($publicResults | Where-Object status -eq 'PASS').Count
    public_warning = @($publicResults | Where-Object status -eq 'WARNING').Count
    public_fail = @($publicResults | Where-Object status -eq 'FAIL').Count
    auth_api_tested = $authResults.Count
    auth_api_pass = @($authResults | Where-Object status -eq 'PASS').Count
    auth_api_fail = @($authResults | Where-Object status -eq 'FAIL').Count
    android_screens_in_code = $androidScreens.Count
    android_device_tested = 0
    google_oauth_clients_app = $gs.oauth_app
    google_oauth_clients_staff = $gs.oauth_staff
    firebase_project = $gs.project
    zero_byte_admin_includes = $zeroBytes.Count
    screenshots = 'BLOCKED - no browser/device session'
    authenticated_ui = 'BLOCKED - no session cookies'
    device_logcat = 'BLOCKED - no adb/device'
}
$summary | ConvertTo-Json | Set-Content (Join-Path $outRoot 'summary.json') -Encoding UTF8

Write-Host "Phase 6 harness complete -> $outRoot"
$summary | Format-List
