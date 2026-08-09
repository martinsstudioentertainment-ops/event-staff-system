# Full-site HTTP error audit — all olasentra.com properties (read-only probes)
param(
    [string]$OutCsv = 'docs/audit-full-site-errors-2026-06-22.csv',
    [int]$TimeoutSec = 20
)

$ErrorActionPreference = 'Continue'
$root = Split-Path $PSScriptRoot -Parent
$outPath = Join-Path $root $OutCsv
New-Item -ItemType Directory -Force -Path (Split-Path $outPath) | Out-Null

$bases = @(
    @{ Portal = 'marketing'; Base = 'https://olasentra.com'; Prefix = '' },
    @{ Portal = 'register';  Base = 'https://register.olasentra.com'; Prefix = '' },
    @{ Portal = 'admin';     Base = 'https://admin.olasentra.com'; Prefix = '/admin' },
    @{ Portal = 'apply';     Base = 'https://apply.olasentra.com'; Prefix = '' }
)

$skipRoot = @(
    'config.php', 'config.production.example.php', '_tmp_prod_signout.php',
    'import-summer-roster.php', 'setup-check.php', 'wizard-screenshot-preview.php',
    'status-screenshot-preview.php', 'admin-manifest.php'
)

$skipAdmin = @('config.php')

function Add-Url {
    param([System.Collections.Generic.List[object]]$List, [string]$Portal, [string]$Url, [string]$Source)
    if ($Url -match '(/includes/|/vendor/|/database/|\.example\.php|/config\.php$)') { return }
    $key = "$Portal|$Url"
    if ($script:seen.Contains($key)) { return }
    [void]$script:seen.Add($key)
    $List.Add([pscustomobject]@{ Portal = $Portal; Url = $Url; Source = $Source })
}

$script:seen = [System.Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
$urls = [System.Collections.Generic.List[object]]::new()

# Root PHP on register + marketing (same codebase, both hosts)
Get-ChildItem -Path (Join-Path $root '*.php') -File | ForEach-Object {
    $name = $_.Name
    if ($skipRoot -contains $name) { return }
    Add-Url $urls 'register' "https://register.olasentra.com/$name" "root:$name"
    Add-Url $urls 'marketing' "https://olasentra.com/$name" "root:$name"
}

# Admin PHP
Get-ChildItem -Path (Join-Path $root 'admin\*.php') -File | ForEach-Object {
    $name = $_.Name
    if ($skipAdmin -contains $name) { return }
    Add-Url $urls 'admin' "https://admin.olasentra.com/admin/$name" "admin:$name"
}

# Marketing-only extras
@(
    'https://olasentra.com/',
    'https://olasentra.com/home.php',
    'https://www.olasentra.com/',
    'https://www.olasentra.com/home.php'
) | ForEach-Object { Add-Url $urls 'marketing' $_ 'marketing-extra' }

# Apply portal (public PHP only)
$applyPublic = @(
    'index.php', 'login.php', 'front.php', 'staff-list.php', 'sso.php', 'update-details.php', 'import-main-staff.php'
)
foreach ($f in $applyPublic) {
    Add-Url $urls 'apply' "https://apply.olasentra.com/$f" "apply:$f"
}
Add-Url $urls 'apply' 'https://apply.olasentra.com/' 'apply:root'
@(
    'admin/index.php', 'admin/login.php', 'admin/dashboard.php', 'admin/staff-list.php',
    'admin/applicants.php', 'admin/payroll.php', 'admin/settings.php', 'admin/view-staff.php'
) | ForEach-Object { Add-Url $urls 'apply' "https://apply.olasentra.com/$_" "apply:$_" }

# Public API (register host)
Get-ChildItem -Path (Join-Path $root 'api\*.php') -File | ForEach-Object {
    Add-Url $urls 'register' "https://register.olasentra.com/api/$($_.Name)" "api:$($_.Name)"
}
Add-Url $urls 'register' 'https://register.olasentra.com/api/mobile/v1/config' 'api:mobile-v1-config'
Add-Url $urls 'admin' 'https://admin.olasentra.com/api/mobile/v1/config' 'api:mobile-v1-config-admin'

# Static / PWA
@('manifest.php', 'sw.js', 'og-image.php') | ForEach-Object {
    Add-Url $urls 'register' "https://register.olasentra.com/$_" "static:$_"
    Add-Url $urls 'marketing' "https://olasentra.com/$_" "static:$_"
}

function Test-ProbeUrl {
    param([string]$Portal, [string]$Url, [string]$Source)
    $row = [ordered]@{
        portal      = $Portal
        url         = $Url
        source      = $Source
        http_status = 0
        status_class = 'error'
        category    = 'connection_error'
        body_len    = 0
        php_error   = $false
        is_blank    = $false
        final_url   = ''
        notes       = ''
    }
    try {
        $resp = Invoke-WebRequest -Uri $Url -UseBasicParsing -MaximumRedirection 5 -TimeoutSec $TimeoutSec
        $body = [string]$resp.Content
        $code = [int]$resp.StatusCode
        $row.http_status = $code
        $row.body_len = $body.Length
        $row.final_url = [string]$resp.BaseResponse.ResponseUri
        $row.php_error = $body -match 'Fatal error|Parse error|Uncaught Error|Stack trace:|<b>Warning</b>.*on line'
        $row.is_blank = ($body.Trim().Length -lt 60)

        if ($row.php_error) {
            $row.status_class = 'error'
            $row.category = 'php_runtime_error'
            $row.notes = 'PHP/runtime error in response body'
        } elseif ($code -ge 500) {
            $row.status_class = 'error'
            $row.category = "http_$code"
        } elseif ($code -eq 404) {
            $row.status_class = 'error'
            $row.category = 'http_404'
        } elseif ($code -eq 403 -and $Url -match 'probe-|registrant-lookup') {
            $row.status_class = 'ok'
            $row.category = 'auth_required'
            $row.notes = '403 — protected endpoint (expected without key)'
        } elseif ($code -eq 403) {
            $row.status_class = 'error'
            $row.category = 'http_403'
        } elseif ($code -eq 401) {
            $row.status_class = 'ok'
            $row.category = 'auth_required'
            $row.notes = '401 — auth required (expected for protected API)'
        } elseif ($code -eq 405) {
            $row.status_class = 'ok'
            $row.category = 'method_not_allowed'
            $row.notes = '405 on GET — likely POST-only action endpoint'
        } elseif ($row.is_blank -and $code -eq 200) {
            $row.status_class = 'error'
            $row.category = 'blank_page'
            $row.notes = '200 but near-blank body'
        } elseif ($code -ge 400) {
            $row.status_class = 'error'
            $row.category = "http_$code"
        } elseif ($row.final_url -match 'login\.php|staff-app\.php|staff-google-signin') {
            $row.status_class = 'ok'
            $row.category = 'auth_redirect'
            $row.notes = 'Redirects to login (expected without session)'
        } else {
            $row.status_class = 'ok'
            $row.category = 'ok'
        }
    } catch {
        $code = 0
        if ($_.Exception.Response) {
            try { $code = [int]$_.Exception.Response.StatusCode } catch { $code = 0 }
        }
        $row.http_status = $code
        $row.notes = ($_.Exception.Message -replace '\s+', ' ').Trim()

        if ($code -eq 404) {
            $row.status_class = 'error'
            $row.category = 'http_404'
        } elseif ($code -eq 403 -and $Url -match 'probe-|registrant-lookup') {
            $row.status_class = 'ok'
            $row.category = 'auth_required'
        } elseif ($code -eq 403) {
            $row.status_class = 'error'
            $row.category = 'http_403'
        } elseif ($code -ge 500) {
            $row.status_class = 'error'
            $row.category = "http_$code"
        } elseif ($code -eq 401) {
            $row.status_class = 'ok'
            $row.category = 'auth_required'
        } elseif ($code -eq 405) {
            $row.status_class = 'ok'
            $row.category = 'method_not_allowed'
        } elseif ($code -ge 400) {
            $row.status_class = 'error'
            $row.category = "http_$code"
        } else {
            $row.status_class = 'error'
            $row.category = 'connection_error'
        }
    }
    return [pscustomobject]$row
}

Write-Host "Probing $($urls.Count) URLs..." -ForegroundColor Cyan
$results = @()
$i = 0
foreach ($u in $urls) {
    $i++
    if ($i % 25 -eq 0) { Write-Host "  $i / $($urls.Count)" }
    $results += Test-ProbeUrl -Portal $u.Portal -Url $u.Url -Source $u.Source
    Start-Sleep -Milliseconds 80
}

$results | Export-Csv -Path $outPath -NoTypeInformation

$errors = $results | Where-Object { $_.status_class -eq 'error' }
$byCat = $errors | Group-Object category | Sort-Object Count -Descending

$summary = [ordered]@{
    probed_total   = $results.Count
    ok_total       = ($results | Where-Object { $_.status_class -eq 'ok' }).Count
    error_total    = $errors.Count
    http_404       = ($errors | Where-Object { $_.category -eq 'http_404' }).Count
    http_403       = ($errors | Where-Object { $_.category -eq 'http_403' }).Count
    http_500       = ($errors | Where-Object { $_.http_status -eq 500 }).Count
    http_502       = ($errors | Where-Object { $_.http_status -eq 502 }).Count
    http_503       = ($errors | Where-Object { $_.http_status -eq 503 }).Count
    php_errors     = ($errors | Where-Object { $_.category -eq 'php_runtime_error' }).Count
    blank_pages    = ($errors | Where-Object { $_.category -eq 'blank_page' }).Count
    connection_err = ($errors | Where-Object { $_.category -eq 'connection_error' }).Count
    other_4xx_5xx  = ($errors | Where-Object {
        $_.category -match '^http_' -and $_.category -notin @('http_404','http_403') -and $_.http_status -notin 500,502,503
    }).Count
}

$summaryPath = $outPath -replace '\.csv$', '-summary.json'
$summary | ConvertTo-Json | Set-Content -Path $summaryPath -Encoding UTF8

Write-Host ''
Write-Host '=== AUDIT SUMMARY ===' -ForegroundColor Yellow
$summary.GetEnumerator() | ForEach-Object { Write-Host ("{0}: {1}" -f $_.Key, $_.Value) }

Write-Host ''
Write-Host 'Errors by category:' -ForegroundColor Yellow
$byCat | ForEach-Object { Write-Host ("  {0}: {1}" -f $_.Name, $_.Count) }

Write-Host ''
Write-Host "Full CSV: $outPath" -ForegroundColor Green
