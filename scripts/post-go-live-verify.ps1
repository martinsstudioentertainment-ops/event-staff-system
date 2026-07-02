$base = 'https://register.olasentra.com'
$pass = 0
$fail = 0

function Report {
    param([bool]$Ok, [string]$Name, [string]$Detail)
    if ($Ok) { $script:pass++ } else { $script:fail++ }
    $tag = if ($Ok) { 'PASS' } else { 'FAIL' }
    Write-Output "$tag  $Name  $Detail"
}

function Get-Text {
    param($Response)
    $c = $Response.Content
    if ($c -is [byte[]]) {
        return [System.Text.Encoding]::UTF8.GetString($c)
    }
    return [string]$c
}

$r = Invoke-WebRequest -Uri "$base/staff-app.php" -UseBasicParsing -TimeoutSec 30
$b = Get-Text $r
Report ($b -like '*Sign in with Google*' -and $b -like '*staff-portal-email-otp*') 'Login page' "HTTP $($r.StatusCode)"

$r = Invoke-WebRequest -Uri "$base/staff-google-signin.php?return=staff-app.php" -UseBasicParsing -TimeoutSec 30 -MaximumRedirection 5
$b = Get-Text $r
Report ($b -like '*accounts.google.com*' -or $r.BaseResponse.ResponseUri.Host -like '*google*') 'Google OAuth' "final host $($r.BaseResponse.ResponseUri.Host)"

foreach ($ep in @('api/staff-portal-otp-send.php', 'api/staff-portal-otp-verify.php')) {
    try {
        Invoke-WebRequest -Uri "$base/$ep" -Method GET -UseBasicParsing -TimeoutSec 20 -ErrorAction Stop | Out-Null
        Report $true "OTP $ep" 'unexpected GET success'
    } catch {
        $code = [int]$_.Exception.Response.StatusCode
        Report ($code -ge 400 -and $code -lt 500) "OTP $ep" "HTTP $code"
    }
}

try {
    Invoke-WebRequest -Uri "$base/staff-checkin.php" -UseBasicParsing -TimeoutSec 30 -MaximumRedirection 0 -ErrorAction Stop | Out-Null
    Report $false 'Check-in gate' 'expected redirect without session'
} catch {
    $code = [int]$_.Exception.Response.StatusCode
    if ($code -eq 302) {
        Report $true 'Check-in gate' "HTTP $code"
    } else {
        $r2 = Invoke-WebRequest -Uri "$base/staff-checkin.php" -UseBasicParsing -TimeoutSec 30 -MaximumRedirection 5
        $b2 = Get-Text $r2
        Report ($r2.StatusCode -eq 200 -and ($b2 -like '*Sign in*' -or $b2 -like '*staff-app*')) 'Check-in gate' "redirect to login HTTP $($r2.StatusCode)"
    }
}

$r = Invoke-WebRequest -Uri "$base/status.php" -UseBasicParsing -TimeoutSec 30
$b = Get-Text $r
Report ($b -like '*Application status*' -and $b -like '*staff-app-v3.css*') 'Application Status' "HTTP $($r.StatusCode)"

$r = Invoke-WebRequest -Uri "$base/staff-profile.php" -UseBasicParsing -TimeoutSec 30
$b = Get-Text $r
Report ($b -notmatch 'Fatal error|Parse error') 'Profile Edit' "HTTP $($r.StatusCode)"

$r = Invoke-WebRequest -Uri "$base/staff-app.php" -UseBasicParsing -TimeoutSec 30
$b = Get-Text $r
Report ($b -like '*es-v3-pwa-banner*') 'PWA install banner' 'single banner in shell'

$r = Invoke-WebRequest -Uri "$base/manifest.php" -UseBasicParsing -TimeoutSec 30
$j = Get-Text $r | ConvertFrom-Json
Report ($j.theme_color -eq '#F58220' -and $j.background_color -eq '#0B1020') 'Manifest colours' "$($j.theme_color) / $($j.background_color)"

$r = Invoke-WebRequest -Uri "$base/offline.php" -UseBasicParsing -TimeoutSec 30
$b = Get-Text $r
Report ($b -like '*staff-app-v3.css*') 'Offline PWA' "HTTP $($r.StatusCode)"

$r = Invoke-WebRequest -Uri "$base/sw.js" -UseBasicParsing -TimeoutSec 30
$b = Get-Text $r
Report ($b -like '*offline*') 'Service worker' "HTTP $($r.StatusCode)"

Write-Output "SUMMARY pass=$pass fail=$fail"
exit $(if ($fail -eq 0) { 0 } else { 1 })
