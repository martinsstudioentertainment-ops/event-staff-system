# Phase A.7 Mobile QA — verify + screenshots + HTML report.
#   powershell -ExecutionPolicy Bypass -File .\scripts\run-phase-a7-qa.ps1
#   powershell -ExecutionPolicy Bypass -File .\scripts\run-phase-a7-qa.ps1 -SkipScreenshots

param(
    [switch]$SkipScreenshots,
    [switch]$SkipUpload,
    [string] $BaseUrl = 'https://register.olasentra.com'
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
Set-Location $Root

if (-not $SkipUpload) {
    . (Join-Path $PSScriptRoot 'ftp-common.ps1')
    $cfg = Get-DeployConfig
    foreach ($f in @('status-screenshot-preview.php', 'wizard-screenshot-preview.php')) {
        Send-FtpFile -LocalPath (Join-Path $Root $f) -RemoteRelativePath $f -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
    }
}

& (Join-Path $Root 'scripts\verify-phase-a7.ps1') -BaseUrl $BaseUrl
$verifyExit = if ($null -ne $LASTEXITCODE) { $LASTEXITCODE } else { 0 }
$verifyOk = ($verifyExit -eq 0)

if (-not $SkipScreenshots) {
    & (Join-Path $Root 'scripts\capture-phase-a7-screenshots.ps1') -BaseUrl $BaseUrl
}

& (Join-Path $Root 'scripts\generate-phase-a7-report.ps1') -BaseUrl $BaseUrl

if (-not $verifyOk) { exit 1 }
