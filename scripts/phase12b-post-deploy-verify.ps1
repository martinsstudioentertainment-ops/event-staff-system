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
    if ($c -is [byte[]]) {
        return [System.Text.Encoding]::UTF8.GetString($c)
    }
    return [string]$c
}

Write-Host 'Phase 12B post-deploy verification' -ForegroundColor Cyan

$routes = @(
    @{ Name = 'Registration'; Url = "$base/index.php" },
    @{ Name = 'Staff login'; Url = "$base/staff-app.php" },
    @{ Name = 'Application status'; Url = "$base/status.php" },
    @{ Name = 'Offline PWA'; Url = "$base/offline.php" },
    @{ Name = 'Privacy'; Url = "$base/privacy.php" },
    @{ Name = 'Terms'; Url = "$base/terms.php" },
    @{ Name = 'v3 CSS'; Url = "$base/assets/css/staff-app-v3.css" },
    @{ Name = 'Email banner'; Url = "$base/storage/branding/olasentra-email-banner.png" }
)

foreach ($r in $routes) {
    try {
        $resp = Invoke-WebRequest -Uri $r.Url -UseBasicParsing -TimeoutSec 30
        $body = Get-Text $resp
        $ok = ($resp.StatusCode -eq 200) -and ($body -notmatch 'Fatal error|Parse error')
        Report $ok $r.Name "HTTP $($resp.StatusCode)"
    } catch {
        Report $false $r.Name $_.Exception.Message
    }
}

try {
    Invoke-WebRequest -Uri "$base/api/staff-portal-otp-send.php" -UseBasicParsing -TimeoutSec 20 | Out-Null
    Report $false 'OTP send GET' 'unexpected 200'
} catch {
    $code = [int]$_.Exception.Response.StatusCode
    Report ($code -eq 405) 'OTP send GET' "HTTP $code"
}

$css = Get-Text (Invoke-WebRequest -Uri "$base/assets/css/staff-app-v3.css" -UseBasicParsing -TimeoutSec 30)
Report ($css -like '*Phase 12A*') 'Status v3 CSS live' 'staff-app-v3.css'

Write-Host ''
Write-Host "Failures: $fail" -ForegroundColor $(if ($fail -eq 0) { 'Green' } else { 'Red' })
exit $(if ($fail -eq 0) { 0 } else { 1 })
