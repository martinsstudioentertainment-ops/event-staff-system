# Production E2E verification — account-only registration architecture (read-only HTTP).
#   powershell -ExecutionPolicy Bypass -File .\scripts\verify-production-registration-e2e.ps1

$ErrorActionPreference = 'Stop'
$Base = if ($env:REGISTRATION_BASE_URL) { $env:REGISTRATION_BASE_URL.TrimEnd('/') } else { 'https://register.olasentra.com' }
$Admin = 'https://admin.olasentra.com'
$results = @()

function Add-Result([string]$Area, [string]$Test, [string]$Status, [string]$Detail) {
    $script:results += [PSCustomObject]@{ Area = $Area; Test = $Test; Status = $Status; Detail = $Detail }
}

function Fetch([string]$Url) {
    return (Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 45).Content
}

Write-Host "Production registration E2E verification — $Base" -ForegroundColor Cyan
Write-Host ''

# --- Registration portal ---
try {
    $index = Fetch "$Base/index.php"
    Add-Result 'Registration' 'Account-only flag on body' $(if ($index -match 'data-registration-account-only="1"') { 'PASS' } else { 'FAIL' }) 'data-registration-account-only="1"'
    Add-Result 'Registration' 'registration_mode=profile_only' $(if ($index -match 'id="registration_mode"[^>]*value="profile_only"') { 'PASS' } else { 'FAIL' }) 'Hidden field default'
    Add-Result 'Registration' 'Wizard enabled' $(if ($index -match 'data-wizard-mode="1"') { 'PASS' } else { 'FAIL' }) 'data-wizard-mode="1"'

    if ($index -match 'Pick your gigs') {
        Add-Result 'Registration' 'Pick your gigs in HTML' 'WARN' 'Step panel markup still present (skipped in JS navigation)'
    } else {
        Add-Result 'Registration' 'Pick your gigs absent from HTML' 'PASS' 'Not in page'
    }

    $eventsMatch = [regex]::Match($index, 'src="([^"]*events\.js[^"]*)"')
    $eventsJsUrl = if ($eventsMatch.Success) { $eventsMatch.Groups[1].Value } else { 'assets/js/events.js' }
    if ($eventsJsUrl -notmatch '^https?://') { $eventsJsUrl = "$Base/$($eventsJsUrl.TrimStart('/'))" }
    $eventsJs = Fetch $eventsJsUrl
    Add-Result 'Registration' 'events.js account-only guard' $(if ($eventsJs -match "registrationAccountOnly === '1'") { 'PASS' } else { 'FAIL' }) 'initShiftSelection early return'

    $wizMatch = [regex]::Match($index, 'src="([^"]*registration-wizard\.js[^"]*)"')
    $wizUrl = if ($wizMatch.Success) { $wizMatch.Groups[1].Value } else { 'assets/js/registration-wizard.js' }
    if ($wizUrl -notmatch '^https?://') { $wizUrl = "$Base/$($wizUrl.TrimStart('/'))" }
    $wizJs = Fetch $wizUrl
    Add-Result 'Registration' 'Wizard skips shift step' $(if ($wizJs -match 'function skipsShiftStep') { 'PASS' } else { 'FAIL' }) 'skipsShiftStep() present'
    Add-Result 'Registration' 'No before-review redirect' $(if ($wizJs -notmatch 'before review') { 'PASS' } else { 'FAIL' }) 'No stale error string'

    $valMatch = [regex]::Match($index, 'src="([^"]*registration-wizard-validation\.js[^"]*)"')
    $valUrl = if ($valMatch.Success) { $valMatch.Groups[1].Value } else { 'assets/js/registration-wizard-validation.js' }
    if ($valUrl -notmatch '^https?://') { $valUrl = "$Base/$($valUrl.TrimStart('/'))" }
    $valJs = Fetch $valUrl
    Add-Result 'Registration' 'shouldRequireEventSelection false' $(if ($valJs -match 'function shouldRequireEventSelection\(\)\s*\{\s*return false') { 'PASS' } else { 'FAIL' }) 'Always optional'

    # submit.php redirect target (deployed source in repo)
    $submitLocal = Get-Content (Join-Path $PSScriptRoot '..\includes\status-repository.php') -Raw
    Add-Result 'Registration' 'Redirect URL in codebase' $(if ($submitLocal -match 'staff-app\.php\?registered=profile') { 'PASS' } else { 'FAIL' }) 'getProfileOnlyRegistrationRedirectUrl'
} catch {
    Add-Result 'Registration' 'Portal fetch' 'FAIL' $_.Exception.Message
}

# --- Staff app ---
try {
    $staff = Fetch "$Base/staff-app.php?registered=profile"
    Add-Result 'Staff App' 'Guest sign-in page loads' $(if ($staff -match 'staff-app|Sign in|Continue with Google') { 'PASS' } else { 'FAIL' }) 'HTTP 200'
    Add-Result 'Staff App' 'Registration success notice' $(if ($staff -match 'Registration complete|available shifts|Sign in below') { 'PASS' } else { 'WARN' }) 'registered=profile query'

    $pagesPhp = Get-Content (Join-Path $PSScriptRoot '..\includes\staff-app-v3-pages.php') -Raw
    Add-Result 'Staff App' 'No-shifts message in dashboard' $(if ($pagesPhp -match 'No shifts are currently available') { 'PASS' } else { 'FAIL' }) 'staff-app-v3-pages.php'
} catch {
    Add-Result 'Staff App' 'staff-app.php' 'FAIL' $_.Exception.Message
}

# --- APIs & admin ---
$endpoints = @(
    @{ Area = 'Mobile API'; Url = "$Base/api/mobile/v1/config" },
    @{ Area = 'Shift API'; Url = "$Base/api/registration-options.php?form=static" },
    @{ Area = 'Admin'; Url = "$Admin/admin/events.php" }
)
foreach ($ep in $endpoints) {
    try {
        $r = Invoke-WebRequest -Uri $ep.Url -UseBasicParsing -TimeoutSec 30 -MaximumRedirection 0 -ErrorAction SilentlyContinue
        $ok = $r.StatusCode -in 200, 301, 302, 303
        Add-Result $ep.Area "HTTP $($r.StatusCode)" $(if ($ok) { 'PASS' } else { 'FAIL' }) $ep.Url
    } catch {
        if ($_.Exception.Response.StatusCode.value__ -in 301, 302, 303) {
            Add-Result $ep.Area 'Redirect (auth)' 'PASS' $ep.Url
        } else {
            Add-Result $ep.Area 'Request' 'FAIL' $_.Exception.Message
        }
    }
}

# --- PHP validation rules (local, mirrors production deploy) ---
try {
    $php = Get-Command php -ErrorAction SilentlyContinue
    if (-not $php) {
        $laragon = Get-ChildItem 'C:\laragon\bin\php\*\php.exe' -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($laragon) { $php = $laragon.FullName } else { throw 'PHP not found' }
    } else { $php = $php.Source }
    $out = & $php (Join-Path $PSScriptRoot 'verify-account-only-registration.php') 2>&1
    $code = $LASTEXITCODE
    Add-Result 'Role Validation' 'PHP steward/DSP rules' $(if ($code -eq 0) { 'PASS' } else { 'FAIL' }) ($out -join ' ' | Select-Object -First 1)
} catch {
    Add-Result 'Role Validation' 'PHP CLI tests' 'FAIL' $_.Exception.Message
}

# --- submit.php architecture ---
try {
    $submit = Get-Content (Join-Path $PSScriptRoot '..\submit.php') -Raw
    Add-Result 'Registration' 'submit uses normalizePortalRegistrationPost' $(if ($submit -match 'normalizePortalRegistrationPost') { 'PASS' } else { 'FAIL' }) 'submit.php'
    Add-Result 'Registration' 'submit forces profileOnlyMode' $(if ($submit -match '\$profileOnlyMode\s*=\s*true') { 'PASS' } else { 'FAIL' }) 'Always account-only'
    Add-Result 'Registration' 'saveProfileOnlyRegistration path' $(if ($submit -match 'saveProfileOnlyRegistration') { 'PASS' } else { 'FAIL' }) 'No shift rows on portal submit'
} catch {
    Add-Result 'Registration' 'submit.php read' 'FAIL' $_.Exception.Message
}

$fail = @($results | Where-Object Status -eq 'FAIL')
$warn = @($results | Where-Object Status -eq 'WARN')
Write-Host ''
$results | Format-Table -AutoSize
Write-Host "Result: $($results.Count - $fail.Count - $warn.Count) PASS, $($warn.Count) WARN, $($fail.Count) FAIL" -ForegroundColor $(if ($fail.Count -eq 0) { 'Green' } else { 'Red' })
if ($fail.Count -gt 0) { exit 1 }
