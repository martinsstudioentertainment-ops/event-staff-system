$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig
$uri = Get-FtpUri -Server $cfg.FtpServer -RemoteDir '' -RelativePath 'logs/register.olasentra.com-ssl_log-Jun-2026.gz'
$client = New-Object System.Net.WebClient
$client.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)
$bytes = $client.DownloadData($uri)
$client.Dispose()
$ms = New-Object System.IO.MemoryStream(,$bytes)
$gz = New-Object System.IO.Compression.GzipStream($ms, [System.IO.Compression.CompressionMode]::Decompress)
$out = New-Object System.IO.MemoryStream
$gz.CopyTo($out)
$text = [System.Text.Encoding]::UTF8.GetString($out.ToArray())
$lines = $text -split "`n"
Write-Host "Total lines: $($lines.Count)"
$event = $lines | Where-Object { $_ -match 'event-sign|sign-in' }
Write-Host "event-sign/sign-in lines: $($event.Count)"
$event | Select-Object -First 5 | ForEach-Object { Write-Host $_ }
Write-Host "--- sample dates ---"
$lines | Select-Object -First 3 | ForEach-Object { Write-Host $_ }
$lines | Select-Object -Last 3 | ForEach-Object { Write-Host $_ }
