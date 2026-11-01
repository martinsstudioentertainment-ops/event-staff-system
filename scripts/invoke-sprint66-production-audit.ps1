# Transient read-only Sprint 6.6 audit on production (upload → invoke → remove).
# Does NOT run deploy.ps1 or modify application data.
param(
    [switch]$KeepRemoteFile
)
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

function Get-ProductionCronKey {
    param([hashtable]$Deploy)
    if ($Deploy.ReminderCronKey -and $Deploy.ReminderCronKey.Trim() -ne '') {
        return $Deploy.ReminderCronKey.Trim()
    }
    $uri = Get-FtpUri -Server $Deploy.FtpServer -RemoteDir $Deploy.FtpRemoteDir -RelativePath 'storage/backups/weekly/settings-and-cms.json'
    $client = New-Object System.Net.WebClient
    $client.Credentials = New-Object System.Net.NetworkCredential($Deploy.FtpUser, $Deploy.FtpPassword)
    try {
        $json = [System.Text.Encoding]::UTF8.GetString($client.DownloadData($uri)) | ConvertFrom-Json
        $key  = [string]$json.settings.reminder_cron_key
        if ($key.Trim() -ne '') { return $key.Trim() }
    } finally {
        $client.Dispose()
    }
    throw 'Missing ReminderCronKey — set in deploy.local.ps1 or ensure settings backup exists.'
}

function Remove-FtpFile {
    param([string]$RemoteRelativePath, [hashtable]$Deploy)
    $uri = Get-FtpUri -Server $Deploy.FtpServer -RemoteDir $Deploy.FtpRemoteDir -RelativePath $RemoteRelativePath
    $req = [System.Net.FtpWebRequest]::Create($uri)
    $req.Method = [System.Net.WebRequestMethods+Ftp]::DeleteFile
    $req.Credentials = New-Object System.Net.NetworkCredential($Deploy.FtpUser, $Deploy.FtpPassword)
    $req.UsePassive = $true
    $req.GetResponse().Close()
}

$local  = Join-Path $ProjectRoot 'cron\sprint66-production-audit.php'
$remote = 'cron/sprint66-production-audit.php'
$outDir = Join-Path $ProjectRoot 'storage\reports'
$outFile = Join-Path $outDir 'sprint66-production-audit-latest.json'

if (-not (Test-Path $local)) { throw "Missing $local" }
if (-not (Test-Path $outDir)) { New-Item -ItemType Directory -Path $outDir -Force | Out-Null }

Write-Host 'Uploading transient read-only audit endpoint ...' -ForegroundColor Cyan
Send-FtpFile -LocalPath $local -RemoteRelativePath $remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg

$cronKey = Get-ProductionCronKey -Deploy $cfg
$baseUrl = if ($cfg.AdminUrl) { $cfg.AdminUrl.TrimEnd('/') } else { 'https://admin.olasentra.com' }
$baseUrl = $baseUrl -replace '/admin$', ''
$url = "$baseUrl/cron/sprint66-production-audit.php?key=$([uri]::EscapeDataString($cronKey))"

Write-Host 'Invoking live production audit (read-only) ...' -ForegroundColor Cyan
try {
    $resp = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 300
    $body = $resp.Content
} catch {
    $body = ''
    if ($_.Exception.Response) {
        $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
        $body = $reader.ReadToEnd()
        $reader.Close()
    }
    if (-not $KeepRemoteFile) {
        try { Remove-FtpFile -RemoteRelativePath $remote -Deploy $cfg; Write-Host 'Removed transient audit file from server.' -ForegroundColor DarkGray } catch { }
    }
    throw "Audit HTTP failed: $body"
}

$body | Out-File -FilePath $outFile -Encoding utf8
Write-Host "Saved: $outFile" -ForegroundColor Green

if (-not $KeepRemoteFile) {
    try {
        Remove-FtpFile -RemoteRelativePath $remote -Deploy $cfg
        Write-Host 'Removed transient audit file from server.' -ForegroundColor DarkGray
    } catch {
        Write-Host "Warning: could not remove remote audit file: $($_.Exception.Message)" -ForegroundColor Yellow
    }
}

Write-Host $body
