param(
    [string]$Date = '10/Jun/2026',
    [string]$OutDir = ''
)
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
if ($OutDir -eq '') { $OutDir = Join-Path $Root 'storage\reports' }
if (-not (Test-Path $OutDir)) { New-Item -ItemType Directory -Path $OutDir -Force | Out-Null }

. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

function Get-FtpBytes {
    param([string]$RemotePath)
    $uri = Get-FtpUri -Server $cfg.FtpServer -RemoteDir '' -RelativePath $RemotePath.TrimStart('/')
    $client = New-Object System.Net.WebClient
    $client.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)
    try { return $client.DownloadData($uri) } finally { $client.Dispose() }
}

function Expand-GzipBytes([byte[]]$bytes) {
    $ms = New-Object System.IO.MemoryStream(,$bytes)
    $gz = New-Object System.IO.Compression.GzipStream($ms, [System.IO.Compression.CompressionMode]::Decompress)
    $out = New-Object System.IO.MemoryStream
    $gz.CopyTo($out)
    $gz.Dispose(); $ms.Dispose()
    return [System.Text.Encoding]::UTF8.GetString($out.ToArray())
}

$logFiles = @(
    'logs/register.olasentra.com-Jun-2026.gz',
    'logs/register.olasentra.com-ssl_log-Jun-2026.gz',
    'logs/admin.olasentra.com-Jun-2026.gz',
    'logs/admin.olasentra.com-ssl_log-Jun-2026.gz'
)

$hits = @()
$uniqueIps = @{}

foreach ($remote in $logFiles) {
    Write-Host "Downloading $remote ..." -ForegroundColor Cyan
    try {
        $raw = Get-FtpBytes -RemotePath $remote
        $text = Expand-GzipBytes $raw
    } catch {
        Write-Host "  skip: $($_.Exception.Message)" -ForegroundColor Yellow
        continue
    }

    foreach ($line in ($text -split "`n")) {
        if ($line -notmatch [regex]::Escape($Date)) { continue }
        if ($line -notmatch 'event-sign\.php|sign-in\.php') { continue }
        $ip = ''
        if ($line -match '^(\S+)') { $ip = $Matches[1] }
        $path = ''
        if ($line -match '"[A-Z]+ ([^"]+)"') { $path = $Matches[1] }
        $status = ''
        if ($line -match '" (\d{3}) ') { $status = $Matches[1] }
        $method = ''
        if ($line -match '"([A-Z]+) ') { $method = $Matches[1] }

        $hits += [pscustomobject]@{
            log    = (Split-Path $remote -Leaf)
            ip     = $ip
            method = $method
            path   = $path
            status = $status
            line   = $line.Trim()
        }
        if ($ip -ne '') { $uniqueIps[$ip] = 1 }
    }
}

$getHits  = $hits | Where-Object { $_.method -eq 'GET' }
$postHits = $hits | Where-Object { $_.method -eq 'POST' }
$report = [ordered]@{
    date                    = $Date
    total_hits              = $hits.Count
    unique_ips              = $uniqueIps.Count
    get_page_loads          = $getHits.Count
    post_submit_attempts    = $postHits.Count
    note                    = 'Access logs show IPs only — not staff names. POST = someone submitted email/PPS form.'
    unique_ip_list          = @($uniqueIps.Keys)
    post_requests           = @($postHits | Select-Object ip, path, status, log)
    get_sample              = @($getHits | Select-Object -First 15 ip, path, status, log)
}

$jsonPath = Join-Path $OutDir 'signin-access-logs-report.json'
$report | ConvertTo-Json -Depth 6 | Out-File $jsonPath -Encoding utf8
Write-Host ""
Write-Host "=== Sign-in access log summary ($Date) ===" -ForegroundColor Green
Write-Host "Total hits (GET+POST): $($hits.Count)"
Write-Host "Unique IPs: $($uniqueIps.Count)"
Write-Host "GET (opened page): $($getHits.Count)"
Write-Host "POST (submitted form): $($postHits.Count)"
Write-Host "Saved: $jsonPath"
