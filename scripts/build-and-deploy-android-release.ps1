# Build production APK + AAB, then deploy to register.olasentra.com.
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\build-and-deploy-android-release.ps1
#   powershell -ExecutionPolicy Bypass -File .\scripts\build-and-deploy-android-release.ps1 -Notes "Google Sign-In fix"
#   powershell -ExecutionPolicy Bypass -File .\scripts\build-and-deploy-android-release.ps1 -SkipDeploy
#   powershell -ExecutionPolicy Bypass -File .\scripts\build-and-deploy-android-release.ps1 -SkipBuild

param(
    [string]$Notes = '',
    [switch]$SkipBuild,
    [switch]$SkipDeploy
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

$GradleFile = Join-Path $Root 'android\olasentra-staff\app\build.gradle.kts'
if (-not (Test-Path $GradleFile)) {
    throw "Missing $GradleFile"
}

$gradle = Get-Content $GradleFile -Raw
if ($gradle -notmatch 'versionName\s*=\s*"([^"]+)"') {
    throw 'Could not read versionName from build.gradle.kts'
}
$Version = $Matches[1]

if ($gradle -notmatch 'versionCode\s*=\s*(\d+)') {
    throw 'Could not read versionCode from build.gradle.kts'
}
$VersionCode = [int]$Matches[1]

Write-Host "=== Olasentra Android release v$Version (build $VersionCode) ===" -ForegroundColor Cyan

$AndroidDir = Join-Path $Root 'android\olasentra-staff'
$ApkOut = Join-Path $AndroidDir 'app\build\outputs\apk\production\release\app-production-release.apk'
$AabOut = Join-Path $AndroidDir 'app\build\outputs\bundle\productionRelease\app-production-release.aab'

if (-not $SkipBuild) {
    Set-Location $AndroidDir
    Write-Host 'Building productionRelease APK + AAB...' -ForegroundColor Green
    .\gradlew.bat assembleProductionRelease bundleProductionRelease --no-daemon
    if ($LASTEXITCODE -ne 0) {
        throw "Gradle build failed with exit code $LASTEXITCODE"
    }
    Set-Location $Root

    if (-not (Test-Path $ApkOut)) {
        throw "APK not found after build: $ApkOut"
    }
    if (-not (Test-Path $AabOut)) {
        throw "AAB not found after build: $AabOut"
    }
} else {
    if (-not (Test-Path $ApkOut)) {
        throw "SkipBuild set but APK missing: $ApkOut"
    }
    if (-not (Test-Path $AabOut)) {
        throw "SkipBuild set but AAB missing: $AabOut"
    }
}

$storageDir = Join-Path $Root 'storage\mobile\android'
$archiveDir = Join-Path $storageDir 'archive'
$playDir = Join-Path $Root 'play-store-assets'
New-Item -ItemType Directory -Force -Path $storageDir, $archiveDir, $playDir | Out-Null

$apkName = "olasentra-staff-v$Version.apk"
$aabName = "olasentra-staff-v$Version.aab"
$apkLocal = Join-Path $storageDir $apkName
$aabLocal = Join-Path $storageDir $aabName

# Archive previous current APK/AAB with same version prefix if replacing
foreach ($existing in Get-ChildItem -Path $storageDir -Filter 'olasentra-staff-v*.apk' -File -ErrorAction SilentlyContinue) {
    if ($existing.Name -ne $apkName) {
        $dest = Join-Path $archiveDir $existing.Name
        if (-not (Test-Path $dest)) {
            Copy-Item $existing.FullName $dest -Force
            Write-Host "Archived $($existing.Name)" -ForegroundColor DarkGray
        }
    }
}

Copy-Item -Path $ApkOut -Destination $apkLocal -Force
Copy-Item -Path $AabOut -Destination $aabLocal -Force
Copy-Item -Path $AabOut -Destination (Join-Path $playDir $aabName) -Force

Write-Host "Prepared APK: $apkLocal ($((Get-Item $apkLocal).Length) bytes)" -ForegroundColor Green
Write-Host "Prepared AAB: $aabLocal ($((Get-Item $aabLocal).Length) bytes)" -ForegroundColor Green
Write-Host "Play copy:    $(Join-Path $playDir $aabName)" -ForegroundColor Green

if ($SkipDeploy) {
    Write-Host 'SkipDeploy set — local artifacts only.' -ForegroundColor Yellow
    exit 0
}

$deployArgs = @{
    Version     = $Version
    VersionCode = $VersionCode
    ApkSource   = $apkLocal
    AabSource   = $aabLocal
}
if ($Notes -ne '') {
    $deployArgs['Notes'] = $Notes
}

& (Join-Path $Root 'scripts\deploy-android-apk.ps1') @deployArgs
