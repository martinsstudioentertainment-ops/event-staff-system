# Build signed Google Play AAB for Olasentra Staff (production release).
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\build-play-aab.ps1
#   powershell -ExecutionPolicy Bypass -File .\scripts\build-play-aab.ps1 -Version 1.0.15
#
# Requires:
#   - JDK 17 (JAVA_HOME)
#   - Android SDK (local.properties sdk.dir)
#   - android/olasentra-staff/keystore.properties + olasentra-staff-release.jks
#
# Output:
#   play-store-assets/olasentra-staff-v{Version}.aab
#   (copied from app/build/outputs/bundle/productionRelease/)

param(
    [string]$Version = '1.0.16'
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$AndroidDir = Join-Path $Root 'android\olasentra-staff'
$OutDir = Join-Path $Root 'play-store-assets'
$Jdk = 'C:\Program Files\Microsoft\jdk-17.0.19.10-hotspot'

if (-not (Test-Path $Jdk)) {
    throw "JDK 17 not found at $Jdk - install Microsoft OpenJDK 17 or set JAVA_HOME."
}

$env:JAVA_HOME = $Jdk
$env:Path = "$env:JAVA_HOME\bin;" + [System.Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [System.Environment]::GetEnvironmentVariable('Path', 'User')

$keystoreProps = Join-Path $AndroidDir 'keystore.properties'
$keystoreJks = Join-Path $AndroidDir 'olasentra-staff-release.jks'
if (-not (Test-Path $keystoreProps) -or -not (Test-Path $keystoreJks)) {
    throw "Missing release keystore. Expected:`n  $keystoreProps`n  $keystoreJks"
}

Set-Location $AndroidDir
Write-Host "Building productionRelease AAB (v$Version)..." -ForegroundColor Green
.\gradlew.bat :app:bundleProductionRelease --no-daemon --rerun-tasks
if ($LASTEXITCODE -ne 0) {
    throw "Gradle bundleProductionRelease failed with exit code $LASTEXITCODE"
}

$bundlePath = Join-Path $AndroidDir 'app\build\outputs\bundle\productionRelease\app-production-release.aab'
if (-not (Test-Path $bundlePath)) {
    throw "AAB not found after build: $bundlePath"
}

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
$dest = Join-Path $OutDir "olasentra-staff-v$Version.aab"
Copy-Item -Force $bundlePath $dest
Write-Host ''
Write-Host "Play Store AAB ready:" -ForegroundColor Green
Write-Host "  $dest" -ForegroundColor Gray
Write-Host "  Size: $((Get-Item $dest).Length) bytes" -ForegroundColor Gray
Write-Host ''
Write-Host 'Upload in Google Play Console -> Release -> Production/Testing -> Create release -> Upload AAB' -ForegroundColor Cyan
