# Upload enterprise email system files to production (admin + register roots).
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

$files = @(
    'includes/email-branding.php',
    'includes/email-layout.php',
    'includes/email-copy.php',
    'includes/mailer.php',
    'includes/notifications.php',
    'includes/notification-center.php',
    'includes/reminders.php',
    'includes/event-staff-alerts.php',
    'includes/access-pass-email.php',
    'includes/admin-login-otp.php',
    'includes/staff-profile-email.php',
    'includes/staff-messaging.php',
    'includes/staff-app-v3-shell.php',
    'includes/settings-repository.php'
)

foreach ($remote in $files) {
    $local = $remote -replace '/', '\'
    Write-Host "  -> $remote" -ForegroundColor Gray
    Send-FtpFile -LocalPath (Join-Path $ProjectRoot $local) -RemoteRelativePath $remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

Write-Host 'Email system deployed to main site.' -ForegroundColor Green
