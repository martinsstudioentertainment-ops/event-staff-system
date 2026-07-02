$ErrorActionPreference = 'Continue'
$base = 'https://register.olasentra.com'
$fail = 0

function Test-Probe {
    param(
        [string]$Name,
        [string]$Url,
        [string[]]$Must
    )
    global:fail
    try {
        $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 30
        $body = $r.Content
        $missing = @($Must | Where-Object { $body -notlike "*$_*" })
        if ($missing.Count -eq 0 -and $r.StatusCode -eq 200) {
            Write-Output "PASS  $Name  HTTP $($r.StatusCode)  $($body.Length) bytes"
            return
        }
        $script:fail++
        Write-Output "FAIL  $Name  HTTP $($r.StatusCode)  missing: $($missing -join ', ')"
    } catch {
        $script:fail++
        Write-Output "FAIL  $Name  error: $($_.Exception.Message)"
    }
}

Write-Host ''
Write-Host 'Phase 10 post-deploy verification' -ForegroundColor Cyan
Write-Host ''

Test-Probe -Name 'Login page' -Url "$base/staff-app.php" -Must @(
    'Sign in with Google',
    'Sign in with Email Code',
    'staff-portal-email-otp',
    'data-staff-app-v3'
)
Test-Probe -Name 'Google Login markup' -Url "$base/staff-app.php" -Must @(
    'Sign in with Google',
    'google'
)
Test-Probe -Name 'Email OTP assets' -Url "$base/staff-app.php" -Must @(
    'staff-portal-email-otp.js',
    'Sign in with Email Code (OTP)'
)
Test-Probe -Name 'Application Status page' -Url "$base/status.php" -Must @(
    'Application status',
    'staff-app-v3.css',
    'es-v3'
)
Test-Probe -Name 'Profile Edit page' -Url "$base/staff-profile.php" -Must @(
    'staff-app-v3.css',
    'es-v3'
)
Test-Probe -Name 'Install Prompt (shell banner)' -Url "$base/staff-app.php" -Must @(
    'es-v3-pwa-banner',
    'es-v3-pwa-install'
)
Test-Probe -Name 'Install Prompt (JS handler)' -Url "$base/assets/js/staff-app-v3.js" -Must @(
    'es-v3-pwa-banner',
    'beforeinstallprompt',
    'isStandalonePwa'
)
Test-Probe -Name 'Manifest theme colours' -Url "$base/manifest.php" -Must @(
    '#F58220',
    '#0B1020'
)
Test-Probe -Name 'Legacy install skip on v3' -Url "$base/assets/js/pwa-install.js" -Must @(
    "data-staff-app-v3') === '1'"
)
Test-Probe -Name 'Offline PWA page' -Url "$base/offline.php" -Must @(
    'staff-app-v3.css',
    'es-ds__empty'
)
Test-Probe -Name 'Service worker' -Url "$base/sw.js" -Must @(
    'offline'
)

# OTP send endpoint reachable (POST-only; expect 405/422 not 500)
try {
    $r = Invoke-WebRequest -Uri "$base/api/staff-portal-otp-send.php" -Method GET -UseBasicParsing -TimeoutSec 20 -ErrorAction Stop
    if ($r.StatusCode -lt 500) {
        Write-Output "PASS  OTP send endpoint  HTTP $($r.StatusCode) (no server error on GET probe)"
    } else {
        $fail++
        Write-Output "FAIL  OTP send endpoint  HTTP $($r.StatusCode)"
    }
} catch {
    $code = 0
    if ($_.Exception.Response) { $code = [int]$_.Exception.Response.StatusCode }
    if ($code -ge 400 -and $code -lt 500) {
        Write-Output "PASS  OTP send endpoint  HTTP $code (expected client error on GET probe)"
    } elseif ($code -eq 0) {
        $fail++
        Write-Output "FAIL  OTP send endpoint  error: $($_.Exception.Message)"
    } else {
        $fail++
        Write-Output "FAIL  OTP send endpoint  HTTP $code"
    }
}

Write-Host ''
Write-Host "Failures: $fail" -ForegroundColor $(if ($fail -eq 0) { 'Green' } else { 'Red' })
exit $(if ($fail -eq 0) { 0 } else { 1 })
