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

$files = @(
    'logs/register.olasentra.com-ssl_log-Jun-2026.gz',
    'logs/admin.olasentra.com-ssl_log-Jun-2026.gz'
)

foreach ($f in $files) {
    Write-Host "`n==== $f ====" -ForegroundColor Cyan
    $text = Get-LogText $f
    $june10 = ($text -split "`n") | Where-Object { $_ -match '\[10/Jun/2026' }
    Write-Host "Lines on 10/Jun/2026: $($june10.Count)"
    $signin = $june10 | Where-Object { $_ -match 'event-sign|sign-in|check-in' }
    Write-Host "Sign-in related: $($signin.Count)"
    $signin | ForEach-Object { Write-Host $_ }
    if ($signin.Count -eq 0 -and $june10.Count -gt 0) {
        Write-Host "Sample June 10 paths:"
        $june10 | Select-Object -First 10 | ForEach-Object {
            if ($_ -match '"[A-Z]+ ([^"]+)"') { Write-Host "  $($Matches[1])" }
        }
    }
}
