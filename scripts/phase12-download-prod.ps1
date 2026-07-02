$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir
$tmp = Join-Path (Split-Path $PSScriptRoot -Parent) 'storage\backups\phase12-probe'
New-Item -ItemType Directory -Force -Path $tmp | Out-Null
$files = @(
    'submit.php',
    'index.php',
    'status.php',
    'error_log',
    'storage/logs/mobile-otp-debug-prod.log'
)
foreach ($f in $files) {
    try {
        $p = Join-Path $tmp ($f -replace '/', '\')
        $dir = Split-Path $p -Parent
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
        $n = Download-FtpFile -LocalPath $p -RemoteRelativePath $f -RemoteBase $base -Deploy $cfg
        Write-Host "OK $f $n bytes"
    } catch {
        Write-Host "FAIL $f $($_.Exception.Message)"
    }
}
