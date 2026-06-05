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

function Send-FtpFile {
    param(
        [string]$LocalPath,
        [string]$RemoteRelativePath,
        [string]$RemoteBase,
        [hashtable]$Deploy
    )
    if (-not (Test-Path $LocalPath)) {
        throw "Local file missing: $LocalPath"
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
