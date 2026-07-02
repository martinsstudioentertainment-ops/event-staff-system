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

Write-Host 'Phase 12A post-deploy verification' -ForegroundColor Cyan

$status = Get-Text (Invoke-WebRequest -Uri "$base/status.php" -UseBasicParsing -TimeoutSec 30)
Report ($status -like '*Application status*') 'Status page title' 'status.php'
Report ($status -like '*staff-app-v3.css*') 'V3 CSS linked' 'status.php'
Report ($status -like '*es-v3*') 'V3 shell markup' 'status.php'
Report ($status -notlike '*Fatal error*' -and $status -notlike '*Parse error*') 'No PHP fatal on status' 'status.php'

$css = Get-Text (Invoke-WebRequest -Uri "$base/assets/css/staff-app-v3.css" -UseBasicParsing -TimeoutSec 30)
Report ($css -like '*Phase 12A*') 'Phase 12A CSS deployed' 'staff-app-v3.css'
Report ($css -like '*status-dash__metric-grid*') 'Metric grid CSS live' 'staff-app-v3.css'
Report ($css -like '*es-v3--status-page*') 'Status page padding CSS live' 'staff-app-v3.css'

Write-Host ''
Write-Host "Failures: $fail" -ForegroundColor $(if ($fail -eq 0) { 'Green' } else { 'Red' })
exit $(if ($fail -eq 0) { 0 } else { 1 })
