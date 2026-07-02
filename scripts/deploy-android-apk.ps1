# Deploy native Android APK to register.olasentra.com (FTP + apply settings on server).
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\deploy-android-apk.ps1
#   powershell -ExecutionPolicy Bypass -File .\scripts\deploy-android-apk.ps1 -Version 1.0.11

param(
    [string]$Version = '1.0.11',
    [string]$ApkSource = '',
    [string]$Notes = 'Light theme dashboard, 12-tile overview, Scan/Menu bottom nav, dark mode in Settings.'
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

if ($ApkSource -eq '') {
    $ApkSource = Join-Path $Root "android\olasentra-staff\app\build\outputs\apk\production\debug\app-production-debug.apk"
}

if (-not (Test-Path $ApkSource)) {
    throw "APK not found: $ApkSource`nBuild first: cd android\olasentra-staff; .\gradlew.bat :app:assembleProductionDebug"
}

$apkDir = Join-Path $Root 'storage\mobile\android'
New-Item -ItemType Directory -Force -Path $apkDir | Out-Null

$apkName = "olasentra-staff-v$Version.apk"
$apkLocal = Join-Path $apkDir $apkName
Copy-Item -Path $ApkSource -Destination $apkLocal -Force
Write-Host "Prepared $apkLocal ($((Get-Item $apkLocal).Length) bytes)" -ForegroundColor Green

$files = @(
    @{ Local = 'staff-app-download.php'; Remote = 'staff-app-download.php' },
    @{ Local = 'includes\staff-app-android.php'; Remote = 'includes/staff-app-android.php' },
    @{ Local = 'includes\settings-repository.php'; Remote = 'includes/settings-repository.php' },
    @{ Local = 'includes\mobile\services\MobileConfigService.php'; Remote = 'includes/mobile/services/MobileConfigService.php' },
    @{ Local = 'includes\mobile\services\MobilePortalConfigService.php'; Remote = 'includes/mobile/services/MobilePortalConfigService.php' },
    @{ Local = 'admin\mobile-portal.php'; Remote = 'admin/mobile-portal.php' },
    @{ Local = 'cron\apply-android-app-release.php'; Remote = 'cron/apply-android-app-release.php' },
    @{ Local = "storage\mobile\android\$apkName"; Remote = "storage/mobile/android/$apkName" }
)

Write-Host 'Uploading Android release files...' -ForegroundColor Green
Ensure-FtpDirectoryTree -Server $cfg.FtpServer -RemoteDir "$($cfg.FtpRemoteDir)/storage/mobile/android" -Deploy $cfg

foreach ($f in $files) {
    $localPath = Join-Path $Root $f.Local
    if (-not (Test-Path $localPath)) {
        throw "Missing local file: $($f.Local)"
    }
    $uri = Get-FtpUri -Server $cfg.FtpServer -RemoteDir $cfg.FtpRemoteDir -RelativePath $f.Remote
    Write-Host "  -> $($f.Remote)" -ForegroundColor Cyan
    $client = New-Object System.Net.WebClient
    $client.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)
    try {
        $client.UploadFile($uri, $localPath)
    } finally {
        $client.Dispose()
    }
}

$encodedNotes = [uri]::EscapeDataString($Notes)
$applyUrl = "https://register.olasentra.com/cron/apply-android-app-release.php?key=email-encoding-verify-20260606&version=$Version&notes=$encodedNotes"
Write-Host "Applying release settings on server..." -ForegroundColor Cyan
$resp = Invoke-RestMethod -Uri $applyUrl -Method Get -TimeoutSec 120
$resp | ConvertTo-Json -Depth 5

$configUrl = 'https://register.olasentra.com/api/mobile/v1/config'
Write-Host "Verifying Mobile API config..." -ForegroundColor Cyan
$config = Invoke-RestMethod -Uri $configUrl -Method Get -TimeoutSec 60
Write-Host ("  mobile_api_enabled: {0}" -f $config.mobile_api_enabled)
Write-Host ("  portal.version.label: {0}" -f $config.portal.version.label)
Write-Host ("  android_apk_download_url: {0}" -f $config.android_apk_download_url)

$downloadUrl = [string]$config.android_apk_download_url
if ($downloadUrl -eq '') {
    throw 'Config missing android_apk_download_url after apply.'
}

Write-Host "Verifying APK download endpoint..." -ForegroundColor Cyan
try {
    $head = Invoke-WebRequest -Uri $downloadUrl -Method Head -TimeoutSec 120
    Write-Host ("  HTTP {0}, Content-Type: {1}, Length: {2}" -f $head.StatusCode, $head.Headers['Content-Type'], $head.Headers['Content-Length'])
} catch {
    Write-Host "  HEAD failed, trying GET stream check..." -ForegroundColor Yellow
    $get = Invoke-WebRequest -Uri $downloadUrl -Method Get -TimeoutSec 120
    Write-Host ("  HTTP {0}, bytes: {1}" -f $get.StatusCode, $get.RawContentLength)
}

Write-Host ''
Write-Host 'Android APK deployed.' -ForegroundColor Green
Write-Host "  Download: $downloadUrl" -ForegroundColor Gray
Write-Host "  Version:  $Version" -ForegroundColor Gray
