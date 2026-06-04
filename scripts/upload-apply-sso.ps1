# Upload apply.olasentra.com SSO + shared login files via FTP.
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\upload-apply-sso.ps1
#
# Uses deploy.local.ps1. Optional key: FtpApplyRemoteDir (default /apply/admin)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$localDeploy = Join-Path $ProjectRoot 'deploy.local.ps1'
if (-not (Test-Path $localDeploy)) {
    Write-Host 'Missing deploy.local.ps1' -ForegroundColor Red
    exit 1
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

$applyDir = $cfg.FtpApplyRemoteDir
if ([string]::IsNullOrWhiteSpace($applyDir)) {
    $applyDir = '/apply/admin'
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
        # already exists
    }
}

function Send-FtpFile {
    param([string]$LocalPath, [string]$RemoteRelativePath, [string]$RemoteBase, [hashtable]$Deploy)
    if (-not (Test-Path $LocalPath)) {
        throw "Local file missing: $LocalPath"
    }
    $uri = Get-FtpUri -Server $Deploy.FtpServer -RemoteDir $RemoteBase -RelativePath $RemoteRelativePath
    Write-Host "  Upload $RemoteRelativePath" -ForegroundColor Cyan
    $client = New-Object System.Net.WebClient
    $client.Credentials = New-Object System.Net.NetworkCredential($Deploy.FtpUser, $Deploy.FtpPassword)
    try {
        $client.UploadFile($uri, $LocalPath)
    } finally {
        $client.Dispose()
    }
}

$files = @(
    @{ Local = 'apply\admin\sso.php'; Remote = 'sso.php' },
    @{ Local = 'apply\admin\includes\apply-sso.php'; Remote = 'includes/apply-sso.php' },
    @{ Local = 'apply\admin\includes\main-admin-bridge.php'; Remote = 'includes/main-admin-bridge.php' },
    @{ Local = 'apply\admin\includes\auth.php'; Remote = 'includes/auth.php' },
    @{ Local = 'apply\admin\admin\login.php'; Remote = 'admin/login.php' }
)

Write-Host "Uploading apply SSO to $($cfg.FtpServer)$applyDir ..." -ForegroundColor Green
Ensure-FtpDirectory -Server $cfg.FtpServer -RemoteDir $applyDir -Deploy $cfg
Ensure-FtpDirectory -Server $cfg.FtpServer -RemoteDir "$applyDir/includes" -Deploy $cfg
Ensure-FtpDirectory -Server $cfg.FtpServer -RemoteDir "$applyDir/admin" -Deploy $cfg

foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $ProjectRoot $f.Local) -RemoteRelativePath $f.Remote -RemoteBase $applyDir -Deploy $cfg
}

Write-Host ''
Write-Host 'Done. Test: https://apply.olasentra.com/sso.php' -ForegroundColor Green
Write-Host '(Expect "Invalid or expired sign-in link" — not 404.)' -ForegroundColor Gray
