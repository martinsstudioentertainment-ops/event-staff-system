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

Write-Host 'Phase 12 post-deploy verification' -ForegroundColor Cyan

$r = Invoke-WebRequest -Uri "$base/index.php" -UseBasicParsing -TimeoutSec 30
$b = Get-Text $r
Report ($b -like '*registration-page--v3*') 'Registration page loads' 'index.php v3'
Report ($b -like '*submit.php*' -or $b -like '*registration-google-gate*') 'Registration flow markup' 'index.php'
Report ($b -notlike '*Fatal error*' -and $b -notlike '*Parse error*') 'No PHP fatal on index' 'index.php'

$status = Get-Text (Invoke-WebRequest -Uri "$base/status.php" -UseBasicParsing -TimeoutSec 30)
Report ($status -like '*Application status*' -or $status -like '*status_lookup*' -or $status -like '*es-v3*') 'Status page loads' 'status.php'
Report ($status -notlike '*Fatal error*' -and $status -notlike '*Parse error*') 'No PHP fatal on status lookup' 'status.php'

$statusPhp = Get-Text (Invoke-WebRequest -Uri "$base/status.php" -UseBasicParsing -TimeoutSec 30)
# Probe production file via indirect check: download not available here; verify deployed include via FTP re-download in deploy script.
# Additional: staff login parity
$login = Get-Text (Invoke-WebRequest -Uri "$base/staff-app.php" -UseBasicParsing -TimeoutSec 30)
Report ($login -like '*Sign in with Google*') 'Google Login' 'staff-app.php'
Report ($login -like '*staff-portal-email-send*') 'Email OTP' 'staff-app.php'

try {
    Invoke-WebRequest -Uri "$base/submit.php" -Method GET -UseBasicParsing -MaximumRedirection 0 -TimeoutSec 20 -ErrorAction Stop | Out-Null
    Report $false 'submit.php GET redirect' 'unexpected 200'
} catch {
    $code = [int]$_.Exception.Response.StatusCode
    $loc = $_.Exception.Response.Headers['Location']
    Report ($code -eq 302 -and $loc -like '*index.php*') 'submit.php GET redirect' "HTTP $code"
}

Write-Host ''
Write-Host "Failures: $fail" -ForegroundColor $(if ($fail -eq 0) { 'Green' } else { 'Red' })
exit $(if ($fail -eq 0) { 0 } else { 1 })
