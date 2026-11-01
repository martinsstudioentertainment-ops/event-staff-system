$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    @{ Local = 'submit.php'; Remote = 'submit.php' },
    @{ Local = 'index.php'; Remote = 'index.php' },
    @{ Local = 'admin\dashboard.php'; Remote = 'admin/dashboard.php' },
    @{ Local = 'includes\pwa-install-analytics.php'; Remote = 'includes/pwa-install-analytics.php' },
    @{ Local = 'includes\production-readiness.php'; Remote = 'includes/production-readiness.php' },
    @{ Local = 'assets\js\registration-wizard.js'; Remote = 'assets/js/registration-wizard.js' },
    @{ Local = 'assets\js\registration-wizard-validation.js'; Remote = 'assets/js/registration-wizard-validation.js' },
    @{ Local = 'assets\js\registration-wizard-autosave.js'; Remote = 'assets/js/registration-wizard-autosave.js' },
    @{ Local = 'assets\js\registration-wizard-review.js'; Remote = 'assets/js/registration-wizard-review.js' },
    @{ Local = 'assets\css\registration-wizard.css'; Remote = 'assets/css/registration-wizard.css' },
    @{ Local = 'includes\reminders.php'; Remote = 'includes/reminders.php' },
    @{ Local = 'includes\staff-app-easy.php'; Remote = 'includes/staff-app-easy.php' },
    @{ Local = 'includes\staff-app-v3-shell.php'; Remote = 'includes/staff-app-v3-shell.php' }
)

foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
    Write-Host "Uploaded $($f.Remote)" -ForegroundColor Green
}

Write-Host 'Registration + reminders + branding deploy complete.' -ForegroundColor Cyan
