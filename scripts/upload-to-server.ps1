# Upload changed app files to Namecheap (FTP). Run after git push or any production fix.
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\upload-to-server.ps1
#
# Credentials (first time):
#   Copy deploy.local.ps1.example to deploy.local.ps1 and set FtpPassword from cPanel.
#   Or set env EVENT_STAFF_FTP_PASSWORD for this session only (not saved to disk).

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

# Production bundle (Google Sheets, OAuth, settings). Add paths here when you change other areas.
$files = @(
    @{ Local = 'includes\google-drive-oauth.php'; Remote = 'includes/google-drive-oauth.php' },
    @{ Local = 'includes\google-sheets-sync.php'; Remote = 'includes/google-sheets-sync.php' },
    @{ Local = 'includes\admin\settings-handler.php'; Remote = 'includes/admin/settings-handler.php' },
    @{ Local = 'admin\google-drive-oauth-callback.php'; Remote = 'admin/google-drive-oauth-callback.php' },
    @{ Local = 'admin\settings-production.php'; Remote = 'admin/settings-production.php' },
    @{ Local = 'admin\google-sheets-diagnostic.php'; Remote = 'admin/google-sheets-diagnostic.php' },
    @{ Local = 'admin\events-sheets-action.php'; Remote = 'admin/events-sheets-action.php' },
    @{ Local = 'admin\event-form.php'; Remote = 'admin/event-form.php' },
    @{ Local = 'includes\events-repository.php'; Remote = 'includes/events-repository.php' },
    @{ Local = 'includes\registration-forms.php'; Remote = 'includes/registration-forms.php' },
    @{ Local = 'includes\venues-repository.php'; Remote = 'includes/venues-repository.php' },
    @{ Local = 'includes\staff-registration-schema.php'; Remote = 'includes/staff-registration-schema.php' },
    @{ Local = 'includes\validation.php'; Remote = 'includes/validation.php' },
    @{ Local = 'includes\financial-field-validation.php'; Remote = 'includes/financial-field-validation.php' },
    @{ Local = 'assets\js\financial-field-validation.js'; Remote = 'assets/js/financial-field-validation.js' },
    @{ Local = 'assets\js\app.js'; Remote = 'assets/js/app.js' },
    @{ Local = 'includes\pwa-scripts.php'; Remote = 'includes/pwa-scripts.php' },
    @{ Local = 'includes\admin\layout-bottom.php'; Remote = 'includes/admin/layout-bottom.php' },
    @{ Local = 'includes\staff-repository.php'; Remote = 'includes/staff-repository.php' },
    @{ Local = 'includes\commission-invoice-repository.php'; Remote = 'includes/commission-invoice-repository.php' },
    @{ Local = 'admin\forms.php'; Remote = 'admin/forms.php' },
    @{ Local = 'admin\staff.php'; Remote = 'admin/staff.php' },
    @{ Local = 'admin\staff-directory.php'; Remote = 'admin/staff-directory.php' },
    @{ Local = 'admin\staff-edit.php'; Remote = 'admin/staff-edit.php' },
    @{ Local = 'admin\staff-send-profile-link.php'; Remote = 'admin/staff-send-profile-link.php' },
    @{ Local = 'admin\bulk-status.php'; Remote = 'admin/bulk-status.php' },
    @{ Local = 'admin\update-status.php'; Remote = 'admin/update-status.php' },
    @{ Local = 'admin\export.php'; Remote = 'admin/export.php' },
    @{ Local = 'staff-portal.php'; Remote = 'staff-portal.php' },
    @{ Local = 'staff-profile.php'; Remote = 'staff-profile.php' },
    @{ Local = 'staff-app.php'; Remote = 'staff-app.php' },
    @{ Local = 'staff-profile.php'; Remote = 'staff-profile.php' },
    @{ Local = 'staff-portal.php'; Remote = 'staff-portal.php' },
    @{ Local = 'includes\staff-profile-gate.php'; Remote = 'includes/staff-profile-gate.php' },
    @{ Local = 'admin\settings-production.php'; Remote = 'admin/settings-production.php' },
    @{ Local = 'includes\admin\settings-handler.php'; Remote = 'includes/admin/settings-handler.php' },
    @{ Local = 'submit.php'; Remote = 'submit.php' },
    @{ Local = 'status.php'; Remote = 'status.php' },
    @{ Local = 'check-in.php'; Remote = 'check-in.php' },
    @{ Local = 'includes\staff-onboarding.php'; Remote = 'includes/staff-onboarding.php' },
    @{ Local = 'includes\notifications.php'; Remote = 'includes/notifications.php' },
    @{ Local = 'includes\notification-center.php'; Remote = 'includes/notification-center.php' },
    @{ Local = 'includes\notification-center-schema.php'; Remote = 'includes/notification-center-schema.php' },
    @{ Local = 'includes\components\notification-list.php'; Remote = 'includes/components/notification-list.php' },
    @{ Local = 'includes\components\whatsapp-join.php'; Remote = 'includes/components/whatsapp-join.php' },
    @{ Local = 'includes\apply-remote-sync.php'; Remote = 'includes/apply-remote-sync.php' },
    @{ Local = 'includes\settings-repository.php'; Remote = 'includes/settings-repository.php' },
    @{ Local = 'admin\notifications.php'; Remote = 'admin/notifications.php' },
    @{ Local = 'admin\staff-delete.php'; Remote = 'admin/staff-delete.php' },
    @{ Local = 'admin\apply-sync-ping.php'; Remote = 'admin/apply-sync-ping.php' },
    @{ Local = 'admin\settings-site.php'; Remote = 'admin/settings-site.php' },
    @{ Local = 'api\notifications.php'; Remote = 'api/notifications.php' },
    @{ Local = 'api\notifications-mark-read.php'; Remote = 'api/notifications-mark-read.php' },
    @{ Local = 'staff-notifications.php'; Remote = 'staff-notifications.php' },
    @{ Local = 'assets\css\notifications.css'; Remote = 'assets/css/notifications.css' },
    @{ Local = 'assets\js\notifications.js'; Remote = 'assets/js/notifications.js' },
    @{ Local = 'includes\admin\sidebar.php'; Remote = 'includes/admin/sidebar.php' },
    @{ Local = 'includes\admin\nav-icons.php'; Remote = 'includes/admin/nav-icons.php' },
    @{ Local = 'includes\access-pass-email.php'; Remote = 'includes/access-pass-email.php' },
    @{ Local = 'includes\reminders.php'; Remote = 'includes/reminders.php' },
    @{ Local = 'includes\staff-psa.php'; Remote = 'includes/staff-psa.php' },
    @{ Local = 'admin\settings-email.php'; Remote = 'admin/settings-email.php' },
    @{ Local = 'includes\status-psa-form.php'; Remote = 'includes/status-psa-form.php' },
    @{ Local = 'includes\validation.php'; Remote = 'includes/validation.php' },
    @{ Local = 'includes\staff-repository.php'; Remote = 'includes/staff-repository.php' },
    @{ Local = 'api\registrant-lookup.php'; Remote = 'api/registrant-lookup.php' },
    @{ Local = 'assets\js\app.js'; Remote = 'assets/js/app.js' },
    @{ Local = 'assets\js\returning-registrant.js'; Remote = 'assets/js/returning-registrant.js' },
    @{ Local = 'assets\css\staff-app.css'; Remote = 'assets/css/staff-app.css' },
    @{ Local = 'includes\staff-portal-session.php'; Remote = 'includes/staff-portal-session.php' },
    @{ Local = 'includes\staff-profile-email.php'; Remote = 'includes/staff-profile-email.php' },
    @{ Local = 'includes\staff-employee-export.php'; Remote = 'includes/staff-employee-export.php' },
    @{ Local = 'includes\admin-capabilities.php'; Remote = 'includes/admin-capabilities.php' },
    @{ Local = 'includes\status-repository.php'; Remote = 'includes/status-repository.php' },
    @{ Local = 'assets\css\admin.css'; Remote = 'assets/css/admin.css' },
    @{ Local = 'assets\css\mobile.css'; Remote = 'assets/css/mobile.css' },
    @{ Local = 'assets\css\public-front.css'; Remote = 'assets/css/public-front.css' },
    @{ Local = 'includes\google-sheets-sync.php'; Remote = 'includes/google-sheets-sync.php' },
    @{ Local = 'admin\export-staff.php'; Remote = 'admin/export-staff.php' },
    @{ Local = 'index.php'; Remote = 'index.php' },
    @{ Local = 'includes\phone-numbers.php'; Remote = 'includes/phone-numbers.php' },
    @{ Local = 'includes\components\phone-input.php'; Remote = 'includes/components/phone-input.php' },
    @{ Local = 'assets\js\phone-input.js'; Remote = 'assets/js/phone-input.js' },
    @{ Local = 'lang\en.php'; Remote = 'lang/en.php' },
    @{ Local = 'lang\es.php'; Remote = 'lang/es.php' },
    @{ Local = 'assets\css\registration-compact.css'; Remote = 'assets/css/registration-compact.css' },
    @{ Local = 'lang\en.php'; Remote = 'lang/en.php' },
    @{ Local = 'lang\es.php'; Remote = 'lang/es.php' },
    @{ Local = 'includes\system-cleanup.php'; Remote = 'includes/system-cleanup.php' },
    @{ Local = 'admin\system-cleanup.php'; Remote = 'admin/system-cleanup.php' },
    @{ Local = 'admin\go-live.php'; Remote = 'admin/go-live.php' },
    @{ Local = 'includes\go-live.php'; Remote = 'includes/go-live.php' },
    @{ Local = 'includes\production-readiness.php'; Remote = 'includes/production-readiness.php' },
    @{ Local = 'admin\apply-portal.php'; Remote = 'admin/apply-portal.php' },
    @{ Local = 'includes\apply-sso.php'; Remote = 'includes/apply-sso.php' },
    @{ Local = 'includes\site-urls.php'; Remote = 'includes/site-urls.php' }
)

$extra = $args | Where-Object { $_ -match '\.(php|css|js)$' }
foreach ($path in $extra) {
    $norm = $path -replace '/', '\'
    if (Test-Path (Join-Path $ProjectRoot $norm)) {
        $remote = $path -replace '\\', '/'
        $files += @{ Local = $norm; Remote = $remote }
    }
}

Write-Host "Main admin -> $($cfg.FtpServer)$($cfg.FtpRemoteDir)" -ForegroundColor Green
$seen = @{}
foreach ($f in $files) {
    if ($seen[$f.Remote]) { continue }
    $seen[$f.Remote] = $true
    Send-FtpFile -LocalPath (Join-Path $ProjectRoot $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

Write-Host ''
Write-Host 'Done.' -ForegroundColor Green
Write-Host '  https://admin.olasentra.com/google-sheets-diagnostic.php'
Write-Host '  https://admin.olasentra.com/settings-production.php#google-sheets'
Write-Host ''
