# One-off: upload restored includes/maps.php
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$cfg = & (Join-Path $ProjectRoot 'deploy.local.ps1')
foreach ($key in @('FtpServer', 'FtpUser', 'FtpPassword', 'FtpRemoteDir')) {
    if ([string]::IsNullOrWhiteSpace($cfg[$key])) {
        throw "deploy.local.ps1: '$key' is empty."
    }
}

function Get-FtpUri {
    param([string]$Server, [string]$RemoteDir, [string]$RelativePath)
    $dir = $RemoteDir.TrimEnd('/')
    $rel = $RelativePath.TrimStart('/')
    return "ftp://$Server$dir/$rel"
}

function Send-FtpFile {
    param([string]$LocalPath, [string]$RemoteRelativePath)
    if (-not (Test-Path $LocalPath)) {
        throw "Local file missing: $LocalPath"
    }
    $uri = Get-FtpUri -Server $cfg.FtpServer -RemoteDir $cfg.FtpRemoteDir -RelativePath $RemoteRelativePath
    Write-Host "  Upload $RemoteRelativePath" -ForegroundColor Cyan
    $client = New-Object System.Net.WebClient
    $client.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)
    try {
        $client.UploadFile($uri, $LocalPath)
    } finally {
        $client.Dispose()
    }
}

Send-FtpFile -LocalPath (Join-Path $ProjectRoot 'includes\maps.php') -RemoteRelativePath 'includes/maps.php'
Write-Host 'maps.php uploaded to admin.olasentra.com' -ForegroundColor Green
