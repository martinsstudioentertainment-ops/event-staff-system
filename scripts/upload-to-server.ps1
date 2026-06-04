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

$localDeploy = Join-Path $ProjectRoot 'deploy.local.ps1'
if (-not (Test-Path $localDeploy)) {
    Write-Host 'Missing deploy.local.ps1 - copy deploy.local.ps1.example and add FTP password.' -ForegroundColor Red
    exit 1
}

$cfg = & $localDeploy
if ($env:EVENT_STAFF_FTP_PASSWORD) {
    $cfg.FtpPassword = $env:EVENT_STAFF_FTP_PASSWORD
}

foreach ($key in @('FtpServer', 'FtpUser', 'FtpPassword', 'FtpRemoteDir')) {
    if ([string]::IsNullOrWhiteSpace($cfg[$key])) {
        throw "deploy.local.ps1: '$key' is empty."
    }
}
if ($cfg.FtpPassword -eq 'YOUR_FTP_PASSWORD') {
    throw 'Set FtpPassword in deploy.local.ps1 (cPanel FTP Accounts) or env EVENT_STAFF_FTP_PASSWORD.'
}

function Get-FtpUri {
    param([string]$Server, [string]$RemoteDir, [string]$RelativePath)
    $dir = $RemoteDir.TrimEnd('/')
    $rel = $RelativePath.TrimStart('/')
    return "ftp://$Server$dir/$rel"
}

function Send-FtpFile {
    param([string]$LocalPath, [string]$RemoteRelativePath, [hashtable]$Deploy)
    if (-not (Test-Path $LocalPath)) {
        throw "Local file missing: $LocalPath"
    }
    $uri = Get-FtpUri -Server $Deploy.FtpServer -RemoteDir $Deploy.FtpRemoteDir -RelativePath $RemoteRelativePath
    Write-Host "  Upload $RemoteRelativePath" -ForegroundColor Cyan
    $client = New-Object System.Net.WebClient
    $client.Credentials = New-Object System.Net.NetworkCredential($Deploy.FtpUser, $Deploy.FtpPassword)
    try {
        $client.UploadFile($uri, $LocalPath)
    } finally {
        $client.Dispose()
    }
}

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
    @{ Local = 'submit.php'; Remote = 'submit.php' },
    @{ Local = 'status.php'; Remote = 'status.php' },
    @{ Local = 'check-in.php'; Remote = 'check-in.php' },
    @{ Local = 'includes\staff-onboarding.php'; Remote = 'includes/staff-onboarding.php' },
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
    @{ Local = 'lang\en.php'; Remote = 'lang/en.php' },
    @{ Local = 'lang\es.php'; Remote = 'lang/es.php' },
    @{ Local = 'includes\system-cleanup.php'; Remote = 'includes/system-cleanup.php' },
    @{ Local = 'admin\system-cleanup.php'; Remote = 'admin/system-cleanup.php' },
    @{ Local = 'admin\go-live.php'; Remote = 'admin/go-live.php' },
    @{ Local = 'scripts\cpanel-deploy.sh'; Remote = 'scripts/cpanel-deploy.sh' }
)

$extra = $args | Where-Object { $_ -match '\.php$' }
foreach ($path in $extra) {
    $norm = $path -replace '/', '\'
    if (Test-Path (Join-Path $ProjectRoot $norm)) {
        $remote = $path -replace '\\', '/'
        $files += @{ Local = $norm; Remote = $remote }
    }
}

Write-Host 'Uploading to ' $cfg.FtpServer $cfg.FtpRemoteDir ' ...' -ForegroundColor Green
$seen = @{}
foreach ($f in $files) {
    if ($seen[$f.Remote]) { continue }
    $seen[$f.Remote] = $true
    Send-FtpFile -LocalPath (Join-Path $ProjectRoot $f.Local) -RemoteRelativePath $f.Remote -Deploy $cfg
}

Write-Host ''
Write-Host 'Done.' -ForegroundColor Green
Write-Host '  https://admin.olasentra.com/google-sheets-diagnostic.php'
Write-Host '  https://admin.olasentra.com/settings-production.php#google-sheets'
Write-Host ''
