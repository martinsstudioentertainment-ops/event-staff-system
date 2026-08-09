# Live production registration submit test (creates one real staff row).
# Usage: powershell -ExecutionPolicy Bypass -File .\scripts\live-e2e-registration-submit.ps1 -Role steward
param(
    [ValidateSet('steward', 'static', 'dsp')]
    [string]$Role = 'steward'
)

$ErrorActionPreference = 'Stop'
$ts = Get-Date -Format 'yyyyMMddHHmmss'
$email = "olasentra.e2e.$Role.$ts@example.com"
$base = if ($env:REGISTRATION_BASE_URL) { $env:REGISTRATION_BASE_URL.TrimEnd('/') } else { 'https://register.olasentra.com' }
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$log = [ordered]@{
    timestamp = (Get-Date).ToString('o')
    role      = $Role
    email     = $email
    steps     = @()
}

function Step([string]$Name, [string]$Status, [string]$Detail) {
    $script:log.steps += [pscustomobject]@{ step = $Name; status = $Status; detail = $Detail }
    Write-Host "[$Status] $Name - $Detail"
}

Write-Host "Live E2E registration submit - $Role - $email" -ForegroundColor Cyan

try {
    $indexUrl = "$base/index.php?form=$Role"
    $index = Invoke-WebRequest -Uri $indexUrl -WebSession $session -UseBasicParsing -TimeoutSec 60
    Step 'GET index' $(if ($index.StatusCode -eq 200) { 'PASS' } else { 'FAIL' }) "HTTP $($index.StatusCode) $indexUrl"

    if ($index.Content -notmatch 'data-registration-account-only="1"') {
        Step 'Account-only flag' 'FAIL' 'data-registration-account-only missing'
    } else {
        Step 'Account-only flag' 'PASS' 'data-registration-account-only="1"'
    }

    $csrfMatch = [regex]::Match($index.Content, 'name="csrf_token"\s+value="([0-9a-f]+)"')
    if (-not $csrfMatch.Success) {
        throw 'CSRF token not found in index HTML'
    }
    $csrf = $csrfMatch.Groups[1].Value
    Step 'CSRF token' 'PASS' ($csrf.Substring(0, [Math]::Min(16, $csrf.Length)) + '...')

    $body = @{
        csrf_token        = $csrf
        form_slug         = $Role
        staff_role        = $Role
        registration_mode = 'profile_only'
        privacy_consent   = '1'
        surname           = 'E2ETest'
        first_name        = 'Live'
        full_address      = '1 E2E Street, Dublin'
        eircode           = 'D02 X285'
        date_of_birth     = '1990-01-15'
        gender            = 'male'
        email             = $email
        mobile            = '+353871234567'
        mobile_national   = '0871234567'
        mobile_country    = 'IE'
        pps_number        = '1234567T'
        bank_iban         = 'IE29AIBK93115212345678'
    }

    if ($Role -ne 'steward') {
        $body['psa_licence'] = '1234567'
        $body['psa_expiry_date'] = '2030-12-31'
    }

    try {
        $submit = Invoke-WebRequest -Uri "$base/submit.php" -Method POST -WebSession $session -Body $body -UseBasicParsing -TimeoutSec 90 -MaximumRedirection 0
        $code = [int]$submit.StatusCode
        $loc = $submit.Headers['Location']
    } catch {
        $resp = $_.Exception.Response
        if ($resp) {
            $code = [int]$resp.StatusCode
            $loc = $resp.Headers['Location']
            $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
            $submitBody = $reader.ReadToEnd()
        } else {
            throw
        }
    }

    Step 'POST submit.php' $(if ($code -ge 200 -and $code -lt 400) { 'PASS' } else { 'FAIL' }) "HTTP $code Location=$loc"

    if ($code -ge 300 -and $code -lt 400 -and $loc) {
        $target = if ($loc -match '^https?://') { $loc } else { "$base/$($loc.TrimStart('/'))" }
        $follow = Invoke-WebRequest -Uri $target -WebSession $session -UseBasicParsing -TimeoutSec 60
        Step 'Follow redirect' $(if ($follow.StatusCode -eq 200) { 'PASS' } else { 'FAIL' }) "HTTP $($follow.StatusCode) $target"
        $markers = @('registered=profile', 'Registration complete', 'Sign in', 'staff-app')
        $hit = $false
        foreach ($m in $markers) {
            if ($follow.Content -match [regex]::Escape($m)) { $hit = $true; break }
        }
        Step 'Success page markers' $(if ($hit) { 'PASS' } else { 'WARN' }) "Checked redirect body for success markers"
        if ($follow.Content -match 'Fatal error|Parse error|Uncaught Error') {
            Step 'PHP errors in redirect' 'FAIL' 'Runtime error text in response'
        } else {
            Step 'PHP errors in redirect' 'PASS' 'No fatal error text'
        }
    } elseif ($code -eq 200) {
        $snippet = if ($submitBody) { $submitBody } else { $submit.Content }
        if ($snippet -match 'Fatal error|Parse error') {
            Step 'submit response' 'FAIL' 'PHP fatal in body'
        } else {
            Step 'submit response' 'WARN' ('Body length ' + $snippet.Length)
        }
    }

    $appJs = (Invoke-WebRequest -Uri "$base/assets/js/app.js" -UseBasicParsing -TimeoutSec 30).Content
    Step 'Submit JS fix deployed' $(if ($appJs -match 'getEffectiveRegistrationEmailValue') { 'PASS' } else { 'FAIL' }) 'app.js contains email sync helper'

    $out = Join-Path $PSScriptRoot '..' 'docs' "live-e2e-submit-$Role-$ts.json"
    $log | ConvertTo-Json -Depth 5 | Set-Content -Path $out -Encoding UTF8
    Write-Host "Log: $out" -ForegroundColor Gray
} catch {
    Step 'Fatal' 'FAIL' $_.Exception.Message
    exit 1
}
