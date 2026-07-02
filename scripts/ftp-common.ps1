# Shared FTP helpers for deploy scripts.

function Get-DeployConfig {
    $ProjectRoot = if ($PSScriptRoot) { Split-Path -Parent $PSScriptRoot } else { Get-Location }
    $localDeploy = Join-Path $ProjectRoot 'deploy.local.ps1'
    if (-not (Test-Path $localDeploy)) {
        throw 'Missing deploy.local.ps1 - copy deploy.local.ps1.example and set FtpPassword from cPanel FTP Accounts.'
    }

    $cfg = & $localDeploy
    if ($env:EVENT_STAFF_FTP_PASSWORD) {
        $cfg.FtpPassword = $env:EVENT_STAFF_FTP_PASSWORD
    }

    foreach ($key in @('FtpServer', 'FtpUser', 'FtpPassword')) {
        if ([string]::IsNullOrWhiteSpace($cfg[$key])) {
            throw "deploy.local.ps1: '$key' is empty."
        }
    }

    if ($cfg.FtpPassword -eq 'YOUR_FTP_PASSWORD') {
        throw 'Set FtpPassword in deploy.local.ps1 (cPanel FTP Accounts) or env EVENT_STAFF_FTP_PASSWORD.'
    }

    if ([string]::IsNullOrWhiteSpace($cfg.FtpRemoteDir)) {
        $cfg.FtpRemoteDir = '/public_html'
    }

    if ([string]::IsNullOrWhiteSpace($cfg.FtpApplyRemoteDir)) {
        $cfg.FtpApplyRemoteDir = '/apply/admin'
    }

    return $cfg
}

function Get-FtpUri {
    param([string]$Server, [string]$RemoteDir, [string]$RelativePath)
    $dir = $RemoteDir.TrimEnd('/')
    $rel = $RelativePath.TrimStart('/')
    return "ftp://$Server$dir/$rel"
}

function Download-FtpFile {
    param(
        [string]$LocalPath,
        [string]$RemoteRelativePath,
        [string]$RemoteBase,
        [hashtable]$Deploy
    )
    $uri = Get-FtpUri -Server $Deploy.FtpServer -RemoteDir $RemoteBase -RelativePath $RemoteRelativePath
    $dir = Split-Path $LocalPath -Parent
    if ($dir -and -not (Test-Path $dir)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
    }
    $client = New-Object System.Net.WebClient
    $client.Credentials = New-Object System.Net.NetworkCredential($Deploy.FtpUser, $Deploy.FtpPassword)
    try {
        $client.DownloadFile($uri, $LocalPath)
        return (Get-Item $LocalPath).Length
    } finally {
        $client.Dispose()
    }
}

function Get-FtpDirectoryNames {
    param(
        [string]$RemoteRelativePath,
        [string]$RemoteBase,
        [hashtable]$Deploy
    )
    $rel = $RemoteRelativePath.Trim('/')
    $uriPath = if ($rel) { "$rel/" } else { '' }
    $uri = Get-FtpUri -Server $Deploy.FtpServer -RemoteDir $RemoteBase -RelativePath $uriPath
    $req = [System.Net.FtpWebRequest]::Create($uri)
    $req.Method = [System.Net.WebRequestMethods+Ftp]::ListDirectory
    $req.Credentials = New-Object System.Net.NetworkCredential($Deploy.FtpUser, $Deploy.FtpPassword)
    $req.UsePassive = $true
    $req.Timeout = 60000
    $resp = $req.GetResponse()
    try {
        $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
        $listing = $reader.ReadToEnd()
        $reader.Close()
    } finally {
        $resp.Close()
    }
    return @($listing -split "`r?`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
}

function Ensure-FtpDirectory {
    param([string]$Server, [string]$RemoteDir, [hashtable]$Deploy)
    $uri = "ftp://$Server$($RemoteDir.TrimEnd('/'))/"
    $req = [System.Net.FtpWebRequest]::Create($uri)
    $req.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
    $req.Credentials = New-Object System.Net.NetworkCredential($Deploy.FtpUser, $Deploy.FtpPassword)
    $req.UsePassive = $true
    try {
        $req.GetResponse().Close()
    } catch {
        # directory may already exist
    }
}

function Ensure-FtpDirectoryTree {
    param([string]$Server, [string]$RemoteDir, [hashtable]$Deploy)
    $normalized = '/' + ($RemoteDir.Trim('/') -replace '\\', '/')
    $parts = $normalized.Trim('/').Split('/', [System.StringSplitOptions]::RemoveEmptyEntries)
    $built = ''
    foreach ($part in $parts) {
        $built += '/' + $part
        Ensure-FtpDirectory -Server $Server -RemoteDir $built -Deploy $Deploy
    }
}

function Send-FtpFile {
    param(
        [string]$LocalPath,
        [string]$RemoteRelativePath,
        [string]$RemoteBase,
        [hashtable]$Deploy,
        [switch]$AllowEmpty
    )
    if (-not (Test-Path $LocalPath)) {
        throw "Local file missing: $LocalPath"
    }
    if ((Get-Item $LocalPath).Length -eq 0) {
        if ($AllowEmpty) {
            Write-Host "  !! skipped empty file (allowed): $RemoteRelativePath" -ForegroundColor Yellow
            return
        }
        throw "Refusing 0-byte upload: $RemoteRelativePath ($LocalPath)"
    }
    $uri = Get-FtpUri -Server $Deploy.FtpServer -RemoteDir $RemoteBase -RelativePath $RemoteRelativePath
    Write-Host "  -> $RemoteRelativePath" -ForegroundColor Cyan
    $client = New-Object System.Net.WebClient
    $client.Credentials = New-Object System.Net.NetworkCredential($Deploy.FtpUser, $Deploy.FtpPassword)
    try {
        $client.UploadFile($uri, $LocalPath)
    } catch {
        Write-Host "  !! skipped ($($_.Exception.Message))" -ForegroundColor Yellow
    } finally {
        $client.Dispose()
    }
}
