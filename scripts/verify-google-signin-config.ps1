# Verify Google Sign-In / Firebase Android configuration for com.olasentra.app
# Usage: powershell -ExecutionPolicy Bypass -File .\scripts\verify-google-signin-config.ps1

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$AndroidRoot = Join-Path $Root 'android\olasentra-staff'
$GsPath = Join-Path $AndroidRoot 'app\google-services.json'
$BuildGradle = Join-Path $AndroidRoot 'app\build.gradle.kts'
$LocalProps = Join-Path $AndroidRoot 'local.properties'
$KeystoreProps = Join-Path $AndroidRoot 'keystore.properties'

Write-Host '=== Olasentra Google Sign-In verification ===' -ForegroundColor Cyan

# google-services.json
if (-not (Test-Path $GsPath)) { throw "Missing $GsPath" }
$gs = Get-Content $GsPath -Raw | ConvertFrom-Json
Write-Host "`nFirebase project_id: $($gs.project_info.project_id)"
Write-Host "Firebase project_number: $($gs.project_info.project_number)"
$packages = @($gs.client | ForEach-Object { $_.client_info.android_client_info.package_name })
Write-Host "Android packages in google-services.json:"
$packages | ForEach-Object { Write-Host "  - $_" }
$hasApp = $packages -contains 'com.olasentra.app'
$hasStaff = $packages -contains 'com.olasentra.staff'
Write-Host ("com.olasentra.app present: {0}" -f $hasApp) -ForegroundColor $(if ($hasApp) { 'Green' } else { 'Red' })
Write-Host ("com.olasentra.staff present (legacy, keep in Firebase): {0}" -f $hasStaff)

# applicationId
$gradle = Get-Content $BuildGradle -Raw
if ($gradle -match 'applicationId\s*=\s*"([^"]+)"') {
    Write-Host "`napplicationId: $($Matches[1])"
} else {
    Write-Host '`napplicationId: NOT FOUND' -ForegroundColor Red
}

# GOOGLE_WEB_CLIENT_ID
if (Test-Path $LocalProps) {
    $lp = Get-Content $LocalProps | Where-Object { $_ -match '^GOOGLE_WEB_CLIENT_ID=' }
    $webClient = ($lp -replace '^GOOGLE_WEB_CLIENT_ID=', '').Trim()
    if ($webClient) {
        $prefix = ($webClient -split '-')[0]
        Write-Host "`nGOOGLE_WEB_CLIENT_ID prefix (GCP project #): $prefix"
        Write-Host "Firebase project_number: $($gs.project_info.project_number)"
        if ($prefix -ne $gs.project_info.project_number) {
            Write-Host 'WARNING: Web client project number does not match Firebase project_number.' -ForegroundColor Yellow
            Write-Host 'Ensure admin Settings -> Google Sheets OAuth Client ID uses the SAME Web client as Android.'
        } else {
            Write-Host 'Web client project number matches Firebase.' -ForegroundColor Green
        }
    } else {
        Write-Host '`nGOOGLE_WEB_CLIENT_ID: MISSING in local.properties' -ForegroundColor Red
    }
}

# Release keystore SHA
if (Test-Path $KeystoreProps) {
    $kv = @{}
    Get-Content $KeystoreProps | Where-Object { $_ -match '=' } | ForEach-Object {
        $p = $_ -split '=', 2
        $kv[$p[0].Trim()] = $p[1].Trim()
    }
    $store = Join-Path $AndroidRoot $kv['storeFile']
    if (Test-Path $store) {
        $keytool = if (Test-Path "$env:JAVA_HOME\bin\keytool.exe") { "$env:JAVA_HOME\bin\keytool.exe" } else { 'keytool' }
        $out = & $keytool -list -v -keystore $store -alias $kv['keyAlias'] -storepass $kv['storePassword'] 2>&1 | Out-String
        $sha1 = if ($out -match 'SHA1:\s*([0-9A-F:]+)') { $Matches[1] } else { '?' }
        $sha256 = if ($out -match 'SHA256:\s*([0-9A-F:]+)') { $Matches[1] } else { '?' }
        Write-Host "`nUpload/release keystore SHA-1:  $sha1"
        Write-Host "Upload/release keystore SHA-256: $sha256"
        Write-Host 'Add BOTH to Firebase -> com.olasentra.app -> SHA certificate fingerprints.'
        Write-Host 'Also add Play Console App Integrity App Signing SHA-1/SHA-256 if different.'
    }
}

# Debug keystore (for local debug APK)
$debugStore = Join-Path $env:USERPROFILE '.android\debug.keystore'
if (Test-Path $debugStore) {
    $keytool = if (Test-Path "$env:JAVA_HOME\bin\keytool.exe") { "$env:JAVA_HOME\bin\keytool.exe" } else { 'keytool' }
    $out = & $keytool -list -v -keystore $debugStore -alias androiddebugkey -storepass android 2>&1 | Out-String
    $sha1 = if ($out -match 'SHA1:\s*([0-9A-F:]+)') { $Matches[1] } else { '?' }
    Write-Host "`nDebug keystore SHA-1 (for assembleProductionDebug): $sha1"
}

# Production API
try {
    $cfg = Invoke-RestMethod -Uri 'https://register.olasentra.com/api/mobile/v1/config' -TimeoutSec 30
    Write-Host "`nMobile API google_signin_enabled: $($cfg.google_signin_enabled)"
    Write-Host "Mobile API email_otp_enabled: $($cfg.email_otp_enabled)"
} catch {
    Write-Host "`nCould not reach Mobile API config: $($_.Exception.Message)" -ForegroundColor Yellow
}

Write-Host "`nDone." -ForegroundColor Green
