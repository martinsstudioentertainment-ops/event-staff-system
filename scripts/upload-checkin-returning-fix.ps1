# Deploy check-in rules, returning registration, status display fixes.
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir

$files = @(
    @{ Local = 'includes\attendance-repository.php'; Remote = 'includes/attendance-repository.php' },
    @{ Local = 'includes\staff-app-v3-data.php'; Remote = 'includes/staff-app-v3-data.php' },
    @{ Local = 'includes\staff-venue-checkin.php'; Remote = 'includes/staff-venue-checkin.php' },
    @{ Local = 'includes\staff-profile-gate.php'; Remote = 'includes/staff-profile-gate.php' },
    @{ Local = 'includes\registration-returning-profile.php'; Remote = 'includes/registration-returning-profile.php' },
    @{ Local = 'includes\components\staff-status-dashboard.php'; Remote = 'includes/components/staff-status-dashboard.php' },
    @{ Local = 'api\registrant-lookup.php'; Remote = 'api/registrant-lookup.php' },
    @{ Local = 'index.php'; Remote = 'index.php' },
    @{ Local = 'submit.php'; Remote = 'submit.php' },
    @{ Local = 'assets\js\returning-registrant.js'; Remote = 'assets/js/returning-registrant.js' },
    @{ Local = 'assets\js\registration-wizard.js'; Remote = 'assets/js/registration-wizard.js' },
    @{ Local = 'assets\js\registration-wizard-validation.js'; Remote = 'assets/js/registration-wizard-validation.js' },
    @{ Local = 'assets\js\registration-wizard-review.js'; Remote = 'assets/js/registration-wizard-review.js' },
    @{ Local = 'assets\js\registration-wizard-psa.js'; Remote = 'assets/js/registration-wizard-psa.js' },
    @{ Local = 'assets\js\registration-wizard-autosave.js'; Remote = 'assets/js/registration-wizard-autosave.js' },
    @{ Local = 'assets\js\registration-wizard-server-restore.js'; Remote = 'assets/js/registration-wizard-server-restore.js' },
    @{ Local = 'cron\repair-no-show-with-checkin.php'; Remote = 'cron/repair-no-show-with-checkin.php' }
)

Write-Host 'Uploading check-in + returning registration fixes...' -ForegroundColor Green
foreach ($entry in $files) {
    $local = Join-Path $ProjectRoot $entry.Local
    if (-not (Test-Path $local)) {
        throw "Missing: $local"
    }
    Send-FtpFile -LocalPath $local -RemoteRelativePath $entry.Remote -RemoteBase $base -Deploy $cfg
}
Write-Host 'Done.' -ForegroundColor Green
