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

Write-Host 'Phase 11 post-deploy verification' -ForegroundColor Cyan

$r = Invoke-WebRequest -Uri "$base/staff-app.php" -UseBasicParsing -TimeoutSec 30
$b = Get-Text $r
Report ($b -like '*Welcome to Olasentra*') 'Welcome headline' 'staff-app.php'
Report ($b -like '*Manage shifts, messages, documents and work updates.*') 'Welcome subtitle' 'staff-app.php'
Report ($b -like '*Sign in with Google*') 'Google Login' 'staff-app.php'
Report ($b -like '*Sign in with Email Code (OTP)*') 'Email OTP UI' 'staff-app.php'
Report ($b -like '*es-v3-login--compact*') 'Compact login layout' 'staff-app.php'

$r = Invoke-WebRequest -Uri "$base/index.php" -UseBasicParsing -TimeoutSec 30
$b = Get-Text $r
Report ($b -like '*registration-page--v3*') 'Registration v3 body class' 'index.php'
Report ($b -like '*registration-v3.css*') 'Registration v3 CSS' 'index.php'
$hasRegisterFlow = ($b -like '*submit.php*' -or $b -like '*registration-form*' -or $b -like '*registration-google-gate*')
Report $hasRegisterFlow 'Register flow markup' 'index.php'

try {
    Invoke-WebRequest -Uri "$base/api/staff-portal-otp-send.php" -Method GET -UseBasicParsing -TimeoutSec 20 -ErrorAction Stop | Out-Null
    Report $true 'OTP send API' 'GET probe'
} catch {
    $code = [int]$_.Exception.Response.StatusCode
    Report ($code -ge 400 -and $code -lt 500) 'OTP send API' "HTTP $code"
}

$r = Invoke-WebRequest -Uri "$base/staff-google-signin.php?return=staff-app.php" -UseBasicParsing -TimeoutSec 30 -MaximumRedirection 5
$b = Get-Text $r
Report ($b -like '*accounts.google.com*' -or $r.BaseResponse.ResponseUri.Host -like '*google*') 'Google OAuth redirect' 'staff-google-signin.php'

Write-Host ''
Write-Host "Failures: $fail" -ForegroundColor $(if ($fail -eq 0) { 'Green' } else { 'Red' })
exit $(if ($fail -eq 0) { 0 } else { 1 })
