# Read-only: download a single file from production FTP to temp path.
param(
    [Parameter(Mandatory = $true)][string]$RemoteRelative,
    [Parameter(Mandatory = $true)][string]$LocalPath
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir.TrimEnd('/')
$remote = "$base/$($RemoteRelative.TrimStart('/'))"
$uri = 'ftp://' + $cfg.FtpServer + $remote
$dir = Split-Path $LocalPath -Parent
if ($dir -and -not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
$client = New-Object System.Net.WebClient
$client.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)
try {
    $client.DownloadFile($uri, $LocalPath)
    Write-Host "Downloaded: $remote -> $LocalPath ($((Get-Item $LocalPath).Length) bytes)"
} finally {
    $client.Dispose()
}
