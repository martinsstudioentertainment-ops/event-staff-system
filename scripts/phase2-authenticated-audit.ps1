# Phase 2 Deep Audit — authenticated workflow verification harness
# Baseline: .audit-admin.json + includes/admin-capabilities.php + Phase 1 report (2026-06-16)
# Evidence: HTTP probes, local file integrity, mobile API route matrix
# Note: Full screenshot capture requires browser session cookies (not in this script).

param(
    [string]$AdminBase = 'https://admin.olasentra.com/admin',
    [string]$StaffBase = 'https://register.olasentra.com',
    [string]$ApplyBase = 'https://apply.olasentra.com',
    [string]$ApiBase = 'https://register.olasentra.com/api/mobile/v1',
    [string]$OutDir = 'docs/phase2-audit-2026-06-18'
)

$ErrorActionPreference = 'Continue'
$root = Split-Path $PSScriptRoot -Parent
New-Item -ItemType Directory -Force -Path (Join-Path $root $OutDir) | Out-Null
$outRoot = Join-Path $root $OutDir

function Test-HttpPage {
    param(
        [string]$Name,
        [string]$Url,
        [string]$Portal = 'admin',
        [string]$LocalPath = ''
    )
    $result = [ordered]@{
        name       = $Name
        url        = $Url
        portal     = $Portal
        status     = 'FAIL'
        http_code  = 0
        final_url  = ''
        body_len   = 0
        null_bytes = 0
        php_error  = $false
        is_blank   = $false
        auth_gate  = $false
        local_bytes = $null
        notes      = ''
        screenshot = 'BLOCKED - no session cookie'
        permission = 'UNVERIFIED - requires authenticated session'
    }
    if ($LocalPath -and (Test-Path $LocalPath)) {
        $result.local_bytes = (Get-Item $LocalPath).Length
    }
    try {
        $resp = Invoke-WebRequest -Uri $Url -UseBasicParsing -MaximumRedirection 5 -TimeoutSec 30
        $body = [string]$resp.Content
        $result.http_code = [int]$resp.StatusCode
        $result.final_url = [string]$resp.BaseResponse.ResponseUri
        $result.body_len = $body.Length
        $result.null_bytes = ([regex]::Matches($body, "`0")).Count
        $result.php_error = $body -match 'Fatal error|Parse error|Uncaught Error|Stack trace:'
        $result.is_blank = ($body.Trim().Length -lt 80)
        $loginHit = $body -match '(?i)admin login|sign in|login\.php|staff-app\.php'
        $result.auth_gate = $loginHit -or ($result.final_url -match 'login\.php|staff-app\.php')
        if ($result.php_error) {
            $result.status = 'FAIL'
            $result.notes = 'PHP/runtime error in response body'
        } elseif ($result.is_blank) {
            $result.status = 'FAIL'
            $result.notes = 'Blank or near-blank response'
        } elseif ($result.local_bytes -eq 0) {
            $result.status = 'WARNING'
            $result.notes = 'Local file 0 bytes — authenticated view likely blank on server'
        } elseif ($result.null_bytes -gt 0) {
            $result.status = 'WARNING'
            $result.notes = "Null-byte prefix ($($result.null_bytes) chars) - file corruption risk"
        } elseif ($result.auth_gate) {
            $result.status = 'WARNING'
            $result.notes = 'Auth gate OK unauthenticated; authenticated UI not verified'
        } else {
            $result.status = 'PASS'
            $result.notes = 'Reachable with content (unauthenticated probe)'
        }
    } catch {
        $result.status = 'FAIL'
        $result.notes = $_.Exception.Message
    }
    return [pscustomobject]$result
}

function Test-ApiRoute {
    param([string]$Method, [string]$Path, [int]$ExpectCode = 401)
    $url = "$ApiBase/$Path"
    try {
        if ($Method -eq 'GET') {
            $resp = Invoke-WebRequest -Uri $url -Method GET -UseBasicParsing -TimeoutSec 20
            $code = [int]$resp.StatusCode
        } else {
            $resp = Invoke-WebRequest -Uri $url -Method POST -UseBasicParsing -Body '{}' -ContentType 'application/json' -TimeoutSec 20
            $code = [int]$resp.StatusCode
        }
        $json = $false
        try { $null = $resp.Content | ConvertFrom-Json; $json = $true } catch {}
        $status = if ($code -eq $ExpectCode) { 'PASS' } elseif ($code -eq 404) { 'FAIL' } else { 'WARNING' }
        [pscustomobject]@{
            method = $Method
            path   = $Path
            code   = $code
            json   = $json
            status = $status
            notes  = if ($code -eq 404) { 'Route not found' } elseif ($code -eq 401) { 'Auth required (route exists)' } else { "Unexpected $code" }
        }
    } catch {
        $code = 0
        if ($_.Exception.Response) { $code = [int]$_.Exception.Response.StatusCode }
        $status = if ($code -eq $ExpectCode) { 'PASS' } elseif ($code -eq 404) { 'FAIL' } else { 'WARNING' }
        [pscustomobject]@{
            method = $Method
            path   = $Path
            code   = $code
            json   = $false
            status = $status
            notes  = if ($code -eq 404) { 'Route not found' } elseif ($code -eq 401) { 'Auth required (route exists)' } else { $_.Exception.Message }
        }
    }
}

# --- Admin nav pages (from admin-capabilities.php) ---
$adminNav = @(
    @{ label='Dashboard'; url='dashboard.php' },
    @{ label='Queue'; url='staff.php' },
    @{ label='Allocation centre'; url='allocation-centre.php' },
    @{ label='Directory'; url='staff-directory.php' },
    @{ label='Blacklist'; url='blacklist.php' },
    @{ label='Events'; url='events.php' },
    @{ label='Venues'; url='venues.php' },
    @{ label='Work types'; url='work-types.php' },
    @{ label='Attendance'; url='attendance.php' },
    @{ label='Work hours'; url='work-hours.php' },
    @{ label='Manual sign-in'; url='manual-signin.php' },
    @{ label='Messages'; url='staff-inbox.php' },
    @{ label='Communication Hub'; url='communication-hub.php' },
    @{ label='Notifications'; url='notifications.php' },
    @{ label='Performance'; url='workforce-performance.php' },
    @{ label='Risk management'; url='workforce-risk.php' },
    @{ label='Event staffing'; url='event-staffing.php' },
    @{ label='Compliance'; url='compliance-centre.php' },
    @{ label='Documents'; url='staff-documents.php' },
    @{ label='Smart search'; url='staff-search.php' },
    @{ label='Availability'; url='staff-availability.php' },
    @{ label='Reports'; url='operations-reports.php' },
    @{ label='Executive'; url='executive-dashboard.php' },
    @{ label='Audit logs'; url='compliance-audit.php' },
    @{ label='Event rostering'; url='event-rostering.php' },
    @{ label='Recruitment'; url='recruitment-centre.php' },
    @{ label='Training'; url='training-centre.php' },
    @{ label='Payroll prep'; url='payroll-centre.php' },
    @{ label='Communications'; url='communication-centre.php' },
    @{ label='Incidents'; url='incident-centre.php' },
    @{ label='Clients'; url='client-centre.php' },
    @{ label='Contracts'; url='contracts-centre.php' },
    @{ label='Automation'; url='ops-automation.php' },
    @{ label='Invoices'; url='invoices.php' },
    @{ label='Export'; url='export-staff.php' },
    @{ label='Forms'; url='forms.php' },
    @{ label='Apply admin'; url='apply-portal.php' },
    @{ label='Health'; url='system-health.php' },
    @{ label='Feature flags'; url='feature-flags.php' },
    @{ label='Settings'; url='settings-site.php' },
    @{ label='Data integrity'; url='data-integrity.php' },
    @{ label='Activity'; url='audit-log.php' },
    @{ label='Users'; url='users.php' },
    @{ label='Go live'; url='go-live.php' },
    @{ label='Website CMS'; url='website-global.php' },
    @{ label='Ops checklist'; url='ops-checklist.php' },
    @{ label='Visitors'; url='visitor-locations.php' },
    @{ label='Geo audits'; url='geo-audits.php' },
    @{ label='Mobile portal'; url='mobile-portal.php' },
    @{ label='Scan check-in'; url='scan-checkin.php' },
    @{ label='Google Sheets'; url='google-sheets-control.php' },
    @{ label='Backup center'; url='backup-center.php' },
    @{ label='Trust scores'; url='trust-scores.php' },
    @{ label='Command center'; url='command-center.php' },
    @{ label='Unified inbox'; url='unified-inbox.php' },
    @{ label='Payroll intelligence'; url='payroll-intelligence.php' },
    @{ label='Job records'; url='job-records.php' },
    @{ label='Personal invoices'; url='personal-invoices.php' },
    @{ label='Same-day conflicts'; url='same-day-conflicts.php' },
    @{ label='Cleanup report'; url='cleanup-report.php' },
    @{ label='Roster diagnostic'; url='roster-diagnostic.php' }
)

$adminResults = @()
foreach ($p in $adminNav) {
    $local = Join-Path $root "admin\$($p.url)"
    $adminResults += Test-HttpPage -Name $p.label -Url "$AdminBase/$($p.url)" -Portal 'admin' -LocalPath $local
}
$adminCsv = Join-Path $outRoot 'admin-pages.csv'
$adminResults | Export-Csv -Path $adminCsv -NoTypeInformation

# --- Staff portal (user-requested list) ---
$staffPages = @(
    @{ label='Dashboard / Home'; url='staff-app.php' },
    @{ label='Shifts'; url='staff-shifts.php' },
    @{ label='Check-In'; url='staff-checkin.php' },
    @{ label='Availability'; url='staff-availability.php' },
    @{ label='Leave Requests'; url='staff-leave.php' },
    @{ label='Messages'; url='staff-messages.php' },
    @{ label='Notifications'; url='staff-notifications.php' },
    @{ label='Documents'; url='staff-documents.php' },
    @{ label='Certificates'; url='staff-certificates.php' },
    @{ label='Profile'; url='staff-profile-hub.php' },
    @{ label='Settings'; url='staff-settings.php' },
    @{ label='Change Password'; url='staff-change-password.php' },
    @{ label='Support'; url='staff-support.php' },
    @{ label='Profile legacy'; url='staff-profile.php' },
    @{ label='Google sign-in'; url='staff-google-signin.php' }
)
$staffResults = @()
foreach ($p in $staffPages) {
    $local = Join-Path $root $p.url
    $staffResults += Test-HttpPage -Name $p.label -Url "$StaffBase/$($p.url)" -Portal 'staff' -LocalPath $local
}
$staffCsv = Join-Path $outRoot 'staff-pages.csv'
$staffResults | Export-Csv -Path $staffCsv -NoTypeInformation

# --- Apply recruitment workflow URLs ---
$applyPages = @(
    @{ label='Apply home'; url='/' },
    @{ label='Registration wizard'; url='https://register.olasentra.com/index.php' },
    @{ label='Submit'; url='https://register.olasentra.com/submit.php' },
    @{ label='Status'; url='https://register.olasentra.com/status.php' },
    @{ label='Apply admin'; url='https://admin.olasentra.com/admin/apply-portal.php' }
)
$applyResults = @()
foreach ($p in $applyPages) {
    $url = if ($p.url -match '^https?') { $p.url } else { "$ApplyBase$($p.url)" }
    $applyResults += Test-HttpPage -Name $p.label -Url $url -Portal 'apply' -LocalPath ''
}
$applyCsv = Join-Path $outRoot 'apply-workflow.csv'
$applyResults | Export-Csv -Path $applyCsv -NoTypeInformation

# --- Mobile API full route matrix ---
$apiRoutes = @(
    @{ m='GET'; p='config'; e=200 },
    @{ m='POST'; p='auth/otp/send'; e=422 },
    @{ m='POST'; p='auth/otp/verify'; e=422 },
    @{ m='POST'; p='auth/google'; e=422 },
    @{ m='POST'; p='auth/refresh'; e=401 },
    @{ m='POST'; p='auth/logout'; e=401 },
    @{ m='GET'; p='me'; e=401 },
    @{ m='PATCH'; p='me'; e=401 },
    @{ m='GET'; p='dashboard'; e=401 },
    @{ m='GET'; p='shifts'; e=401 },
    @{ m='GET'; p='shifts/today'; e=401 },
    @{ m='POST'; p='checkin'; e=401 },
    @{ m='POST'; p='checkout'; e=401 },
    @{ m='POST'; p='gps/ping'; e=401 },
    @{ m='GET'; p='gps/status'; e=401 },
    @{ m='GET'; p='notifications'; e=401 },
    @{ m='POST'; p='notifications/read-all'; e=401 },
    @{ m='GET'; p='messages'; e=401 },
    @{ m='POST'; p='messages'; e=401 },
    @{ m='POST'; p='push/register'; e=401 },
    @{ m='GET'; p='documents'; e=401 },
    @{ m='GET'; p='availability'; e=401 },
    @{ m='POST'; p='leave'; e=401 },
    @{ m='POST'; p='sync/offline'; e=401 },
    @{ m='GET'; p='events'; e=401 },
    @{ m='POST'; p='events/register'; e=401 },
    @{ m='POST'; p='me/password'; e=401 }
)
$apiResults = @()
foreach ($r in $apiRoutes) {
    if ($r.m -eq 'PATCH') {
        try {
            Invoke-WebRequest -Uri "$ApiBase/$($r.p)" -Method PATCH -UseBasicParsing -Body '{}' -ContentType 'application/json' -TimeoutSec 20 | Out-Null
            $code = 200
        } catch {
            $code = if ($_.Exception.Response) { [int]$_.Exception.Response.StatusCode } else { 0 }
        }
        $status = if ($code -eq $r.e) { 'PASS' } elseif ($code -eq 404) { 'FAIL' } else { 'WARNING' }
        $apiResults += [pscustomobject]@{ method=$r.m; path=$r.p; code=$code; json=$false; status=$status; notes="PATCH probe" }
    } else {
        $apiResults += Test-ApiRoute -Method $r.m -Path $r.p -ExpectCode $r.e
    }
}
$apiCsv = Join-Path $outRoot 'mobile-api.csv'
$apiResults | Export-Csv -Path $apiCsv -NoTypeInformation

# --- Android / Google structural checks (no secret file reads) ---
$androidChecks = @()
$paths = @{
    'google-services.json (fresh)' = 'android/olasentra-fresh/app/google-services.json'
    'google-services.json (staff)' = 'android/olasentra-staff/app/google-services.json'
    'build.gradle.kts (fresh)'     = 'android/olasentra-fresh/app/build.gradle.kts'
    'local.properties.example'     = 'android/olasentra-fresh/local.properties.example'
}
foreach ($k in $paths.Keys) {
    $fp = Join-Path $root $paths[$k]
    $exists = Test-Path $fp
    $len = if ($exists) { (Get-Item $fp).Length } else { 0 }
    $androidChecks += [pscustomobject]@{
        check  = $k
        path   = $paths[$k]
        exists = $exists
        bytes  = $len
        status = if ($exists -and $len -gt 100) { 'PASS' } elseif ($exists) { 'WARNING' } else { 'FAIL' }
        notes  = if (-not $exists) { 'Missing' } elseif ($len -le 100) { 'File too small' } else { 'Present' }
    }
}
$androidCsv = Join-Path $outRoot 'android-google-structural.csv'
$androidChecks | Export-Csv -Path $androidCsv -NoTypeInformation

# --- Summary ---
$allPage = @($adminResults) + @($staffResults) + @($applyResults)
$summary = [ordered]@{
    generated          = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
    phase              = 'Phase 2 Deep Audit'
    baseline           = 'FULL-SYSTEM-AUDIT-2026-06-16.md + .audit-admin.json (167 admin files)'
    pages_tested       = $allPage.Count
    pages_pass         = @($allPage | Where-Object status -eq 'PASS').Count
    pages_warning      = @($allPage | Where-Object status -eq 'WARNING').Count
    pages_fail         = @($allPage | Where-Object status -eq 'FAIL').Count
    apis_tested        = $apiResults.Count
    apis_pass          = @($apiResults | Where-Object status -eq 'PASS').Count
    apis_fail          = @($apiResults | Where-Object status -eq 'FAIL').Count
    zero_byte_admin    = @($adminResults | Where-Object local_bytes -eq 0).Count
    authenticated_ui   = 'BLOCKED - no admin/staff session cookies supplied'
    screenshots        = 'BLOCKED - Playwright/browser not available in audit environment'
    android_device     = 'BLOCKED - requires physical device + screen capture'
    apply_full_workflow = 'BLOCKED - requires form submission + email + DB verification'
    production_readiness_pct = [math]::Round(((@($allPage | Where-Object status -eq 'PASS').Count + @($apiResults | Where-Object status -eq 'PASS').Count) / ($allPage.Count + $apiResults.Count)) * 100, 1)
}
$summaryJson = Join-Path $outRoot 'summary.json'
$summary | ConvertTo-Json | Set-Content -Path $summaryJson -Encoding UTF8

Write-Host "Phase 2 audit complete."
Write-Host "Admin: $($adminResults.Count) | Staff: $($staffResults.Count) | Apply: $($applyResults.Count) | API: $($apiResults.Count)"
Write-Host "PASS/WARN/FAIL pages: $($summary.pages_pass)/$($summary.pages_warning)/$($summary.pages_fail)"
Write-Host "API PASS/FAIL: $($summary.apis_pass)/$($summary.apis_fail)"
Write-Host "Output: $outRoot"
