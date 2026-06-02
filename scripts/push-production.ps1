# Upload production config.php (and ensure storage/logs) to Namecheap via FTP.
# Prerequisite: copy deploy.local.ps1.example → deploy.local.ps1 and fill in credentials.
#
# Run from project root:
#   powershell -ExecutionPolicy Bypass -File .\scripts\push-production.ps1

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$localDeploy = Join-Path $ProjectRoot 'deploy.local.ps1'
if (-not (Test-Path $localDeploy)) {
    Write-Host ''
    Write-Host 'Missing deploy.local.ps1' -ForegroundColor Red
    Write-Host '  1. Copy deploy.local.ps1.example to deploy.local.ps1'
    Write-Host '  2. Edit FTP + MySQL values from cPanel'
    Write-Host '  3. Run this script again'
    Write-Host ''
    exit 1
}

$cfg = & $localDeploy
foreach ($key in @('FtpServer', 'FtpUser', 'FtpPassword', 'FtpRemoteDir', 'DbName', 'DbUser', 'DbPass')) {
    if ([string]::IsNullOrWhiteSpace($cfg[$key])) {
        throw "deploy.local.ps1: '$key' is empty."
    }
}

$dbHost = if ($cfg.DbHost) { $cfg.DbHost } else { 'localhost' }
$regUrl = if ($cfg.RegistrationUrl) { $cfg.RegistrationUrl } else { 'https://olasentra.com' }
$admUrl = if ($cfg.AdminUrl) { $cfg.AdminUrl } else { 'https://olasentra.com/admin' }

$env:DB_HOST = $dbHost
$env:DB_NAME = $cfg.DbName
$env:DB_USER = $cfg.DbUser
$env:DB_PASS = $cfg.DbPass
$env:REGISTRATION_SITE_URL = $regUrl
$env:ADMIN_SITE_URL = $admUrl

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    $laragonPhp = Get-ChildItem -Path 'C:\laragon\bin\php\*\php.exe' -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($laragonPhp) { $php = $laragonPhp.FullName } else { throw 'PHP not found. Start Laragon or add php to PATH.' }
} else {
    $php = $php.Source
}

Write-Host 'Generating config.php ...' -ForegroundColor Cyan
& $php (Join-Path $ProjectRoot 'scripts\generate-production-config.php')
$configPath = Join-Path $ProjectRoot 'config.php'
if (-not (Test-Path $configPath)) {
    throw 'config.php was not generated.'
}

function Get-FtpUri {
    param([string]$Server, [string]$RemoteDir, [string]$RelativePath)
    $dir = $RemoteDir.TrimEnd('/')
    $rel = $RelativePath.TrimStart('/')
    return "ftp://$Server$dir/$rel"
}

function Send-FtpFile {
    param(
        [string]$LocalPath,
        [string]$RemoteRelativePath,
        [hashtable]$Deploy
    )
    $uri = Get-FtpUri -Server $Deploy.FtpServer -RemoteDir $Deploy.FtpRemoteDir -RelativePath $RemoteRelativePath
    Write-Host "  Upload $RemoteRelativePath" -ForegroundColor DarkGray
    $client = New-Object System.Net.WebClient
    $client.Credentials = New-Object System.Net.NetworkCredential($Deploy.FtpUser, $Deploy.FtpPassword)
    try {
        $client.UploadFile($uri, $LocalPath)
    } finally {
        $client.Dispose()
    }
}

function Ensure-FtpDirectory {
    param(
        [string]$RemoteRelativePath,
        [hashtable]$Deploy
    )
    $uri = Get-FtpUri -Server $Deploy.FtpServer -RemoteDir $Deploy.FtpRemoteDir -RelativePath $RemoteRelativePath
    $request = [System.Net.FtpWebRequest]::Create($uri)
    $request.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
    $request.Credentials = New-Object System.Net.NetworkCredential($Deploy.FtpUser, $Deploy.FtpPassword)
    $request.UsePassive = $true
    try {
        $response = $request.GetResponse()
        $response.Close()
    } catch {
        # Directory may already exist
    }
}

Write-Host 'Uploading via FTP ...' -ForegroundColor Cyan
Send-FtpFile -LocalPath $configPath -RemoteRelativePath 'config.php' -Deploy $cfg

Ensure-FtpDirectory -RemoteRelativePath 'storage' -Deploy $cfg
Ensure-FtpDirectory -RemoteRelativePath 'storage/logs' -Deploy $cfg
$gitkeep = Join-Path $ProjectRoot 'storage\logs\.gitkeep'
if (Test-Path $gitkeep) {
    Send-FtpFile -LocalPath $gitkeep -RemoteRelativePath 'storage/logs/.gitkeep' -Deploy $cfg
}

Write-Host ''
Write-Host 'Done. Test in browser:' -ForegroundColor Green
Write-Host '  https://olasentra.com/api/health.php'
Write-Host '  https://olasentra.com/admin/login.php'
Write-Host ''
Write-Host 'If database still shows error, reset MySQL password in cPanel and update DbPass in deploy.local.ps1, then run this script again.'
Write-Host ''

# Remove generated config from project root if it was local-only (optional keep for debug)
# User local config.php might be overwritten - warn
Write-Host 'Note: config.php in this folder was overwritten with production settings.' -ForegroundColor Yellow
Write-Host 'Restore Laragon local config from config.production.example / your backup if you develop locally.' -ForegroundColor Yellow
