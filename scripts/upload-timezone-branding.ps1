# Deploy Irish time fix, email logo URL, GTBank orange staff app.
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir

$files = @(
    @{ Local = 'includes\date-format.php'; Remote = 'includes/date-format.php' },
    @{ Local = 'includes\system-settings.php'; Remote = 'includes/system-settings.php' },
    @{ Local = 'includes\brand-logo.php'; Remote = 'includes/brand-logo.php' },
    @{ Local = 'includes\email-branding.php'; Remote = 'includes/email-branding.php' },
    @{ Local = 'includes\attendance-repository.php'; Remote = 'includes/attendance-repository.php' },
    @{ Local = 'includes\admin\attendance-roster-row.php'; Remote = 'includes/admin/attendance-roster-row.php' },
    @{ Local = 'assets\css\staff-app-v3.css'; Remote = 'assets/css/staff-app-v3.css' },
    @{ Local = 'includes\staff-app-v3-shell.php'; Remote = 'includes/staff-app-v3-shell.php' },
    @{ Local = 'includes\staff-app-v3-public.php'; Remote = 'includes/staff-app-v3-public.php' },
    @{ Local = 'cron\verify-timezone-email-branding.php'; Remote = 'cron/verify-timezone-email-branding.php' }
)

Write-Host 'Uploading timezone + email logo + GTBank orange...' -ForegroundColor Green
foreach ($entry in $files) {
    $local = Join-Path $ProjectRoot $entry.Local
    if (-not (Test-Path $local)) {
        throw "Missing local file: $local"
    }
    Send-FtpFile -LocalPath $local -RemoteRelativePath $entry.Remote -RemoteBase $base -Deploy $cfg
}
Write-Host 'Deploy complete.' -ForegroundColor Green
