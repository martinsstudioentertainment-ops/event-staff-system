$urls = @(
  'https://olasentra.com/home.php',
  'https://olasentra.com/roles.php',
  'https://olasentra.com/events-page.php',
  'https://olasentra.com/how-it-works.php',
  'https://olasentra.com/contact.php',
  'https://olasentra.com/faq.php',
  'https://olasentra.com/privacy.php',
  'https://olasentra.com/terms.php',
  'https://register.olasentra.com/',
  'https://register.olasentra.com/staff-app.php',
  'https://register.olasentra.com/staff-shifts.php',
  'https://register.olasentra.com/staff-checkin.php',
  'https://register.olasentra.com/staff-messages.php',
  'https://register.olasentra.com/staff-profile-hub.php',
  'https://register.olasentra.com/staff-notifications.php',
  'https://admin.olasentra.com/admin/login.php',
  'https://admin.olasentra.com/admin/dashboard.php',
  'https://admin.olasentra.com/admin/staff.php',
  'https://admin.olasentra.com/admin/events.php',
  'https://admin.olasentra.com/admin/attendance.php',
  'https://admin.olasentra.com/admin/settings-site.php',
  'https://admin.olasentra.com/admin/settings-production.php',
  'https://admin.olasentra.com/admin/settings-email.php',
  'https://admin.olasentra.com/admin/staff-inbox.php',
  'https://admin.olasentra.com/admin/website-global.php',
  'https://admin.olasentra.com/admin/staff-availability.php',
  'https://admin.olasentra.com/admin/executive-dashboard.php',
  'https://admin.olasentra.com/api/mobile/v1/config',
  'https://register.olasentra.com/api/mobile/v1/config'
)

foreach ($u in $urls) {
  try {
    $r = Invoke-WebRequest -Uri $u -UseBasicParsing -TimeoutSec 25 -MaximumRedirection 5
    $len = $r.Content.Length
    $title = ''
    if ($r.Content -match '<title[^>]*>([^<]+)</title>') { $title = $matches[1].Trim() }
    $blank = ($len -lt 50)
    Write-Output "$r.StatusCode|$len|$blank|$title|$u"
  } catch {
    $code = if ($_.Exception.Response) { [int]$_.Exception.Response.StatusCode } else { 0 }
    Write-Output "$code|0|error|$($_.Exception.Message)|$u"
  }
}
