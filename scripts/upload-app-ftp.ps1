# Upload app PHP files to Namecheap via FTP (when cPanel Git pull fails).
# Prerequisite: deploy.local.ps1 with real FtpPassword (cPanel → FTP Accounts).
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\upload-app-ftp.ps1

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$localDeploy = Join-Path $ProjectRoot 'deploy.local.ps1'
if (-not (Test-Path $localDeploy)) {
    Write-Host 'Missing deploy.local.ps1 — copy deploy.local.ps1.example and add FTP password.' -ForegroundColor Red
    exit 1
}

$cfg = & $localDeploy
foreach ($key in @('FtpServer', 'FtpUser', 'FtpPassword', 'FtpRemoteDir')) {
    if ([string]::IsNullOrWhiteSpace($cfg[$key])) {
        throw "deploy.local.ps1: '$key' is empty."
    }
}
if ($cfg.FtpPassword -eq 'YOUR_FTP_PASSWORD') {
    throw 'Edit deploy.local.ps1: set FtpPassword from cPanel → FTP Accounts.'
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

$files = @(
    @{ Local = 'includes\google-sheets-sync.php'; Remote = 'includes/google-sheets-sync.php' },
    @{ Local = 'admin\google-sheets-diagnostic.php'; Remote = 'admin/google-sheets-diagnostic.php' },
    @{ Local = 'admin\settings-production.php'; Remote = 'admin/settings-production.php' },
    @{ Local = 'admin\event-form.php'; Remote = 'admin/event-form.php' },
    @{ Local = 'includes\events-repository.php'; Remote = 'includes/events-repository.php' },
    @{ Local = 'admin\events-sheets-action.php'; Remote = 'admin/events-sheets-action.php' }
)

Write-Host 'Uploading Google Sheets + related fixes to server ...' -ForegroundColor Green
foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $ProjectRoot $f.Local) -RemoteRelativePath $f.Remote -Deploy $cfg
}

Write-Host ''
Write-Host 'Done. Open:' -ForegroundColor Green
Write-Host '  https://admin.olasentra.com/google-sheets-diagnostic.php'
Write-Host '  → Purge test spreadsheets → Run create test (once)'
Write-Host ''
