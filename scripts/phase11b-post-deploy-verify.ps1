$ErrorActionPreference = 'Continue'
$base = 'https://register.olasentra.com'
$fail = 0

function Report {
    param([bool]$Ok, [string]$Name, [string]$Detail)
    if (-not $Ok) { $script:fail++ }
    $tag = if ($Ok) { 'PASS' } else { 'FAIL' }
    Write-Output "$tag  $Name  $Detail"
}

function Get-Text {
    param($Response)
    $c = $Response.Content
    if ($c -is [byte[]]) { return [System.Text.Encoding]::UTF8.GetString($c) }
    return [string]$c
}

Write-Host 'Phase 11B post-deploy verification' -ForegroundColor Cyan

$r = Invoke-WebRequest -Uri "$base/staff-app.php" -UseBasicParsing -SessionVariable sess -TimeoutSec 30
$b = Get-Text $r
Report ($b -like '*Welcome to Olasentra*') 'Welcome headline' 'staff-app.php'
Report ($b -like '*Sign in with Google*') 'Google Login' 'staff-app.php'
Report ($b -like '*staff-portal-email-send*') 'OTP send button' 'staff-app.php'
Report ($b -like '*staff-portal-email-otp.js*') 'OTP JS loaded' 'staff-app.php'
Report ($b -like '*es-v3-login--compact*') 'Compact login' 'staff-app.php'
Report ($b -like '*es-v3-pwa-banner*') 'Install banner markup' 'staff-app.php'

$css = Get-Text (Invoke-WebRequest -Uri "$base/assets/css/staff-app-v3.css" -UseBasicParsing -TimeoutSec 30)
Report ($css -like '*--es-guest-pwa-clearance*') 'PWA clearance CSS token' 'staff-app-v3.css'
Report ($css -like '*pointer-events: none*') 'Banner click passthrough CSS' 'staff-app-v3.css'
Report ($css -like '*es-v3--pwa-banner-open*') 'Banner-open spacing CSS' 'staff-app-v3.css'

$otpJs = Get-Text (Invoke-WebRequest -Uri "$base/assets/js/staff-portal-email-otp.js" -UseBasicParsing -TimeoutSec 30)
Report ($otpJs -like '*scrollIntoView*') 'OTP error scroll UX' 'staff-portal-email-otp.js'

$v3Js = Get-Text (Invoke-WebRequest -Uri "$base/assets/js/staff-app-v3.js" -UseBasicParsing -TimeoutSec 30)
Report ($v3Js -like '*es-v3--pwa-banner-open*') 'Banner open class JS' 'staff-app-v3.js'

if ($b -match 'id="staff-portal-email-otp"[^>]*data-csrf="([^"]+)"') {
    $csrf = $Matches[1]
    $payload = '{"email":"probe@example.com","csrf_token":"' + $csrf + '"}'
    try {
        Invoke-WebRequest -Uri "$base/api/staff-portal-otp-send.php" -Method POST -WebSession $sess -UseBasicParsing -ContentType 'application/json' -Body $payload -TimeoutSec 20 -ErrorAction Stop | Out-Null
        Report $true 'OTP send API' 'unexpected success'
    } catch {
        $code = [int]$_.Exception.Response.StatusCode
        Report ($code -ge 400 -and $code -lt 500) 'OTP send API' "HTTP $code (handler live)"
    }
} else {
    Report $false 'OTP send API' 'CSRF not found'
}

Write-Host ''
Write-Host "Failures: $fail" -ForegroundColor $(if ($fail -eq 0) { 'Green' } else { 'Red' })
exit $(if ($fail -eq 0) { 0 } else { 1 })
