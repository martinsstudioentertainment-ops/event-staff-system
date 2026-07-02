$ErrorActionPreference = 'Continue'
$pages = @(
  @{ Name = 'Staff App Home'; Url = 'https://register.olasentra.com/staff-app.php' },
  @{ Name = 'Staff Shifts'; Url = 'https://register.olasentra.com/staff-shifts.php' },
  @{ Name = 'Staff Check-in'; Url = 'https://register.olasentra.com/staff-checkin.php' },
  @{ Name = 'Staff Profile Hub'; Url = 'https://register.olasentra.com/staff-profile-hub.php' },
  @{ Name = 'Staff Messages'; Url = 'https://register.olasentra.com/staff-messages.php' },
  @{ Name = 'Registration'; Url = 'https://register.olasentra.com/' }
)

foreach ($p in $pages) {
  try {
    $r = Invoke-WebRequest -Uri $p.Url -UseBasicParsing -TimeoutSec 25 -MaximumRedirection 5
    $len = $r.Content.Length
    $title = ''
    if ($r.Content -match '<title[^>]*>([^<]+)</title>') { $title = ($matches[1] -replace '\s+', ' ').Trim() }
    $blank = ($len -lt 100)
    $hasError = ($r.Content -match 'Fatal error|Parse error|500 Internal')
    $status = if ($blank) { 'BROKEN_BLANK' } elseif ($hasError) { 'BROKEN_ERROR' } elseif ($title -match 'Login|Sign in|Olasentra') { 'AUTH_OR_OK' } else { 'REACHABLE' }
    Write-Output "$status|$($r.StatusCode)|$len|$title|$($p.Name)|$($p.Url)"
  } catch {
    $code = if ($_.Exception.Response) { [int]$_.Exception.Response.StatusCode } else { 0 }
    Write-Output "ERROR|$code|0|$($_.Exception.Message)|$($p.Name)|$($p.Url)"
  }
}
