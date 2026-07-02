# Final stabilization deploy — Olasentra v1.0 Stable polish bundle.
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\upload-safe-fix-bundle.ps1

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

$versionPath = Join-Path $ProjectRoot 'storage\version.json'
$gitCommit = ''
try {
    $gitCommit = (git -C $ProjectRoot rev-parse HEAD 2>$null)
    if ($LASTEXITCODE -ne 0) { $gitCommit = '' }
} catch {
    $gitCommit = ''
}

$buildNumber = [int](Get-Date -Format 'yyyyMMddHH')
if (Test-Path $versionPath) {
    $existing = Get-Content $versionPath -Raw | ConvertFrom-Json
    if ($existing.build -ge $buildNumber) {
        $buildNumber = [int]$existing.build + 1
    }
}

$versionDoc = [ordered]@{
    version     = '1.0.0'
    build       = $buildNumber
    deployed_at = (Get-Date).ToUniversalTime().ToString('o')
    git_commit  = $gitCommit
    label       = 'v1.0-stable'
}
$json = $versionDoc | ConvertTo-Json -Depth 3
[System.IO.File]::WriteAllText($versionPath, $json, [System.Text.UTF8Encoding]::new($false))
Write-Host "Build stamp: v$($versionDoc.version) #$($versionDoc.build) ($($versionDoc.label))" -ForegroundColor Cyan

$files = @(
    '.htaccess',
    'r.php',
    'index.php',
    'submit.php',
    'status.php',
    'staff-app.php',
    'staff-notifications.php',
    'staff-checkin.php',
    'admin/system-health.php',
    'api/mobile/index.php',
    'api/mobile/.htaccess',
    'api/registration-email-otp-send.php',
    'api/registration-email-otp-verify.php',
    'api/registration-google-verify.php',
    'api/staff-portal-otp-send.php',
    'api/staff-portal-otp-verify.php',
    'api/staff-offline-sync.php',
    'api/push-vapid-public.php',
    'api/attendance-gps-ping.php',
    'api/staff-shift-gps.php',
    'api/signin-location-verify.php',
    'api/events.php',
    'api/registrant-lookup.php',
    'api/roster-check.php',
    'api/probe-reg-submit.php',
    'api/probe-reg-save.php',
    'api/probe-dash2.php',
    'api/mobile-config-probe.php',
    'api/mobile-dashboard-probe.php',
    'includes/app-environment.php',
    'includes/staff-google-oauth.php',
    'includes/staff-blacklist.php',
    'includes/staff-psa.php',
    'includes/registration-short-links.php',
    'includes/registration-google-gate.php',
    'includes/staff-app-easy.php',
    'includes/staff-portal-email-otp.php',
    'includes/staff-profile-gate.php',
    'includes/staff-venue-checkin.php',
    'includes/staff-app-v3-pages.php',
    'includes/staff-app-v3-shell.php',
    'includes/staff-app-v3-data.php',
    'includes/status-psa-form.php',
    'includes/attendance-repository.php',
    'includes/checkin-bib.php',
    'includes/components/whatsapp-join.php',
    'includes/app-build-version.php',
    'includes/admin/settings-handler.php',
    'includes/admin/system-health.php',
    'includes/mobile/mobile-router.php',
    'includes/mobile/mobile-auth.php',
    'includes/mobile/services/MobileAuthService.php',
    'includes/mobile/services/MobileEmailOtpAuthService.php',
    'includes/mobile/services/MobileOtpService.php',
    'includes/mobile/services/MobileConfigService.php',
    'includes/mobile/services/MobilePreferencesService.php',
    'includes/mobile/services/MobilePortalConfigService.php',
    'assets/js/registration-email-otp.js',
    'assets/js/staff-portal-email-otp.js',
    'assets/js/registration-wizard.js',
    'assets/js/registration-wizard-autosave.js',
    'assets/js/registration-wizard-validation.js',
    'assets/js/registration-wizard-review.js',
    'assets/js/app.js',
    'assets/css/staff-app-v3.css',
    'storage/version.json'
)

Write-Host 'Uploading Olasentra v1.0 Stable polish bundle...' -ForegroundColor Green
foreach ($remote in $files) {
    $local = Join-Path $ProjectRoot ($remote -replace '/', '\')
    if (-not (Test-Path $local)) {
        throw "Missing local file: $local"
    }
    Send-FtpFile -LocalPath $local -RemoteRelativePath $remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
    Write-Host "  -> $remote" -ForegroundColor Gray
}

Write-Host 'Olasentra v1.0 Stable polish bundle upload complete.' -ForegroundColor Green
