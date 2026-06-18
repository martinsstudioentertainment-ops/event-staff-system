# Deploy native Android APK + AAB to register.olasentra.com (FTP + apply release on server).
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\deploy-android-apk.ps1
#   powershell -ExecutionPolicy Bypass -File .\scripts\deploy-android-apk.ps1 -Version 1.0.17 -VersionCode 17

param(
    [string]$Version = '',
    [int]$VersionCode = 0,
    [string]$ApkSource = '',
    [string]$AabSource = '',
    [string]$Notes = ''
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

$GradleFile = Join-Path $Root 'android\olasentra-staff\app\build.gradle.kts'
if ($Version -eq '' -and (Test-Path $GradleFile)) {
    $gradle = Get-Content $GradleFile -Raw
    if ($gradle -match 'versionName\s*=\s*"([^"]+)"') { $Version = $Matches[1] }
    if ($VersionCode -eq 0 -and $gradle -match 'versionCode\s*=\s*(\d+)') { $VersionCode = [int]$Matches[1] }
}

if ($Version -eq '') {
    $Version = '1.0.17'
}
if ($VersionCode -le 0) {
    $VersionCode = 17
}

if ($ApkSource -eq '') {
    $releaseApk = Join-Path $Root "android\olasentra-staff\app\build\outputs\apk\production\release\app-production-release.apk"
    $debugApk = Join-Path $Root "android\olasentra-staff\app\build\outputs\apk\production\debug\app-production-debug.apk"
    if (Test-Path $releaseApk) {
        $ApkSource = $releaseApk
    } elseif (Test-Path $debugApk) {
        $ApkSource = $debugApk
        Write-Host 'Warning: using debug APK — run a release build for production.' -ForegroundColor Yellow
    } else {
        throw "APK not found. Build first:`n  powershell -ExecutionPolicy Bypass -File .\scripts\build-and-deploy-android-release.ps1 -SkipDeploy"
    }
}

if (-not (Test-Path $ApkSource)) {
    throw "APK not found: $ApkSource"
}

$apkDir = Join-Path $Root 'storage\mobile\android'
New-Item -ItemType Directory -Force -Path $apkDir | Out-Null

$apkName = "olasentra-staff-v$Version.apk"
$apkLocal = Join-Path $apkDir $apkName
Copy-Item -Path $ApkSource -Destination $apkLocal -Force
Write-Host "Prepared $apkLocal ($((Get-Item $apkLocal).Length) bytes)" -ForegroundColor Green

$aabName = "olasentra-staff-v$Version.aab"
$aabLocal = Join-Path $apkDir $aabName
$uploadAab = $false

if ($AabSource -ne '' -and (Test-Path $AabSource)) {
    Copy-Item -Path $AabSource -Destination $aabLocal -Force
    $uploadAab = $true
    Write-Host "Prepared $aabLocal ($((Get-Item $aabLocal).Length) bytes)" -ForegroundColor Green
} else {
    $defaultAab = Join-Path $Root "android\olasentra-staff\app\build\outputs\bundle\productionRelease\app-production-release.aab"
    if (Test-Path $defaultAab) {
        Copy-Item -Path $defaultAab -Destination $aabLocal -Force
        $uploadAab = $true
        Write-Host "Prepared $aabLocal ($((Get-Item $aabLocal).Length) bytes)" -ForegroundColor Green
    }
}

if ($Notes -eq '') {
    $Notes = "Olasentra staff app v$Version (build $VersionCode)."
}

$files = @(
    @{ Local = 'staff-app-download.php'; Remote = 'staff-app-download.php' },
    @{ Local = 'includes\staff-app-android.php'; Remote = 'includes/staff-app-android.php' },
    @{ Local = 'includes\mobile\schema\mobile-app-release-schema.php'; Remote = 'includes/mobile/schema/mobile-app-release-schema.php' },
    @{ Local = 'includes\settings-repository.php'; Remote = 'includes/settings-repository.php' },
    @{ Local = 'includes\mobile\services\MobileConfigService.php'; Remote = 'includes/mobile/services/MobileConfigService.php' },
    @{ Local = 'admin\mobile-app-releases.php'; Remote = 'admin/mobile-app-releases.php' },
    @{ Local = 'admin\mobile-app-release-action.php'; Remote = 'admin/mobile-app-release-action.php' },
    @{ Local = 'admin\settings-mobile-portal.php'; Remote = 'admin/settings-mobile-portal.php' },
    @{ Local = 'cron\apply-android-app-release.php'; Remote = 'cron/apply-android-app-release.php' },
    @{ Local = "storage\mobile\android\$apkName"; Remote = "storage/mobile/android/$apkName" }
)

if ($uploadAab) {
    $files += @{ Local = "storage\mobile\android\$aabName"; Remote = "storage/mobile/android/$aabName" }
}

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
$applyUrl = "https://register.olasentra.com/cron/apply-android-app-release.php?key=email-encoding-verify-20260606&version=$Version&build=$VersionCode&notes=$encodedNotes"
Write-Host 'Applying release settings on server...' -ForegroundColor Cyan
$resp = Invoke-RestMethod -Uri $applyUrl -Method Get -TimeoutSec 120
$resp | ConvertTo-Json -Depth 5

$configUrl = 'https://register.olasentra.com/api/mobile/v1/config'
Write-Host 'Verifying Mobile API config...' -ForegroundColor Cyan
$config = Invoke-RestMethod -Uri $configUrl -Method Get -TimeoutSec 60
Write-Host ("  mobile_api_enabled: {0}" -f $config.mobile_api_enabled)
Write-Host ("  portal.version.label: {0}" -f $config.portal.version.label)
Write-Host ("  android_apk_download_url: {0}" -f $config.android_apk_download_url)
Write-Host ("  android_apk_page_url: {0}" -f $config.android_apk_page_url)

$downloadUrl = [string]$config.android_apk_download_url
if ($downloadUrl -eq '') {
    throw 'Config missing android_apk_download_url after apply.'
}

Write-Host 'Verifying APK download endpoint...' -ForegroundColor Cyan
try {
    $head = Invoke-WebRequest -Uri $downloadUrl -Method Head -TimeoutSec 120
    Write-Host ("  HTTP {0}, Content-Type: {1}, Length: {2}" -f $head.StatusCode, $head.Headers['Content-Type'], $head.Headers['Content-Length'])
} catch {
    Write-Host '  HEAD failed, trying GET stream check...' -ForegroundColor Yellow
    $get = Invoke-WebRequest -Uri $downloadUrl -Method Get -TimeoutSec 120
    Write-Host ("  HTTP {0}, bytes: {1}" -f $get.StatusCode, $get.RawContentLength)
}

$pageUrl = [string]$config.android_apk_page_url
if ($pageUrl -ne '') {
    Write-Host 'Verifying download page...' -ForegroundColor Cyan
    $page = Invoke-WebRequest -Uri $pageUrl -Method Get -TimeoutSec 60
    Write-Host ("  HTTP {0}, page length: {1}" -f $page.StatusCode, $page.RawContentLength)
}

Write-Host ''
Write-Host 'Android release deployed.' -ForegroundColor Green
Write-Host "  Page:     $pageUrl" -ForegroundColor Gray
Write-Host "  Download: $downloadUrl" -ForegroundColor Gray
Write-Host "  Version:  $Version (build $VersionCode)" -ForegroundColor Gray
