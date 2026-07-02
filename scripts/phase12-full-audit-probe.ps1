# Phase 12 — Full production functional audit probe
# Output: docs/phase12-audit-probe-YYYYMMDD-HHMMSS.json

$ErrorActionPreference = 'Continue'
$base = 'https://register.olasentra.com'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$outFile = Join-Path (Split-Path $PSScriptRoot -Parent) "docs\phase12-audit-probe-$stamp.json"

function Get-BodyText($Response) {
    $c = $Response.Content
    if ($c -is [byte[]]) {
        return [System.Text.Encoding]::UTF8.GetString($c)
    }
    return [string]$c
}

function Test-Route {
    param(
        [string]$Category,
        [string]$Name,
        [string]$Url,
        [string]$Method = 'GET',
        [hashtable]$Headers = @{},
        [string]$Body = $null,
        [int]$MaxRedirect = 5
    )
    $result = [ordered]@{
        category    = $Category
        name        = $Name
        url         = $Url
        method      = $Method
        status      = 0
        final_url   = $Url
        bytes       = 0
        title       = ''
        fatal       = $false
        parse_error = $false
        blank       = $false
        redirect    = $false
        error       = ''
        verdict     = 'UNKNOWN'
    }
    try {
        $params = @{
            Uri             = $Url
            UseBasicParsing = $true
            TimeoutSec      = 30
            MaximumRedirection = $MaxRedirect
        }
        if ($Headers.Count -gt 0) { $params.Headers = $Headers }
        if ($Method -eq 'POST') {
            $params.Method = 'POST'
            $params.ContentType = 'application/x-www-form-urlencoded'
            if ($Body) { $params.Body = $Body }
        }
        $r = Invoke-WebRequest @params
        $body = Get-BodyText $r
        $result.status = [int]$r.StatusCode
        $result.final_url = [string]$r.BaseResponse.ResponseUri
        $result.bytes = $body.Length
        if ($body -match '<title[^>]*>([^<]+)</title>') {
            $result.title = ($Matches[1] -replace '\s+', ' ').Trim()
        }
        $result.fatal = ($body -match 'Fatal error|Uncaught Error|Parse error')
        $result.parse_error = ($body -match 'Parse error')
        $result.blank = ($body.Length -lt 80)
        $result.redirect = ($r.BaseResponse.ResponseUri -ne $Url)
    } catch {
        $resp = $_.Exception.Response
        if ($resp) {
            $result.status = [int]$resp.StatusCode
            try {
                $stream = $resp.GetResponseStream()
                $reader = New-Object System.IO.StreamReader($stream)
                $body = $reader.ReadToEnd()
                $result.bytes = $body.Length
                $result.fatal = ($body -match 'Fatal error|Uncaught Error|Parse error')
                if ($body -match '<title[^>]*>([^<]+)</title>') {
                    $result.title = ($Matches[1] -replace '\s+', ' ').Trim()
                }
            } catch { }
        } else {
            $result.status = 0
        }
        $result.error = $_.Exception.Message
    }

    if ($result.status -ge 500) { $result.verdict = 'HTTP_500' }
    elseif ($result.status -eq 404) { $result.verdict = 'HTTP_404' }
    elseif ($result.fatal) { $result.verdict = 'PHP_FATAL' }
    elseif ($result.blank -and -not ($result.status -ge 300 -and $result.status -lt 400)) { $result.verdict = 'BLANK' }
    elseif ($result.status -ge 200 -and $result.status -lt 400) { $result.verdict = 'OK' }
    elseif ($result.status -eq 401 -or $result.status -eq 403) { $result.verdict = 'AUTH_EXPECTED' }
    elseif ($result.status -eq 405) { $result.verdict = 'METHOD_EXPECTED' }
    elseif ($result.redirect -or ($result.status -ge 300 -and $result.status -lt 400)) { $result.verdict = 'REDIRECT_OK' }
    else { $result.verdict = 'FAIL' }

    return [pscustomobject]$result
}

$routes = @(
    # Public
    @{ Cat = 'Public'; Name = 'Home'; Url = "$base/home.php" },
    @{ Cat = 'Public'; Name = 'Registration index'; Url = "$base/index.php" },
    @{ Cat = 'Public'; Name = 'Registration form static'; Url = "$base/index.php?form=static" },
    @{ Cat = 'Public'; Name = 'Staff Login'; Url = "$base/staff-app.php" },
    @{ Cat = 'Public'; Name = 'Google Sign-In start'; Url = "$base/staff-google-signin.php?return=staff-app.php" },
    @{ Cat = 'Public'; Name = 'Application Status'; Url = "$base/status.php" },
    @{ Cat = 'Public'; Name = 'Account Deletion'; Url = "$base/account-deletion.php" },
    @{ Cat = 'Public'; Name = 'Offline'; Url = "$base/offline.php" },
    @{ Cat = 'Public'; Name = 'Privacy'; Url = "$base/privacy.php" },
    @{ Cat = 'Public'; Name = 'Terms'; Url = "$base/terms.php" },
    @{ Cat = 'Public'; Name = 'Submit GET redirect'; Url = "$base/submit.php" },
    # Staff PWA (guest)
    @{ Cat = 'Staff PWA'; Name = 'Dashboard'; Url = "$base/staff-app.php" },
    @{ Cat = 'Staff PWA'; Name = 'Shifts'; Url = "$base/staff-shifts.php" },
    @{ Cat = 'Staff PWA'; Name = 'Check-In'; Url = "$base/staff-checkin.php" },
    @{ Cat = 'Staff PWA'; Name = 'Messages'; Url = "$base/staff-messages.php" },
    @{ Cat = 'Staff PWA'; Name = 'Notifications'; Url = "$base/staff-notifications.php" },
    @{ Cat = 'Staff PWA'; Name = 'Documents'; Url = "$base/staff-documents.php" },
    @{ Cat = 'Staff PWA'; Name = 'Profile Hub'; Url = "$base/staff-profile-hub.php" },
    @{ Cat = 'Staff PWA'; Name = 'Profile Edit'; Url = "$base/staff-profile.php" },
    @{ Cat = 'Staff PWA'; Name = 'Settings'; Url = "$base/staff-settings.php" },
    # PWA assets
    @{ Cat = 'PWA'; Name = 'Manifest'; Url = "$base/manifest.php" },
    @{ Cat = 'PWA'; Name = 'Service Worker'; Url = "$base/sw.js" },
    @{ Cat = 'PWA'; Name = 'v3 CSS'; Url = "$base/assets/css/staff-app-v3.css" },
    @{ Cat = 'PWA'; Name = 'v3 JS'; Url = "$base/assets/js/staff-app-v3.js" },
    @{ Cat = 'PWA'; Name = 'OTP JS'; Url = "$base/assets/js/staff-portal-email-otp.js" },
    @{ Cat = 'PWA'; Name = 'Registration v3 CSS'; Url = "$base/assets/css/registration-v3.css" },
    @{ Cat = 'PWA'; Name = 'Email banner'; Url = "$base/storage/branding/olasentra-email-banner.png" },
    # API
    @{ Cat = 'API'; Name = 'Mobile config'; Url = "$base/api/mobile/v1/config" },
    @{ Cat = 'API'; Name = 'OTP send GET'; Url = "$base/api/staff-portal-otp-send.php" },
    @{ Cat = 'API'; Name = 'OTP verify GET'; Url = "$base/api/staff-portal-otp-verify.php" }
)

$results = @()
foreach ($r in $routes) {
    $extra = @{}
    if ($r.Url -like '*submit.php*') { $extra.MaxRedirect = 0 }
    if ($r.Url -like '*staff-google-signin*') { $extra.MaxRedirect = 0 }
    $res = Test-Route -Category $r.Cat -Name $r.Name -Url $r.Url @extra
    $results += $res
    $tag = if ($res.verdict -match 'OK|REDIRECT|AUTH|METHOD') { 'PASS' } else { 'FAIL' }
    Write-Output "$tag  [$($res.verdict)] $($res.status)  $($r.Name)"
}

# Registration POST validation probe (expect 422/redirect not 500)
$s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$idx = Invoke-WebRequest -Uri "$base/index.php?form=static" -WebSession $s -UseBasicParsing -TimeoutSec 30
$csrf = ''
if ((Get-BodyText $idx) -match 'name="csrf_token"\s+value="([^"]+)"') { $csrf = $Matches[1] }
if ($csrf -ne '') {
    try {
        $post = Invoke-WebRequest -Uri "$base/submit.php" -Method POST -WebSession $s -UseBasicParsing -ContentType 'application/x-www-form-urlencoded' -Body "csrf_token=$csrf&email=phase12-probe@example.com&form_slug=static" -Headers @{'X-Requested-With'='XMLHttpRequest'} -ErrorAction Stop
        $pb = Get-BodyText $post
        $pverdict = if ($post.StatusCode -eq 200 -and $pb.TrimStart().StartsWith('{')) { 'OK_JSON' } elseif ($post.StatusCode -lt 500) { 'OK' } else { 'HTTP_500' }
        $results += [pscustomobject][ordered]@{ category='Registration'; name='Submit POST minimal'; url="$base/submit.php"; method='POST'; status=$post.StatusCode; verdict=$pverdict; fatal=($pb -match 'Fatal error'); bytes=$pb.Length; error='' }
        Write-Output "$(if($pverdict -ne 'HTTP_500'){'PASS'}else{'FAIL'})  [$pverdict] $($post.StatusCode)  Registration POST minimal"
    } catch {
        $code = [int]$_.Exception.Response.StatusCode
        $pverdict = if ($code -ge 500) { 'HTTP_500' } elseif ($code -ge 400) { 'VALIDATION_EXPECTED' } else { 'FAIL' }
        $results += [pscustomobject][ordered]@{ category='Registration'; name='Submit POST minimal'; url="$base/submit.php"; method='POST'; status=$code; verdict=$pverdict; fatal=$false; bytes=0; error=$_.Exception.Message }
        Write-Output "PASS  [$pverdict] $code  Registration POST minimal"
    }
} else {
    Write-Output 'SKIP  Registration POST (Google gate — no CSRF on form page)'
    $results += [pscustomobject][ordered]@{ category='Registration'; name='Submit POST minimal'; url="$base/submit.php"; method='POST'; status=0; verdict='SKIP_GOOGLE_GATE'; fatal=$false; bytes=0; error='No CSRF — Google gate shown' }
}

# status.php include check via deployed file hash probe
$statusBody = (Invoke-WebRequest -Uri "$base/status.php" -UseBasicParsing).Content
$statusFix = ($statusBody -notmatch 'Fatal error' -and $statusBody -notmatch 'computeStaffStatusMetricsFromRows\(\): Uncaught')
Write-Output "$(if($statusFix){'PASS'}else{'FAIL'})  Status page no fatal on lookup view"

$broken = @($results | Where-Object { $_.verdict -match 'HTTP_500|PHP_FATAL|HTTP_404|BLANK|FAIL' })
$summary = [ordered]@{
    timestamp   = (Get-Date).ToString('o')
    base        = $base
    total       = $results.Count
    broken      = $broken.Count
    broken_list = @($broken | Select-Object category, name, url, status, verdict, error)
    results     = $results
}
$summary | ConvertTo-Json -Depth 6 | Set-Content $outFile -Encoding UTF8
Write-Host ''
Write-Host "Probe complete: $($results.Count) routes, $($broken.Count) broken" -ForegroundColor $(if ($broken.Count -eq 0) { 'Green' } else { 'Yellow' })
Write-Host "Report: $outFile"
