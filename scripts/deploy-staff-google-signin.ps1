$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    @{ Local = 'includes\staff-google-oauth.php'; Remote = 'includes/staff-google-oauth.php' },
    @{ Local = 'staff-google-signin.php'; Remote = 'staff-google-signin.php' },
    @{ Local = 'staff-google-oauth-callback.php'; Remote = 'staff-google-oauth-callback.php' },
    @{ Local = 'includes\staff-profile-gate.php'; Remote = 'includes/staff-profile-gate.php' },
    @{ Local = 'includes\settings-repository.php'; Remote = 'includes/settings-repository.php' },
    @{ Local = 'includes\admin\settings-handler.php'; Remote = 'includes/admin/settings-handler.php' },
    @{ Local = 'admin\settings-production.php'; Remote = 'admin/settings-production.php' },
    @{ Local = 'staff-app.php'; Remote = 'staff-app.php' },
    @{ Local = 'staff-shifts.php'; Remote = 'staff-shifts.php' },
    @{ Local = 'staff-checkin.php'; Remote = 'staff-checkin.php' },
    @{ Local = 'includes\staff-venue-checkin.php'; Remote = 'includes/staff-venue-checkin.php' },
    @{ Local = 'includes\status-repository.php'; Remote = 'includes/status-repository.php' },
    @{ Local = 'check-in.php'; Remote = 'check-in.php' },
    @{ Local = 'staff-profile-hub.php'; Remote = 'staff-profile-hub.php' },
    @{ Local = 'staff-messages.php'; Remote = 'staff-messages.php' },
    @{ Local = 'includes\staff-app-v3-shell.php'; Remote = 'includes/staff-app-v3-shell.php' },
    @{ Local = 'includes\staff-app-v3-pages.php'; Remote = 'includes/staff-app-v3-pages.php' },
    @{ Local = 'includes\staff-app-v3-data.php'; Remote = 'includes/staff-app-v3-data.php' },
    @{ Local = 'includes\staff-portal-shift.php'; Remote = 'includes/staff-portal-shift.php' },
    @{ Local = 'includes\staff-portal-dashboard.php'; Remote = 'includes/staff-portal-dashboard.php' },
    @{ Local = 'includes\event-sign-flow.php'; Remote = 'includes/event-sign-flow.php' },
    @{ Local = 'includes\staff-app-v3-public.php'; Remote = 'includes/staff-app-v3-public.php' },
    @{ Local = 'includes\attendance-repository.php'; Remote = 'includes/attendance-repository.php' },
    @{ Local = 'includes\attendance-gps-phase15.php'; Remote = 'includes/attendance-gps-phase15.php' },
    @{ Local = 'event-sign.php'; Remote = 'event-sign.php' },
    @{ Local = 'sign-in.php'; Remote = 'sign-in.php' },
    @{ Local = 'assets\css\staff-app-v3.css'; Remote = 'assets/css/staff-app-v3.css' },
    @{ Local = 'assets\js\staff-app-v3.js'; Remote = 'assets/js/staff-app-v3.js' },
    @{ Local = 'staff-portal.php'; Remote = 'staff-portal.php' },
    @{ Local = 'home.php'; Remote = 'home.php' },
    @{ Local = 'includes\public\homepage-live.php'; Remote = 'includes/public/homepage-live.php' },
    @{ Local = 'assets\css\staff-app-v2.css'; Remote = 'assets/css/staff-app-v2.css' },
    @{ Local = 'assets\css\staff-app.css'; Remote = 'assets/css/staff-app.css' },
    @{ Local = 'staff-profile.php'; Remote = 'staff-profile.php' },
    @{ Local = 'includes\staff-app-easy.php'; Remote = 'includes/staff-app-easy.php' },
    @{ Local = 'admin\staff-go-live.php'; Remote = 'admin/staff-go-live.php' },
    @{ Local = 'admin\staff-google-signin-diagnostic.php'; Remote = 'admin/staff-google-signin-diagnostic.php' },
    @{ Local = 'includes\website-content.php'; Remote = 'includes/website-content.php' },
    @{ Local = 'faq.php'; Remote = 'faq.php' },
    @{ Local = 'index.php'; Remote = 'index.php' },
    @{ Local = 'submit.php'; Remote = 'submit.php' },
    @{ Local = 'staff-google-oauth-callback.php'; Remote = 'staff-google-oauth-callback.php' },
    @{ Local = 'includes\registration-google-gate.php'; Remote = 'includes/registration-google-gate.php' },
    @{ Local = 'includes\registration-post-save.php'; Remote = 'includes/registration-post-save.php' },
    @{ Local = 'includes\public\registration-wizard-shell.php'; Remote = 'includes/public/registration-wizard-shell.php' },
    @{ Local = 'includes\psa-licence-verify.php'; Remote = 'includes/psa-licence-verify.php' },
    @{ Local = 'api\psa-licence-verify.php'; Remote = 'api/psa-licence-verify.php' },
    @{ Local = 'assets\js\registration-wizard.js'; Remote = 'assets/js/registration-wizard.js' },
    @{ Local = 'assets\js\registration-wizard-validation.js'; Remote = 'assets/js/registration-wizard-validation.js' },
    @{ Local = 'assets\js\registration-wizard-psa.js'; Remote = 'assets/js/registration-wizard-psa.js' },
    @{ Local = 'assets\js\pwa-install.js'; Remote = 'assets/js/pwa-install.js' }
)

foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

Write-Host 'Deployed staff Google sign-in (enable in Settings after Google Cloud redirect URI).' -ForegroundColor Green
