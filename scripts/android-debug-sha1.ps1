$ErrorActionPreference = 'Stop'
$debugKs = Join-Path $env:USERPROFILE '.android\debug.keystore'
if (-not (Test-Path $debugKs)) {
    throw "Debug keystore not found: $debugKs`nBuild the app once in Android Studio to create it."
}
Write-Host 'Android debug keystore SHA-1 (register in Google Cloud Console):' -ForegroundColor Green
$keytool = Get-Command keytool -ErrorAction SilentlyContinue
if (-not $keytool) {
    $javaHome = $env:JAVA_HOME
    if (-not $javaHome) {
        $javaHome = 'C:\Program Files\Microsoft\jdk-17.0.19.10-hotspot'
    }
    $keytool = Join-Path $javaHome 'bin\keytool.exe'
}
& $keytool -list -v -keystore $debugKs -alias androiddebugkey -storepass android -keypass android |
    Select-String -Pattern 'SHA1:|SHA-1:'
Write-Host ''
Write-Host 'Package name: com.olasentra.staff' -ForegroundColor Cyan
Write-Host 'See docs/android/GOOGLE-SIGNIN-SHA1.md' -ForegroundColor Gray
