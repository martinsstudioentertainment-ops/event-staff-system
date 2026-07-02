$ErrorActionPreference = 'Continue'
$pages = @(
  @{ Name='Marketing Home'; Url='https://olasentra.com/home.php' },
  @{ Name='Roles'; Url='https://olasentra.com/roles.php' },
  @{ Name='Events Page'; Url='https://olasentra.com/events-page.php' },
  @{ Name='How It Works'; Url='https://olasentra.com/how-it-works.php' },
  @{ Name='Contact'; Url='https://olasentra.com/contact.php' },
  @{ Name='FAQ'; Url='https://olasentra.com/faq.php' },
  @{ Name='Privacy'; Url='https://olasentra.com/privacy.php' },
  @{ Name='Terms'; Url='https://olasentra.com/terms.php' },
  @{ Name='Registration'; Url='https://register.olasentra.com/' },
  @{ Name='Staff App Home'; Url='https://register.olasentra.com/staff-app.php' },
  @{ Name='Staff Shifts'; Url='https://register.olasentra.com/staff-shifts.php' },
  @{ Name='Staff Check-in'; Url='https://register.olasentra.com/staff-checkin.php' },
  @{ Name='Staff Messages'; Url='https://register.olasentra.com/staff-messages.php' },
  @{ Name='Staff Profile Hub'; Url='https://register.olasentra.com/staff-profile-hub.php' },
  @{ Name='Staff Notifications'; Url='https://register.olasentra.com/staff-notifications.php' },
  @{ Name='Admin Login'; Url='https://admin.olasentra.com/admin/login.php' },
  @{ Name='Admin Dashboard'; Url='https://admin.olasentra.com/admin/dashboard.php' },
  @{ Name='Admin Staff Queue'; Url='https://admin.olasentra.com/admin/staff.php' },
  @{ Name='Admin Events'; Url='https://admin.olasentra.com/admin/events.php' },
  @{ Name='Admin Attendance'; Url='https://admin.olasentra.com/admin/attendance.php' },
  @{ Name='Admin Settings General'; Url='https://admin.olasentra.com/admin/settings-site.php' },
  @{ Name='Admin Settings Production'; Url='https://admin.olasentra.com/admin/settings-production.php' },
  @{ Name='Admin Settings Email'; Url='https://admin.olasentra.com/admin/settings-email.php' },
  @{ Name='Admin Staff Inbox'; Url='https://admin.olasentra.com/admin/staff-inbox.php' },
  @{ Name='Admin Website Global'; Url='https://admin.olasentra.com/admin/website-global.php' },
  @{ Name='Admin Availability'; Url='https://admin.olasentra.com/admin/staff-availability.php' },
  @{ Name='Admin Executive'; Url='https://admin.olasentra.com/admin/executive-dashboard.php' },
  @{ Name='Admin Communications Hub'; Url='https://admin.olasentra.com/admin/communication-hub.php' },
  @{ Name='Admin Compliance'; Url='https://admin.olasentra.com/admin/compliance-centre.php' },
  @{ Name='Admin Geo Audits'; Url='https://admin.olasentra.com/admin/geo-audits.php' },
  @{ Name='Admin Go Live'; Url='https://admin.olasentra.com/admin/go-live.php' },
  @{ Name='Admin Invoices'; Url='https://admin.olasentra.com/admin/invoices.php' },
  @{ Name='Admin Forms'; Url='https://admin.olasentra.com/admin/forms.php' },
  @{ Name='Check-in Public'; Url='https://register.olasentra.com/check-in.php' },
  @{ Name='Offline PWA'; Url='https://register.olasentra.com/offline.php' }
)

foreach ($p in $pages) {
  try {
    $r = Invoke-WebRequest -Uri $p.Url -UseBasicParsing -TimeoutSec 25 -MaximumRedirection 5
    $len = $r.Content.Length
    $title = ''
    if ($r.Content -match '<title[^>]*>([^<]+)</title>') { $title = ($matches[1] -replace '\s+', ' ').Trim() }
    $blank = ($len -lt 100)
    $hasError = ($r.Content -match 'Fatal error|Parse error|500 Internal')
    $status = if ($blank) { 'BROKEN_BLANK' } elseif ($hasError) { 'BROKEN_ERROR' } elseif ($title -match 'Login|Sign in') { 'AUTH_REDIRECT' } else { 'REACHABLE' }
    Write-Output "$status|$r.StatusCode|$len|$title|$p.Name|$p.Url"
  } catch {
    $code = if ($_.Exception.Response) { [int]$_.Exception.Response.StatusCode } else { 0 }
    Write-Output "ERROR|$code|0|$($_.Exception.Message)|$p.Name|$p.Url"
  }
}
