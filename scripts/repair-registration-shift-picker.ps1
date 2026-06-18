# Repair production PHP corruption: null-byte bootstrap + UTF-8 BOM on config.php.
# Does NOT change DB credentials — strips BOM only from live config.php.
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\repair-registration-shift-picker.ps1

$ErrorActionPreference = 'Stop'
$Root = $PSScriptRoot | Split-Path -Parent
Set-Location $Root

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

Write-Host 'Repair registration shift picker (production PHP corruption)' -ForegroundColor Green
Write-Host ''

$uploads = @(
    @{ Local = 'includes\background-workers-bootstrap.php'; Remote = 'includes/background-workers-bootstrap.php' },
    @{ Local = 'includes\validation.php'; Remote = 'includes/validation.php' },
    @{ Local = 'includes\registration-options-repository.php'; Remote = 'includes/registration-options-repository.php' },
    @{ Local = 'api\registration-options.php'; Remote = 'api/registration-options.php' }
)

foreach ($f in $uploads) {
    Send-FtpFile -LocalPath (Join-Path $Root $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}

$configLocal = Join-Path $env:TEMP 'olasentra-config-repair.php'
$configRemote = 'config.php'
$uri = Get-FtpUri -Server $cfg.FtpServer -RemoteDir $cfg.FtpRemoteDir -RelativePath $configRemote
$client = New-Object System.Net.WebClient
$client.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)
try {
    $client.DownloadFile($uri, $configLocal)
} finally {
    $client.Dispose()
}

$bytes = [System.IO.File]::ReadAllBytes($configLocal)
$start = 0
if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
    $start = 3
    Write-Host 'Stripping UTF-8 BOM from config.php' -ForegroundColor Yellow
}
$nullCount = ($bytes | Where-Object { $_ -eq 0 }).Count
if ($nullCount -gt 0) {
    Write-Host "WARNING: config.php contains $nullCount null bytes - manual review required" -ForegroundColor Red
}
if ($start -gt 0) {
    $clean = $bytes[$start..($bytes.Length - 1)]
    [System.IO.File]::WriteAllBytes($configLocal, $clean)
    Send-FtpFile -LocalPath $configLocal -RemoteRelativePath $configRemote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
} else {
    Write-Host 'config.php BOM already clean' -ForegroundColor DarkGray
}

Write-Host ''
Write-Host 'Verify:' -ForegroundColor Green
Write-Host '  https://register.olasentra.com/api/registration-options.php?form=static'
Write-Host '  https://admin.olasentra.com/cron/registration-options-diagnostic.php?key=YOUR_CRON_KEY&form=static'
