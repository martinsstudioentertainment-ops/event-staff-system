$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

function Get-LogText($remote) {
    $uri = Get-FtpUri -Server $cfg.FtpServer -RemoteDir '' -RelativePath $remote
    $client = New-Object System.Net.WebClient
    $client.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)
    $bytes = $client.DownloadData($uri)
    $client.Dispose()
    $ms = New-Object System.IO.MemoryStream(,$bytes)
    $gz = New-Object System.IO.Compression.GzipStream($ms, [System.IO.Compression.CompressionMode]::Decompress)
    $out = New-Object System.IO.MemoryStream
    $gz.CopyTo($out)
    return [System.Text.Encoding]::UTF8.GetString($out.ToArray())
}

$files = Get-ChildItem (Join-Path $Root 'scripts') -Filter '*.ps1' | Out-Null
$logFiles = @(
    'logs/register.olasentra.com-ssl_log-Jun-2026.gz',
    'logs/admin.olasentra.com-ssl_log-Jun-2026.gz',
    'logs/register.olasentra.com-Jun-2026.gz',
    'logs/admin.olasentra.com-Jun-2026.gz'
)

foreach ($f in $logFiles) {
    Write-Host "`n$f" -ForegroundColor Cyan
    try {
        $lines = (Get-LogText $f) -split "`n" | Where-Object { $_.Trim() -ne '' }
        $first = $lines | Select-Object -First 1
        $last  = $lines | Select-Object -Last 1
        Write-Host "  lines: $($lines.Count)"
        Write-Host "  first: $first"
        Write-Host "  last:  $last"
        $sign = $lines | Where-Object { $_ -match 'event-sign|sign-in' }
        Write-Host "  event-sign total in file: $($sign.Count)"
    } catch {
        Write-Host "  error: $($_.Exception.Message)"
    }
}
