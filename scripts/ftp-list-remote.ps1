# Read-only FTP listing helper (no uploads).
param(
    [string]$RelativePath = ''
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir.TrimEnd('/')
$path = if ($RelativePath) { "$base/$($RelativePath.TrimStart('/'))" } else { $base }
$uri = 'ftp://' + $cfg.FtpServer + $path.TrimEnd('/') + '/'
$req = [System.Net.FtpWebRequest]::Create($uri)
$req.Method = [System.Net.WebRequestMethods+Ftp]::ListDirectory
$req.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)
$req.UsePassive = $true
$req.Timeout = 20000
try {
    $resp = $req.GetResponse()
    $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
    $listing = $reader.ReadToEnd()
    $reader.Close(); $resp.Close()
    Write-Host "FTP: $path/"
    ($listing -split "`r?`n" | Where-Object { $_.Trim() -ne '' }) | ForEach-Object { Write-Host "  $_" }
} catch {
    Write-Host "FTP list failed ($path): $($_.Exception.Message)"
    exit 1
}
