# Stabilization deploy — upload restored/critical files to production (FTP only).
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$paths = @(
    'includes/mobile/mobile-router.php',
    'includes/mobile/controllers/ProfileController.php',
    'includes/mobile/services/MobileEmailOtpAuthService.php',
    'api/registration-google-verify.php',
    'api/registration-email-otp-send.php',
    'api/registration-email-otp-verify.php',
    'admin/communication-centre.php',
    'admin/contracts-centre.php',
    'admin/communication-hub.php',
    'admin/contract-centre.php',
    'admin/staff-inbox.php',
    'admin/website-global.php',
    'admin/staff-availability.php',
    'admin/staff-documents.php',
    'admin/staff-search.php',
    'admin/mobile-api-qa.php',
    'admin/executive-dashboard.php',
    'admin/recruitment-centre.php',
    'admin/training-centre.php',
    'admin/compliance-centre.php',
    'admin/compliance-audit.php',
    'admin/event-rostering.php',
    'admin/event-staffing.php',
    'admin/workforce-performance.php',
    'admin/workforce-risk.php',
    'admin/data-integrity.php',
    'admin/geo-audits.php',
    'admin/client-centre.php',
    'admin/incident-centre.php',
    'admin/event-hub.php',
    'admin/auto-approval.php',
    'includes/admin/system-health.php',
    'includes/admin/website-handler.php',
    'staff-checkin.php',
    'staff-shifts.php',
    'staff-messages.php',
    'staff-google-signin.php',
    'staff-google-oauth-callback.php',
    'assets/css/style.css',
    'assets/js/registration-wizard.js',
    'assets/js/staff-app-v3.js'
)

$args = @()
foreach ($rel in $paths) {
    $full = Join-Path $ProjectRoot $rel
    if ((Test-Path $full) -and ((Get-Item $full).Length -gt 0)) {
        $args += $rel
    }
}
& (Join-Path $ProjectRoot 'scripts\upload-to-server.ps1') @args
