# Release build (production signed APK + AAB)
#
# Prerequisites:
# 1. Copy local.properties.example -> local.properties (GOOGLE_WEB_CLIENT_ID)
# 2. Copy google-services.json.example -> app/google-services.json (real Firebase project)
# 3. Copy keystore.properties.example -> keystore.properties + release keystore .jks
# 4. Gradle wrapper present (gradlew.bat) — run `gradle wrapper` once if missing
#
# Output:
#   app/build/outputs/apk/production/release/app-production-release.apk
#   app/build/outputs/bundle/productionRelease/app-production-release.aab

$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

if (-not (Test-Path ".\gradlew.bat")) {
    Write-Error "gradlew.bat not found. Open project in Android Studio and run Gradle wrapper task, or install Gradle and run: gradle wrapper"
}

Write-Host "Building production release APK..."
& .\gradlew.bat :app:assembleProductionRelease --no-daemon
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host "Building production release AAB..."
& .\gradlew.bat :app:bundleProductionRelease --no-daemon
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host ""
Write-Host "Build complete."
Write-Host "APK: app\build\outputs\apk\production\release\"
Write-Host "AAB: app\build\outputs\bundle\productionRelease\"
