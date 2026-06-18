# Sprint 6.6 Phase B — bridge fix deploy + vault verify + report regeneration (read-only).
# Permanent deploy: includes/platform/apply-vault-bridge.php only.
# Transient: audit/regenerate cron + generate-sprint66-reports.php (removed after run).
param(
    [switch]$SkipBridgeUpload
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
    try { $req.GetResponse().Close() } catch { }
}

function Invoke-ProductionUrl {
    param([string]$Url)
    try {
        $resp = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 300
        return @{ Ok = $true; Body = $resp.Content }
    } catch {
        $body = ''
        if ($_.Exception.Response) {
            $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
            $body = $reader.ReadToEnd()
            $reader.Close()
        }
        return @{ Ok = $false; Body = $(if ($body) { $body } else { $_.Exception.Message }) }
    }
}

function Download-FtpFile {
    param([string]$RemoteRelativePath, [string]$LocalPath, [hashtable]$Deploy)
    $uri = Get-FtpUri -Server $Deploy.FtpServer -RemoteDir $Deploy.FtpRemoteDir -RelativePath $RemoteRelativePath
    $dir = Split-Path $LocalPath -Parent
    if ($dir -and -not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $client = New-Object System.Net.WebClient
    $client.Credentials = New-Object System.Net.NetworkCredential($Deploy.FtpUser, $Deploy.FtpPassword)
    try {
        $client.DownloadFile($uri, $LocalPath)
        return (Get-Item $LocalPath).Length
    } finally {
        $client.Dispose()
    }
}

$baseUrl = if ($cfg.AdminUrl) { $cfg.AdminUrl.TrimEnd('/') } else { 'https://admin.olasentra.com' }
$baseUrl = $baseUrl -replace '/admin$', ''
$cronKey = Get-ProductionCronKey -Deploy $cfg
$keyQs   = [uri]::EscapeDataString($cronKey)

$transientRemote = @(
    'cron/sprint66-production-audit.php',
    'cron/sprint66-regenerate-reports.php',
    'scripts/generate-sprint66-reports.php'
)

Write-Host ''
Write-Host 'Sprint 6.6 Phase B — Apply vault bridge + complete audit' -ForegroundColor Green
Write-Host ''

if (-not $SkipBridgeUpload) {
    Write-Host '[1/5] Deploy bridge fix only ...' -ForegroundColor Cyan
    Send-FtpFile -LocalPath (Join-Path $ProjectRoot 'includes\platform\apply-vault-bridge.php') `
        -RemoteRelativePath 'includes/platform/apply-vault-bridge.php' `
        -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
} else {
    Write-Host '[1/5] Skipped bridge upload (-SkipBridgeUpload)' -ForegroundColor Yellow
}

Write-Host '[2/5] Upload transient audit/report runners ...' -ForegroundColor Cyan
Send-FtpFile -LocalPath (Join-Path $ProjectRoot 'cron\sprint66-production-audit.php') `
    -RemoteRelativePath 'cron/sprint66-production-audit.php' -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
Send-FtpFile -LocalPath (Join-Path $ProjectRoot 'cron\sprint66-regenerate-reports.php') `
    -RemoteRelativePath 'cron/sprint66-regenerate-reports.php' -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
Send-FtpFile -LocalPath (Join-Path $ProjectRoot 'scripts\generate-sprint66-reports.php') `
    -RemoteRelativePath 'scripts/generate-sprint66-reports.php' -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg

Write-Host '[3/5] Live production audit (read-only) ...' -ForegroundColor Cyan
$auditUrl = "$baseUrl/cron/sprint66-production-audit.php?key=$keyQs"
$auditResp = Invoke-ProductionUrl -Url $auditUrl
$auditOut = Join-Path $ProjectRoot 'storage\reports\sprint66-production-audit-latest.json'
if (-not (Test-Path (Split-Path $auditOut -Parent))) {
    New-Item -ItemType Directory -Path (Split-Path $auditOut -Parent) -Force | Out-Null
}
$auditResp.Body | Out-File -FilePath $auditOut -Encoding utf8
if (-not $auditResp.Ok) { throw "Audit failed: $($auditResp.Body)" }

$auditJson = $auditResp.Body | ConvertFrom-Json
if (-not $auditJson.vault_bridge.connected) {
    Write-Host 'WARNING: Vault bridge still not connected after fix.' -ForegroundColor Red
    Write-Host ($auditJson.vault_bridge | ConvertTo-Json -Depth 5)
} else {
    Write-Host "Vault connected: $($auditJson.vault_bridge.config_path) ($($auditJson.vault_bridge.vault_rows) rows)" -ForegroundColor Green
}

Write-Host '[4/5] Regenerate HTML reports on server ...' -ForegroundColor Cyan
$regenUrl = "$baseUrl/cron/sprint66-regenerate-reports.php?key=$keyQs"
$regenResp = Invoke-ProductionUrl -Url $regenUrl
$regenOut = Join-Path $ProjectRoot 'storage\reports\sprint66-regenerate-latest.json'
$regenResp.Body | Out-File -FilePath $regenOut -Encoding utf8
if (-not $regenResp.Ok) { throw "Report regeneration HTTP failed: $($regenResp.Body)" }
$regenJson = $regenResp.Body | ConvertFrom-Json
if (-not $regenJson.ok) { throw "Report regeneration failed: $($regenJson.cli_output)" }

Write-Host '[5/5] Download reports + remove transient files ...' -ForegroundColor Cyan
$docsLocal = Join-Path $ProjectRoot 'docs'
foreach ($item in $regenJson.reports) {
    if (-not $item.exists) { continue }
    $remote = 'docs/' + $item.file
    $local  = Join-Path $docsLocal $item.file
    try {
        $bytes = Download-FtpFile -RemoteRelativePath $remote -LocalPath $local -Deploy $cfg
        Write-Host "  Downloaded $($item.file) ($bytes bytes)" -ForegroundColor DarkGray
    } catch {
        Write-Host "  Skip download $($item.file): $($_.Exception.Message)" -ForegroundColor Yellow
    }
}

foreach ($remote in $transientRemote) {
    Remove-FtpFile -RemoteRelativePath $remote -Deploy $cfg
}
Write-Host 'Removed transient server files.' -ForegroundColor DarkGray

Write-Host ''
Write-Host 'Phase B complete.' -ForegroundColor Green
Write-Host "  Audit JSON: $auditOut"
Write-Host "  Regen JSON: $regenOut"
Write-Host "  HTML reports: $docsLocal"
Write-Host ''
Write-Host ($auditResp.Body)
