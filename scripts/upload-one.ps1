param([Parameter(Mandatory = $true)][string]$Local, [Parameter(Mandatory = $true)][string]$Remote)
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
Send-FtpFile -LocalPath (Join-Path $ProjectRoot $Local) -RemoteRelativePath $Remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
Write-Host "Uploaded -> $Remote" -ForegroundColor Green
